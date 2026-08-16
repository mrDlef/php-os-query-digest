<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

use MrDlef\OsQueryDigest\Support\IndexNormalizer;

/**
 * Immutable configuration. PHP 7.4 has no named arguments, hence withers.
 */
final class Options
{
    private Normalization $normalization;

    private ?int $maxClauses = 12;

    private ?int $maxValues = 5;

    private ?int $maxLength = 512;

    private IndexNormalizer $indexNormalizer;

    /** @var callable|null */
    private $redactor;

    private bool $includeAggNames = false;

    private int $hashLength = 12;

    /**
     * Bumped whenever the normalisation rules change, so an algorithm change is
     * visible in the data instead of silently rewriting every dashboard.
     */
    private string $hashVersion = 'q1';

    private function __construct()
    {
        $this->normalization = Normalization::values();
        $this->indexNormalizer = IndexNormalizer::datePatterns();
    }

    public static function create(): self
    {
        return new self();
    }

    public function withNormalization(Normalization $normalization): self
    {
        $clone = clone $this;
        $clone->normalization = $normalization;

        return $clone;
    }

    /**
     * Maximum sibling clauses rendered per and/or level before the rest is
     * summarised as `+N more`. Null disables the limit.
     */
    public function withMaxClauses(?int $maxClauses): self
    {
        $clone = clone $this;
        $clone->maxClauses = $maxClauses;

        return $clone;
    }

    /**
     * Maximum values rendered inside a terms clause. Null disables the limit.
     */
    public function withMaxValues(?int $maxValues): self
    {
        $clone = clone $this;
        $clone->maxValues = $maxValues;

        return $clone;
    }

    /**
     * Hard character cap on the rendered lines. Never affects the hash.
     */
    public function withMaxLength(?int $maxLength): self
    {
        $clone = clone $this;
        $clone->maxLength = $maxLength;

        return $clone;
    }

    public function withIndexNormalizer(IndexNormalizer $indexNormalizer): self
    {
        $clone = clone $this;
        $clone->indexNormalizer = $indexNormalizer;

        return $clone;
    }

    /**
     * fn(string $field, mixed $value): mixed — applied to literal values before
     * rendering. Use it to keep PII out of your logs.
     */
    public function withRedactor(?callable $redactor): self
    {
        $clone = clone $this;
        $clone->redactor = $redactor;

        return $clone;
    }

    /**
     * Include user-given aggregation names (`by_host:terms(host)`). Off by
     * default: names are often generated, which would make hashes brittle.
     */
    public function withAggNames(bool $includeAggNames): self
    {
        $clone = clone $this;
        $clone->includeAggNames = $includeAggNames;

        return $clone;
    }

    /**
     * Hex characters kept from the sha256 digest. 12 (48 bits) keeps collisions
     * negligible at any realistic number of distinct query shapes.
     */
    public function withHashLength(int $hashLength): self
    {
        $clone = clone $this;
        $clone->hashLength = max(4, min(64, $hashLength));

        return $clone;
    }

    public function withHashVersion(string $hashVersion): self
    {
        $clone = clone $this;
        $clone->hashVersion = $hashVersion;

        return $clone;
    }

    public function normalization(): Normalization
    {
        return $this->normalization;
    }

    public function maxClauses(): ?int
    {
        return $this->maxClauses;
    }

    public function maxValues(): ?int
    {
        return $this->maxValues;
    }

    public function maxLength(): ?int
    {
        return $this->maxLength;
    }

    public function indexNormalizer(): IndexNormalizer
    {
        return $this->indexNormalizer;
    }

    public function redactor(): ?callable
    {
        return $this->redactor;
    }

    public function includeAggNames(): bool
    {
        return $this->includeAggNames;
    }

    public function hashLength(): int
    {
        return $this->hashLength;
    }

    public function hashVersion(): string
    {
        return $this->hashVersion;
    }
}
