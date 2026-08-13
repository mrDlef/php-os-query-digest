<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

final class NotNode implements Node
{
    /** @var Node */
    private $child;

    public function __construct(Node $child)
    {
        $this->child = $child;
    }

    public function child(): Node
    {
        return $this->child;
    }

    public function withChild(Node $child): self
    {
        return new self($child);
    }

    public function sortKey(): string
    {
        return 'not(' . $this->child->sortKey() . ')';
    }
}
