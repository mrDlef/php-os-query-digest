<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Re-checks `resources/versions.json` against a live cluster.
 *
 * `tools/certify.php` *writes* the matrix; this *verifies* it, which is what
 * makes it a regression guard rather than a snapshot of one afternoon. Point it
 * at a node and it fails if that version stopped accepting something the file
 * says it accepts.
 *
 *     OPENSEARCH_URL=http://localhost:9202 vendor/bin/phpunit --testsuite=integration
 *
 * It lives in its own suite and is skipped without `OPENSEARCH_URL`: the
 * default suite is offline by design and must stay runnable with nothing but
 * PHP.
 */
final class ServerAcceptanceTest extends TestCase
{
    private const INDEX = 'os-query-digest-integration';

    private string $url = '';

    private string $version = '';

    protected function setUp(): void
    {
        $url = getenv('OPENSEARCH_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set OPENSEARCH_URL to run against a cluster.');
        }

        $this->url = rtrim($url, '/');

        $root = $this->request('GET', '/');
        self::assertSame(200, $root['status'], 'No cluster answering at ' . $this->url);

        $version = self::dig($root['body'], ['version', 'number']);
        self::assertIsString($version, 'The cluster did not report a version.');
        $this->version = $version;

        $this->request('DELETE', '/' . self::INDEX);
        $created = $this->request('PUT', '/' . self::INDEX, self::section('probes.json', 'index'));
        self::assertSame(200, $created['status'], 'Could not create the probe index.');
    }

    protected function tearDown(): void
    {
        if ($this->url !== '') {
            $this->request('DELETE', '/' . self::INDEX);
        }
    }

    /**
     * The major line under test has to be one the matrix covers, otherwise the
     * run proves nothing about the file it is meant to guard.
     *
     * Matched by major rather than by exact version on purpose. The scheduled
     * job pulls the floating `:2` and `:3` tags, so it drifts onto a new patch
     * whenever upstream cuts one — failing on that would mean a red build every
     * few weeks for a release that changed nothing about the query DSL, which is
     * how people learn to ignore a job. A query type appearing or vanishing
     * still fails, and that is the thing worth waking up for.
     */
    public function testTheClusterMajorIsCertified(): void
    {
        self::assertNotSame(
            '',
            $this->certifiedVersion(),
            'This cluster runs ' . $this->version . ' and the matrix covers no '
            . self::major($this->version) . '.x version at all. Run `make certify`.',
        );
    }

    /**
     * Every verdict recorded for this major line, replayed. An `accepted` that
     * stopped being accepted is a regression in the library's claims; an
     * `unsupported` that now works means OpenSearch grew the query type and the
     * matrix is stale.
     */
    public function testEveryRecordedVerdictStillHolds(): void
    {
        $certified = $this->certifiedVersion();
        self::assertNotSame('', $certified, 'Nothing certified for this major.');

        $probes = self::section('probes.json', 'queries');
        $drift = [];

        foreach (self::section('versions.json', 'results') as $type => $verdicts) {
            if (!is_array($verdicts) || !isset($verdicts[$certified]) || !isset($probes[$type])) {
                continue;
            }

            $response = $this->request('POST', '/' . self::INDEX . '/_search', [
                'query' => $probes[$type],
                'size' => 0,
            ]);

            $expected = $verdicts[$certified];
            $actual = $response['status'] === 200 ? 'accepted' : 'unsupported';

            if ($actual !== $expected) {
                $drift[] = sprintf('%s: recorded %s, cluster says %s', $type, self::str($expected), $actual);
            }
        }

        self::assertSame(
            [],
            $drift,
            "OpenSearch {$this->version} disagrees with what was certified against {$certified}:\n  "
            . implode("\n  ", $drift) . "\nRun `make certify` and review the diff.",
        );
    }

    /**
     * The certified version this cluster is compared against: the newest one in
     * the matrix sharing its major line, or '' when the major is uncovered.
     */
    private function certifiedVersion(): string
    {
        $candidates = [];

        foreach (self::section('versions.json', 'clusters') as $cluster) {
            $version = is_array($cluster) ? self::str($cluster['version'] ?? null) : '';
            if ($version !== '' && self::major($version) === self::major($this->version)) {
                $candidates[] = $version;
            }
        }

        if ($candidates === []) {
            return '';
        }

        usort($candidates, static fn(string $a, string $b): int => version_compare($a, $b));

        return end($candidates);
    }

    private static function major(string $version): string
    {
        return explode('.', $version)[0];
    }

    /**
     * @param non-empty-string  $method
     * @param array<mixed>|null $body
     *
     * @return array{status:int,body:array<mixed>|null}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $handle = curl_init($this->url . $path);
        self::assertNotFalse($handle, 'Could not open a connection to ' . $this->url);

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_TIMEOUT, 30);
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, self::encode($body));
        }

        $raw = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
        ];
    }

    /**
     * json_encode, with empty arrays restored to empty objects — the same trade
     * `tools/certify.php` makes, and for the same reason: decoded into arrays,
     * `{"match_all": {}}` re-encodes as `{"match_all": []}`, which OpenSearch
     * rejects as malformed instead of running.
     *
     * @param array<mixed> $body
     *
     * @return non-empty-string
     */
    private static function encode(array $body): string
    {
        $json = json_encode(self::objectifyEmpty($body));

        self::assertIsString($json, 'A probe body could not be encoded.');

        return $json;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function objectifyEmpty($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return new \stdClass();
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::objectifyEmpty($item);
        }

        return $out;
    }

    /**
     * One top-level section of a resource file, keyed by name.
     *
     * @return array<string,mixed>
     */
    private static function section(string $file, string $key): array
    {
        $contents = file_get_contents(__DIR__ . '/../../resources/' . $file);
        self::assertIsString($contents, 'Unreadable: ' . $file);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded, 'Invalid JSON: ' . $file);
        self::assertArrayHasKey($key, $decoded, $file . ' has no "' . $key . '" section.');
        self::assertIsArray($decoded[$key]);

        $out = [];
        foreach ($decoded[$key] as $name => $value) {
            $out[(string) $name] = $value;
        }

        return $out;
    }

    /**
     * @param mixed                 $node
     * @param array<int,string|int> $path
     *
     * @return mixed
     */
    private static function dig($node, array $path)
    {
        foreach ($path as $step) {
            if (!is_array($node) || !array_key_exists($step, $node)) {
                return null;
            }
            $node = $node[$step];
        }

        return $node;
    }

    /**
     * @param mixed $value
     */
    private static function str($value): string
    {
        return is_string($value) ? $value : '';
    }
}
