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
     * @param AggNode[] $aggs
     */
    public function render(array $aggs, RenderProfile $profile): string
    {
        $parts = [];
        foreach ($aggs as $agg) {
            $parts[] = $this->one($agg, $profile);
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
