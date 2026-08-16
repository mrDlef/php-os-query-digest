<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * Pins what we know about the OpenSearch DSL against the official API
 * specification.
 *
 * `resources/opensearch-spec.json` is a committed snapshot of the query and
 * aggregation type names declared by
 * https://github.com/opensearch-project/opensearch-api-specification —
 * refreshed with `make spec`, never fetched at test time so the suite stays
 * offline and deterministic.
 *
 * `resources/coverage.json` records our stance on each of them. When OpenSearch
 * adds a type, refreshing the snapshot makes this test fail until someone
 * classifies it. That is the point: the alternative is finding out in
 * production that a query renders as `something(?)`.
 */
final class SpecCoverageTest extends TestCase
{
    public function testEveryQueryTypeInTheSpecIsClassified(): void
    {
        $spec = self::snapshot()['query_types'];
        $coverage = array_keys(self::coverage()['query']);

        sort($spec);
        sort($coverage);

        self::assertSame(
            $spec,
            $coverage,
            'The OpenSearch spec snapshot and resources/coverage.json disagree. '
            . 'Add the new query types to coverage.json as "native" or "opaque".',
        );
    }

    public function testEveryAggregationTypeInTheSpecIsClassified(): void
    {
        $spec = self::snapshot()['aggregation_types'];
        $coverage = array_keys(self::coverage()['aggregation']);

        sort($spec);
        sort($coverage);

        self::assertSame($spec, $coverage, 'Add the new aggregation types to coverage.json.');
    }

    /**
     * A type declared "native" must not fall through to the opaque branch.
     * Without this the stance file could claim coverage the parser never had.
     */
    public function testNativeQueryTypesDoNotRenderAsOpaque(): void
    {
        $formatter = Formatter::create();
        $opaque = [];

        foreach (self::coverage()['query'] as $type => $stance) {
            if ($stance !== 'native') {
                continue;
            }

            $text = $formatter->describe(['query' => [$type => self::probe($type)]])->text();
            if (strpos($text, $type . '(?)') !== false) {
                $opaque[] = $type;
            }
        }

        self::assertSame([], $opaque, 'Declared native but rendered opaque: ' . implode(', ', $opaque));
    }

    /**
     * A type declared "opaque" must still be visible in the output — never
     * dropped. It also has to keep contributing to the fingerprint.
     */
    public function testOpaqueQueryTypesAreSignalledNotDropped(): void
    {
        $formatter = Formatter::create();
        $missing = [];

        foreach (self::coverage()['query'] as $type => $stance) {
            if ($stance !== 'opaque') {
                continue;
            }

            $digest = $formatter->describe(['query' => ['bool' => ['filter' => [
                ['term' => ['env' => 'prod']],
                [$type => self::probe($type)],
            ]]]]);

            if (strpos($digest->text(), $type) === false) {
                $missing[] = $type;
            }
        }

        self::assertSame([], $missing, 'Silently dropped instead of signalled: ' . implode(', ', $missing));
    }

    /**
     * A minimal body that is enough to exercise the parser branch. It does not
     * have to be a valid query — only to reach the right code path.
     *
     * @return array<string,mixed>
     */
    private static function probe(string $type): array
    {
        switch ($type) {
            case 'exists':
                return ['field' => 'f'];
            case 'ids':
                return ['values' => ['1']];
            case 'range':
                return ['f' => ['gte' => 1]];
            case 'terms':
            case 'terms_set':
                return ['f' => ['a', 'b']];
            case 'multi_match':
                return ['query' => 'x', 'fields' => ['f']];
            case 'query_string':
            case 'simple_query_string':
                return ['query' => 'f:x'];
            case 'nested':
                return ['path' => 'p', 'query' => ['term' => ['p.f' => 'x']]];
            case 'constant_score':
                return ['filter' => ['term' => ['f' => 'x']]];
            case 'function_score':
                return ['query' => ['term' => ['f' => 'x']]];
            case 'boosting':
                return ['positive' => ['term' => ['f' => 'x']], 'negative' => ['term' => ['g' => 'y']]];
            case 'dis_max':
                return ['queries' => [['term' => ['f' => 'x']]]];
            case 'knn':
                return ['f' => ['vector' => [0.1, 0.2], 'k' => 3]];
            case 'neural':
                return ['f' => ['query_text' => 'x', 'model_id' => 'm', 'k' => 3]];
            case 'geo_distance':
                return ['distance' => '1km', 'f' => ['lat' => 0, 'lon' => 0]];
            case 'geo_bounding_box':
                return ['f' => [
                    'top_left' => ['lat' => 1, 'lon' => 0],
                    'bottom_right' => ['lat' => 0, 'lon' => 1],
                ]];
            case 'script':
                return ['script' => ['source' => "doc['f'].value > 1"]];
            case 'has_child':
                return ['type' => 'c', 'query' => ['term' => ['f' => 'x']]];
            case 'has_parent':
                return ['parent_type' => 'p', 'query' => ['term' => ['f' => 'x']]];
            case 'more_like_this':
                return ['fields' => ['f'], 'like' => 'x'];
            case 'bool':
                return ['filter' => [['term' => ['f' => 'x']]]];
            case 'match_all':
            case 'match_none':
                return [];
            default:
                return ['f' => 'x'];
        }
    }

    /**
     * @return array{query_types:array<int,string>,aggregation_types:array<int,string>}
     */
    private static function snapshot(): array
    {
        /** @var array{query_types:array<int,string>,aggregation_types:array<int,string>} $data */
        $data = self::readJson(__DIR__ . '/../resources/opensearch-spec.json');

        return $data;
    }

    /**
     * @return array{query:array<string,string>,aggregation:array<string,string>}
     */
    private static function coverage(): array
    {
        /** @var array{query:array<string,string>,aggregation:array<string,string>} $data */
        $data = self::readJson(__DIR__ . '/../resources/coverage.json');

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private static function readJson(string $file): array
    {
        $contents = file_get_contents($file);
        self::assertIsString($contents, 'Unreadable: ' . $file);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded, 'Invalid JSON: ' . $file);

        return $decoded;
    }
}
