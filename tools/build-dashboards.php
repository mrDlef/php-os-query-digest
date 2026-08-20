<?php

declare(strict_types=1);

/**
 * Builds the importable dashboard pack from the Use cases pages.
 *
 *     php tools/build-dashboards.php            write resources/dashboards/*.ndjson
 *     php tools/build-dashboards.php --check    fail if what is committed is stale
 *
 * **The pages stay the source.** The two panels worth having ask questions no
 * classic visualisation can express — they need `bucket_script`,
 * `bucket_selector` and `bucket_sort` — so they are Vega, and their spec carries
 * the aggregation from the page, extracted by its `<!-- verified: … -->` marker
 * rather than copied. `UseCaseTest` runs those same aggregations against a live
 * cluster, so a panel cannot quietly drift from a query that is proven to work.
 *
 * What the pages hard-code, the pack cannot: a page pins 14:00 because it
 * describes one afternoon, while a panel has to follow the time picker. The two
 * fixed windows become the `%timefilter%` macro and the same window shifted back
 * an hour — the substitution is here, in one place, and the offline test asserts
 * that nothing else about the aggregation changed.
 *
 * **Two files, one pack.** OpenSearch Dashboards 2.x bundles vega-lite 4 and 3.x
 * bundles vega-lite 6, and the plugin refuses a spec whose `$schema` names the
 * other one. So each variant is written out whole, and the test that keeps them
 * honest asserts they differ in nothing but that URL.
 */

// No autoloader: this reads markdown and writes JSON, and the Docker matrix
// gives each PHP version its own vendor directory — one this tool would have to
// be told about to require the right one.

const PAGES = __DIR__ . '/../docs/use-cases';
const OUT = __DIR__ . '/../resources/dashboards';
const TEMPLATE = __DIR__ . '/../resources/dashboards/index-template.json';

/** The index pattern the pack ships with, and the one thing a reader renames. */
const PATTERN = 'app-logs-*';

const INDEX_PATTERN_ID = 'os-query-digest-logs';
const DASHBOARD_ID = 'os-query-digest-query-shapes';

/** Which vega-lite each Dashboards major bundles. Checked, not assumed. */
const VARIANTS = [
    'opensearch-2.x' => 'https://vega.github.io/schema/vega-lite/v4.json',
    'opensearch-3.x' => 'https://vega.github.io/schema/vega-lite/v6.json',
];

$arguments = [];
foreach ((array) ($_SERVER['argv'] ?? []) as $argument) {
    if (is_string($argument)) {
        $arguments[] = $argument;
    }
}

exit(main($arguments));

/**
 * @param array<int,string> $argv
 */
function main(array $argv): int
{
    $check = in_array('--check', $argv, true);
    $stale = [];

    foreach (VARIANTS as $variant => $schema) {
        $path = OUT . '/os-query-digest-' . $variant . '.ndjson';
        $built = savedObjects($schema);

        if (!$check) {
            file_put_contents($path, $built);
            printf("  %-40s %s\n", basename($path), size($built));
            continue;
        }

        $committed = is_file($path) ? (string) file_get_contents($path) : '';
        if ($committed !== $built) {
            $stale[] = basename($path);
        }
    }

    if ($check && $stale !== []) {
        fwrite(STDERR, "The dashboard pack is out of date:\n");
        foreach ($stale as $file) {
            fwrite(STDERR, '  - ' . $file . " differs\n");
        }
        fwrite(STDERR, "Run: make dashboards\n");

        return 1;
    }

    return 0;
}

