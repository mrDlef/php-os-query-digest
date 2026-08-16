<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Render;

/**
 * How one particular rendering pass behaves. Three profiles are used per
 * digest: the readable line, the signature, and the (uncapped) hash input.
 */
final class RenderProfile
{
    private ValueRenderer $values;

    /** @var bool render `~`/`*`/`/…/` sigils that tell leaf types apart */
    private bool $distinguishTypes;

    /** @var int|null max sibling clauses per connector, null = unlimited */
    private ?int $maxClauses;

    /** @var int|null max values inside a terms clause, null = unlimited */
    private ?int $maxValues;

    /** @var bool collapse a terms list to a single placeholder */
    private bool $eraseCardinality;

    /** @var bool replace non-zero size and from with a placeholder */
    private bool $erasePagination;

    private bool $includeAggNames;

    public function __construct(
        ValueRenderer $values,
        bool $distinguishTypes = false,
        ?int $maxClauses = null,
        ?int $maxValues = null,
        bool $eraseCardinality = false,
        bool $erasePagination = false,
        bool $includeAggNames = false
    ) {
        $this->values = $values;
        $this->distinguishTypes = $distinguishTypes;
        $this->maxClauses = $maxClauses;
        $this->maxValues = $maxValues;
        $this->eraseCardinality = $eraseCardinality;
        $this->erasePagination = $erasePagination;
        $this->includeAggNames = $includeAggNames;
    }

    public function values(): ValueRenderer
    {
        return $this->values;
    }

    public function distinguishTypes(): bool
    {
        return $this->distinguishTypes;
    }

    public function maxClauses(): ?int
    {
        return $this->maxClauses;
    }

    public function maxValues(): ?int
    {
        return $this->maxValues;
    }

    public function eraseCardinality(): bool
    {
        return $this->eraseCardinality;
    }

    public function erasePagination(): bool
    {
        return $this->erasePagination;
    }

    public function includeAggNames(): bool
    {
        return $this->includeAggNames;
    }

    /**
     * The same profile without any truncation — used for the hash input, which
     * must never depend on display limits.
     */
    public function uncapped(): self
    {
        return new self(
            $this->values,
            $this->distinguishTypes,
            null,
            null,
            $this->eraseCardinality,
            $this->erasePagination,
            $this->includeAggNames,
        );
    }
}
