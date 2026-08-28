<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Analysis\Report;
use MrDlef\OsQueryDigest\Analysis\Shape;
use MrDlef\OsQueryDigest\Exception\InvalidOptionException;
use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * The public aggregator, fed the way an application feeds it: with digests it
 * made itself, not with a slow log.
 *
 * @internal
 */
final class ReportTest extends TestCase
{
    /** @var array<string,string> label => request */
    private const SEARCHES = [
        'browse' => '{"query":{"match":{"title":"boots"}},"size":20}',
        'lookup' => '{"query":{"ids":{"values":["a1"]}}}',
        'panel' => '{"query":{"term":{"shop":"fr"}},"aggs":{"per_brand":{"terms":{"field":"brand"}}},"size":0}',
    ];

    public function testSearchesThatShareAFingerprintShareAShape(): void
    {
        $report = $this->report(['browse' => [10.0, 20.0, 30.0]]);

        self::assertSame(3, $report->records(), 'Three searches went in.');
        self::assertSame(1, $report->count(), 'They are one shape.');
        self::assertSame(60.0, $report->total());

        $shape = $report->rank()[0];
        self::assertSame(3, $shape->count());
        self::assertSame(20.0, $shape->mean());
    }

    /**
     * The two values that are counted separately, because a report answering
     * "how many searches" with "how many shapes" is the mistake the whole thing
     * exists to prevent.
     */
    public function testRecordsAndShapesAreCountedApart(): void
    {
        $report = $this->report([
            'browse' => [10.0, 10.0],
            'lookup' => [1.0],
            'panel' => [5.0],
        ]);

        self::assertSame(4, $report->records());
        self::assertSame(3, $report->count());
    }

    public function testEachKeyRanksByItsOwnNumber(): void
    {
        // `browse` costs the most in total; `lookup` is the slowest one record.
        $report = $this->report([
            'browse' => [40.0, 40.0, 40.0],
            'lookup' => [100.0],
        ]);

        self::assertSame('browse', $this->labelOf($report->rank(Report::TOTAL)[0]->signature()));
        self::assertSame('browse', $this->labelOf($report->rank(Report::COUNT)[0]->signature()));
        self::assertSame('lookup', $this->labelOf($report->rank(Report::MAX)[0]->signature()));
        self::assertSame('lookup', $this->labelOf($report->rank(Report::MEAN)[0]->signature()));
        self::assertSame('lookup', $this->labelOf($report->rank(Report::P95)[0]->signature()));
    }

    /**
     * A report you cannot diff cannot say what a deploy changed, so equal costs
     * must not rank in whichever order the stream happened to arrive in. Equal
     * cost *and* equal count fall back to the fingerprint, ascending.
     */
    public function testEqualShapesRankByFingerprintWhicheverOrderTheyArrivedIn(): void
    {
        $forwards = $this->report(['browse' => [10.0], 'lookup' => [10.0], 'panel' => [10.0]]);
        $backwards = $this->report(['panel' => [10.0], 'lookup' => [10.0], 'browse' => [10.0]]);

        $hashes = array_map(static fn(Shape $shape): string => $shape->hash(), $forwards->rank());

        self::assertSame(
            $hashes,
            array_map(static fn(Shape $shape): string => $shape->hash(), $backwards->rank()),
        );

        $sorted = $hashes;
        sort($sorted);
        self::assertSame($sorted, $hashes, 'The last tie-break is the fingerprint, ascending.');
    }

    /**
     * p95 and max are different questions above twenty records, and ranking by
     * one must not quietly answer the other: the shape with the worst outlier
     * is not the shape that is slow.
     */
    public function testRankingByP95IsNotRankingByMax(): void
    {
        $steady = array_fill(0, 21, 50.0);
        $spiky = array_merge(array_fill(0, 20, 10.0), [1000.0]);

        $report = $this->report(['browse' => $spiky, 'lookup' => $steady]);

        self::assertSame('lookup', $this->labelOf($report->rank(Report::P95)[0]->signature()));
        self::assertSame('browse', $this->labelOf($report->rank(Report::MAX)[0]->signature()));
    }

