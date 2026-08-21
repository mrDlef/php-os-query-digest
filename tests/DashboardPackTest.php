<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * The importable dashboard pack, held to the pages it is generated from.
 *
 * What this suite can prove offline is that the file is a coherent set of saved
 * objects, that it names fields the library actually emits and the shipped index
 * template actually maps, and that nothing in it is pinned to the afternoon the
 * documentation describes. Whether a panel *draws* is not provable here and is
 * not claimed: `UseCaseTest` runs each Vega panel's aggregation against live
 * 2.19.6 and 3.8.0 nodes, and the rendering is the one part a reader has to
 * judge with their own eyes.
 */
final class DashboardPackTest extends TestCase
{
    private const PACK = __DIR__ . '/../resources/dashboards';

    /** Each Dashboards major bundles a different vega-lite; both are shipped. */
    private const VARIANTS = [
        'opensearch-2.x' => 'https://vega.github.io/schema/vega-lite/v4.json',
        'opensearch-3.x' => 'https://vega.github.io/schema/vega-lite/v6.json',
    ];

    private const PANELS = [
        'os-query-digest-top-shapes',
        'os-query-digest-p95-over-time',
        'os-query-digest-what-regressed',
        'os-query-digest-new-shapes',
    ];

    public function testTheCommittedPackIsWhatTheToolWouldWriteNow(): void
    {
        $tool = escapeshellarg(dirname(__DIR__) . '/tools/build-dashboards.php');
        $output = [];
        $status = 0;
        exec('php ' . $tool . ' --check 2>&1', $output, $status);

        self::assertSame(0, $status, "php tools/build-dashboards.php --check failed:\n" . implode("\n", $output));
    }

    public function testEveryObjectIsSavedObjectShaped(): void
    {
        foreach (array_keys(self::VARIANTS) as $variant) {
            foreach (self::objects($variant) as $line => $object) {
                $where = $variant . ' line ' . ($line + 1);

                self::assertArrayHasKey('id', $object, $where);
                self::assertArrayHasKey('type', $object, $where);
                self::assertArrayHasKey('attributes', $object, $where);
                self::assertArrayHasKey('references', $object, $where);
                self::assertIsArray($object['attributes'], $where);
                self::assertIsArray($object['references'], $where);
            }
        }
    }

    /**
     * An import resolves panels by reference. A dangling one imports without
     * complaint and leaves an empty frame on the dashboard.
     */
    public function testEveryReferenceResolvesInsideTheFile(): void
    {
        foreach (array_keys(self::VARIANTS) as $variant) {
            $objects = self::objects($variant);

            $ids = [];
            foreach ($objects as $object) {
                $ids[] = self::string($object, 'id');
            }

            self::assertSame(array_unique($ids), $ids, 'Two objects share an id in ' . $variant);

            foreach ($objects as $object) {
                $references = $object['references'];
                self::assertIsArray($references);
                foreach ($references as $reference) {
                    self::assertIsArray($reference);
                    self::assertContains(
                        $reference['id'] ?? null,
                        $ids,
                        self::string($object, 'id') . ' references something the file does not carry.',
                    );
                }
            }
        }
    }

    public function testTheDashboardCarriesEveryPanelAndNothingElse(): void
    {
        foreach (array_keys(self::VARIANTS) as $variant) {
            $dashboard = self::object($variant, 'os-query-digest-query-shapes');

            /** @var array<int,array<string,mixed>> $references */
            $references = $dashboard['references'];
            $referenced = [];
            foreach ($references as $reference) {
                $referenced[] = $reference['id'] ?? null;
            }

            self::assertSame(self::PANELS, $referenced, $variant);

            /** @var array<string,mixed> $attributes */
            $attributes = $dashboard['attributes'];
            $layout = json_decode(self::string($attributes, 'panelsJSON'), true);
            self::assertIsArray($layout);
            self::assertCount(count(self::PANELS), $layout, 'A panel has no place on the grid.');
        }
    }

