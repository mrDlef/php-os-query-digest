<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Normalizer;

use MrDlef\OsQueryDigest\Explain\Rule;
use MrDlef\OsQueryDigest\Explain\Trace;
use MrDlef\OsQueryDigest\Tree\AggNode;
use MrDlef\OsQueryDigest\Tree\AndNode;
use MrDlef\OsQueryDigest\Tree\JoinNode;
use MrDlef\OsQueryDigest\Tree\MatchAllNode;
use MrDlef\OsQueryDigest\Tree\MatchNoneNode;
use MrDlef\OsQueryDigest\Tree\NestedNode;
use MrDlef\OsQueryDigest\Tree\Node;
use MrDlef\OsQueryDigest\Tree\NotNode;
use MrDlef\OsQueryDigest\Tree\OrNode;

/**
 * Rewrites the tree into a canonical shape so that two equivalent queries,
 * written differently, converge on the same rendering — and therefore the same
 * fingerprint.
 *
 * Every rule here must preserve the result set. Rules that only preserve
 * *intent* (merging must into filter, dropping boosts, collapsing `.keyword`)
 * are deliberately not applied: over-normalising makes genuinely different
 * queries collide, which destroys the diagnostic value of the hash.
 *
 * Applied rules:
 *  - flatten nested connectors of the same kind:   AND(a, AND(b, c)) → AND(a, b, c)
 *  - unwrap single-child connectors:               AND(a) → a
 *  - drop match_all inside a multi-child AND:      AND(a, *) → a
 *  - de-duplicate identical siblings:              AND(a, a) → a
 *  - absorb match_none:                            AND(a, none) → none, OR(a, none) → a
 *  - order commutative siblings by their sort key
 *
 * Each one reports itself into a {@see Trace} so `explain()` can say which
 * fired.
 */
final class Canonicalizer
{
    public function node(Node $node, ?Trace $trace = null): Node
    {
        return $this->walk($node, $trace !== null ? $trace : new Trace());
    }

    /**
     * @param AggNode[] $aggs
     *
     * @return AggNode[]
     */
    public function aggs(array $aggs, ?Trace $trace = null): array
    {
        return $this->walkAggs($aggs, $trace !== null ? $trace : new Trace());
    }

    private function walk(Node $node, Trace $trace): Node
    {
        if ($node instanceof AndNode) {
            return $this->rebuild($node->children(), true, null, $trace);
        }

        if ($node instanceof OrNode) {
            return $this->rebuild($node->children(), false, $node->minimumShouldMatch(), $trace);
        }

        if ($node instanceof NotNode) {
            return $node->withChild($this->walk($node->child(), $trace));
        }

        if ($node instanceof NestedNode) {
            return $node->withChild($this->walk($node->child(), $trace));
        }

        if ($node instanceof JoinNode) {
            return $node->withChild($this->walk($node->child(), $trace));
        }

        return $node;
    }

    /**
     * @param AggNode[] $aggs
     *
     * @return AggNode[]
     */
    private function walkAggs(array $aggs, Trace $trace): array
    {
        $canonical = [];
        foreach ($aggs as $agg) {
            $canonical[] = $agg->withChildren($this->walkAggs($agg->children(), $trace));
        }

        $before = self::keys($canonical);

        usort($canonical, static fn(AggNode $a, AggNode $b): int => strcmp($a->sortKey(), $b->sortKey()));

        if (self::keys($canonical) !== $before) {
            $trace->record(Rule::REORDER, 'aggs');
        }

        return $canonical;
    }

    /**
     * @param Node[] $original
     */
    private function rebuild(array $original, bool $isAnd, ?int $msm, Trace $trace): Node
    {
        $kind = $isAnd ? 'and' : 'or';
        $children = [];

        foreach ($original as $child) {
            $child = $this->walk($child, $trace);

            if ($isAnd && $child instanceof AndNode) {
                foreach ($child->children() as $grandChild) {
                    $children[] = $grandChild;
                }
                $trace->record(Rule::FLATTEN, $kind);
                continue;
            }

            // Flattening an OR into an OR is only safe when neither side
            // carries an explicit minimum_should_match.
            if (!$isAnd && $msm === null && $child instanceof OrNode && $child->minimumShouldMatch() === null) {
                foreach ($child->children() as $grandChild) {
                    $children[] = $grandChild;
                }
                $trace->record(Rule::FLATTEN, $kind);
                continue;
            }

            $children[] = $child;
        }

        // match_none absorbs an AND outright, and contributes nothing to an OR.
        // Both directions preserve the result set exactly.
        if ($isAnd) {
            foreach ($children as $child) {
                if ($child instanceof MatchNoneNode) {
                    $trace->record(Rule::ABSORB_MATCH_NONE, $kind);

                    return new MatchNoneNode();
                }
            }
        } elseif ($msm === null) {
            $withoutMatchNone = [];
            foreach ($children as $child) {
                if (!$child instanceof MatchNoneNode) {
                    $withoutMatchNone[] = $child;
                }
            }
            if (count($withoutMatchNone) !== count($children)) {
                $trace->record(Rule::ABSORB_MATCH_NONE, $kind);

                // An OR of nothing but match_none still matches nothing — it
                // must not fall through to the empty-connector rule below,
                // which would turn it into match_all.
                if ($withoutMatchNone === []) {
                    return new MatchNoneNode();
                }

                $children = $withoutMatchNone;
            }
        }

        if ($isAnd && count($children) > 1) {
            $withoutMatchAll = [];
            foreach ($children as $child) {
                if (!$child instanceof MatchAllNode) {
                    $withoutMatchAll[] = $child;
                }
            }
            if (count($withoutMatchAll) !== count($children)) {
                $trace->record(Rule::DROP_MATCH_ALL);
            }
            $children = $withoutMatchAll;
        }

        // De-duplication would change the meaning of a weighted should group.
        if ($msm === null) {
            $seen = [];
            $unique = [];
            foreach ($children as $child) {
                $key = $child->sortKey();
                if (isset($seen[$key])) {
                    $trace->record(Rule::DEDUPE, $kind);
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $child;
            }
            $children = $unique;
        }

        $before = self::keys($children);

        usort($children, static fn(Node $a, Node $b): int => strcmp($a->sortKey(), $b->sortKey()));

        if (self::keys($children) !== $before) {
            $trace->record(Rule::REORDER, $kind);
        }

        if ($children === []) {
            $trace->record(Rule::EMPTY_TO_MATCH_ALL, $kind);

            return new MatchAllNode();
        }

        if (count($children) === 1 && $msm === null) {
            // Only a rewrite when the connector had something to unwrap: a
            // one-clause bool was already reported by the parser.
            if (count($original) > 1) {
                $trace->record(Rule::UNWRAP, $kind);
            }

            return $children[0];
        }

        return $isAnd ? new AndNode($children) : new OrNode($children, $msm);
    }

    /**
     * @param array<int,Node|AggNode> $nodes
     *
     * @return array<int,string>
     */
    private static function keys(array $nodes): array
    {
        $keys = [];
        foreach ($nodes as $node) {
            $keys[] = $node->sortKey();
        }

        return $keys;
    }
}
