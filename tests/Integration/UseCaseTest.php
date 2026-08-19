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
 * Those pages make claims that are only worth making if they are true: that this
 * query finds the shape that regressed, that the obvious one finds the wrong
 * shape instead, that dropping a `bucket_selector` makes the request fail. None
 * of it is inferred from the OpenSearch documentation — it is run.
 *
 * **The pages are the source.** Each aggregation is extracted from the markdown
 * by its `<!-- verified: name -->` marker rather than copied here, so there is
 * one copy of every query and it is the one a reader sees. Editing a page edits
 * what this test executes.
 *
 * The index it builds is the scenario those pages describe: four query shapes
 * over six hours, digested by the library itself so no hash is written by hand.
 * One shape regresses at 14:00 and one appears at 15:00, an hour apart, because
 * telling those two events apart is the point of the first two pages.
 */
final class UseCaseTest extends TestCase
{
    private const INDEX = 'os-query-digest-use-cases';

    private const PAGES = __DIR__ . '/../../docs/use-cases';

    /** Fixed, because a documentation example that moves cannot be reproduced. */
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
     * The naive ranking is on the page as a counter-example, so it has to keep
     * being wrong. If p95 ever puts the regressed shape first, the page's whole
     * argument evaporates and the section needs rewriting rather than patching.
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

    /**
     * The claim this one guards is the counter-intuitive one: the shape that
     * costs the most is not the shape that runs the most.
     */
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
     * The page tells the reader that dropping `established` breaks the request,
     * and quotes the exception. Left unguarded that is the first line to become
     * folklore — OpenSearch could start tolerating it, and the page would be
     * warning about nothing.
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
     * Every hash printed on a page must be one this library still produces. A
     * promotion that moves fingerprints turns the pages stale in a way no build
     * would notice, and their whole selling point is that the numbers are real.
     */
    public function testEveryHashOnThePagesIsOneTheLibraryStillProduces(): void
    {
        $prose = '';
        foreach (self::pages() as $page) {
            $prose .= (string) file_get_contents($page);
        }

        preg_match_all('/\bq3x?:[0-9a-f]{12}\b/', $prose, $found);
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
     * Marked blocks and expectations have to stay in step: a page that gains a
     * `<!-- verified: -->` marker nobody wrote a test for is a query claiming to
     * be verified that never runs.
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
     * The page's regression query with `established` taken out. Every level is
     * validated on the way down rather than reached through: the point of the
     * test is that this specific structure is what fails, so a page that no
     * longer has that structure must say so rather than silently pass.
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
     * The JSON block a `<!-- verified: name -->` marker introduces. Fails loudly
     * rather than skipping: a marker whose block cannot be found means the page
     * was restructured and this test is silently checking nothing.
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

    /**
     * Builds the scenario. Every document's digest comes from the library, so a
     * hash here is a hash a user would get.
     */
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
     * Every hash the committed fixtures currently produce. Wider than the four
     * the scenario uses, so a page may quote any fixture's hash.
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

        // The pages also digest queries written inline rather than as fixtures:
        // the multi-tenant filter, the email line, the selectivity pair and the
        // reordered bool. Kept here so the page and the test cannot disagree.
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
     * @return array<string,mixed>
     */
    private static function mapping(): array
    {
        return ['mappings' => ['properties' => [
            '@timestamp' => ['type' => 'date'],
            'release' => ['type' => 'keyword'],
            'took' => ['type' => 'integer'],
            'os' => ['properties' => [
                'hash' => ['type' => 'keyword'],
                'sig' => ['type' => 'keyword', 'ignore_above' => 1024],
                'q' => ['type' => 'text'],
                'idx' => ['type' => 'keyword'],
            ]],
        ]]];
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
     * A number out of a response, validated rather than cast. `self::fail()`
     * never returns, which is what makes the cast below a real one.
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
     * The markdown files, as a list. `glob()` can return false and a short
     * ternary is not allowed here.
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
     * @param non-empty-string         $method
     * @param array<string,mixed>|null $body
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
