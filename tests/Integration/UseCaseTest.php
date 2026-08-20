<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests\Integration;

use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * Runs the aggregations printed on the Use cases pages against a live cluster.
 *
 *     OPENSEARCH_URL=http://localhost:9202 vendor/bin/phpunit --testsuite=integration
 *
 * **The pages are the source.** Each aggregation is extracted from the markdown
 * by its `<!-- verified: name -->` marker, never copied here: one copy of every
 * query, and it is the one a reader sees.
 *
 * The index is the scenario the pages describe — four shapes over six hours,
 * digested by the library so no hash is hand-written. One regresses at 14:00,
 * one appears at 15:00, and telling those apart is the point of two of the pages.
 */
final class UseCaseTest extends TestCase
{
    private const INDEX = 'os-query-digest-use-cases';

    private const PAGES = __DIR__ . '/../../docs/use-cases';

    /** Fixed: a documentation example that moves cannot be reproduced. */
    private const BASE = '2026-08-19T10:00:00Z';

    private const HOURS = 6;

    /**
     * fixture => [index, per-hour rate, ms before 14:00, ms from 14:00, first hour]
     *
     * @var array<string,array{0:string,1:int,2:int,3:int,4:int}>
     */
    private const SHAPES = [
        'workhorse' => ['01-error-rate-filter', 1200, 8, 8, 0],
        'regressed' => ['02-dashboard-aggs', 60, 42, 910, 0],
        'deployed' => ['12-vector-search', 90, 25, 25, 5],
        'alwaysSlow' => ['04-terms-overflow', 5, 1400, 1400, 0],
    ];

    private string $url = '';

    /** @var array<string,string> role => hash, as the library produces it */
    private array $hashes = [];

    protected function setUp(): void
    {
        $url = getenv('OPENSEARCH_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set OPENSEARCH_URL to run against a cluster.');
        }

        $this->url = rtrim($url, '/');

        $root = $this->request('GET', '/');
        self::assertSame(200, $root['status'], 'No cluster answering at ' . $this->url);

