<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Render;

use MrDlef\OsQueryDigest\Support\Arr;
use MrDlef\OsQueryDigest\Tree\AndNode;
use MrDlef\OsQueryDigest\Tree\JoinNode;
use MrDlef\OsQueryDigest\Tree\LeafNode;
use MrDlef\OsQueryDigest\Tree\MatchAllNode;
use MrDlef\OsQueryDigest\Tree\MatchNoneNode;
use MrDlef\OsQueryDigest\Tree\NestedNode;
use MrDlef\OsQueryDigest\Tree\Node;
use MrDlef\OsQueryDigest\Tree\NotNode;
use MrDlef\OsQueryDigest\Tree\OpaqueNode;
use MrDlef\OsQueryDigest\Tree\OrNode;

/**
 * Renders the query tree in OpenSearch Dashboards Query Language (DQL).
 *
 * With literal values and no type sigils the output is meant to be pasted
 * straight into the Dashboards search bar. The signature pass turns the sigils
 * on: it can afford to be more precise than DQL because, with its values
 * erased, it was never executable in the first place.
 *
 * @internal
 */
final class DqlRenderer
{
    /** How each range bound reads once rendered. */
    private const RANGE_SYMBOLS = ['gte' => '>=', 'gt' => '>', 'lte' => '<=', 'lt' => '<'];

    private const PREC_OR = 1;
    private const PREC_AND = 2;
    private const PREC_NOT = 3;
    private const PREC_ATOM = 4;

    public function render(Node $node, RenderProfile $profile): string
    {
        return $this->node($node, $profile, 0);
    }

    private function node(Node $node, RenderProfile $profile, int $parentPrecedence): string
    {
        if ($node instanceof AndNode) {
            return $this->wrap(
                $this->connector($node->children(), ' and ', $profile, self::PREC_AND),
                self::PREC_AND,
                $parentPrecedence,
            );
        }

        if ($node instanceof OrNode) {
            $rendered = $this->connector($node->children(), ' or ', $profile, self::PREC_OR);
            $msm = $node->minimumShouldMatch();
            if ($msm !== null) {
                return '(' . $rendered . '){msm=' . $msm . '}';
            }

            return $this->wrap($rendered, self::PREC_OR, $parentPrecedence);
        }

        if ($node instanceof NotNode) {
            return $this->wrap(
                'not ' . $this->node($node->child(), $profile, self::PREC_NOT),
                self::PREC_NOT,
                $parentPrecedence,
            );
        }

        if ($node instanceof NestedNode) {
            return $node->path() . ':{ ' . $this->node($node->child(), $profile, 0) . ' }';
        }

        if ($node instanceof JoinNode) {
            // Same shape as a nested clause, because it reads the same way: the
            // inner expression is evaluated against other documents.
            return $node->kind() . '(' . $node->relation() . '):{ '
                . $this->node($node->child(), $profile, 0) . ' }';
        }

        if ($node instanceof MatchAllNode) {
            return '*';
        }

        if ($node instanceof MatchNoneNode) {
            return 'none';
        }

        if ($node instanceof OpaqueNode) {
            return $node->type() . '(?)';
        }

        if ($node instanceof LeafNode) {
            return $this->leaf($node, $profile, $parentPrecedence);
        }

        return '?';
    }

    /**
     * @param Node[] $children
     */
    private function connector(array $children, string $glue, RenderProfile $profile, int $precedence): string
    {
        $max = $profile->maxClauses();
        $dropped = 0;

        if ($max !== null && count($children) > $max) {
            $dropped = count($children) - $max;
            $children = array_slice($children, 0, $max);
        }

        $parts = [];
        foreach ($children as $child) {
            $parts[] = $this->node($child, $profile, $precedence);
        }

        if ($dropped > 0) {
            $parts[] = '+' . $dropped . ' more';
        }

        return implode($glue, $parts);
    }

