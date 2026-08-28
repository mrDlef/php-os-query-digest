<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Cli;

use MrDlef\OsQueryDigest\Analysis\Report;
use MrDlef\OsQueryDigest\Analysis\Shape;
use MrDlef\OsQueryDigest\Exception\InvalidOptionException;
use MrDlef\OsQueryDigest\Exception\InvalidQueryException;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Options;

/**
 * `os-query-digest slowlog` — which shape of query is costing you, from the log
 * the cluster already writes.
 *
 * This is the answer to the objection the rest of the tool cannot meet: it asks
 * you to log digests before you know whether they tell you anything. Every
 * cluster has `index.search.slowlog` on already, so this reads what is there
 * and ranks it — no application change, nothing to deploy, no index to create.
 *
 * **Ranked by total time by default**, not by the slowest single record. A
 * shape at 4 s once is a bad afternoon; a shape at 90 ms four thousand times is
 * the cluster's afternoon, and the slow log lists the second one four thousand
 * times without ever adding them up.
 *
 * Input is read a line at a time rather than slurped: rotated slow logs run to
 * gigabytes, and the whole point is that you can point this at the file you
 * already have.
 *
 * @internal
 */
final class SlowlogCommand
{
    /**
     * How the ranking is ordered, and what the default answers. The list is
     * {@see Report}'s: the grouping and the ranking are the library's, and a
     * flag offering a key the report cannot rank by would be a promise made in
     * the wrong file.
     *
     * @var array<int,string>
     */
    private const SORTS = Report::KEYS;

    private const DEFAULT_TOP = 20;

    /**
     * A search is logged twice, once per phase, both records carrying the same
     * body. Counting both would double every number in the report, so one phase
     * is read at a time and the query phase — the one people tune — is the
     * default.
     *
     * @var array<int,string>
     */
    private const PHASES = ['query', 'fetch', 'both'];

    /** @var array<int,string> */
    private const VALUED = ['-s', '--sort', '-t', '--top', '-p', '--phase'];

    private string $name;

    private string $base;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct($stdin, $stdout, $stderr, string $name = 'os-query-digest')
    {
        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->base = $name;
        $this->name = $name . ' slowlog';
    }

    /**
     * @param array<int,string> $args everything after the sub-command name
     */
    public function run(array $args): int
    {
        /** @var array<string,mixed> $spec */
        $spec = [];
        $sort = 'total';
        $phase = 'query';
        $top = self::DEFAULT_TOP;
        $json = false;
        $files = [];
        $literal = false;

        $count = count($args);
        $i = 0;

        while ($i < $count) {
            $arg = $args[$i];
            $i++;

            if ($literal || $arg === '' || $arg === '-' || strpos($arg, '-') !== 0) {
                $files[] = $arg;
                continue;
            }

            if ($arg === '--') {
                $literal = true;
                continue;
            }

            $name = $arg;
            $inline = null;
            $equals = strpos($arg, '=');
            if ($equals !== false) {
                $name = substr($arg, 0, $equals);
                $inline = substr($arg, $equals + 1);
            }

            if (in_array($name, self::VALUED, true) || in_array($name, FingerprintFlags::VALUED, true)) {
                if ($inline !== null) {
                    $given = $inline;
                } elseif ($i < $count) {
                    $given = $args[$i];
                    $i++;
                } else {
                    return $this->fail($name . ' needs a value');
                }

                switch ($name) {
                    case '-s':
                    case '--sort':
                        if (!in_array($given, self::SORTS, true)) {
                            return $this->fail(
                                $name . ' takes one of ' . implode(', ', self::SORTS) . ', not ' . $given,
                            );
                        }
                        $sort = $given;
                        continue 2;
                    case '-p':
                    case '--phase':
                        if (!in_array($given, self::PHASES, true)) {
                            return $this->fail(
                                $name . ' takes one of ' . implode(', ', self::PHASES) . ', not ' . $given,
                            );
                        }
                        $phase = $given;
                        continue 2;
                    case '-t':
                    case '--top':
                        if ($given === 'none') {
                            $top = null;
                            continue 2;
                        }
                        if (preg_match('/^\d+$/', $given) !== 1) {
                            return $this->fail($name . ' takes a number, or `none`, not ' . $given);
                        }
                        $top = (int) $given;
                        continue 2;
                }

                try {
                    $updated = FingerprintFlags::valued($spec, $name, $given);
                } catch (InvalidOptionException $exception) {
                    return $this->fail($exception->getMessage());
                }

                if ($updated === null) {
                    return $this->fail('unknown option ' . $name);
                }

                $spec = $updated;
                continue;
            }

            if ($inline !== null) {
                return $this->fail($name . ' takes no value');
            }

            $updated = FingerprintFlags::flag($spec, $name);
            if ($updated !== null) {
                $spec = $updated;
                continue;
            }

            switch ($name) {
                case '-j':
                case '--json':
                    $json = true;
                    break;
                case '-h':
                case '--help':
                    $this->write($this->stdout, $this->usage());

                    return Command::OK;
                default:
                    return $this->fail('unknown option ' . $name);
            }
        }

        try {
            $options = Options::fromArray($spec);
        } catch (InvalidOptionException $exception) {
            return $this->fail($exception->getMessage());
        }

        return $this->report(
            Formatter::create($options),
            $files === [] ? ['-'] : $files,
            $sort,
            $phase,
            $top,
            $json,
        );
    }

