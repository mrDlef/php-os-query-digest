<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests\Integration;

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Support\Arr;
use PHPUnit\Framework\TestCase;

/**
 * Parses the `text` line back with OpenSearch Dashboards' own parser and checks
 * it names the fields the request named.
 *
 *     DASHBOARDS_IMAGE=opensearchproject/opensearch-dashboards:2.19.0 \
 *       vendor/bin/phpunit --testsuite=integration
 *
 * The one promise with a referent outside this repository — *paste it into the
 * search bar* — and until this test the suite only ever checked the library
 * against itself. A `nested` clause spent six weeks rendering `path:{ path.sub }`,
 * which DQL resolves to `path.path.sub`, with a golden file pinning it as correct.
 *
 * Only `text` is checked. The signature is deliberately not DQL: its values are
 * erased and it carries sigils (`~`, `*`, `/…/`) that no parser accepts.
 *
 * @internal
 */
final class DqlRoundTripTest extends TestCase
{
    private const PROBE = __DIR__ . '/../../tools/kql-probe.js';

    private string $image = '';

    /**
     * Each case: the request, and every field its DSL names. The round-trip has
     * to recover exactly that set — no more, no fewer, none misspelt.
     *
     * @return array<string,array{0:array<mixed>,1:array<int,string>}>
     */
    public static function cases(): array
    {
        return [
            'nested term' => [
                ['query' => ['nested' => [
                    'path' => 'variants',
                    'query' => ['term' => ['variants.color' => 'red']],
                ]]],
                ['variants.color'],
            ],
            'nested with two clauses' => [
                ['query' => ['nested' => [
                    'path' => 'variants',
                    'query' => ['bool' => ['filter' => [
                        ['term' => ['variants.color' => 'red']],
                        ['range' => ['variants.price' => ['lte' => 100]]],
                    ]]],
                ]]],
                ['variants.color', 'variants.price'],
            ],
            'nested inside nested' => [
                ['query' => ['nested' => [
                    'path' => 'order',
                    'query' => ['nested' => [
                        'path' => 'order.lines',
                        'query' => ['term' => ['order.lines.sku' => 'abc']],
                    ]],
                ]]],
                ['order.lines.sku'],
            ],
            'the shape production sends' => [
                ['query' => ['nested' => [
                    'path' => 'nested_taxon_i18n_tags',
                    'query' => ['match' => ['nested_taxon_i18n_tags.fr.keyword' => 'ingenieur']],
                ]]],
                ['nested_taxon_i18n_tags.fr.keyword'],
            ],
            'no nesting at all' => [
                ['query' => ['bool' => [
                    'filter' => [
                        ['term' => ['service' => 'api']],
                        ['range' => ['latency' => ['gte' => 10, 'lt' => 100]]],
                    ],
                    'must_not' => [['term' => ['status' => 200]]],
                ]]],
                ['latency', 'service', 'status'],
            ],
            'exists' => [
                ['query' => ['exists' => ['field' => 'trace_id']]],
                ['trace_id'],
            ],
        ];
    }

    protected function setUp(): void
    {
        $image = getenv('DASHBOARDS_IMAGE');
        if (!is_string($image) || $image === '') {
            self::markTestSkipped('Set DASHBOARDS_IMAGE to run the DQL round-trip.');
        }

        $this->image = $image;
    }

    /**
     * One container run for every case, rather than one each: booting node is
     * most of the cost and the parser holds no state between expressions.
     */
    public function testEveryTextLineNamesTheFieldsItsRequestNamed(): void
    {
        $expected = [];
        $probes = [];

        foreach (self::cases() as $name => $case) {
            [$request, $fields] = $case;

            $probes[] = ['id' => $name, 'kql' => self::queryOf($request)];
            sort($fields);
            $expected[$name] = $fields;
        }

        $actual = [];
        foreach ($this->parse($probes) as $result) {
            self::assertIsArray($result);

            $id = $result['id'] ?? null;
            self::assertIsString($id);

            $error = $result['error'] ?? null;
            self::assertNull($error, $id . ': Dashboards refused the line — ' . Arr::str($error));

            $fields = $result['fields'] ?? null;
            self::assertIsArray($fields);

            $actual[$id] = array_map(static fn($field): string => Arr::str($field), $fields);
        }

        self::assertSame($expected, $actual, 'A text line resolves to fields its request never named.');
    }

    /**
     * The `q=(…)` segment on its own: the rest of the line — index, size, sort
     * — was never DQL and was never claimed to be.
     *
     * @param array<mixed> $request
     */
    private static function queryOf(array $request): string
    {
        $text = Formatter::create()->describe($request)->text();

        foreach (explode(' | ', $text) as $segment) {
            if (strpos($segment, 'q=(') === 0) {
                return substr($segment, 3, -1);
            }
        }

        self::fail('No q=(…) segment in: ' . $text);
    }

    /**
     * @param array<int,array{id:string,kql:string}> $probes
     *
     * @return array<int,mixed>
     */
    private function parse(array $probes): array
    {
        $input = tempnam(sys_get_temp_dir(), 'kql');
        self::assertIsString($input);
        self::assertNotFalse(file_put_contents($input, (string) json_encode($probes)));

        $command = sprintf(
            'docker run --rm -v %s:/kql-probe.js:ro -v %s:/cases.json:ro '
            . '--entrypoint /usr/share/opensearch-dashboards/node/bin/node %s /kql-probe.js /cases.json 2>&1',
            escapeshellarg((string) realpath(self::PROBE)),
            escapeshellarg($input),
            escapeshellarg($this->image),
        );

        $output = shell_exec($command);
        unlink($input);

        self::assertIsString($output, 'The probe produced nothing.');

        $decoded = json_decode(trim($output), true);
        self::assertIsArray($decoded, 'The probe did not answer JSON: ' . $output);
        self::assertCount(count($probes), $decoded, 'The probe skipped a case.');

        return array_values($decoded);
    }
}