    /**
     * The mapping and the pack have to name the same fields, and both have to
     * name the ones the digest actually produces. A panel aggregating `os.hashh`
     * imports cleanly and draws nothing.
     */
    public function testEveryFieldNamedInThePackIsOneTheTemplateMapsAndTheLibraryEmits(): void
    {
        $mapped = self::mappedFields();

        self::assertSame(
            ['hash', 'idx', 'q', 'sig'],
            self::digestFields(),
            'The digest no longer emits the four fields the template maps under `os`.',
        );

        foreach (array_keys(self::VARIANTS) as $variant) {
            $pack = (string) file_get_contents(self::PACK . '/os-query-digest-' . $variant . '.ndjson');

            preg_match_all('/(?:\\\\"field\\\\":\\\\"|"field": ?")([^"\\\\]+)/', $pack, $found);
            $fields = array_values(array_unique($found[1]));

            self::assertNotSame([], $fields, 'The pack names no field at all.');

            foreach ($fields as $field) {
                // Vega encodings name their own keys — the aggregation's output,
                // not the index's. Only document fields are checked.
                if (strpos($field, '.') === false && !in_array($field, ['took', '@timestamp'], true)) {
                    continue;
                }
                if (strpos($field, 'shape.') === 0) {
                    continue;
                }

                self::assertContains($field, $mapped, $field . ' is used in ' . $variant . ' and is not mapped.');
            }
        }
    }

    public function testThePackAndTheTemplateAgreeOnTheIndexPattern(): void
    {
        $template = self::template();
        /** @var array<int,string> $patterns */
        $patterns = $template['index_patterns'];
        $pattern = $patterns[0];

        foreach (array_keys(self::VARIANTS) as $variant) {
            /** @var array<string,mixed> $attributes */
            $attributes = self::object($variant, 'os-query-digest-logs')['attributes'];

            self::assertSame($pattern, $attributes['title'] ?? null, $variant);
            self::assertSame('@timestamp', $attributes['timeFieldName'] ?? null, $variant);

            foreach (self::vegaSpecs($variant) as $id => $spec) {
                self::assertSame(
                    $pattern,
                    self::dataUrl($spec)['index'] ?? null,
                    $id . ' queries an index the pack does not declare.',
                );
            }
        }
    }

    /**
     * The pages pin 14:00 and 15:00 because they describe one afternoon. A panel
     * that shipped with those dates in it would draw an empty chart forever, and
     * it is the kind of thing nobody notices in a 16 KB file.
     */
    public function testNoPanelIsPinnedToTheAfternoonTheDocumentationDescribes(): void
    {
        foreach (array_keys(self::VARIANTS) as $variant) {
            $pack = (string) file_get_contents(self::PACK . '/os-query-digest-' . $variant . '.ndjson');

            self::assertDoesNotMatchRegularExpression(
                '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/',
                $pack,
                $variant . ' carries a fixed timestamp; the panels follow the time picker.',
            );

            foreach (self::vegaSpecs($variant) as $id => $spec) {
                $url = self::dataUrl($spec);
                $body = (string) json_encode($url['body'] ?? []);

                self::assertStringContainsString('%timefilter%', $body, $id . ' ignores the time picker.');

                // `%context%` cannot be used beside a body query, so the
                // dashboard's own query and filters come back in clause by
                // clause. Without them a panel silently ignores the filter bar.
                foreach (['must', 'filter', 'must_not'] as $clause) {
                    self::assertStringContainsString(
                        '%dashboard_context-' . $clause . '_clause%',
                        $body,
                        $id . ' drops the dashboard\'s ' . $clause . ' clauses.',
                    );
                }
            }
        }
    }

    /**
     * The question each Vega panel asks is the page's, so the pipeline
     * aggregation that makes it answerable has to survive the trip.
     */
    public function testTheVegaPanelsStillCarryThePagesPipelineAggregations(): void
    {
        $expected = [
            'os-query-digest-what-regressed' => ['bucket_selector', 'bucket_script', 'bucket_sort'],
            'os-query-digest-new-shapes' => ['bucket_selector', 'min'],
        ];

        foreach (array_keys(self::VARIANTS) as $variant) {
            foreach ($expected as $id => $required) {
                $spec = self::vegaSpecs($variant)[$id] ?? null;
                self::assertIsArray($spec, $id . ' is missing from ' . $variant);

                $body = (string) json_encode(self::dataUrl($spec)['body'] ?? []);
                foreach ($required as $needle) {
                    self::assertStringContainsString('"' . $needle . '"', $body, $id . ' lost its ' . $needle);
                }

                self::assertStringContainsString('"os.hash"', $body, $id . ' no longer groups by fingerprint.');
            }
        }
    }

