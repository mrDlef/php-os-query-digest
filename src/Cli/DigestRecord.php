<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Cli;

use MrDlef\OsQueryDigest\Digest;
use MrDlef\OsQueryDigest\Kind;

/**
 * One line of an application's own log, read back as a digest.
 *
 * Nothing is parsed here in the DSL sense: the fingerprint was minted when the
 * search ran, and this reads it back. That is the point — a report over a
 * week of logs must not depend on re-deriving a hash from a request body the
 * log no longer carries, and re-deriving it under today's rules would answer a
 * different question than the one the stored hashes were grouped by.
 *
 * **Where the fields are is the caller's to say.** This library emits `dsl` as
 * an object or `dsl_*` as siblings ({@see \MrDlef\OsQueryDigest\RecordLayout}),
 * and an application that assembled its own record put them wherever it liked.
 * So the key names are given, a dotted one walking into a nested object — which
 * is what makes one reader enough for both spellings.
 *
 * @internal
 */
final class DigestRecord
{
    /**
     * The keys a record is read from, and the field each holds.
     *
     * @var array<int,string>
     */
    public const FIELDS = ['hash', 'sig', 'text', 'kind', 'index', 'took', 'time'];

    private Digest $digest;

    private ?float $tookMillis;

    private ?string $timestamp;

    private function __construct(Digest $digest, ?float $tookMillis, ?string $timestamp)
    {
        $this->digest = $digest;
        $this->tookMillis = $tookMillis;
        $this->timestamp = $timestamp;
    }

    /**
     * The record this line holds, or null when it holds none.
     *
     * A log file holds more than searches, and every line that is not one is
     * skipped in silence — the alternative is a tool that refuses the file it
     * was pointed at. A line counts as a record when it has a fingerprint;
     * everything else about it is optional.
     *
     * @param array<string,string> $keys field => key, as {@see FIELDS} names them
     */
    public static function parse(string $line, array $keys): ?self
    {
        // Whatever the collector wrote in front of the JSON — a syslog prefix,
        // a container name, a timestamp — is not ours to model.
        $start = strpos($line, '{');
        if ($start === false) {
            return null;
        }

        $decoded = json_decode(substr($line, $start), true);
        if (!is_array($decoded)) {
            return null;
        }

        $hash = self::string($decoded, $keys['hash'] ?? '');
        if ($hash === null || $hash === '') {
            return null;
        }

        $text = self::string($decoded, $keys['text'] ?? '');
        $signature = self::string($decoded, $keys['sig'] ?? '');
        $kind = self::string($decoded, $keys['kind'] ?? '');

        return new self(
            new Digest(
                self::string($decoded, $keys['index'] ?? '') ?? '',
                $text,
                // A record logged under `withText(false)` has a signature and no
                // line; one logged the other way round is not a shape anyone can
                // read, so the hash stands in rather than an empty column.
                $signature ?? $text ?? $hash,
                $hash,
                [],
                $kind === null ? null : Kind::fromName($kind),
            ),
            self::millis($decoded, $keys['took'] ?? ''),
            self::string($decoded, $keys['time'] ?? ''),
        );
    }

    public function digest(): Digest
    {
        return $this->digest;
    }

    public function tookMillis(): ?float
    {
        return $this->tookMillis;
    }

    public function timestamp(): ?string
    {
        return $this->timestamp;
    }

    /**
     * The value at `$key`, which is a plain key or a dotted path into nested
     * objects. The literal key is tried first: a collector is free to have
     * written a key with a dot in its name, and guessing otherwise would make
     * that record unreadable for a reason nobody could see.
     *
     * @param array<mixed> $data
     *
     * @return mixed
     */
    private static function at(array $data, string $key)
    {
        if ($key === '') {
            return null;
        }

        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        $value = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = self::at($data, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<mixed> $data
     */
    private static function millis(array $data, string $key): ?float
    {
        $value = self::at($data, $key);

        // Numeric strings included: a duration is whatever the collector's JSON
        // encoder made of it, and a report that ignored `"took": "12"` would
        // rank a whole file at zero without saying why.
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;
    }
}
