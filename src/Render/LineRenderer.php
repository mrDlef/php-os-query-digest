<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Render;

use MrDlef\OsQueryDigest\Tree\QueryModel;

/**
 * Assembles the one-line form:
 *
 *   logs-* | q=(service:api and @timestamp >= "now-15m") | aggs=terms(host,10)>p95(rt) | size=0 sort=@timestamp:desc
 *
 * Segments are pipe-separated so the `q=(…)` part can be selected and pasted
 * into Dashboards on its own.
 */
final class LineRenderer
{
    /** @var DqlRenderer */
    private $dql;

    /** @var AggRenderer */
    private $aggs;

    public function __construct()
    {
        $this->dql = new DqlRenderer();
        $this->aggs = new AggRenderer();
    }

    public function render(QueryModel $model, RenderProfile $profile): string
    {
        $segments = [];

        if ($model->index() !== '') {
            $segments[] = $model->index();
        }

        $query = $model->query();
        if ($query !== null) {
            $segments[] = 'q=(' . $this->dql->render($query, $profile) . ')';
        }

        // Its own segment rather than folded into `q=(…)`: post_filter runs
        // after the aggregations, so it narrows the hits while the buckets keep
        // counting the whole result set. Merging the two would describe a query
        // nobody sent.
        $postFilter = $model->postFilter();
        if ($postFilter !== null) {
            $segments[] = 'post=(' . $this->dql->render($postFilter, $profile) . ')';
        }

        if ($model->aggs() !== []) {
            $segments[] = 'aggs=' . $this->aggs->render($model->aggs(), $profile);
        }

        $options = $this->options($model, $profile);
        if ($options !== '') {
            $segments[] = $options;
        }

        if ($model->notes() !== []) {
            $segments[] = implode(' ', $model->notes());
        }

        return implode(' | ', $segments);
    }

    private function options(QueryModel $model, RenderProfile $profile): string
    {
        $parts = [];

        $size = $model->size();
        if ($profile->erasePagination()) {
            // size=0 means "aggregations only" — a different kind of query, so
            // the zero survives. Every other page size, including an absent one,
            // collapses to the same shape.
            $parts[] = $size === 0 ? 'size=0' : 'size=?';
        } elseif ($size !== null) {
            $parts[] = 'size=' . $size;
        }

        $from = $model->from();
        // Dropped outright rather than replaced by a placeholder: `from` absent
        // and `from: 0` are the same query, so they must render the same way.
        if (!$profile->erasePagination() && $from !== null && $from !== 0) {
            $parts[] = 'from=' . $from;
        }

        $sort = $model->sort();
        if ($sort !== []) {
            $rendered = [];
            foreach ($sort as $entry) {
                $rendered[] = $entry[0] . ':' . $entry[1];
            }
            $parts[] = 'sort=' . implode(',', $rendered);
        }

        return implode(' ', $parts);
    }
}
