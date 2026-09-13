<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Cli;

use MrDlef\OsQueryDigest\Analysis\Report;
use MrDlef\OsQueryDigest\RecordLayout;

/**
 * `os-query-digest report` — which shape of query is costing you, from the
 * digests your own application already logs.
 *
 * The other two sub-commands mint fingerprints: `--ndjson` from request bodies,
 * `slowlog` from what the cluster wrote. This one mints none. It reads back what
 * was logged when each search ran, and that is deliberate rather than lazy —
 * the log has no request body left to re-parse, and re-deriving hashes under
 * today's rules would group a year of records by a rule they were never grouped
 * by. What is stored is the answer; this adds it up.
 *
 * **Which key holds what is the caller's to say.** Records this library emits
 * are read with the defaults; a record an application assembled itself is read
 * by naming its keys, and a dotted name walks into a nested object.
 *
 * @internal
 */
final class ReportCommand
{
    /** @var array<int,string> */
    private const SORTS = Report::KEYS;

    private const DEFAULT_TOP = 20;

    private const DEFAULT_PREFIX = 'dsl_';

    /**
     * The key each field is read from when nothing says otherwise: what
     * {@see RecordLayout::flat()} writes, plus the duration beside it that
     * every integration logs under `took`.
     *
     * @var array<string,string>
     */
    private const DEFAULT_KEYS = ['took' => 'took', 'time' => ''];

