<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

/**
 * `has_child` / `has_parent` — a query that matches documents by what their
 * relatives match.
 *
 * Its own node for the same reason as {@see NestedNode}: the inner query runs
 * against other documents, so hoisting its clauses up would claim the parent
 * matches them itself.
 *
 * @internal
 */
final class JoinNode implements Node
{
    public const HAS_CHILD = 'has_child';
    public const HAS_PARENT = 'has_parent';

    /** @var string one of the constants above */
    private string $kind;

    /** @var string the joined relation: child `type` or `parent_type` */
    private string $relation;

    private Node $child;

    public function __construct(string $kind, string $relation, Node $child)
    {
        $this->kind = $kind;
        $this->relation = $relation;
        $this->child = $child;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function relation(): string
    {
        return $this->relation;
    }

    public function child(): Node
    {
        return $this->child;
    }

    public function withChild(Node $child): self
    {
        return new self($this->kind, $this->relation, $child);
    }

    public function sortKey(): string
    {
        return $this->kind . ':' . $this->relation . '(' . $this->child->sortKey() . ')';
    }
}