    private function leaf(LeafNode $leaf, RenderProfile $profile, int $parentPrecedence): string
    {
        $values = $leaf->values();
        $field = $leaf->field();
        $renderer = $profile->values();
        $sigils = $profile->distinguishTypes();

        switch ($leaf->op()) {
            case LeafNode::OP_EXISTS:
                return $field . ':*';

            case LeafNode::OP_TERM:
                return $field . ':' . $renderer->scalar($field, reset($values));

            case LeafNode::OP_MATCH:
                return $field . ':' . ($sigils ? '~' : '') . $renderer->scalar($field, reset($values));

            case LeafNode::OP_PHRASE:
                return $field . ':' . $renderer->phrase($field, reset($values));

            case LeafNode::OP_PREFIX:
                return $field . ':' . $renderer->scalar($field, reset($values)) . '*';

            case LeafNode::OP_WILDCARD:
                return $sigils
                    ? $field . ':*' . $renderer->scalar($field, reset($values)) . '*'
                    : $field . ':' . $renderer->scalar($field, reset($values));

            case LeafNode::OP_REGEXP:
                return $field . ':/' . $renderer->scalar($field, reset($values)) . '/';

            case LeafNode::OP_RAW:
                $raw = $renderer->raw($field, Arr::str(reset($values)));
                // With sigils on, the payload has been erased to `?` and needs
                // a marker to stay distinguishable from a plain term.
                $raw = $sigils ? 'raw(' . $raw . ')' : '(' . $raw . ')';

                return $field === '' ? $raw : $field . ':' . $raw;

            case LeafNode::OP_TERMS:
                return $field . ':(' . $this->termsValues($field, $values, $profile) . ')';

            case LeafNode::OP_LIKE:
                return $field . ':like(' . $this->termsValues($field, $values, $profile) . ')';

            case LeafNode::OP_PARENT_ID:
                // Reads like the join clauses — `parent_id(blog):7` next to
                // `has_child(review):{ … }` — because it walks the same relation.
                return 'parent_id(' . $field . '):' . $renderer->scalar($field, reset($values));

            case LeafNode::OP_RANGE:
                return $this->range($leaf, $profile, $parentPrecedence);

            case LeafNode::OP_KNN:
            case LeafNode::OP_NEURAL:
            case LeafNode::OP_RANK_FEATURE:
            case LeafNode::OP_DISTANCE_FEATURE:
                return $field . ':' . $leaf->op() . '(' . $this->params($field, $values, $profile) . ')';

            case LeafNode::OP_GEO_DISTANCE:
                return $field . ':geo_distance(' . $renderer->scalar($field, reset($values)) . ')';

            case LeafNode::OP_GEO_BBOX:
            case LeafNode::OP_GEO_POLYGON:
            case LeafNode::OP_INTERVALS:
                // Nothing inside is worth a log line: the field and the kind of
                // clause are the whole shape.
                return $field . ':' . $leaf->op() . '()';

            case LeafNode::OP_PERCOLATE:
                // Rendered without the value renderer, like the shape queries:
                // the only value it can hold is the closed marker `indexed`,
                // which says where the document came from, not what it was.
                return $field . ':percolate(' . implode(',', Arr::strings($values)) . ')';

            case LeafNode::OP_GEO_SHAPE:
            case LeafNode::OP_XY_SHAPE:
                // Rendered without the value renderer, so the geometry kind and
                // the relation survive into the signature: they decide which
                // documents match, and `within` versus `disjoint` is not a
                // parameter but the opposite query.
                return $field . ':' . $leaf->op() . '(' . implode(',', Arr::strings($values)) . ')';

            case LeafNode::OP_SCRIPT:
                // The source is a value: it holds thresholds and parameters, so
                // leaving it in would mint a fingerprint per threshold.
                return 'script(' . $renderer->raw($field, Arr::str(reset($values))) . ')';
        }

        return $field . ':?';
    }

    /**
     * @param array<string|int,mixed> $values
     */
    private function termsValues(string $field, array $values, RenderProfile $profile): string
    {
        $renderer = $profile->values();

        if ($profile->eraseCardinality()) {
            return $renderer->scalar($field, null);
        }

        $max = $profile->maxValues();
        $dropped = 0;
        if ($max !== null && count($values) > $max) {
            $dropped = count($values) - $max;
            $values = array_slice($values, 0, $max);
        }

        $parts = [];
        foreach ($values as $value) {
            $parts[] = $renderer->scalar($field, $value);
        }

        if ($dropped > 0) {
            $parts[] = '+' . $dropped;
        }

        return implode(' or ', $parts);
    }

    /**
     * `k=10, min_score=0.9` — the keyed parameters of a vector search. They are
     * values, so they are erased in the signature: what stays is which knobs
     * the query turned.
     *
     * @param array<string|int,mixed> $values
     */
    private function params(string $field, array $values, RenderProfile $profile): string
    {
        $renderer = $profile->values();
        $parts = [];

        foreach ($values as $name => $value) {
            $rendered = $renderer->scalar($field, $value);
            $parts[] = is_string($name) ? $name . '=' . $rendered : $rendered;
        }

        return implode(',', $parts);
    }

    private function range(LeafNode $leaf, RenderProfile $profile, int $parentPrecedence): string
    {
        $field = $leaf->field();
        $renderer = $profile->values();
        $parts = [];

        foreach ($leaf->values() as $bound => $value) {
            $symbol = self::RANGE_SYMBOLS[$bound] ?? '=';
            $parts[] = $field . ' ' . $symbol . ' ' . $renderer->scalar($field, $value);
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return $this->wrap(implode(' and ', $parts), self::PREC_AND, $parentPrecedence);
    }

    private function wrap(string $rendered, int $precedence, int $parentPrecedence): string
    {
        return $precedence < $parentPrecedence && $precedence < self::PREC_ATOM
            ? '(' . $rendered . ')'
            : $rendered;
    }
}