        $this->index();
    }

    protected function tearDown(): void
    {
        if ($this->url !== '') {
            $this->request('DELETE', '/' . self::INDEX);
        }
    }

    /**
     * On the page as a counter-example, so it has to keep being wrong: p95 must
     * still surface the always-slow shape ahead of the regressed one.
     */
    public function testRankingByPercentileFindsTheWrongShape(): void
    {
        $buckets = $this->aggregate('naive');

        self::assertSame(
            $this->hashes['alwaysSlow'],
            self::text($buckets[0], ['key']),
            'The page claims p95 surfaces the always-slow report first.',
        );
        self::assertSame($this->hashes['regressed'], self::text($buckets[1], ['key']));
    }

    public function testRankingByChangeFindsTheShapeThatRegressed(): void
    {
        $buckets = $this->aggregate('what-regressed');

        self::assertSame($this->hashes['regressed'], self::text($buckets[0], ['key']));
        self::assertGreaterThan(
            10.0,
            self::number($buckets[0], ['slowdown', 'value']),
            'The page says twenty-one times.',
        );

        $unchanged = self::bucket($buckets, $this->hashes['alwaysSlow']);
        self::assertNotNull($unchanged, 'The always-slow shape should still be listed.');
        self::assertEqualsWithDelta(
            1.0,
            self::number($unchanged, ['slowdown', 'value']),
            0.05,
            'It did not regress.',
        );

        self::assertNull(
            self::bucket($buckets, $this->hashes['deployed']),
            'A shape with no before-window must be excluded, or bucket_sort throws.',
        );
    }

    /** Guards the counter-intuitive claim: the costliest shape is not the busiest. */
    public function testTheCostliestShapeIsNotTheMostFrequent(): void
    {
        $buckets = $this->aggregate('worth-fixing');

        self::assertSame($this->hashes['regressed'], self::text($buckets[0], ['key']));
        self::assertSame($this->hashes['workhorse'], self::text($buckets[1], ['key']));

        $costliest = $buckets[0];
        $busiest = $buckets[1];

        self::assertLessThan(
            self::number($busiest, ['doc_count']),
            self::number($costliest, ['doc_count']),
            'The costliest shape should be the rarer of the two, or the page is stating the obvious.',
        );
        self::assertGreaterThan(
            self::number($busiest, ['total_ms', 'value']),
            self::number($costliest, ['total_ms', 'value']),
        );
    }

    public function testOnlyTheDeployedShapeIsNewAfterFifteenHundred(): void
    {
        foreach (['new-shapes', 'one-release-only'] as $name) {
            $buckets = $this->aggregate($name);

            self::assertCount(1, $buckets, $name . ' should find exactly one new shape.');
            self::assertSame($this->hashes['deployed'], self::text($buckets[0], ['key']), $name);
        }
    }

    /**
     * The page quotes the exception dropping `established` produces. Guarded so
     * the warning cannot outlive the behaviour if OpenSearch starts tolerating it.
     */
    public function testTheRegressionQueryFailsWithoutItsBucketSelector(): void
    {
        $response = $this->request('POST', '/' . self::INDEX . '/_search', self::withoutSelector());

        self::assertSame(500, $response['status'], 'Without the selector this is expected to fail.');
        self::assertSame(
            'null_pointer_exception',
            self::text($response['body'], ['error', 'caused_by', 'type']),
            'The page quotes a null_pointer_exception.',
        );
    }

    /**
     * A promotion that moves fingerprints would leave the pages quoting hashes
     * nothing produces, and no build would notice.
     */
    public function testEveryHashOnThePagesIsOneTheLibraryStillProduces(): void
    {
        $prose = '';
        foreach (self::pages() as $page) {
            $prose .= (string) file_get_contents($page);
        }

        preg_match_all('/\bq4x?:[0-9a-f]{12}\b/', $prose, $found);
        $printed = array_values(array_unique($found[0]));

        self::assertNotSame([], $printed, 'The pages should print hashes at all.');

        $produced = $this->everyHashTheLibraryProduces();

        foreach ($printed as $hash) {
            self::assertContains(
                $hash,
                $produced,
                $hash . ' is printed on a Use cases page and this library no longer produces it. '
                . 'If fingerprints moved, the pages need regenerating.',
            );
        }
    }

    /**
     * Markers and expectations must stay in step: a marked query nobody runs
     * claims to be verified and is not.
     */
    public function testEveryMarkedAggregationIsExercised(): void
    {
        $exercised = ['naive', 'what-regressed', 'worth-fixing', 'new-shapes', 'one-release-only'];

        $marked = [];
        foreach (self::pages() as $page) {
            preg_match_all('/<!-- verified: ([a-z0-9-]+) -->/', (string) file_get_contents($page), $m);
            $marked = array_merge($marked, $m[1]);
        }

        sort($marked);
        sort($exercised);

        self::assertSame($exercised, $marked, 'A marked aggregation is not being run, or vice versa.');
    }

    /**
     * The two Vega panels of the dashboard pack carry these same aggregations
     * with the pages' fixed windows replaced by the time picker's macros.
     * Dashboards substitutes those before sending; this substitutes them the
     * same way and runs the result, so a panel cannot ship a query the cluster
     * refuses — the one thing about the pack a machine can check.
     */
    public function testTheDashboardPanelsRunTheseAggregationsAgainstTheCluster(): void
    {
        // The window a reader would pick in each case: the hour the slowdown
        // happened, and the hour the release shipped.
        $cases = [
            'os-query-digest-what-regressed' => ['14:00', '15:00', 'regressed'],
            'os-query-digest-new-shapes' => ['15:00', '16:00', 'deployed'],
        ];

        foreach ($cases as $panel => [$from, $to, $expected]) {
            $body = self::panelBody($panel, $from, $to);

            self::assertStringNotContainsString('%timefilter%', (string) json_encode($body), $panel);

            $response = $this->request('POST', '/' . self::INDEX . '/_search', $body);
            self::assertSame(
                200,
                $response['status'],
                $panel . ' was refused: ' . json_encode($response['body']),
            );

            $buckets = self::dig($response['body'], ['aggregations', 'shapes', 'buckets']);
            self::assertIsArray($buckets, $panel . ' returned no shape buckets.');
            self::assertNotSame([], $buckets, $panel . ' drew nothing on the scenario index.');

            /** @var array<int,array<string,mixed>> $buckets */
            self::assertSame(
                $this->hashes[$expected],
                self::text($buckets[0], ['key']),
                $panel . ' does not answer with the shape the pages say it should.',
            );
        }
    }

    /**
     * A panel's aggregation with the two macros resolved, the way Dashboards
     * resolves them: `%timefilter%` is the selected window, and the same macro
     * with a `shift` is that window moved back by it.
     *
     * @return array<mixed>
     */
    private static function panelBody(string $panel, string $from, string $to): array
    {
        $spec = self::panelSpec($panel);

        $data = $spec['data'] ?? null;
        self::assertIsArray($data, $panel . ' has no data section.');
        $url = $data['url'] ?? null;
        self::assertIsArray($url, $panel . ' queries nothing.');
        $body = $url['body'] ?? null;
        self::assertIsArray($body, $panel . ' has no aggregation.');

        $day = substr(self::BASE, 0, 10) . 'T';
        $encoded = (string) json_encode($body);

        $selected = ['gte' => $day . $from . ':00Z', 'lt' => $day . $to . ':00Z'];
        $shifted = [
            'gte' => $day . self::hourBefore($from) . ':00Z',
            'lt' => $day . self::hourBefore($to) . ':00Z',
        ];

        $encoded = str_replace(
            [
                (string) json_encode(['%timefilter%' => true, 'shift' => 1, 'unit' => 'hour']),
                (string) json_encode(['%timefilter%' => true]),
            ],
            [(string) json_encode($shifted), (string) json_encode($selected)],
            $encoded,
        );

        $resolved = json_decode($encoded, true);
        self::assertIsArray($resolved, 'The substituted body is not valid JSON.');

        return $resolved;
    }

    private static function hourBefore(string $time): string
    {
        return sprintf('%02d:00', ((int) substr($time, 0, 2)) - 1);
    }

    /**
     * @return array<mixed>
     */
    private static function panelSpec(string $panel): array
    {
        // Either variant: they differ in the vega-lite schema they declare and
        // in nothing else, which DashboardPackTest is what guarantees.
        $path = __DIR__ . '/../../resources/dashboards/os-query-digest-opensearch-2.x.ndjson';

        foreach (explode("\n", trim((string) file_get_contents($path))) as $line) {
            $object = json_decode($line, true);
            if (!is_array($object) || ($object['id'] ?? null) !== $panel) {
                continue;
            }

            $attributes = $object['attributes'] ?? null;
            self::assertIsArray($attributes);
            $encoded = $attributes['visState'] ?? null;
            self::assertIsString($encoded, $panel . ' has no visState.');
            $visState = json_decode($encoded, true);
            self::assertIsArray($visState);
            $params = $visState['params'] ?? null;
            self::assertIsArray($params);
            $spec = $params['spec'] ?? null;
            self::assertIsString($spec, $panel . ' carries no Vega spec.');
            $spec = json_decode($spec, true);
            self::assertIsArray($spec, $panel . ' has no readable Vega spec.');

            return $spec;
        }

        self::fail($panel . ' is not in the dashboard pack.');
    }

    /**
     * The template the pack ships has to be one a cluster accepts, and it has to
     * produce the mapping the pages depend on — `os.hash` a keyword rather than
     * an analysed field, which is the difference between an aggregation and a
     * pile of word fragments.
     */
    public function testTheShippedIndexTemplateCreatesTheMappingThePagesNeed(): void
    {
        $template = self::indexTemplate();
        $template['index_patterns'] = ['os-query-digest-template-check-*'];
        $index = 'os-query-digest-template-check-000001';

        $put = $this->request('PUT', '/_index_template/os-query-digest-check', $template);
        self::assertSame(200, $put['status'], 'The cluster refused the shipped template: '
            . json_encode($put['body']));

        try {
            // No body at all: an empty array encodes as `[]`, which the
            // cluster rejects as a request that is not an object.
            $created = $this->request('PUT', '/' . $index);
            self::assertSame(200, $created['status'], 'The template did not apply to a new index.');

            $mapping = $this->request('GET', '/' . $index . '/_mapping');
            $properties = self::dig(
                $mapping['body'],
                [$index, 'mappings', 'properties', 'os', 'properties'],
            );

            self::assertIsArray($properties, 'The template mapped no `os` object.');
            self::assertSame('keyword', self::text($properties, ['hash', 'type']));
            self::assertSame('keyword', self::text($properties, ['sig', 'type']));
            self::assertSame('text', self::text($properties, ['q', 'type']));
        } finally {
            $this->request('DELETE', '/' . $index);
            $this->request('DELETE', '/_index_template/os-query-digest-check');
        }
    }

    /**
     * The regression query with `established` taken out. Each level is validated
     * on the way down, so a restructured page fails loudly instead of passing.
     *
     * @return array<string,mixed>
     */
    private static function withoutSelector(): array
    {
        $body = self::extract('what-regressed');

        $aggs = $body['aggs'] ?? null;
        self::assertIsArray($aggs, 'The regression query has no aggs.');

        $shapes = $aggs['shapes'] ?? null;
        self::assertIsArray($shapes, 'The regression query has no shapes aggregation.');

        $inner = $shapes['aggs'] ?? null;
        self::assertIsArray($inner, 'The shapes aggregation has no sub-aggregations.');
        self::assertArrayHasKey('established', $inner, 'The page no longer has the bucket_selector.');

        unset($inner['established']);

        $shapes['aggs'] = $inner;
        $aggs['shapes'] = $shapes;
        $body['aggs'] = $aggs;

        return $body;
    }

    /**
     * Runs one of the page's aggregations and hands back its shape buckets.
     *
     * @return array<int,array<string,mixed>>
     */
    private function aggregate(string $name): array
    {
        $response = $this->request('POST', '/' . self::INDEX . '/_search', self::extract($name));

        self::assertSame(200, $response['status'], $name . ' did not run: ' . json_encode($response['body']));

        $buckets = self::dig($response['body'], ['aggregations', 'shapes', 'buckets']);
        self::assertIsArray($buckets, $name . ' returned no shape buckets.');

        /** @var array<int,array<string,mixed>> $buckets */
        return $buckets;
    }

    /**
     * The JSON block a `<!-- verified: name -->` marker introduces. A missing
     * block fails rather than skips, or the test checks nothing in silence.
     *
     * @return array<string,mixed>
     */
    private static function extract(string $name): array
    {
        foreach (self::pages() as $page) {
            $markdown = (string) file_get_contents($page);
            $pattern = '/<!-- verified: ' . preg_quote($name, '/') . " -->\n```json\n(.*?)\n```/s";

            if (preg_match($pattern, $markdown, $match) === 1) {
                $decoded = json_decode($match[1], true);
                self::assertIsArray($decoded, $name . ' is not valid JSON in ' . basename($page));

                /** @var array<string,mixed> $decoded */
                return $decoded;
            }
        }

        self::fail('No <!-- verified: ' . $name . ' --> block in ' . self::PAGES);
    }

    /** Every digest comes from the library, so a hash here is one a user gets. */
    private function index(): void
    {
        $formatter = Formatter::create();
        $base = strtotime(self::BASE);
        $lines = [];

        foreach (self::SHAPES as $role => [$fixture, $rate, $before, $after, $from]) {
            $digest = self::digest($formatter, $fixture);
            $this->hashes[$role] = $digest['hash'];

            for ($hour = $from; $hour < self::HOURS; $hour++) {
                $took = $hour >= 4 ? $after : $before;

                for ($i = 0; $i < $rate; $i++) {
                    // Deterministic spread, so a percentile is not one value.
                    $jitter = 1 + (($i % 7) - 3) * 0.06;

                    $lines[] = (string) json_encode(['index' => ['_index' => self::INDEX]]);
                    $lines[] = (string) json_encode([
                        '@timestamp' => gmdate('c', $base + $hour * 3600 + intdiv($i * 3600, $rate)),
                        'release' => $hour >= 5 ? 'v2.31.0' : 'v2.30.1',
                        'took' => (int) round($took * $jitter),
                        'os' => $digest,
                    ]);
                }
            }
        }

        $this->request('DELETE', '/' . self::INDEX);

        $created = $this->request('PUT', '/' . self::INDEX, self::mapping());
        self::assertSame(200, $created['status'], 'Could not create the scenario index.');

        $bulk = $this->bulk(implode("\n", $lines) . "\n");
        self::assertSame(200, $bulk['status'], 'Bulk indexing failed.');
        self::assertFalse(self::dig($bulk['body'], ['errors']), 'Bulk indexing reported errors.');
    }

    /**
     * @return array<string,string>
     */
    private static function digest(Formatter $formatter, string $fixture): array
    {
        $path = __DIR__ . '/../fixtures/' . $fixture . '/input.json';
        $input = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($input, $fixture . ' is not readable.');

        $request = $input['request'] ?? null;
        $index = $input['index'] ?? null;

        if (!is_array($request) || !is_string($index)) {
            self::fail($fixture . ' is not an {index, request} envelope.');
        }

        $digest = $formatter->describe($request, $index);

        return [
            'idx' => $digest->index(),
            'q' => $digest->text(),
            'sig' => $digest->signature(),
            'hash' => $digest->hash(),
        ];
    }

    /**
     * Every hash the fixtures produce — wider than the scenario's four, so a
     * page may quote any of them.
     *
     * @return array<int,string>
     */
    private function everyHashTheLibraryProduces(): array
    {
        $formatter = Formatter::create();
        $hashes = [];

        $inputs = glob(__DIR__ . '/../fixtures/*/input.json');

        foreach (is_array($inputs) ? $inputs : [] as $path) {
            $input = json_decode((string) file_get_contents($path), true);
            if (!is_array($input)) {
                continue;
            }

            $request = $input['request'] ?? null;
            $index = $input['index'] ?? null;

            if (!is_array($request) || !is_string($index)) {
                continue;
            }

            $hashes[] = $formatter->describe($request, $index)->hash();
        }

        // Queries the pages write inline rather than as fixtures.
        foreach (self::inlineExamples() as $body => $index) {
            $decoded = json_decode($body, true);
            self::assertIsArray($decoded, 'An inline example is not valid JSON.');

            $hashes[] = $formatter->describe($decoded, $index)->hash();
        }

        return array_values(array_unique($hashes));
    }

    /**
     * @return array<string,string> request body as JSON => index
     */
    private static function inlineExamples(): array
    {
        return [
            '{"query":{"bool":{"filter":[{"term":{"tenant_id":41}},{"range":{"@timestamp":{"gte":"now-1d"}}}]}},"size":20}' => 'invoices',
            '{"query":{"bool":{"filter":[{"term":{"email":"alice@example.com"}},{"term":{"status":"shipped"}}]}},"size":20}' => 'logs-2026.08.19',
            '{"query":{"term":{"service":"api"}},"size":50}' => 'logs-2026.08.19',
            '{"query":{"bool":{"filter":[{"term":{"status":"open"}},{"term":{"team":"core"}}]}}}' => 't',
        ];
    }

    /**
     * The mapping of the scenario index is the one the dashboard pack ships, so
     * every number on the pages was produced under the mapping a reader
     * installs — and a second copy of it cannot drift from the first.
     *
     * @return array<mixed>
     */
    private static function mapping(): array
    {
        $template = self::indexTemplate();

        $inner = $template['template'] ?? null;
        self::assertIsArray($inner, 'The shipped index template has no template section.');
        $mappings = $inner['mappings'] ?? null;
        self::assertIsArray($mappings, 'The shipped index template maps nothing.');

        return ['mappings' => $mappings];
    }

    /**
     * @return array<mixed>
     */
    private static function indexTemplate(): array
    {
        $path = __DIR__ . '/../../resources/dashboards/index-template.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, 'The shipped index template is not valid JSON.');

        return $decoded;
    }

    /**
     * @param array<int,array<string,mixed>> $buckets
     *
     * @return array<string,mixed>|null
     */
    private static function bucket(array $buckets, string $key): ?array
    {
        foreach ($buckets as $bucket) {
            if (($bucket['key'] ?? null) === $key) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * Validated rather than cast: `self::fail()` never returns, which is what
     * makes the cast below sound.
     *
     * @param mixed             $node
     * @param array<int,string> $path
     */
    private static function number($node, array $path): float
    {
        $value = self::dig($node, $path);

        if (!is_int($value) && !is_float($value)) {
            self::fail(implode(' > ', $path) . ' is not a number: ' . var_export($value, true));
        }

        return (float) $value;
    }

    /**
     * @param mixed             $node
     * @param array<int,string> $path
     */
    private static function text($node, array $path): string
    {
        $value = self::dig($node, $path);

        if (!is_string($value)) {
            self::fail(implode(' > ', $path) . ' is not a string: ' . var_export($value, true));
        }

        return $value;
    }

    /**
     * `glob()` can return false, and a short ternary is not allowed here.
     *
     * @return array<int,string>
     */
    private static function pages(): array
    {
        $found = glob(self::PAGES . '/*.md');

        return is_array($found) ? $found : [];
    }

    /**
     * @param mixed             $node
     * @param array<int,string> $path
     *
     * @return mixed
     */
    private static function dig($node, array $path)
    {
        $cursor = $node;

        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }

            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * @return array{status:int,body:array<int|string,mixed>|null}
     */
    private function bulk(string $ndjson): array
    {
        return $this->send('POST', '/_bulk?refresh=wait_for', $ndjson, 'application/x-ndjson');
    }

    /**
     * @param non-empty-string  $method
     * @param array<mixed>|null $body
     *
     * @return array{status:int,body:array<int|string,mixed>|null}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        return $this->send($method, $path, $body === null ? null : (string) json_encode($body));
    }

    /**
     * @param non-empty-string $method
     *
     * @return array{status:int,body:array<int|string,mixed>|null}
     */
    private function send(string $method, string $path, ?string $payload, string $type = 'application/json'): array
    {
        $handle = curl_init($this->url . $path);
        self::assertNotFalse($handle, 'Could not open a connection to ' . $this->url);

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_TIMEOUT, 120);
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: ' . $type]);

        if ($payload !== null && $payload !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
        ];
    }
}
