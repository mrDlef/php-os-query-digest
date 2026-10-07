<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Analysis;

/**
 * One tenant's share of a report: what it played, and what that cost.
 *
 * "Tenant" is this library's word for the second axis a multi-tenant
 * deployment reads a report along — whatever the records themselves call it,
 * customer, site, instance or service. Nothing here mints it: it is a value
 * read off each record, beside the fingerprint that was already there.
 *
 * The shape table answers *which query is expensive*. This answers *whose*,
 * and the two are not the same question: a shape totalling twenty seconds over
 * a hundred customers is the application's, and the same twenty seconds under
 * one customer is that customer's data.
 *
 * @api
 */
final class Tenant implements \JsonSerializable
{
    private string $name;

    private int $count = 0;

    /** @var array<string,true> the fingerprints seen under this name */
    private array $shapes = [];

    private float $total = 0.0;

    private int $measured = 0;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * One more search of this tenant's.
     *
     * @param float|null $millis what it cost, when that is known
     */
    public function record(string $hash, ?float $millis = null): void
    {
        $this->count++;
        $this->shapes[$hash] = true;

        if ($millis === null) {
            return;
        }

        $this->measured++;
        $this->total += $millis;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** How many searches this tenant made. */
    public function count(): int
    {
        return $this->count;
    }

    /** How many of them carried a duration at all. */
    public function measured(): int
    {
        return $this->measured;
    }

    /**
     * How many distinct fingerprints it played. The number that separates a
     * tenant running one expensive report from one running a hundred pages.
     */
    public function shapes(): int
    {
        return count($this->shapes);
    }

    /** Milliseconds over every record that carried a duration. */
    public function total(): float
    {
        return $this->total;
    }

    public function mean(): ?float
    {
        return $this->measured === 0 ? null : $this->total / $this->measured;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'tenant' => $this->name,
            'count' => $this->count,
            'measured' => $this->measured,
            'shapes' => count($this->shapes),
            'total_ms' => $this->measured === 0 ? null : round($this->total, 3),
            'mean_ms' => $this->measured === 0 ? null : round($this->total / $this->measured, 3),
        ];
    }
}
