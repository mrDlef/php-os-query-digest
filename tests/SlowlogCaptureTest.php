<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Cli\Command;
use PHPUnit\Framework\TestCase;

/**
 * The slow log reader, against records real nodes wrote.
 *
 * `SlowlogTest` builds its input from what the appenders are documented to
 * emit, which is only ever as right as the person who wrote it. The four files
 * under `tests/slowlog/` were captured instead: an index created with
 * `index.search.slowlog.threshold.query.warn: 0ms`, a handful of searches, and
 * whatever OpenSearch 2.19.6 and 3.8.0 then wrote through the plain appender
 * and through a JSON one — verbatim, node name and all.
 *
 * Two things only a real node showed:
 *
 * - **OpenSearch 3 escapes the body twice** in the JSON layout, from the same
 *   configuration file 2.19.6 escapes it once with. Every JSON record from 3.8.0
 *   was unreadable before it was handled.
 * - **The body in a record is the query the shard ran, not the one the client
 *   sent** — rewritten, `boost` and `adjust_pure_negative` added, a range
 *   matching nothing collapsed to `match_none`. So these fingerprints are not
 *   the ones the application would log for the same request, and the pinned
 *   hashes below are the rewritten shapes.
 *
 * The files are offline, committed and deterministic, like every other fixture
 * here; `make certify` is where live nodes belong.
 */
final class SlowlogCaptureTest extends TestCase
{
    /** Every version and appender must agree on these, or the reader is guessing. */
    private const SHAPES = [
        'q3:3d2f59f81444' => 'logs-* | q=(none) | size=50 sort=@timestamp:desc',
        'q3:6b6fb17c6640' => 'orders-* | q=(sku:(? or ? or ?)) | aggs=date_histogram(created,day)',
        'q3:8203e75719e5' => 'logs-* | q=(not status:? and range(?) and service:?) | size=50 sort=@timestamp:desc',
        'q3:c2c79d39a171' => 'logs-* | q=(note:~?) | size=10',
    ];

    /**
     * @return array<int,string>
     */
    private static function captures(): array
    {
        $files = glob(__DIR__ . '/slowlog/*');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'The captured slow logs are missing.');

        return $files;
    }

    public function testEveryCaptureReadsAsTheSameFourShapes(): void
    {
        foreach (self::captures() as $file) {
            $shapes = $this->ranking([$file]);

            self::assertSame(
                array_keys(self::SHAPES),
                array_keys($shapes),
                basename($file) . ' does not read as the shapes the others do.',
            );

            foreach (self::SHAPES as $hash => $signature) {
                self::assertSame($signature, $shapes[$hash]['sig'] ?? null, $hash . ' in ' . basename($file));
                self::assertSame(1, $shapes[$hash]['count'] ?? null, $hash . ' in ' . basename($file));
            }
        }
    }

    /**
     * The point of reading both: a fingerprint that depended on which appender
     * was configured would be worthless for comparing one cluster with another.
     */
    public function testThePlainAndJsonAppendersOfOneVersionAgree(): void
    {
        foreach (['2.19.6', '3.8.0'] as $version) {
            $plain = $this->ranking([__DIR__ . '/slowlog/opensearch-' . $version . '.log']);
            $json = $this->ranking([__DIR__ . '/slowlog/opensearch-' . $version . '.json']);

            self::assertSame($plain, $json, 'The two appenders of ' . $version . ' disagree.');
        }
    }

    /**
     * A search is logged once per phase, both records carrying the same body.
     * The default reads the query phase, so `count` is searches; `--phase=both`
     * counts the fetch record too, and the two shapes that have one show it.
     */
    public function testTheFetchRecordsAreThereButNotCountedTwice(): void
    {
        $file = __DIR__ . '/slowlog/opensearch-2.19.6.log';

        $query = $this->ranking([$file]);
        $both = $this->ranking([$file], ['--phase=both']);
        $fetch = $this->ranking([$file], ['--phase=fetch']);

        self::assertSame(1, $query['q3:3d2f59f81444']['count'] ?? null);
        self::assertSame(2, $both['q3:3d2f59f81444']['count'] ?? null);
        self::assertSame(1, $fetch['q3:3d2f59f81444']['count'] ?? null);

        // Only two of the four searches were slow enough to log a fetch record.
        self::assertSame(['q3:3d2f59f81444', 'q3:8203e75719e5'], array_keys($fetch));
    }

    public function testTheTableSaysHowManyRecordsTheOtherPhaseHeld(): void
    {
        [$status, $out, $err] = $this->invoke([__DIR__ . '/slowlog/opensearch-3.8.0.json']);

        self::assertSame(Command::OK, $status, $err);
        self::assertStringContainsString('6 lines, 4 records', $out);
        self::assertStringContainsString('2 records from another phase, see --phase', $out);
    }

    /**
     * The ranking as a hash-keyed map, sorted by hash so two files can be
     * compared without the ordering getting in the way.
     *
     * @param array<int,string> $files
     * @param array<int,string> $options
     *
     * @return array<string,array<mixed>>
     */
    private function ranking(array $files, array $options = []): array
    {
        [$status, $out, $err] = $this->invoke(array_merge(['--json'], $options, $files));
        self::assertSame(Command::OK, $status, $err);

        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);

        $shapes = [];
        foreach ($decoded as $shape) {
            self::assertIsArray($shape);
            $hash = $shape['hash'] ?? null;
            self::assertIsString($hash);
            $shapes[$hash] = ['sig' => $shape['sig'] ?? null, 'count' => $shape['count'] ?? null];
        }

        ksort($shapes);

        return $shapes;
    }

    /**
     * @param array<int,string> $args
     *
     * @return array{0:int,1:string,2:string}
     */
    private function invoke(array $args): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);

        $argv = array_merge(['os-query-digest', 'slowlog'], $args);
        $status = (new Command($in, $out, $err))->run($argv);

        rewind($out);
        rewind($err);

        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