    /**
     * Two files exist for one reason: 2.x bundles vega-lite 4, 3.x bundles 6,
     * and the plugin refuses the other's schema. If they ever differed in
     * anything else, one of them would be a fork nobody is maintaining.
     */
    public function testTheTwoVariantsDifferOnlyInTheSchemaTheyDeclare(): void
    {
        $two = (string) file_get_contents(self::PACK . '/os-query-digest-opensearch-2.x.ndjson');
        $three = (string) file_get_contents(self::PACK . '/os-query-digest-opensearch-3.x.ndjson');

        self::assertNotSame($two, $three, 'The variants are identical, so one of them is wrong.');

        $normalised = str_replace(
            self::VARIANTS['opensearch-3.x'],
            self::VARIANTS['opensearch-2.x'],
            $three,
        );

        self::assertSame($two, $normalised, 'The variants have drifted apart beyond their schema URL.');
    }

    /**
     * The `data.url` of a Vega spec: what the panel will send to the cluster.
     *
     * @param array<mixed> $spec
     *
     * @return array<mixed>
     */
    private static function dataUrl(array $spec): array
    {
        $data = $spec['data'] ?? null;
        self::assertIsArray($data, 'A Vega spec has no data section.');

        $url = $data['url'] ?? null;
        self::assertIsArray($url, 'A Vega spec queries nothing.');

        return $url;
    }

    /**
     * @return array<string,array<mixed>> spec, keyed by visualisation id
     */
    private static function vegaSpecs(string $variant): array
    {
        $specs = [];

        foreach (self::objects($variant) as $object) {
            if (($object['type'] ?? null) !== 'visualization') {
                continue;
            }

            /** @var array<string,mixed> $attributes */
            $attributes = $object['attributes'];
            $visState = json_decode(self::string($attributes, 'visState'), true);
            self::assertIsArray($visState);

            if (($visState['type'] ?? null) !== 'vega') {
                continue;
            }

            /** @var array<string,mixed> $params */
            $params = $visState['params'];
            $spec = json_decode(self::string($params, 'spec'), true);
            self::assertIsArray($spec, 'A Vega spec is not valid JSON.');

            $specs[self::string($object, 'id')] = $spec;
        }

        self::assertNotSame([], $specs, 'No Vega panel in ' . $variant);

        return $specs;
    }

    /**
     * @return array<int,string> every mapped path, dotted
     */
    private static function mappedFields(): array
    {
        $template = self::template();

        $inner = $template['template'] ?? null;
        self::assertIsArray($inner, 'The index template has no template section.');
        $mappings = $inner['mappings'] ?? null;
        self::assertIsArray($mappings, 'The index template maps nothing.');
        $properties = $mappings['properties'] ?? null;
        self::assertIsArray($properties, 'The mapping has no properties.');

        $fields = [];
        foreach ($properties as $name => $definition) {
            $name = (string) $name;
            $fields[] = $name;

            if (is_array($definition) && isset($definition['properties']) && is_array($definition['properties'])) {
                foreach (array_keys($definition['properties']) as $child) {
                    $fields[] = $name . '.' . $child;
                }
            }
        }

        return $fields;
    }

    /**
     * @return array<int,string> the keys a digest puts in a log record, sorted
     */
    private static function digestFields(): array
    {
        $digest = Formatter::create()->describe(['query' => ['term' => ['env' => 'prod']]], 'app-logs-2026.08.21');

        $keys = array_keys($digest->jsonSerialize());
        $keys = array_values(array_filter($keys, static fn(string $key): bool => $key !== 'notes'));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<mixed>
     */
    private static function template(): array
    {
        $decoded = json_decode((string) file_get_contents(self::PACK . '/index-template.json'), true);
        self::assertIsArray($decoded, 'The index template is not valid JSON.');

        return $decoded;
    }

    /**
     * @return array<mixed>
     */
    private static function object(string $variant, string $id): array
    {
        foreach (self::objects($variant) as $object) {
            if (($object['id'] ?? null) === $id) {
                return $object;
            }
        }

        self::fail($id . ' is missing from ' . $variant);
    }

    /**
     * @return array<int,array<mixed>>
     */
    private static function objects(string $variant): array
    {
        $path = self::PACK . '/os-query-digest-' . $variant . '.ndjson';
        self::assertFileExists($path);

        $objects = [];
        foreach (explode("\n", trim((string) file_get_contents($path))) as $number => $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded, $variant . ' line ' . ($number + 1) . ' is not a JSON object.');
            $objects[] = $decoded;
        }

        return $objects;
    }

    /**
     * @param array<mixed> $source
     */
    private static function string(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        self::assertIsString($value, $key . ' is not a string.');

        return $value;
    }
}
