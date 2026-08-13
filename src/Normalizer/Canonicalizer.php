<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Normalizer;

use MrDlef\OsQueryDigest\Tree\AggNode;
use MrDlef\OsQueryDigest\Tree\AndNode;
use MrDlef\OsQueryDigest\Tree\MatchAllNode;
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
 *  - order commutative siblings by their sort key
 */
final class Canonicalizer
{
    public function node(Node $node): Node
    {
        if ($node instanceof AndNode) {
            return $this->rebuild($node->children(), true, null);
        }

        if ($node instanceof OrNode) {
            return $this->rebuild($node->children(), false, $node->minimumShouldMatch());
        }

        if ($node instanceof NotNode) {
            return $node->withChild($this->node($node->child()));
        }

        if ($node instanceof NestedNode) {
            return $node->withChild($this->node($node->child()));
        }

        return $node;
    }

    /**
     * @param AggNode[] $aggs
     *
     * @return AggNode[]
     */
    public function aggs(array $aggs): array
    {
        $canonical = [];
        foreach ($aggs as $agg) {
            $canonical[] = $agg->withChildren($this->aggs($agg->children()));
        }

        usort($canonical, static function (AggNode $a, AggNode $b): int {
            return strcmp($a->sortKey(), $b->sortKey());
        });

        return $canonical;
    }

    /**
     * @param Node[] $original
     */
    private function rebuild(array $original, bool $isAnd, ?int $msm): Node
    {
        $children = [];

        foreach ($original as $child) {
            $child = $this->node($child);

            if ($isAnd && $child instanceof AndNode) {
                foreach ($child->children() as $grandChild) {
                    $children[] = $grandChild;
                }
                continue;
            }

            // Flattening an OR into an OR is only safe when neither side
            // carries an explicit minimum_should_match.
            if (!$isAnd && $msm === null && $child instanceof OrNode && $child->minimumShouldMatch() === null) {
                foreach ($child->children() as $grandChild) {
                    $children[] = $grandChild;
                }
                continue;
            }

            $children[] = $child;
        }

        if ($isAnd && count($children) > 1) {
            $withoutMatchAll = [];
            foreach ($children as $child) {
                if (!$child instanceof MatchAllNode) {
                    $withoutMatchAll[] = $child;
                }
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
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $child;
            }
            $children = $unique;
        }

        usort($children, static function (Node $a, Node $b): int {
            return strcmp($a->sortKey(), $b->sortKey());
        });

        if ($children === []) {
            return new MatchAllNode();
        }

        if (count($children) === 1 && $msm === null) {
            return $children[0];
        }

        return $isAnd ? new AndNode($children) : new OrNode($children, $msm);
    }
}
