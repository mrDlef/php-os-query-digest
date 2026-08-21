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

/**
 * The saved-object shape each panel declares. Both majors descend from the same
 * 7.10 fork and read it the same way, so it is a lineage marker rather than the
 * version of anything installed.
 */
const PANEL_VERSION = '7.10.0';

/** How Dashboards names the field types this mapping uses. */
const TYPES = ['date' => 'date', 'integer' => 'number', 'keyword' => 'string', 'text' => 'string'];
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
            'datum.slowdown.value',
            '× vs the hour before',
            true,
        ),
        vegaPanel(
            'os-query-digest-new-shapes',
            'Shapes the release added',
            'Shapes present in the selected window and absent from the same window an hour '
                . 'earlier — with what each one ran, and what it is.',
            $schema,
            timeMacros(aggregation('new-shapes')),
            'datum.doc_count',
            'runs',
            false,
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
            // Dashboards 3.x resolves a classic panel's field against this
            // cached list and fails the panel outright — "Could not locate that
            // index-pattern-field (id: took)" — when it is absent; 2.x fetches
            // it instead. So it is written out, derived from the template so
            // the two cannot describe different indices, and it is only the
            // fields the pack maps: refreshing the pattern in Dashboards picks
            // up whatever else a reader's index holds.
            'fields' => json_encode(fields(), JSON_UNESCAPED_SLASHES),
        ],
    ];
}

/**
 * The index pattern's field list, from the mapping the pack ships.
 *
 * @return array<int,array<string,mixed>>
 */
function fields(): array
{
    $decoded = json_decode((string) file_get_contents(TEMPLATE), true);
    $template = is_array($decoded) ? ($decoded['template'] ?? null) : null;
    $mappings = is_array($template) ? ($template['mappings'] ?? null) : null;
    $properties = is_array($mappings) ? ($mappings['properties'] ?? null) : null;

    if (!is_array($properties)) {
        fwrite(STDERR, "The index template maps nothing.\n");
        exit(1);
    }

    $fields = [];
    foreach (flatten($properties) as $name => $type) {
        // A text field is searched, never aggregated — which is the whole
        // reason `os.sig` is a keyword and `os.q` is not.
        $aggregatable = $type !== 'text';

        $fields[] = [
            'name' => $name,
            'type' => TYPES[$type] ?? 'string',
            'esTypes' => [$type],
            'count' => 0,
            'scripted' => false,
            'searchable' => true,
            'aggregatable' => $aggregatable,
            'readFromDocValues' => $aggregatable,
        ];
    }

    return $fields;
}

/**
 * Mapped paths and their type, dotted, one level of objects deep — which is as
 * deep as this mapping goes.
 *
 * @param array<mixed> $properties
 *
 * @return array<string,string>
 */
