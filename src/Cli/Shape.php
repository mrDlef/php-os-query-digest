<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Cli;

use MrDlef\OsQueryDigest\Digest;

/**
 * Every slow log record that shares one fingerprint, and what they cost.
 *
 * The table prints the **signature**, never one record's text: under a count of
 * twenty-eight, a single sample's literals read as the group's, and they are
 * not. The slowest sample survives in the JSON output, where it is labelled as
 * what it is.
 *
 * @internal
 */
final class Shape implements \JsonSerializable
{
    private Digest $digest;

    private int $count = 0;

    /** @var array<int,float> */
    private array $durations = [];

    private ?float $slowest = null;

    private string $slowestText;

    private ?string $first = null;

    private ?string $last = null;

    public function __construct(Digest $digest)
    {
        $this->digest = $digest;
        $this->slowestText = $digest->text();
    }

    public function record(Digest $digest, ?float $millis, ?string $timestamp): void
    {
        $this->count++;

        if ($millis !== null) {
            $this->durations[] = $millis;

            if ($this->slowest === null || $millis > $this->slowest) {
                $this->slowest = $millis;
                $this->slowestText = $digest->text();
            }
        }

        if ($timestamp === null) {
            return;
        }

        // Compared as text. Every appender writes a fixed-width, most
        // significant field first timestamp, which sorts correctly as a string
        // — and a file whose records are in order, the usual case, only ever
        // extends the range at one end anyway.
        if ($this->first === null || strcmp($timestamp, $this->first) < 0) {
            $this->first = $timestamp;
        }
        if ($this->last === null || strcmp($timestamp, $this->last) > 0) {
            $this->last = $timestamp;
        }
    }

    public function hash(): string
    {
        return $this->digest->hash();
    }

    public function signature(): string
    {
        return $this->digest->signature();
    }

    public function count(): int
    {
        return $this->count;
    }

    /** How many of them carried a duration at all. */
    public function measured(): int
    {
        return count($this->durations);
    }

    /** Milliseconds over every record that carried a duration. */
    public function total(): float
    {
        return array_sum($this->durations);
    }

    public function mean(): ?float
    {
        if ($this->durations === []) {
            return null;
        }

        return $this->total() / count($this->durations);
    }

    public function max(): ?float
    {
        return $this->durations === [] ? null : max($this->durations);
    }

    /**
     * Nearest rank, the definition that needs no interpolation and no
     * apologies: the smallest value at or above which 95% of the records sit.
     * On the handful of records a slow log usually holds for one shape, an
     * interpolated percentile would be inventing precision.
     */
    public function p95(): ?float
    {
        if ($this->durations === []) {
            return null;
        }

        $sorted = $this->durations;
        sort($sorted);
        $rank = (int) ceil(0.95 * count($sorted));

        return $sorted[max(1, $rank) - 1];
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'hash' => $this->digest->hash(),
            'sig' => $this->digest->signature(),
            'idx' => $this->digest->index(),
            'count' => $this->count,
            'measured' => count($this->durations),
            'total_ms' => $this->durations === [] ? null : round($this->total(), 3),
            'mean_ms' => self::rounded($this->mean()),
            'p95_ms' => self::rounded($this->p95()),
            'max_ms' => self::rounded($this->max()),
            'first' => $this->first,
            'last' => $this->last,
            'slowest' => [
                'took_ms' => self::rounded($this->slowest),
                'text' => $this->slowestText,
            ],
        ];
    }

    private static function rounded(?float $value): ?float
    {
        return $value === null ? null : round($value, 3);
    }
}
