<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Cli;

use MrDlef\OsQueryDigest\Exception\InvalidOptionException;
use MrDlef\OsQueryDigest\Exception\InvalidQueryException;
use MrDlef\OsQueryDigest\Explain\Explanation;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;
use MrDlef\OsQueryDigest\Support\IndexNormalizer;

/**
 * The library on a pipe.
 *
 * The payoff is `--ndjson --hash`: point it at a slow log and
 * `sort | uniq -c | sort -rn` answers which *shape* of query is hurting you,
 * which no amount of reading individual slow queries will tell you.
 *
 * Streams are injected rather than taken from the `STDIN` constants so the
 * whole thing is testable in-process, without spawning a shell.
 *
 * @internal
 */
final class Command
{
    public const OK = 0;
    public const INVALID_INPUT = 1;
    public const USAGE = 2;

    /**
     * Options that consume a value, in either `--key=value` or `--key value`
     * form. Everything else is a flag and rejects `=`.
     *
     * @var array<int,string>
     */
    private const VALUED = [
        '-i', '--index',
        '-n', '--normalization',
        '--max-clauses',
        '--max-values',
        '--max-length',
        '--hash-version',
        '--hash-length',
    ];

    private string $name;

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
        $this->name = $name;
    }

    /**
     * @param array<int,string> $argv the whole argv, program name included
     */
    public function run(array $argv): int
    {
        /** @var array<string,mixed> $spec */
        $spec = [];
        $index = null;
        $explain = false;
        $json = false;
        $hashOnly = false;
        $ndjson = false;
        $positional = [];
        $literal = false;

        $args = array_slice($argv, 1);
        $count = count($args);
        $i = 0;

        while ($i < $count) {
            $arg = $args[$i];
            $i++;

            if ($literal || $arg === '' || $arg === '-' || strpos($arg, '-') !== 0) {
                $positional[] = $arg;
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

            if (in_array($name, self::VALUED, true)) {
                if ($inline !== null) {
                    $given = $inline;
                } elseif ($i < $count) {
                    $given = $args[$i];
                    $i++;
                } else {
                    return $this->fail($name . ' needs a value');
                }

                try {
                    switch ($name) {
                        case '-i':
                        case '--index':
                            $index = $given;
                            break;
                        case '-n':
                        case '--normalization':
                            $spec['normalization'] = $given;
                            break;
                        case '--max-clauses':
                            $spec['maxClauses'] = self::cap($name, $given);
                            break;
                        case '--max-values':
                            $spec['maxValues'] = self::cap($name, $given);
                            break;
                        case '--max-length':
                            $spec['maxLength'] = self::cap($name, $given);
                            break;
                        case '--hash-version':
                            $spec['hashVersion'] = $given;
                            break;
                        case '--hash-length':
                            $spec['hashLength'] = self::number($name, $given);
                            break;
                    }
                } catch (InvalidOptionException $exception) {
                    return $this->fail($exception->getMessage());
                }

                continue;
            }

            if ($inline !== null) {
                return $this->fail($name . ' takes no value');
            }

            switch ($name) {
                case '-e':
                case '--explain':
                    $explain = true;
                    break;
                case '-j':
                case '--json':
                    $json = true;
                    break;
                case '--hash':
                    $hashOnly = true;
                    break;
                case '--ndjson':
                    $ndjson = true;
                    break;
                case '--agg-names':
                    $spec['aggNames'] = true;
                    break;
                case '--raw-index':
                    $spec['indexNormalizer'] = IndexNormalizer::IDENTITY;
                    break;
                case '-h':
                case '--help':
                    $this->write($this->stdout, $this->usage());

                    return self::OK;
                case '-V':
                case '--version':
                    $this->write(
                        $this->stdout,
                        $this->name . ', fingerprint version '
                        . Options::create()->hashVersion() . "\n",
                    );

                    return self::OK;
                default:
                    return $this->fail('unknown option ' . $name);
            }
        }

        if ($json && $hashOnly) {
            return $this->fail('--json and --hash are two different output formats');
        }

        if ($ndjson && $explain && !$json) {
            return $this->fail('--explain needs --json in --ndjson mode: a rules table per line is unreadable');
        }

        if (count($positional) > 1) {
            return $this->fail('expected at most one file, got ' . count($positional));
        }

        try {
            $options = Options::fromArray($spec);
        } catch (InvalidOptionException $exception) {
            return $this->fail($exception->getMessage());
        }

        $input = $this->read($positional === [] ? null : $positional[0]);
        if ($input === null) {
            return self::USAGE;
        }

        $formatter = Formatter::create($options);

        if (!$ndjson) {
            return $this->one($formatter, $input, $index, $explain, $json, $hashOnly);
        }

        return $this->many($formatter, $input, $index, $explain, $json, $hashOnly);
    }

    /**
     * One query: the readable block, or a single JSON object.
     */
    private function one(
        Formatter $formatter,
        string $input,
        ?string $index,
        bool $explain,
        bool $json,
        bool $hashOnly
    ): int {
        try {
            $explanation = $formatter->explain(trim($input), $index);
        } catch (InvalidQueryException $exception) {
            $this->write($this->stderr, $this->name . ': ' . $exception->getMessage() . "\n");

            return self::INVALID_INPUT;
        }

        if ($hashOnly) {
            $this->write($this->stdout, $explanation->digest()->hash() . "\n");

            return self::OK;
        }

        if ($json) {
            $encoded = self::encode($explain ? $explanation : $explanation->digest(), true);
            if ($encoded === null) {
                $this->write($this->stderr, $this->name . ": the digest is not valid UTF-8, so it cannot be encoded as JSON\n");

                return self::INVALID_INPUT;
            }
            $this->write($this->stdout, $encoded . "\n");

            return self::OK;
        }

        $this->write($this->stdout, $this->report($explanation, $explain));

        return self::OK;
    }

    /**
     * One query per line, one output line per query — the shape `sort | uniq -c`
     * expects. A bad line is reported and skipped: a slow log is untrusted
     * input, and stopping at the first mangled record would make the tool
     * useless exactly where it is needed.
     */
    private function many(
        Formatter $formatter,
        string $input,
        ?string $index,
        bool $explain,
        bool $json,
        bool $hashOnly
    ): int {
        $lines = preg_split('/\R/', $input);
        if ($lines === false) {
            return $this->fail('could not split the input into lines');
        }

        $failed = false;

        foreach ($lines as $number => $line) {
            if (trim($line) === '') {
                continue;
            }

            try {
                $explanation = $formatter->explain($line, $index);
            } catch (InvalidQueryException $exception) {
                $this->write(
                    $this->stderr,
                    $this->name . ': line ' . ($number + 1) . ': ' . $exception->getMessage() . "\n",
                );
                $failed = true;
                continue;
            }

            $digest = $explanation->digest();

            if ($hashOnly) {
                $this->write($this->stdout, $digest->hash() . "\n");
                continue;
            }

            if ($json) {
                $encoded = self::encode($explain ? $explanation : $digest, false);
                if ($encoded === null) {
                    $this->write(
                        $this->stderr,
                        $this->name . ': line ' . ($number + 1) . ": not valid UTF-8, cannot be encoded as JSON\n",
                    );
                    $failed = true;
                    continue;
                }
                $this->write($this->stdout, $encoded . "\n");
                continue;
            }

            $this->write($this->stdout, $digest->hash() . "\t" . $digest->text() . "\n");
        }

        return $failed ? self::INVALID_INPUT : self::OK;
    }

    /**
     * The whole input, or null when there is nothing usable to read — the
     * message is already on stderr by then.
     */
    private function read(?string $file): ?string
    {
        if ($file !== null && $file !== '-') {
            if (!is_file($file) || !is_readable($file)) {
                $this->fail('cannot read ' . $file);

                return null;
            }

            $raw = file_get_contents($file);
            if ($raw === false) {
                $this->fail('cannot read ' . $file);

                return null;
            }
        } else {
            $raw = stream_get_contents($this->stdin);
            if ($raw === false) {
                $raw = '';
            }
        }

        if (trim($raw) === '') {
            $this->fail('no input: pass a file, or pipe a query on stdin');

            return null;
        }

        return $raw;
    }

    /**
     * The terminal block. With `--explain` the rules table comes from
     * {@see Explanation::__toString()} — the library already renders it, and
     * two renderers would drift.
     */
    private function report(Explanation $explanation, bool $explain): string
    {
        $digest = $explanation->digest();
        $index = 'idx:  ' . $digest->index() . "\n";

        if ($explain) {
            return $index . $explanation . "\n";
        }

        // Same labels and same column as Explanation::__toString(), so
        // --explain only ever *adds* to what you already saw.
        $lines = [
            'text: ' . $digest->text(),
            'sig:  ' . $digest->signature(),
            'hash: ' . $digest->hash(),
        ];

        $notes = $digest->notes();
        if ($notes !== []) {
            $lines[] = 'notes: ' . implode(' ', $notes);
        }

        return $index . implode("\n", $lines) . "\n";
    }

    /**
     * A cap: a number, or `none` to lift it.
     *
     * @throws InvalidOptionException
     */
    private static function cap(string $option, string $value): ?int
    {
        if ($value === 'none') {
            return null;
        }

        return self::number($option, $value);
    }

    /**
     * @throws InvalidOptionException
     */
    private static function number(string $option, string $value): int
    {
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            throw InvalidOptionException::notAllowed($option, $value, ['a number', 'none']);
        }

        return (int) $value;
    }

    /**
     * The JSON of a digest or an explanation, or null when the strings it holds
     * are not valid UTF-8.
     */
    private static function encode(\JsonSerializable $value, bool $pretty): ?string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode($value, $flags);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @param resource $stream
     */
    private function write($stream, string $text): void
    {
        fwrite($stream, $text);
    }

    /**
     * Report a bad invocation and hand back the exit code, so every call site
     * is a single `return $this->fail(...)`.
     */
    private function fail(string $message): int
    {
        $this->write($this->stderr, $this->name . ': ' . $message . "\n");
        $this->write($this->stderr, 'Try `' . $this->name . " --help`.\n");

        return self::USAGE;
    }

    private function usage(): string
    {
        $levels = implode('|', Normalization::LEVELS);

        return <<<TXT
{$this->name} — a readable line, a signature and a stable hash for an
OpenSearch / Elasticsearch DSL query.

Usage:
  {$this->name} [options] [FILE]

Reads a search body, an {"index": …, "body": …} envelope, or the JSON of
either, from FILE or from stdin (`-` also means stdin).

Output:
  -e, --explain            list the normalisation rules that fired
  -j, --json               emit JSON instead of the terminal block
      --hash               emit only the hash
      --ndjson             one query per input line, one line of output each
                           (--explain then requires --json)

Query:
  -i, --index=NAME         the index the query was sent to, when the input is
                           a bare body

Fingerprint:
  -n, --normalization=L    {$levels} (default: values)
      --max-clauses=N      sibling clauses rendered per level, or `none`
      --max-values=N       values rendered inside a terms clause, or `none`
      --max-length=N       hard cap on the rendered lines, or `none`
      --agg-names          keep user-given aggregation names
      --raw-index          do not collapse logs-2026.08.13 to logs-*
      --hash-version=V     prefix marking which rules produced the hash
      --hash-length=N      hex characters kept from the sha256

  -h, --help               this text
  -V, --version            the fingerprint version this build produces

Exit codes: 0 ok, 1 an input could not be parsed, 2 a bad invocation.

Which query shape is hurting, from a log of search bodies:
  {$this->name} --ndjson --hash < slow.ndjson | sort | uniq -c | sort -rn

TXT;
    }
}
