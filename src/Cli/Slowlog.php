<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Cli;

/**
 * One search slow log line, taken apart.
 *
 * A cluster writes the same record twice, through two appenders: the plain one
 * that ends up in `*_index_search_slowlog.log`, and the JSON one beside it.
 * Both are read here — they carry the same fields and differ only in
 * packaging, and understanding one of them would leave half the clusters
 * unable to run the report.
 *
 * Layouts that namespace their JSON keys — `elasticsearch.slowlog.source` and
 * the like — are read too, which is why the lookup takes a prefix list rather
 * than a second parser. That is tolerance, not a supported product: no
 * distribution but OpenSearch is certified here, and none is claimed.
 *
 * **A line with no search source is not an error.** Slow log files also hold
 * rotation notices, stack traces and whatever else log4j put there, and a tool
 * that refused the file over them would be useless exactly where it is pointed.
 * A line that *is* a record and whose source will not parse is a different
 * matter, and the caller reports it.
 *
 * @internal
 */
final class Slowlog
{
    /**
     * Key prefixes the JSON appenders use, in the order they are tried.
     *
     * @var array<int,string>
     */
    private const PREFIXES = ['', 'elasticsearch.slowlog.'];

    private string $source;

    private ?string $index;

    private ?float $tookMillis;

    private ?string $timestamp;

    private ?string $phase;

    private function __construct(
        string $source,
        ?string $index,
        ?float $tookMillis,
        ?string $timestamp,
        ?string $phase
    ) {
        $this->source = $source;
        $this->index = $index;
        $this->tookMillis = $tookMillis;
        $this->timestamp = $timestamp;
        $this->phase = $phase;
    }

