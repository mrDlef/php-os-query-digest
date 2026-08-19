<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * Guards `resources/versions.json` — the record of which OpenSearch versions
 * were observed to accept the queries this library renders.
 *
 * Offline and deterministic, like {@see SpecCoverageTest}: it reads the
 * committed artefact rather than talking to a cluster. Producing that artefact
 * is `make certify`, which needs real nodes; running it in the unit suite would
 * make the suite need Docker and a network to say anything at all.
 *
 * What this test is for: making it impossible to claim a version is certified
 * without checking. Every query type is either probed against a live cluster or
 * carries a written reason why it cannot be.
 */
final class CertificationTest extends TestCase
{
    public function testEveryQueryTypeIsEitherProbedOrExplained(): void
    {
        $declared = array_keys(self::coverage()['query']);
        $matrix = self::matrix();

        $accounted = array_merge(array_keys($matrix['results']), array_keys($matrix['unprobed']));

        sort($declared);
        sort($accounted);

        self::assertSame(
            $declared,
            $accounted,
            'resources/versions.json and resources/coverage.json disagree. Run `make certify`, '
            . 'and give any type that cannot be probed a reason under "unprobed".',
        );
    }

    /**
     * A probe body has to exist for everything the matrix reports on, otherwise
     * the next `make certify` would silently stop covering it.
     */
    public function testEveryProbedTypeStillHasAProbe(): void
    {
        $probes = array_keys(self::probes()['queries']);
        $reported = array_keys(self::matrix()['results']);

        sort($probes);
        sort($reported);

        self::assertSame($probes, $reported);
    }

    /**
     * The point of the whole exercise: a type we render natively must be one a
     * real cluster accepts. If no version does, we are rendering a query nobody
     * can run — the spec listed it, but nothing implements it.
     */
    public function testEveryNativeTypeIsAcceptedBySomeCertifiedVersion(): void
    {
        $matrix = self::matrix();
        $orphans = [];

        foreach (self::coverage()['query'] as $type => $stance) {
            if ($stance !== 'native' || !isset($matrix['results'][$type])) {
                continue;
            }

            if (!in_array('accepted', $matrix['results'][$type], true)) {
                $orphans[] = $type;
            }
        }

        self::assertSame(
            [],
            $orphans,
            'Rendered natively but rejected by every certified version: ' . implode(', ', $orphans),
        );
    }

    /**
     * The probes are real DSL, which makes them a harder test of the parser
     * than {@see SpecCoverageTest}'s minimal bodies: those only have to reach a
     * branch, these are what a cluster actually accepted. A native type whose
     * real-world body falls through to `type(?)` is a native type in name only.
     */
    public function testTheRealProbesRenderNativelyToo(): void
    {
        $formatter = Formatter::create();
        $opaque = [];

        foreach (self::probes()['queries'] as $type => $query) {
            if ((self::coverage()['query'][$type] ?? null) !== 'native') {
                continue;
            }

            if (strpos($formatter->describe(['query' => $query])->text(), $type . '(?)') !== false) {
                $opaque[] = $type;
            }
        }

        self::assertSame([], $opaque, 'Declared native but opaque on a real body: ' . implode(', ', $opaque));
    }

    /**
     * An unprobed type must say why in a sentence, not with an empty string —
     * "we did not get to it" and "it cannot be probed" are different answers and
     * the file has to distinguish them.
     */
    public function testEveryUnprobedTypeCarriesAReason(): void
    {
        foreach (self::matrix()['unprobed'] as $type => $reason) {
            self::assertGreaterThan(
                20,
                strlen($reason),
                $type . ' is listed as unprobed without a usable reason.',
            );
        }
    }

    public function testAtLeastOneVersionOfEachSupportedMajorIsCertified(): void
    {
        $majors = [];
        foreach (self::matrix()['clusters'] as $cluster) {
            $majors[] = explode('.', $cluster['version'])[0];
        }

        self::assertContains('2', $majors, 'No OpenSearch 2.x in the matrix.');
        self::assertContains('3', $majors, 'No OpenSearch 3.x in the matrix.');
    }

    /**
     * @return array{clusters:array<int,array{distribution:string,version:string}>,results:array<string,array<string,string>>,unprobed:array<string,string>}
     */
    private static function matrix(): array
    {
        /** @var array{clusters:array<int,array{distribution:string,version:string}>,results:array<string,array<string,string>>,unprobed:array<string,string>} $data */
        $data = self::readJson(__DIR__ . '/../resources/versions.json');

        return $data;
    }

    /**
     * @return array{queries:array<string,mixed>,unprobed:array<string,string>}
     */
    private static function probes(): array
    {
        /** @var array{queries:array<string,mixed>,unprobed:array<string,string>} $data */
        $data = self::readJson(__DIR__ . '/../resources/probes.json');

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
