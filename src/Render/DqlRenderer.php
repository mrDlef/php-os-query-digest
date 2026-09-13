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

        // Two names for one field, and the split matters: `$shown` is what the
        // line prints, `$field` is what the value renderer — and through it the
        // redactor — is keyed on. A redactor deciding whether a value may be
        // logged must see the fields the query named, never a shortened display
        // of them.
        $shown = self::shownField($field, $profile);

        switch ($leaf->op()) {
            case LeafNode::OP_EXISTS:
                return $shown . ':*';

            case LeafNode::OP_TERM:
                return $shown . ':' . $renderer->scalar($field, reset($values));

            case LeafNode::OP_BOOL_PREFIX:
            case LeafNode::OP_MATCH:
                // A completion op renders as the op it refines, deliberately —
                // see LeafNode. Its own name lives in the model, not the line.
                return $shown . ':' . ($sigils ? '~' : '') . $renderer->scalar($field, reset($values));

            case LeafNode::OP_PHRASE_PREFIX:
            case LeafNode::OP_PHRASE:
                return $shown . ':' . $renderer->phrase($field, reset($values));

            case LeafNode::OP_PREFIX:
                return $shown . ':' . $renderer->scalar($field, reset($values)) . '*';

            case LeafNode::OP_WILDCARD:
                return $sigils
                    ? $shown . ':*' . $renderer->scalar($field, reset($values)) . '*'
                    : $shown . ':' . $renderer->scalar($field, reset($values));

            case LeafNode::OP_REGEXP:
                return $shown . ':/' . $renderer->scalar($field, reset($values)) . '/';

            case LeafNode::OP_RAW:
                $raw = $renderer->raw($field, Arr::str(reset($values)));
                // With sigils on, the payload has been erased to `?` and needs
                // a marker to stay distinguishable from a plain term.
                $raw = $sigils ? 'raw(' . $raw . ')' : '(' . $raw . ')';

                return $shown === '' ? $raw : $shown . ':' . $raw;

            case LeafNode::OP_TERMS:
                return $shown . ':(' . $this->termsValues($field, $values, $profile) . ')';

            case LeafNode::OP_LIKE:
                return $shown . ':like(' . $this->termsValues($field, $values, $profile) . ')';

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
                return $shown . ':' . $leaf->op() . '(' . $this->params($field, $values, $profile) . ')';

            case LeafNode::OP_GEO_DISTANCE:
                return $shown . ':geo_distance(' . $renderer->scalar($field, reset($values)) . ')';

            case LeafNode::OP_GEO_BBOX:
            case LeafNode::OP_GEO_POLYGON:
            case LeafNode::OP_INTERVALS:
                // Nothing inside is worth a log line: the field and the kind of
                // clause are the whole shape.
                return $shown . ':' . $leaf->op() . '()';

            case LeafNode::OP_PERCOLATE:
                // Rendered without the value renderer, like the shape queries:
                // the only value it can hold is the closed marker `indexed`,
                // which says where the document came from, not what it was.
                return $shown . ':percolate(' . implode(',', Arr::strings($values)) . ')';

            case LeafNode::OP_GEO_SHAPE:
            case LeafNode::OP_XY_SHAPE:
                // Rendered without the value renderer, so the geometry kind and
                // the relation survive into the signature: they decide which
                // documents match, and `within` versus `disjoint` is not a
                // parameter but the opposite query.
                return $shown . ':' . $leaf->op() . '(' . implode(',', Arr::strings($values)) . ')';

            case LeafNode::OP_EXTENSION:
                // The label leads the values, so it survives the signature the
                // way an op name would; the parameters after it are erased like
                // every other clause's.
                $label = Arr::str(reset($values));
                $rendered = $label . '(' . $this->params($field, array_slice($values, 1, null, true), $profile) . ')';

                return $shown === '' ? $rendered : $shown . ':' . $rendered;

            case LeafNode::OP_SCRIPT:
                // The source is a value: it holds thresholds and parameters, so
                // leaving it in would mint a fingerprint per threshold.
                return 'script(' . $renderer->raw($field, Arr::str(reset($values))) . ')';
        }

        return $shown . ':?';
    }

    /**
     * The field list as the line shows it: `title^10|content^5|tags|+3 more`.
     *
     * A `multi_match`, a `query_string` and a `more_like_this` join their
     * fields into the node's single field at parse time, so the cap is applied
     * on the way out rather than on the tree — the hash renders the same node
     * through {@see RenderProfile::uncapped()} and still sees every field.
     *
     * A field name holding a `|` would be split by this, which is why nothing
     * but the display is allowed to depend on it.
     */
    private static function shownField(string $field, RenderProfile $profile): string
    {
        $max = $profile->maxFields();

        if ($max === null || strpos($field, '|') === false) {
            return $field;
        }

        $fields = explode('|', $field);
        if (count($fields) <= $max) {
            return $field;
        }

        $kept = array_slice($fields, 0, max(0, $max));
        $kept[] = '+' . (count($fields) - count($kept)) . ' more';

        return implode('|', $kept);
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