    /** The record this line holds, or null when it holds none. */
    public static function parse(string $line): ?self
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        return $line[0] === '{' ? self::fromJson($line) : self::fromPlain($line);
    }

    /** The search body, as it was logged. */
    public function source(): string
    {
        return $this->source;
    }

    /** The index searched, when the record names it. */
    public function index(): ?string
    {
        return $this->index;
    }

    /** What the shard took, in milliseconds. */
    public function tookMillis(): ?float
    {
        return $this->tookMillis;
    }

    /**
     * The record's own timestamp, verbatim. Not parsed and not normalised: the
     * appenders disagree on the format and half of them omit the zone, so any
     * reading of it would be a guess printed as a fact.
     */
    public function timestamp(): ?string
    {
        return $this->timestamp;
    }

    /**
     * `query` or `fetch` — the two phases a search is logged in, both carrying
     * the same body. Null when the record does not say which, and a record that
     * does not say is never filtered out on the strength of a guess.
     */
    public function phase(): ?string
    {
        return $this->phase;
    }

    /**
     * Both products abbreviate the logger in the plain layout — `i.s.s.query`
     * for `index.search.slowlog.query` — so the suffix is what is read, not the
     * whole name.
     */
    private static function phaseOf(?string $logger): ?string
    {
        if ($logger === null) {
            return null;
        }

        $logger = trim($logger);

        foreach (['query', 'fetch'] as $phase) {
            if (substr($logger, -strlen($phase) - 1) === '.' . $phase) {
                return $phase;
            }
        }

        return null;
    }

    private static function fromJson(string $line): ?self
    {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            return null;
        }

        $source = self::field($decoded, 'source');
        if (is_array($source)) {
            // Some layouts embed the body rather than the string of it.
            $encoded = json_encode($source);
            $source = $encoded === false ? null : $encoded;
        }
        if (!is_string($source) || trim($source) === '') {
            return null;
        }

        $source = self::unescaped($source);

        $timestamp = null;
        foreach (['timestamp', '@timestamp'] as $key) {
            $candidate = $decoded[$key] ?? null;
            if (is_string($candidate)) {
                $timestamp = $candidate;
                break;
            }
        }

        $logger = null;
        foreach (['component', 'log.logger', 'logger'] as $key) {
            $candidate = $decoded[$key] ?? null;
            if (is_string($candidate)) {
                $logger = $candidate;
                break;
            }
        }

        return new self(
            $source,
            self::jsonIndex($decoded),
            self::millis(self::field($decoded, 'took_millis')),
            $timestamp,
            self::phaseOf($logger),
        );
    }

    /**
     * OpenSearch 3's JSON layout escapes the body a second time, so the field
     * decodes to `{\"size\":50}` where 2.x gives `{"size":50}` — same config
     * file, different node. One layer is taken back off, through the decoder
     * rather than by stripping backslashes: a query holding `\\"` inside a
     * string would not survive the crude version.
     *
     * Only a body that opens `{\"` is touched, which no JSON object does, and
     * only when what comes out is valid. Anything else is handed on unchanged,
     * so a genuinely broken record is still reported as the record it is.
     */
    private static function unescaped(string $source): string
    {
        if (strpos($source, '{\\"') !== 0) {
            return $source;
        }

        $once = json_decode('"' . $source . '"');
        if (!is_string($once)) {
            return $source;
        }

        json_decode($once);

        return json_last_error() === JSON_ERROR_NONE ? $once : $source;
    }

    /**
     * The index, from the field that names it or from the `[index][shard]`
     * message every appender opens with.
     *
     * @param array<mixed> $record
     */
    private static function jsonIndex(array $record): ?string
    {
        foreach (['index', 'elasticsearch.index.name'] as $key) {
            $named = $record[$key] ?? null;
            if (is_string($named) && $named !== '') {
                return $named;
            }
        }

        $message = self::field($record, 'message');
        if (is_string($message) && preg_match('/^\[([^\[\]]+)\]\[\d+\]/', $message, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private static function fromPlain(string $line): ?self
    {
        $at = strpos($line, 'source[');
        if ($at === false) {
            return null;
        }

        $source = self::bracketed($line, $at + 7);
        if ($source === null || trim($source) === '') {
            return null;
        }

        $index = null;
        // Anchored on `took[`, so the node name in front of it cannot match.
        if (preg_match('/\[([^\[\]]+)\]\[\d+\]\s*took\[/', $line, $match) === 1) {
            $index = $match[1];
        }

        $took = null;
        if (preg_match('/took_millis\[(\d+(?:\.\d+)?)\]/', $line, $match) === 1) {
            $took = (float) $match[1];
        }

        $timestamp = null;
        if (preg_match('/^\[([^\[\]]+)\]/', $line, $match) === 1) {
            $timestamp = $match[1];
        }

        // Third bracket group of the layout every distribution ships:
        // `[timestamp][LEVEL][logger] [node] …`.
        $logger = null;
        if (preg_match('/^\[[^\[\]]*\]\[[^\[\]]*\]\[([^\[\]]*)\]/', $line, $match) === 1) {
            $logger = $match[1];
        }

        return new self($source, $index, $took, $timestamp, self::phaseOf($logger));
    }

    /**
     * What `source[` opened, up to the bracket that closes it.
     *
     * Counting brackets is not enough on its own: a query holds `[` and `]`
     * inside strings — a `terms` value, a regexp, a field named `a[0]` — and
     * the plain appender escapes none of it. So the scan knows where strings
     * are, and stops at the first `]` outside one that brings the depth back to
     * zero. A line the appender truncated never reaches zero, and returns null.
     */
    private static function bracketed(string $line, int $from): ?string
    {
        $depth = 1;
        $inString = false;
        $escaped = false;
        $length = strlen($line);

        for ($i = $from; $i < $length; $i++) {
            $char = $line[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($line, $from, $i - $from);
                }
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $record
     *
     * @return mixed the field under any of the prefixes, or null
     */
    private static function field(array $record, string $name)
    {
        foreach (self::PREFIXES as $prefix) {
            $key = $prefix . $name;
            if (array_key_exists($key, $record)) {
                return $record[$key];
            }
        }

        return null;
    }

    /**
     * @param mixed $value the appenders write it as a number or as its string
     */
    private static function millis($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
