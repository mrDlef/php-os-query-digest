<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Render;

use MrDlef\OsQueryDigest\Tree\AggNode;

/**
 * `terms(host,10)>p95(latency_ms)` — a compact pipeline notation where `>`
 * reads as "then, per bucket".
 *
 * @internal
 */
final class AggRenderer
{
    /**
     * One list of sibling aggregations: the request's own, and every `>{…}`
     * below it, which is why the cap is applied here rather than on the way in.
     * A facet page nests a list per level, and capping only the outer one would
     * let the line back out through the children.
     *
     * @param AggNode[] $aggs
     */
    public function render(array $aggs, RenderProfile $profile): string
    {
        $max = $profile->maxAggs();
        $dropped = 0;

        if ($max !== null && count($aggs) > $max) {
            $kept = array_slice($aggs, 0, max(0, $max));
            $dropped = count($aggs) - count($kept);
            $aggs = $kept;
        }

        $parts = [];
        foreach ($aggs as $agg) {
            $parts[] = $this->one($agg, $profile);
        }

        if ($dropped > 0) {
            $parts[] = '+' . $dropped . ' more';
        }

        return implode(', ', $parts);
    }

    private function one(AggNode $agg, RenderProfile $profile): string
    {
        $arguments = [];
        if ($agg->field() !== null) {
            $arguments[] = $agg->field();
        }
        foreach ($agg->params() as $param) {
            $arguments[] = $param;
        }

        $rendered = ($profile->includeAggNames() ? $agg->name() . ':' : '')
            . $agg->type() . '(' . implode(',', $arguments) . ')';

        $children = $agg->children();
        if ($children === []) {
            return $rendered;
        }

        if (count($children) === 1) {
            return $rendered . '>' . $this->one($children[0], $profile);
        }

        return $rendered . '>{' . $this->render($children, $profile) . '}';
    }
}