    /** @var array<int,string> */
    private const VALUED = [
        '-s', '--sort', '-t', '--top',
        '--key-prefix', '--hash-key', '--sig-key', '--text-key',
        '--kind-key', '--index-key', '--took-key', '--time-key',
    ];

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
        $this->name = $name . ' report';
    }

    /**
     * @param array<int,string> $args everything after the sub-command name
     */
    public function run(array $args): int
    {
        $sort = Report::TOTAL;
        $top = self::DEFAULT_TOP;
        $json = false;
        $prefix = self::DEFAULT_PREFIX;
        /** @var array<string,string> $named */
        $named = [];
        $files = [];
        $literal = false;
        $counter = count($args);

        for ($i = 0; $i < $counter; $i++) {
            $argument = $args[$i];

            if ($literal || $argument === '' || $argument[0] !== '-' || $argument === '-') {
                $files[] = $argument;
                continue;
            }

            if ($argument === '--') {
                $literal = true;
                continue;
            }

            $name = $argument;
            $inline = null;
            $equals = strpos($argument, '=');
            if ($equals !== false) {
                $name = substr($argument, 0, $equals);
                $inline = substr($argument, $equals + 1);
            }

            if (in_array($name, self::VALUED, true)) {
                $value = $inline ?? ($args[++$i] ?? null);
                if ($value === null) {
                    return $this->fail($name . ' needs a value');
                }

                if ($name === '-s' || $name === '--sort') {
                    if (!in_array($value, self::SORTS, true)) {
                        return $this->fail('--sort takes ' . implode('|', self::SORTS));
                    }
                    $sort = $value;
                    continue;
                }

                if ($name === '-t' || $name === '--top') {
                    if ($value === 'none') {
                        $top = null;
                        continue;
                    }
                    if (!ctype_digit($value) || (int) $value < 1) {
                        return $this->fail('--top takes a positive number, or `none`');
                    }
                    $top = (int) $value;
                    continue;
                }

                if ($name === '--key-prefix') {
                    $prefix = $value;
                    continue;
                }

                $named[substr($name, 2, -4)] = $value;
                continue;
            }

            if ($inline !== null) {
                return $this->fail($name . ' takes no value');
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

        return $this->report(self::keys($prefix, $named), $files === [] ? ['-'] : $files, $sort, $top, $json);
    }

    /**
     * The key each field is read from: the prefixed name, then whatever was
     * named explicitly. `--text-key=q` on top of `--key-prefix=q_` is the
     * ordinary case, not a corner one — an application that flattened the digest
     * itself had no reason to call the readable line `q_q`.
     *
     * @param array<string,string> $named
     *
     * @return array<string,string>
     */
    private static function keys(string $prefix, array $named): array
    {
        $keys = [];
        foreach (DigestRecord::FIELDS as $field) {
            $keys[$field] = self::DEFAULT_KEYS[$field] ?? $prefix . self::field($field);
        }

        return array_merge($keys, $named);
    }

    /** The digest's own name for a field, where it differs from the flag's. */
    private static function field(string $field): string
    {
        if ($field === 'text') {
            return 'q';
        }

        return $field === 'index' ? 'idx' : $field;
    }

    /**
     * @param array<string,string> $keys
     * @param array<int,string>    $files
     */
    private function report(array $keys, array $files, string $sort, ?int $top, bool $json): int
    {
        $report = new Report();
        $lines = 0;
        $records = 0;

        foreach ($files as $file) {
            $stream = $this->open($file);
            if ($stream === null) {
                return Command::USAGE;
            }

            while (($line = fgets($stream)) !== false) {
                $lines++;

                $record = DigestRecord::parse($line, $keys);
                if ($record === null) {
                    continue;
                }

                $records++;
                $report->record($record->digest(), $record->tookMillis(), $record->timestamp());
            }

            if ($file !== '-') {
                fclose($stream);
            }
        }

        if ($report->count() === 0) {
            $this->write(
                $this->stderr,
                $this->name . ': no digest in ' . ReportPrinter::plural($lines, 'line') . ".\n"
                . 'Expected one JSON object per line carrying `' . $keys['hash'] . "`.\n"
                . "Name the keys with --key-prefix, or one at a time with --hash-key and friends.\n"
                . 'A file of query bodies is `' . $this->base . " --ndjson`, a cluster's slow log `"
                . $this->base . " slowlog`.\n",
            );

            return Command::INVALID_INPUT;
        }

        $ranked = $report->rank($sort);
        $kept = $top === null ? $ranked : $report->top($top, $sort);

        if ($json) {
            $encoded = ReportPrinter::json($kept);
            if ($encoded === null) {
                $this->write($this->stderr, $this->name . ": the report is not valid UTF-8, so it cannot be encoded as JSON\n");

                return Command::INVALID_INPUT;
            }
            $this->write($this->stdout, $encoded);

            return Command::OK;
        }

        $this->write(
            $this->stdout,
            ReportPrinter::summary($lines, $records, $report, self::notes($report))
            . ReportPrinter::table($kept, $sort)
            . ReportPrinter::footer(count($ranked), count($kept)),
        );

        return Command::OK;
    }

    /**
     * @return array<int,string>
     */
    private static function notes(Report $report): array
    {
        // Ranking by total time is the default, and on a file whose records
        // carry no duration every total is zero — which looks like a broken
        // report rather than a missing field. Said once, in the header.
        return $report->total() > 0.0 ? [] : ['no durations, so only the counts are ranked; see --took-key'];
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
        $top = self::DEFAULT_TOP;
        $prefix = self::DEFAULT_PREFIX;

        return <<<TXT
{$this->name} — which shape of query is costing you, from the digests your
application already logs.

Usage:
  {$this->name} [options] [FILE…]

Reads one JSON object per line, from every FILE or from stdin, and groups them
by the fingerprint they already carry. Nothing is re-parsed: the hash stored
when the search ran is the one it is grouped by, so a year of records stays
comparable however the rules have moved since. Anything before the `{` on a
line — a syslog prefix, a container name — is ignored, and a line carrying no
fingerprint is skipped in silence: a log file holds more than searches.

Where the fields are:
      --key-prefix=P       read P+hash, P+sig, P+q, P+kind, P+idx (default: {$prefix})
      --hash-key=K         the fingerprint, and the only field a record needs
      --sig-key=K          the signature, which the table prints
      --text-key=K         the readable line, kept for the slowest of each shape
      --kind-key=K         suggest|aggregate|scan|lookup|browse|unknown
      --index-key=K        the index pattern
      --took-key=K         what the search cost, in ms (default: took)
      --time-key=K         when it happened, for the span each shape covers

A dotted key walks into a nested object, so `--key-prefix=dsl.` reads the
records this library writes under one key.

Report:
  -s, --sort=KEY           {$sorts} (default: total)
  -t, --top=N              shapes listed, or `none` (default: {$top})
  -j, --json               emit the ranking as JSON, with the slowest sample
                           of each shape and the timestamps it spans
  -h, --help               this text

Exit codes: 0 ok, 1 no digest found, 2 a bad invocation.

  {$this->name} /var/log/app/*.log
  {$this->name} --key-prefix=q_ --text-key=q --took-key=duration_ms app.log
  {$this->name} --key-prefix=dsl. --sort=p95 --top=5 app.log

TXT;
    }
}