function flatten(array $properties, string $prefix = ''): array
{
    $flat = [];

    foreach ($properties as $name => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $path = $prefix . $name;

        if (isset($definition['properties']) && is_array($definition['properties'])) {
            $flat += flatten($definition['properties'], $path . '.');
            continue;
        }

        $type = $definition['type'] ?? null;
        if (is_string($type)) {
            $flat[$path] = $type;
        }
    }

    return $flat;
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
 * The specification stays deliberately plain — one mark, one encoding per axis —
 * so it reads the same under vega-lite 4 and 6. Everything interesting is in the
 * aggregation, which is the page's. Four things in here are not decoration, and
 * each was found by opening the panel in a real Dashboards:
 *
 * - **the window is in the body**, not in `%timefield%`, because these two
 *   panels compare the selected range with an earlier one and `%timefield%`
 *   restricts the search to the selected range — leaving the before-window
 *   matching nothing and every bucket dropped. A body query and `%context%` are
 *   mutually exclusive, so the dashboard's own query and filters come back in
 *   through the `%dashboard_context-*%` clauses.
 * - **the value is computed, not addressed.** `slowdown.value` as a field name
 *   resolves to nothing, and the panel draws an axis of `[Infinity, -Infinity]`
 *   rather than complaining. A `calculate` reads the nested value.
 * - **no stacking.** A quantitative encoding stacks by default, which on one bar
 *   per shape is meaningless and normalises every bar to the same length.
 * - **a mark that survives no data**, where an empty result is the common case:
 *   a quantitative extent over nothing is what prints `[Infinity, -Infinity]` in
 *   the panel, and a discrete text mark has no extent to compute.
 *
 * @param array<mixed> $body
 * @param string       $value a vega expression over one bucket
 * @param bool         $bars  false for the panel whose result is usually empty
 *
 * @return array<string,mixed>
 */
function vegaPanel(
    string $id,
    string $title,
    string $description,
    string $schema,
    array $body,
    string $value,
    string $valueTitle,
    bool $bars
): array {
    $signature = 'datum.shape && datum.shape.buckets && datum.shape.buckets.length'
        . " ? datum.shape.buckets[0].key : ''";

    $spec = [
        '$schema' => $schema,
        'title' => $title,
        'data' => [
            'url' => [
                'index' => PATTERN,
                'body' => $body,
            ],
            'format' => ['property' => 'aggregations.shapes.buckets'],
        ],
        'transform' => [
            ['calculate' => $value, 'as' => 'value'],
            ['calculate' => $signature, 'as' => 'signature'],
            ['calculate' => "datum.doc_count + '  ·  ' + (" . $signature . ')', 'as' => 'label'],
        ],
        'mark' => $bars
            ? ['type' => 'bar', 'tooltip' => true]
            : ['type' => 'text', 'align' => 'left', 'dx' => 6, 'limit' => 900, 'tooltip' => true],
        'encoding' => [
            'y' => [
                'field' => 'key',
                'type' => 'nominal',
                'title' => 'shape',
                'axis' => ['labelLimit' => 200],
                'sort' => ['field' => 'value', 'order' => 'descending'],
            ],
            'tooltip' => [
                ['field' => 'key', 'type' => 'nominal', 'title' => 'hash'],
                ['field' => 'signature', 'type' => 'nominal', 'title' => 'signature'],
                ['field' => 'value', 'type' => 'quantitative', 'title' => $valueTitle],
                ['field' => 'doc_count', 'type' => 'quantitative', 'title' => 'records'],
            ],
        ],
    ];

    if ($bars) {
        $spec['encoding']['x'] = [
            'field' => 'value',
            'type' => 'quantitative',
            'title' => $valueTitle,
            'stack' => null,
        ];
    } else {
        // Pinned to the left edge: with no quantitative encoding the mark would
        // otherwise be centred in the panel, half a screen from its label.
        $spec['encoding']['x'] = ['value' => 8];
        $spec['encoding']['text'] = ['field' => 'label', 'type' => 'nominal'];
    }

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

    // Negative: the macro shifts the window the way it is signed, and a
    // positive one lands an hour *after* the selection — which reads as "every
    // shape is unchanged", because it compares the slow hour with itself.
    $earlier = ['range' => ['@timestamp' => ['%timefilter%' => true, 'shift' => -1, 'unit' => 'hour']]];
    $selected = ['range' => ['@timestamp' => ['%timefilter%' => true]]];

    $before['filter'] = $earlier;
    $after['filter'] = $selected;

    $inner['before'] = $before;
    $inner['after'] = $after;
    $shapes['aggs'] = $inner;
    $aggs['shapes'] = $shapes;
    $body['aggs'] = $aggs;

    // The search has to span both windows, so the time scope is written here
    // rather than left to `%timefield%`, which would have restricted it to the
    // selected one — leaving the before-window matching nothing and every
    // bucket dropped. A body query rules out `%context%`, so the dashboard's
    // own query and filters are re-injected clause by clause instead; without
    // that, these two panels would quietly ignore the filter bar.
    // Bare strings, not objects: that is how the plugin recognises them, and an
    // object goes to the cluster verbatim and comes back a Bad Request.
    $body['query'] = ['bool' => [
        'must' => ['%dashboard_context-must_clause%'],
        'filter' => [
            '%dashboard_context-filter_clause%',
            ['bool' => ['should' => [$earlier, $selected], 'minimum_should_match' => 1]],
        ],
        'must_not' => ['%dashboard_context-must_not_clause%'],
    ]];

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
                    // The reference is resolved by name on read. Without this
                    // the object still imports, and every classic panel then
                    // fails with "Trying to initialize aggs without index
                    // pattern".
                    'indexRefName' => 'kibanaSavedObjectMeta.searchSourceJSON.index',
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
            // Not optional, whatever an empty dashboard export suggests: the
            // dashboard plugin reads it to decide whether a panel predates 7.3,
            // and without it the whole app throws before drawing anything.
            'version' => PANEL_VERSION,
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