    /**
     * A shape nobody timed ranks last on every duration key, including against
     * a shape whose mean is under a millisecond. Sorting it as if it cost
     * something would put the one thing the report knows nothing about at the
     * top of a report about cost.
     */
    public function testAShapeWithNoDurationRanksBelowOneThatIsMerelyFast(): void
    {
        $report = new Report();
        $formatter = Formatter::create();

        $fast = $formatter->describe(self::SEARCHES['browse'], 'catalog');
        $report->record($fast, 0.5);

        $untimed = $formatter->describe(self::SEARCHES['lookup'], 'catalog');
        $report->record($untimed);

        foreach ([Report::MEAN, Report::P95, Report::MAX, Report::TOTAL] as $key) {
            self::assertSame(
                $fast->hash(),
                $report->rank($key)[0]->hash(),
                'Ranked by ' . $key . ', a shape with no duration came first.',
            );
        }
    }

    public function testTopIsTheWorstFewAndNeverAskedForNegativelyMany(): void
    {
        $report = $this->report(['browse' => [40.0], 'lookup' => [30.0], 'panel' => [20.0]]);

        self::assertCount(2, $report->top(2));
        self::assertSame($report->rank()[0]->hash(), $report->top(2)[0]->hash());
        self::assertSame([], $report->top(0));
        self::assertSame([], $report->top(-5));
        self::assertCount(3, $report->top(99), 'Asking for more than there are gives what there is.');
    }

    public function testAnUnknownKeyIsRefusedRatherThanRankedByTheDefault(): void
    {
        $report = $this->report(['browse' => [1.0]]);

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('total, count, p95, max, mean');

        $report->rank('slowest');
    }

    public function testSerialisingAReportIsRankingIt(): void
    {
        $report = $this->report(['browse' => [40.0], 'lookup' => [30.0]]);

        $encoded = json_encode($report);
        self::assertIsString($encoded);

        $decoded = json_decode($encoded, true);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);

        $worst = $decoded[0];
        self::assertIsArray($worst);
        self::assertSame($report->rank()[0]->hash(), $worst['hash']);
        self::assertSame('browse', $worst['kind'], 'A shape carries the kind of work its searches are.');
    }

    public function testAShapeIsReachableByItsFingerprint(): void
    {
        $report = $this->report(['browse' => [1.0]]);
        $hash = $report->rank()[0]->hash();

        self::assertNotNull($report->shape($hash));
        self::assertSame($hash, $report->shape($hash)->hash());
        self::assertNull($report->shape('q5:000000000000'), 'A fingerprint nothing recorded is not a shape.');
    }

    /**
     * A stream may carry no durations at all — an application logging digests
     * without `took`. That is a report with counts and no percentiles, not a
     * report of zeros, which would read as "fast".
     */
    public function testASearchWithNoDurationIsStillCounted(): void
    {
        $report = new Report();
        $digest = Formatter::create()->describe(self::SEARCHES['browse'], 'catalog');

        $report->record($digest);
        $report->record($digest);

        $shape = $report->rank()[0];

        self::assertSame(2, $shape->count());
        self::assertSame(0, $shape->measured());
        self::assertNull($shape->mean());
        self::assertNull($shape->p95());
        self::assertNull($shape->max());
        self::assertSame(0.0, $report->total());
    }

    /**
     * @param array<string,array<int,float>> $durations label => the cost of each search of that shape
     */
    private function report(array $durations): Report
    {
        $formatter = Formatter::create();
        $report = new Report();

        foreach ($durations as $label => $costs) {
            $digest = $formatter->describe(self::SEARCHES[$label], 'catalog');

            foreach ($costs as $cost) {
                $report->record($digest, $cost);
            }
        }

        return $report;
    }

    /** Which of the three searches a shape is, read back off its signature. */
    private function labelOf(string $signature): string
    {
        foreach (self::SEARCHES as $label => $request) {
            if (Formatter::create()->describe($request, 'catalog')->signature() === $signature) {
                return $label;
            }
        }

        return 'unknown';
    }
}