    /**
     * @param array<int,string> $files
     */
    private function report(
        Formatter $formatter,
        array $files,
        string $sort,
        string $phase,
        ?int $top,
        bool $json
    ): int {
        $report = new Report();
        $lines = 0;
        $records = 0;
        $failed = 0;
        $otherPhase = 0;
        $labelled = count($files) > 1;

        foreach ($files as $file) {
            $stream = $this->open($file);
            if ($stream === null) {
                return Command::USAGE;
            }

            $number = 0;

            while (($line = fgets($stream)) !== false) {
                $number++;
                $lines++;

                $record = Slowlog::parse($line);
                if ($record === null) {
                    // A line that opened `source[` and never closed it is a
                    // record, not noise — rotation cuts lines, and staying
                    // quiet about one would understate the shape it belonged
                    // to. Everything else genuinely is noise.
                    if (strpos($line, 'source[') !== false) {
                        $this->write(
                            $this->stderr,
                            $this->name . ': ' . $this->where($file, $number, $labelled)
                            . ": the source is unterminated — the line looks cut short\n",
                        );
                        $failed++;
                    }

                    continue;
                }

                // A record that does not name its phase is kept whatever was
                // asked for: dropping data on the strength of a guess about an
                // unknown layout is worse than counting it.
                $its = $record->phase();
                if ($phase !== 'both' && $its !== null && $its !== $phase) {
                    $otherPhase++;

                    continue;
                }

                $records++;

                try {
                    $digest = $formatter->describe($record->source(), $record->index());
                } catch (InvalidQueryException $exception) {
                    $this->write(
                        $this->stderr,
                        $this->name . ': ' . $this->where($file, $number, $labelled)
                        . ': ' . $exception->getMessage() . "\n",
                    );
                    $failed++;
                    continue;
                }

                $report->record($digest, $record->tookMillis(), $record->timestamp());
            }

            if ($file !== '-') {
                fclose($stream);
            }
        }

        if ($report->count() === 0) {
            $this->write(
                $this->stderr,
                $this->name . ': no search record in ' . self::plural($lines, 'line') . ".\n"
                . "Expected a slow log: plain `… source[{…}] …` lines, or the JSON appender's `source` field.\n"
                . 'A file of bare query bodies is `' . $this->base . " --ndjson` instead.\n",
            );

            return Command::INVALID_INPUT;
        }

        $ranked = $report->rank($sort);
        $kept = $top === null ? $ranked : $report->top($top, $sort);

        if ($json) {
            $encoded = json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($encoded === false) {
                $this->write($this->stderr, $this->name . ": the report is not valid UTF-8, so it cannot be encoded as JSON\n");

                return Command::INVALID_INPUT;
            }
            $this->write($this->stdout, $encoded . "\n");
        } else {
            $this->write(
                $this->stdout,
                self::summary($lines, $records, $failed, $otherPhase, $report)
                . self::table($kept, $sort)
                . self::footer(count($ranked), count($kept)),
            );
        }

        return $failed === 0 ? Command::OK : Command::INVALID_INPUT;
    }

    /** Where a bad record was, named only when more than one file was read. */
    private function where(string $file, int $number, bool $labelled): string
    {
        return ($labelled && $file !== '-' ? $file . ' ' : '') . 'line ' . $number;
    }

    private static function summary(
        int $lines,
        int $records,
        int $failed,
        int $otherPhase,
        Report $report
    ): string {
        $summary = sprintf(
            '%s, %s, %s, %s ms total',
            self::plural($lines, 'line'),
            self::plural($records, 'record'),
            self::plural($report->count(), 'shape'),
            self::thousands($report->total()),
        );

        $notes = [];
        if ($otherPhase > 0) {
            $notes[] = sprintf('%s from another phase, see --phase', self::plural($otherPhase, 'record'));
        }
        if ($failed > 0) {
            $notes[] = sprintf('%s unreadable, reported above', self::thousands((float) $failed));
        }
        if ($notes !== []) {
            $summary .= ' (' . implode('; ', $notes) . ')';
        }

        return $summary . "\n\n";
    }

