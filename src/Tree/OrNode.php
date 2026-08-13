<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

final class OrNode implements Node
{
    /** @var Node[] */
    private $children;

    /**
     * minimum_should_match, when it was set explicitly and is greater than 1.
     * A plain OR (msm = 1) carries no annotation.
     *
     * @var int|null
     */
    private $minimumShouldMatch;

    /**
     * @param Node[] $children
     */
    public function __construct(array $children, ?int $minimumShouldMatch = null)
    {
        $this->children = array_values($children);
        $this->minimumShouldMatch = $minimumShouldMatch;
    }

    /**
     * @return Node[]
     */
    public function children(): array
    {
        return $this->children;
    }

    public function minimumShouldMatch(): ?int
    {
        return $this->minimumShouldMatch;
    }

    /**
     * @param Node[] $children
     */
    public function withChildren(array $children): self
    {
        return new self($children, $this->minimumShouldMatch);
    }

    public function sortKey(): string
    {
        $keys = [];
        foreach ($this->children as $child) {
            $keys[] = $child->sortKey();
        }

        return 'or' . ($this->minimumShouldMatch !== null ? '~' . $this->minimumShouldMatch : '')
            . '(' . implode('|', $keys) . ')';
    }
}
