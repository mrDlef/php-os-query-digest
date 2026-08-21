<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Imports the dashboard pack into a running OpenSearch Dashboards.
 *
 *     DASHBOARDS_URL=http://localhost:5602 vendor/bin/phpunit --testsuite=integration
 *
 * `make dashboards-check` brings both majors up and runs this against each.
 *
 * What an import proves is narrow and worth having: that this version knows
 * every object type, that the references resolve, that the attributes are
 * shaped the way the saved-object schema expects, and that the migrations it
 * runs on the way in do not reject the file. It says nothing about whether a
 * panel *draws* — the Vega specification is stored as an opaque string, so a
 * spec written for the other major imports just as cleanly. That question needs
 * a browser, and `make dashboards-shot` is where it is answered.
 */
final class DashboardImportTest extends TestCase
{
    private const PACK = __DIR__ . '/../../resources/dashboards';

    private const OBJECTS = [
        'index-pattern' => ['os-query-digest-logs'],
        'visualization' => [
            'os-query-digest-top-shapes',
            'os-query-digest-p95-over-time',
            'os-query-digest-what-regressed',
            'os-query-digest-new-shapes',
        ],
        'dashboard' => ['os-query-digest-query-shapes'],
    ];

    private string $url = '';

    private string $major = '';

    protected function setUp(): void
    {
        $url = getenv('DASHBOARDS_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set DASHBOARDS_URL to run against OpenSearch Dashboards.');
        }

        $this->url = rtrim($url, '/');

        $status = $this->get('/api/status');
        $reported = $status['version'] ?? null;
        self::assertIsArray($reported, 'No Dashboards answering at ' . $this->url);

        $version = $reported['number'] ?? null;
        self::assertIsString($version, 'Dashboards reports no version number.');

        $this->major = explode('.', $version)[0];
    }

    /**
     * The variant is chosen by major, which is the instruction the guide gives a
     * reader. Importing it has to come back clean, with every object accounted
     * for — a partial import leaves a dashboard with holes in it.
     */
    public function testThePackForThisMajorImportsWithoutErrors(): void
    {
        $result = $this->import($this->variant());

        self::assertTrue($result['success'] ?? null, 'The import reported: ' . json_encode($result));
        self::assertSame([], $result['errors'] ?? [], 'The import rejected objects.');

        $expected = 0;
        foreach (self::OBJECTS as $ids) {
            $expected += count($ids);
        }

        self::assertSame($expected, $result['successCount'] ?? null, 'Not every object was imported.');
    }

    /**
     * Read back rather than trusted: an import can report success and still
     * leave an object under a type this version renames or drops.
     */
    public function testEveryObjectIsThereAfterwards(): void
    {
        $this->import($this->variant());

        foreach (self::OBJECTS as $type => $ids) {
            $found = $this->get('/api/saved_objects/_find?per_page=100&type=' . $type);

            $seen = [];
            /** @var array<int,array<string,mixed>> $objects */
            $objects = $found['saved_objects'] ?? [];
            foreach ($objects as $object) {
                $seen[] = $object['id'] ?? null;
            }

            foreach ($ids as $id) {
                self::assertContains($id, $seen, $id . ' is not in Dashboards ' . $this->major . ' after import.');
            }
        }
    }

    /**
     * The dashboard's panels are references, and Dashboards resolves them on
     * read. A dangling one comes back in `missingReferences` rather than as a
     * loud failure, so it is asked for explicitly.
     */
    public function testTheDashboardResolvesItsPanels(): void
    {
        $this->import($this->variant());

        $dashboard = $this->get('/api/saved_objects/dashboard/os-query-digest-query-shapes');

        /** @var array<int,array<string,mixed>> $references */
        $references = $dashboard['references'] ?? [];
        self::assertCount(4, $references, 'The dashboard lost a panel on the way in.');

        foreach ($references as $reference) {
            $id = $reference['id'] ?? null;
            self::assertIsString($id);

            $panel = $this->get('/api/saved_objects/visualization/' . $id);
            self::assertSame($id, $panel['id'] ?? null, $id . ' is referenced and absent.');
            self::assertArrayNotHasKey('error', $panel, $id . ' came back as an error.');
        }
    }

    /**
     * The two variants exist because each major bundles a different vega-lite.
     * What is stored has to be the one this major can read — the import itself
     * cannot tell, since the specification is an opaque string to it.
     */
    public function testTheStoredSpecificationNamesTheSchemaThisMajorBundles(): void
    {
        $this->import($this->variant());

        $expected = $this->major === '2'
            ? 'https://vega.github.io/schema/vega-lite/v4.json'
            : 'https://vega.github.io/schema/vega-lite/v6.json';

        foreach (['os-query-digest-what-regressed', 'os-query-digest-new-shapes'] as $panel) {
            $stored = $this->get('/api/saved_objects/visualization/' . $panel);

            $attributes = $stored['attributes'] ?? null;
            self::assertIsArray($attributes);
            $visState = $attributes['visState'] ?? null;
            self::assertIsString($visState);

            self::assertStringContainsString($expected, $visState, $panel . ' carries the other major\'s schema.');
        }
    }

    private function variant(): string
    {
        $path = self::PACK . '/os-query-digest-opensearch-' . $this->major . '.x.ndjson';
        self::assertFileExists($path, 'No pack for Dashboards ' . $this->major . '.x');

        return $path;
    }

    /**
     * @return array<mixed>
     */
    private function import(string $file): array
    {
        $boundary = '----os-query-digest';
        $body = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="file"; filename="' . basename($file) . "\"\r\n"
            . "Content-Type: application/ndjson\r\n\r\n"
            . file_get_contents($file) . "\r\n"
            . '--' . $boundary . "--\r\n";

        $response = $this->send(
            'POST',
            '/api/saved_objects/_import?overwrite=true',
            $body,
            'multipart/form-data; boundary=' . $boundary,
        );

        self::assertIsArray($response, 'The import returned no JSON.');

        return $response;
    }

    /**
     * @return array<mixed>
     */
    private function get(string $path): array
    {
        $response = $this->send('GET', $path, null, 'application/json');
        self::assertIsArray($response, $path . ' returned no JSON.');

        return $response;
    }

    /**
     * @return array<mixed>|null
     */
    private function send(string $method, string $path, ?string $body, string $type): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            // osd-xsrf is required on every write, and harmless on a read.
            'header' => 'Content-Type: ' . $type . "\r\nosd-xsrf: true\r\n",
            'content' => $body ?? '',
            'timeout' => 120,
            'ignore_errors' => true,
        ]]);

        $raw = @file_get_contents($this->url . $path, false, $context);
        if ($raw === false) {
            self::fail('No Dashboards answering at ' . $this->url . $path);
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
