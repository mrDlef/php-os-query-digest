<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

/**
 * @internal
 */
final class AndNode implements Node
{
    /** @var Node[] */
    private array $children;

    /**
     * @param Node[] $children
     */
    public function __construct(array $children)
    {
        $this->children = array_values($children);
    }

    /**
     * @return Node[]
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * @param Node[] $children
     */
    public function withChildren(array $children): self
    {
        return new self($children);
    }

    public function sortKey(): string
    {
        $keys = [];
        foreach ($this->children as $child) {
            $keys[] = $child->sortKey();
        }

        return 'and(' . implode('&', $keys) . ')';
    }
}
