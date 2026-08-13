<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

final class AggNode
{
    /** @var string user-given name, e.g. "by_host" */
    private $name;

    /** @var string agg type, e.g. "terms", "date_histogram", "avg" */
    private $type;

    /** @var string|null the field it runs on, when it has one */
    private $field;

    /**
     * Rendered-as-is extra parameters (size, interval, percents…), already
     * reduced to display strings by the parser.
     *
     * @var array<int,string>
     */
    private $params;

    /** @var AggNode[] */
    private $children;

    /**
     * @param array<int,string> $params
     * @param AggNode[]         $children
     */
    public function __construct(
        string $name,
        string $type,
        ?string $field = null,
        array $params = [],
        array $children = []
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->field = $field;
        $this->params = $params;
        $this->children = array_values($children);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    /**
     * @return array<int,string>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * @return AggNode[]
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * @param AggNode[] $children
     */
    public function withChildren(array $children): self
    {
        return new self($this->name, $this->type, $this->field, $this->params, $children);
    }

    public function sortKey(): string
    {
        $keys = [];
        foreach ($this->children as $child) {
            $keys[] = $child->sortKey();
        }

        return $this->type . '(' . (string) $this->field . ';' . implode(',', $this->params) . ')'
            . ($keys === [] ? '' : '>[' . implode(',', $keys) . ']');
    }
}
