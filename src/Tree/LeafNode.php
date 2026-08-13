<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

final class LeafNode implements Node
{
    public const OP_TERM = 'term';
    public const OP_TERMS = 'terms';
    public const OP_MATCH = 'match';
    public const OP_PHRASE = 'phrase';
    public const OP_PREFIX = 'prefix';
    public const OP_WILDCARD = 'wildcard';
    public const OP_REGEXP = 'regexp';
    public const OP_EXISTS = 'exists';
    public const OP_RANGE = 'range';
    public const OP_RAW = 'raw';

    /** @var string */
    private $field;

    /** @var string */
    private $op;

    /**
     * For OP_RANGE: an ordered map of bound => value (gte/gt/lte/lt).
     * For every other op: a list of scalar values.
     *
     * @var array<string|int,mixed>
     */
    private $values;

    /**
     * @param array<string|int,mixed> $values
     */
    public function __construct(string $field, string $op, array $values = [])
    {
        $this->field = $field;
        $this->op = $op;
        $this->values = $values;
    }

    public function field(): string
    {
        return $this->field;
    }

    public function op(): string
    {
        return $this->op;
    }

    /**
     * @return array<string|int,mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * @param array<string|int,mixed> $values
     */
    public function withValues(array $values): self
    {
        return new self($this->field, $this->op, $values);
    }

    public function sortKey(): string
    {
        // Values are part of the key: two `term` clauses on the same field but
        // different values must keep a stable relative order.
        $flat = [];
        foreach ($this->values as $key => $value) {
            $flat[] = (is_string($key) ? $key . '=' : '') . self::scalarKey($value);
        }
        if ($this->op !== self::OP_RANGE) {
            sort($flat);
        }

        return $this->field . ':' . $this->op . ':' . implode(',', $flat);
    }

    /**
     * @param mixed $value
     */
    private static function scalarKey($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return md5((string) json_encode($value));
    }
}
