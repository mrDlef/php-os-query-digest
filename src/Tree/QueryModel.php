<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

/**
 * The whole search request reduced to its intent-carrying parts.
 *
 * @internal
 */
final class QueryModel
{
    private string $index;

    private ?Node $query;

    /**
     * `post_filter` runs after the aggregations, so it narrows the hits without
     * narrowing the buckets. Merging it into the query would describe a
     * different request, hence its own slot.
     */
    private ?Node $postFilter;

    /** @var AggNode[] */
    private array $aggs;

    private ?int $size;

    private ?int $from;

    /**
     * Ordered list of [field, direction] pairs. Order is significant here, so
     * it is never sorted.
     *
     * @var array<int,array{0:string,1:string}>
     */
    private array $sort;

    /**
     * Everything acknowledged but not rendered inline: non-filtering `should`
     * groups, unsupported top-level sections. Sorted, so it stays stable.
     *
     * @var array<int,string>
     */
    private array $notes;

    /**
     * The three facts below are read for {@see \MrDlef\OsQueryDigest\Kind}
     * and are deliberately **not rendered**. They say what the request is *for*
     * rather than what it selects, and putting them in the line would move
     * every fingerprint that has one — for a classification that is available
     * beside the hash anyway.
     */
    private bool $suggests;

    /** A cursor: `search_after`, `pit`, `slice`, or a `scroll` beside `body`. */
    private bool $cursored;

    /** `_source: false` exactly — a filtered `_source` still returns documents. */
    private bool $sourceDisabled;

    /**
     * @param AggNode[]                           $aggs
     * @param array<int,array{0:string,1:string}> $sort
     * @param array<int,string>                   $notes
     */
    public function __construct(
        string $index,
        ?Node $query,
        ?Node $postFilter = null,
        array $aggs = [],
        ?int $size = null,
        ?int $from = null,
        array $sort = [],
        array $notes = [],
        bool $suggests = false,
        bool $cursored = false,
        bool $sourceDisabled = false
    ) {
        $this->index = $index;
        $this->query = $query;
        $this->postFilter = $postFilter;
        $this->aggs = array_values($aggs);
        $this->size = $size;
        $this->from = $from;
        $this->sort = array_values($sort);
        $this->notes = array_values($notes);
        $this->suggests = $suggests;
        $this->cursored = $cursored;
        $this->sourceDisabled = $sourceDisabled;
    }

    public function index(): string
    {
        return $this->index;
    }

    public function query(): ?Node
    {
        return $this->query;
    }

    public function postFilter(): ?Node
    {
        return $this->postFilter;
    }

    /**
     * @return AggNode[]
     */
    public function aggs(): array
    {
        return $this->aggs;
    }

    public function size(): ?int
    {
        return $this->size;
    }

    public function from(): ?int
    {
        return $this->from;
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    public function sort(): array
    {
        return $this->sort;
    }

    /**
     * @return array<int,string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /** A top-level `suggest` section. */
    public function suggests(): bool
    {
        return $this->suggests;
    }

    /** The request walks a cursor rather than asking for one page. */
    public function cursored(): bool
    {
        return $this->cursored;
    }

    /** `_source` was turned off outright. */
    public function sourceDisabled(): bool
    {
        return $this->sourceDisabled;
    }

    public function withIndex(string $index): self
    {
        return new self(
            $index,
            $this->query,
            $this->postFilter,
            $this->aggs,
            $this->size,
            $this->from,
            $this->sort,
            $this->notes,
            $this->suggests,
            $this->cursored,
            $this->sourceDisabled,
        );
    }

    /**
     * @param AggNode[] $aggs
     */
    public function withTree(?Node $query, ?Node $postFilter, array $aggs): self
    {
        return new self(
            $this->index,
            $query,
            $postFilter,
            $aggs,
            $this->size,
            $this->from,
            $this->sort,
            $this->notes,
            $this->suggests,
            $this->cursored,
            $this->sourceDisabled,
        );
    }
}