    /**
     * @param array<int,Shape> $shapes
     */
    private static function table(array $shapes, string $sort): string
    {
        $headers = ['count', 'total ms', 'mean', 'p95', 'max'];
        $rows = [];

        foreach ($shapes as $shape) {
            $rows[] = [
                self::thousands((float) $shape->count()),
                self::duration($shape->measured() === 0 ? null : $shape->total()),
                self::duration($shape->mean()),
                self::duration($shape->p95()),
                self::duration($shape->max()),
            ];
        }

        // The column the ranking used is starred, so a table pasted into a
        // ticket still says what it was ordered by.
        $ranked = $sort === 'total' ? 'total ms' : $sort;
        foreach ($headers as $column => $header) {
            if ($header === $ranked) {
                $headers[$column] = $header . '*';
            }
        }

        $widths = [];
        foreach ($headers as $column => $header) {
            $width = strlen($header);
            foreach ($rows as $row) {
                $width = max($width, strlen($row[$column]));
            }
            $widths[$column] = $width;
        }

        $out = '  ' . self::row($headers, $widths) . "  shape\n";
        $indent = 2 + array_sum($widths) + 2 * count($widths);

        foreach ($shapes as $position => $shape) {
            $out .= '  ' . self::row($rows[$position], $widths) . '  ' . $shape->hash() . "\n"
                . str_repeat(' ', $indent) . $shape->signature() . "\n";
        }

        return $out;
    }

    /**
     * @param array<int,string> $cells
     * @param array<int,int>    $widths
     */
    private static function row(array $cells, array $widths): string
    {
        $padded = [];
        foreach ($cells as $column => $cell) {
            $padded[] = str_pad($cell, $widths[$column], ' ', STR_PAD_LEFT);
        }

        return implode('  ', $padded);
    }

    private static function footer(int $total, int $kept): string
    {
        if ($kept >= $total) {
            return '';
        }

        $hidden = $total - $kept;

        return sprintf(
            "\n%s more %s (--top none for all)\n",
            self::thousands((float) $hidden),
            $hidden === 1 ? 'shape' : 'shapes',
        );
    }

    private static function plural(int $count, string $noun): string
    {
        return self::thousands((float) $count) . ' ' . $noun . ($count === 1 ? '' : 's');
    }

    private static function duration(?float $millis): string
    {
        return $millis === null ? '-' : self::thousands($millis);
    }

    /** Milliseconds, whole: the appenders report them whole. */
    private static function thousands(float $value): string
    {
        return number_format(round($value), 0, '.', ',');
    }

    /**
     * @return resource|null
     */
    private function open(string $file)
    {
        if ($file === '-') {
            return $this->stdin;
        }

        if (!is_file($file) || !is_readable($file)) {
            $this->fail('cannot read ' . $file);

            return null;
        }

        $stream = fopen($file, 'rb');
        if ($stream === false) {
            $this->fail('cannot read ' . $file);

            return null;
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function write($stream, string $text): void
    {
        fwrite($stream, $text);
    }

    private function fail(string $message): int
    {
        $this->write($this->stderr, $this->name . ': ' . $message . "\n");
        $this->write($this->stderr, 'Try `' . $this->name . " --help`.\n");

        return Command::USAGE;
    }

    private function usage(): string
    {
        $sorts = implode('|', self::SORTS);
        $phases = implode('|', self::PHASES);
        $fingerprint = FingerprintFlags::usage();
        $top = self::DEFAULT_TOP;

        return <<<TXT
{$this->name} — which shape of query is costing you, from the log your
cluster already writes.

Usage:
  {$this->name} [options] [FILE…]

Reads OpenSearch `index.search.slowlog` records — the plain appender and the
JSON one — from every FILE, or from stdin. Lines that hold no search record are
skipped in silence: a slow log holds more than searches.

Groups the records by fingerprint and ranks the groups, so one shape hit four
thousand times outranks one slow record you would otherwise read four thousand
times. The table prints the signature of each group, not one record's values.

Report:
  -p, --phase=WHICH        {$phases} (default: query) — a search is logged
                           once per phase and both records carry the same
                           body, so counting both doubles every number
  -s, --sort=KEY           {$sorts} (default: total)
  -t, --top=N              shapes listed, or `none` (default: {$top})
  -j, --json               emit the ranking as JSON, with the slowest sample
                           of each shape and the timestamps it spans

{$fingerprint}

  -h, --help               this text

Exit codes: 0 ok, 1 no records found or one could not be parsed, 2 a bad
invocation.

  {$this->name} /var/log/opensearch/*_index_search_slowlog.log
  {$this->name} --sort=p95 --top=5 slowlog.json

TXT;
    }
}