/** Every saved object of one variant, newline-delimited. */
function savedObjects(string $schema): string
{
    $objects = [
        indexPattern(),
        topShapes(),
        percentileOverTime(),
        vegaPanel(
            'os-query-digest-what-regressed',
            'What regressed',
            'Each shape against its own past: p95 over the selected window, divided by p95 over '
                . 'the same window an hour earlier. Shapes with no history are dropped rather than '
                . 'ranked, which is what keeps bucket_sort from throwing.',
            $schema,
            timeMacros(aggregation('what-regressed')),
            'slowdown.value',
            'x',
        ),
        vegaPanel(
            'os-query-digest-new-shapes',
            'Shapes the release added',
            'Shapes present in the selected window and absent from the same window an hour '
                . 'earlier. One bar per shape, by how many times it ran.',
            $schema,
            timeMacros(aggregation('new-shapes')),
            'doc_count',
            'runs',
        ),
        dashboard(),
    ];

    $lines = '';
    foreach ($objects as $object) {
        $lines .= json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    return $lines;
}

/**
 * @return array<string,mixed>
 */
function indexPattern(): array
{
    return [
        'id' => INDEX_PATTERN_ID,
        'type' => 'index-pattern',
        'references' => [],
        'attributes' => [
            'title' => PATTERN,
            'timeFieldName' => '@timestamp',
            // Left empty on purpose: Dashboards fills the field list from the
            // mapping on first use, and a stale copy of it here would describe
            // an index the reader does not have.
            'fields' => '[]',
        ],
    ];
}

/**
 * Where the cluster's time actually goes. Ranked by the sum of `took`, because
 * the shape that costs most is rarely the one that is slowest — the whole point
 * of the page this comes from.
 *
 * @return array<string,mixed>
 */
function topShapes(): array
{
    return visualization('os-query-digest-top-shapes', 'Where the time goes', [
        'title' => 'Where the time goes',
        'type' => 'table',
        'aggs' => [
            metric('1', 'count', []),
            metric('2', 'sum', ['field' => 'took'], 'Total ms'),
            metric('3', 'median', ['field' => 'took', 'percents' => [50]], 'Median ms'),
            bucket('4', 'terms', [
                'field' => 'os.hash',
                'orderBy' => '2',
                'order' => 'desc',
                'size' => 10,
                'customLabel' => 'Shape',
            ]),
            bucket('5', 'terms', [
                'field' => 'os.sig',
                'orderBy' => '1',
                'order' => 'desc',
                'size' => 1,
                'customLabel' => 'Signature',
            ]),
        ],
        'params' => [
            'perPage' => 10,
            'percentageCol' => '',
            'showMetricsAtAllLevels' => false,
            'showPartialRows' => false,
            'showTotal' => false,
            'totalFunc' => 'sum',
        ],
    ]);
}

/**
 * @return array<string,mixed>
 */
function percentileOverTime(): array
{
    return visualization('os-query-digest-p95-over-time', 'p95 by shape over time', [
        'title' => 'p95 by shape over time',
        'type' => 'line',
        'aggs' => [
            metric('1', 'percentiles', ['field' => 'took', 'percents' => [95]], 'p95 ms'),
            [
                'id' => '2',
                'enabled' => true,
                'type' => 'date_histogram',
                'schema' => 'segment',
                'params' => [
                    'field' => '@timestamp',
                    'interval' => 'auto',
                    'min_doc_count' => 1,
                    'drop_partials' => false,
                ],
            ],
            [
                'id' => '3',
                'enabled' => true,
                'type' => 'terms',
                'schema' => 'group',
                'params' => [
                    'field' => 'os.hash',
                    'orderBy' => '1.95',
                    'order' => 'desc',
                    'size' => 5,
                    'customLabel' => 'Shape',
                ],
            ],
        ],
        'params' => [
            'type' => 'line',
            'grid' => ['categoryLines' => false],
            'categoryAxes' => [[
                'id' => 'CategoryAxis-1',
                'type' => 'category',
                'position' => 'bottom',
                'show' => true,
                'scale' => ['type' => 'linear'],
                'labels' => ['show' => true, 'truncate' => 100],
                'title' => new stdClass(),
            ]],
            'valueAxes' => [[
                'id' => 'ValueAxis-1',
                'name' => 'LeftAxis-1',
                'type' => 'value',
                'position' => 'left',
                'show' => true,
                'scale' => ['type' => 'linear', 'mode' => 'normal'],
                'labels' => ['show' => true, 'rotate' => 0, 'filter' => false, 'truncate' => 100],
                'title' => ['text' => 'p95 ms'],
            ]],
            'seriesParams' => [[
                'show' => true,
                'type' => 'line',
                'mode' => 'normal',
                'data' => ['label' => 'p95 ms', 'id' => '1'],
                'valueAxis' => 'ValueAxis-1',
                'drawLinesBetweenPoints' => true,
                'lineWidth' => 2,
                'interpolate' => 'linear',
                'showCircles' => true,
            ]],
            'addTooltip' => true,
            'addLegend' => true,
            'legendPosition' => 'right',
            'times' => [],
            'addTimeMarker' => false,
            'labels' => [],
            'thresholdLine' => ['show' => false, 'value' => 10, 'width' => 1, 'style' => 'full'],
        ],
    ]);
}

/**
 * A Vega panel over one of the page aggregations.
 *
 * The spec stays deliberately plain — a bar chart, one encoding per axis — so it
 * reads the same under vega-lite 4 and 6. Everything interesting is in the
 * aggregation, which is the page's.
 *
 * @param array<mixed> $body
 *
 * @return array<string,mixed>
 */
function vegaPanel(
    string $id,
    string $title,
    string $description,
    string $schema,
    array $body,
    string $valueField,
    string $valueTitle
): array {
    $spec = [
        '$schema' => $schema,
        'title' => $title,
        'data' => [
            'url' => [
                '%context%' => true,
                '%timefield%' => '@timestamp',
                'index' => PATTERN,
                'body' => $body,
            ],
            'format' => ['property' => 'aggregations.shapes.buckets'],
        ],
        'mark' => ['type' => 'bar', 'tooltip' => true],
        'encoding' => [
            'y' => [
                'field' => 'key',
                'type' => 'nominal',
                'title' => 'shape',
                'sort' => ['field' => $valueField, 'order' => 'descending'],
            ],
            'x' => [
                'field' => $valueField,
                'type' => 'quantitative',
                'title' => $valueTitle,
            ],
            'tooltip' => [
                ['field' => 'key', 'type' => 'nominal', 'title' => 'hash'],
                ['field' => 'shape.buckets[0].key', 'type' => 'nominal', 'title' => 'signature'],
                ['field' => $valueField, 'type' => 'quantitative', 'title' => $valueTitle],
                ['field' => 'doc_count', 'type' => 'quantitative', 'title' => 'records'],
            ],
        ],
    ];

    return visualization($id, $title, [
        'title' => $title,
        'type' => 'vega',
        'aggs' => [],
        'params' => ['spec' => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
    ], $description);
}

/**
 * The page's two fixed windows, as the time picker's own.
 *
 * A page pins `2026-08-19T14:00:00Z` because it describes one afternoon. A panel
 * cannot: `after` becomes the selected range and `before` the same range shifted
 * back an hour, which is the incident question — *what got worse in the last
 * hour* — and the `shift` is the one number a reader may want to change.
 *
 * @param array<mixed> $body
 *
 * @return array<mixed>
 */
function timeMacros(array $body): array
{
    $aggs = $body['aggs'] ?? null;
    $shapes = is_array($aggs) ? ($aggs['shapes'] ?? null) : null;
    $inner = is_array($shapes) ? ($shapes['aggs'] ?? null) : null;
    $before = is_array($inner) ? ($inner['before'] ?? null) : null;
    $after = is_array($inner) ? ($inner['after'] ?? null) : null;

    if (!is_array($aggs) || !is_array($shapes) || !is_array($inner) || !is_array($before) || !is_array($after)) {
        fwrite(STDERR, "The page aggregation no longer has before/after filters.\n");
        exit(1);
    }

    $before['filter'] = ['range' => ['@timestamp' => [
        '%timefilter%' => true,
        'shift' => 1,
        'unit' => 'hour',
    ]]];
    $after['filter'] = ['range' => ['@timestamp' => ['%timefilter%' => true]]];

    $inner['before'] = $before;
    $inner['after'] = $after;
    $shapes['aggs'] = $inner;
    $aggs['shapes'] = $shapes;
    $body['aggs'] = $aggs;

    return $body;
}

/**
 * The aggregation a page marks as verified, decoded.
 *
 * @return array<mixed>
 */
function aggregation(string $marker): array
{
    foreach ((array) glob(PAGES . '/*.md') as $page) {
        $markdown = (string) file_get_contents((string) $page);
        $pattern = '/<!-- verified: ' . preg_quote($marker, '/') . " -->\n```json\n(.*?)\n```/s";

        if (preg_match($pattern, $markdown, $match) === 1) {
            $decoded = json_decode($match[1], true);
            if (!is_array($decoded)) {
                fwrite(STDERR, 'The ' . $marker . " block is not valid JSON.\n");
                exit(1);
            }

            return $decoded;
        }
    }

    fwrite(STDERR, 'No page carries a `' . $marker . "` aggregation.\n");
    exit(1);
}

/**
 * @param array<string,mixed> $visState
 *
 * @return array<string,mixed>
 */
function visualization(string $id, string $title, array $visState, string $description = ''): array
{
    return [
        'id' => $id,
        'type' => 'visualization',
        'references' => [[
            'id' => INDEX_PATTERN_ID,
            'name' => 'kibanaSavedObjectMeta.searchSourceJSON.index',
            'type' => 'index-pattern',
        ]],
        'attributes' => [
            'title' => $title,
            'description' => $description,
            'uiStateJSON' => '{}',
            'version' => 1,
            'visState' => json_encode($visState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'kibanaSavedObjectMeta' => [
                'searchSourceJSON' => json_encode([
                    'query' => ['query' => '', 'language' => 'kuery'],
                    'filter' => [],
                ], JSON_UNESCAPED_SLASHES),
            ],
        ],
    ];
}

/**
 * @param array<string,mixed> $params
 *
 * @return array<string,mixed>
 */
function metric(string $id, string $type, array $params, string $label = ''): array
{
    if ($label !== '') {
        $params['customLabel'] = $label;
    }

    return ['id' => $id, 'enabled' => true, 'type' => $type, 'schema' => 'metric', 'params' => $params];
}

/**
 * @param array<string,mixed> $params
 *
 * @return array<string,mixed>
 */
function bucket(string $id, string $type, array $params): array
{
    return ['id' => $id, 'enabled' => true, 'type' => $type, 'schema' => 'bucket', 'params' => $params];
}

/**
 * @return array<string,mixed>
 */
function dashboard(): array
{
    $panels = [
        'os-query-digest-top-shapes' => ['w' => 24, 'h' => 15, 'x' => 0, 'y' => 0],
        'os-query-digest-p95-over-time' => ['w' => 24, 'h' => 15, 'x' => 24, 'y' => 0],
        'os-query-digest-what-regressed' => ['w' => 24, 'h' => 15, 'x' => 0, 'y' => 15],
        'os-query-digest-new-shapes' => ['w' => 24, 'h' => 15, 'x' => 24, 'y' => 15],
    ];

    $references = [];
    $layout = [];
    $position = 0;

    foreach ($panels as $panel => $grid) {
        $name = 'panel_' . $position;
        $references[] = ['id' => $panel, 'name' => $name, 'type' => 'visualization'];
        $layout[] = [
            'panelIndex' => (string) ($position + 1),
            'gridData' => $grid + ['i' => (string) ($position + 1)],
            'embeddableConfig' => new stdClass(),
            'panelRefName' => $name,
        ];
        $position++;
    }

    return [
        'id' => DASHBOARD_ID,
        'type' => 'dashboard',
        'references' => $references,
        'attributes' => [
            'title' => 'Query shapes',
            'description' => 'Which shape of OpenSearch query costs you, which one regressed, '
                . 'and which one the last release added. Built on the digests logged by '
                . 'mr-dlef/os-query-digest.',
            'hits' => 0,
            'version' => 1,
            'timeRestore' => false,
            'optionsJSON' => json_encode(['hidePanelTitles' => false, 'useMargins' => true]),
            'panelsJSON' => json_encode($layout, JSON_UNESCAPED_SLASHES),
            'kibanaSavedObjectMeta' => [
                'searchSourceJSON' => json_encode([
                    'query' => ['query' => '', 'language' => 'kuery'],
                    'filter' => [],
                ], JSON_UNESCAPED_SLASHES),
            ],
        ],
    ];
}

function size(string $contents): string
{
    return number_format(strlen($contents) / 1024, 0) . ' KB';
}
