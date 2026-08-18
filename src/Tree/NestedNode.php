<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

/**
 * A `nested` query. Kept as its own node because DQL has first-class syntax for
 * it (`path:{ inner }`) and because flattening it away would change semantics.
 *
 * @internal
 */
final class NestedNode implements Node
{
    private string $path;

    private Node $child;

    public function __construct(string $path, Node $child)
    {
        $this->path = $path;
        $this->child = $child;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function child(): Node
    {
        return $this->child;
    }

    public function withChild(Node $child): self
    {
        return new self($this->path, $child);
    }

    public function sortKey(): string
    {
        return 'nested:' . $this->path . '(' . $this->child->sortKey() . ')';
    }
}
