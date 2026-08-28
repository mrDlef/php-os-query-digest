<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Analysis;

use MrDlef\OsQueryDigest\Digest;
use MrDlef\OsQueryDigest\Exception\InvalidOptionException;

/**
 * Searches grouped by fingerprint, and ranked by what they cost.
 *
 *   $report = new Report();
 *   foreach ($searches as $search) {
 *       $report->record($formatter->describe($search->body(), $search->index()), $search->took());
 *   }
 *
 *   foreach ($report->top(10) as $shape) {
 *       printf("%-16s %6d  %8.1f ms\n", $shape->hash(), $shape->count(), $shape->total());
 *   }
 *
 * This is what the `slowlog` command does with a cluster's slow log, and it is
 * public because an application logging its own digests has the same stream and
 * the same question. Group, mean, p95, top-N: nobody's second implementation of
 * those is more correct than this one, and several are less.
 *
 * It holds every distinct fingerprint it is given — one {@see Shape} each,
 * whose durations it keeps — so it is bounded by how many *shapes* a stream has,
 * not by how many searches. That is the point: a million searches over forty
 * shapes is forty objects.
 *
 * @api
 */
final class Report implements \JsonSerializable
{
    public const TOTAL = 'total';
    public const COUNT = 'count';
    public const MEAN = 'mean';
    public const P95 = 'p95';
    public const MAX = 'max';

    /**
     * Every ranking key. Public because a configuration front — a CLI flag, a
     * `<select>` — needs the list, and hard-coding it elsewhere is how it
     * drifts.
     *
     * @var array<int,string>
     */
    public const KEYS = [self::TOTAL, self::COUNT, self::P95, self::MAX, self::MEAN];

    /** @var array<string,Shape> keyed by fingerprint */
    private array $shapes = [];

    private int $records = 0;

    /**
     * @param float|null  $millis    what this search cost, when that is known.
     *                               A shape whose records carry no duration is
     *                               still counted; it simply has no mean.
     * @param string|null $timestamp when it happened, as text. Compared as
     *                               text, so any fixed-width, most significant
     *                               field first format sorts correctly —
     *                               `2026-08-28T14:01:02,003` and an ISO 8601
     *                               both do
     */
    public function record(Digest $digest, ?float $millis = null, ?string $timestamp = null): void
    {
        $hash = $digest->hash();
        $this->shapes[$hash] ??= new Shape($digest);
        $this->shapes[$hash]->record($digest, $millis, $timestamp);
        $this->records++;
    }

    /** How many searches went in. */
    public function records(): int
    {
        return $this->records;
    }

    /** How many distinct fingerprints came out. */
    public function count(): int
    {
        return count($this->shapes);
    }

    /** Milliseconds over every record that carried a duration. */
    public function total(): float
    {
        $total = 0.0;
        foreach ($this->shapes as $shape) {
            $total += $shape->total();
        }

        return $total;
    }

    public function shape(string $hash): ?Shape
    {
        return $this->shapes[$hash] ?? null;
    }

    /**
     * Every shape, worst first by the given key.
     *
     * Ties are broken by count and then by fingerprint, so two runs over one
     * stream rank identically — a report you cannot diff is a report you cannot
     * use to say what a deploy changed.
     *
     * @throws InvalidOptionException on a key that is not one of {@see self::KEYS}
     *
     * @return array<int,Shape>
     */
    public function rank(string $by = self::TOTAL): array
    {
        if (!in_array($by, self::KEYS, true)) {
            throw InvalidOptionException::notAllowed('sort', $by, self::KEYS);
        }

        $ranked = array_values($this->shapes);

        usort($ranked, static function (Shape $a, Shape $b) use ($by): int {
            $first = self::key($b, $by) <=> self::key($a, $by);
            if ($first !== 0) {
                return $first;
            }

            return [$b->count(), $a->hash()] <=> [$a->count(), $b->hash()];
        });

        return $ranked;
    }

    /**
     * The worst `$count` of them.
     *
     * @throws InvalidOptionException on an unknown key
     *
     * @return array<int,Shape>
     */
    public function top(int $count, string $by = self::TOTAL): array
    {
        return array_slice($this->rank($by), 0, max(0, $count));
    }

    /**
     * The ranked shapes, worst first by total time — the shape a report is read
     * in. {@see self::rank()} when another key is wanted.
     *
     * @return array<int,Shape>
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->rank();
    }

    private static function key(Shape $shape, string $by): float
    {
        switch ($by) {
            case self::COUNT:
                return (float) $shape->count();
            case self::MEAN:
                return $shape->mean() ?? 0.0;
            case self::P95:
                return $shape->p95() ?? 0.0;
            case self::MAX:
                return $shape->max() ?? 0.0;
            default:
                return $shape->total();
        }
    }
}
