<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Analysis\Report;
use MrDlef\OsQueryDigest\Analysis\Tenant;
use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * The second axis a multi-tenant deployment reads a report along.
 *
 * Ranked by fingerprint alone, a shape every customer plays and a shape one
 * customer plays look the same: same count, same total, same row. So does the
 * customer running one expensive report and the customer running a hundred
 * pages. The name on each record is what tells them apart, and the library
 * mints none of it — it is read beside the fingerprint and counted.
 *
 * @internal
 */
final class TenantAxisTest extends TestCase
{
    private const EVERYONE = '{"query":{"term":{"shop":"fr"}},"aggs":{"per_brand":{"terms":{"field":"brand"}}},"size":0}';

    private const ONE_OF_THEM = '{"query":{"match":{"title":"boots"}},"size":20}';

    /**
     * @param array<string,array<int,array{0:string,1:float}>> $plays request => [tenant, ms][]
     */
    private static function report(array $plays): Report
    {
        $formatter = Formatter::create();
        $report = new Report();

        foreach ($plays as $request => $records) {
            $digest = $formatter->describe($request, 'catalog');

            foreach ($records as [$tenant, $millis]) {
                $report->record($digest, $millis, null, $tenant);
            }
        }

        return $report;
    }

    public function testAShapeCountsTheDistinctTenantsThatPlayedIt(): void
    {
        $report = self::report([
            self::EVERYONE => [['acme', 10.0], ['globex', 10.0], ['initech', 10.0], ['acme', 10.0]],
            self::ONE_OF_THEM => [['globex', 50.0], ['globex', 50.0]],
        ]);

        $shapes = [];
        foreach ($report->rank(Report::TENANTS) as $shape) {
            $shapes[] = [$shape->count(), $shape->tenants()];
        }

        self::assertSame([[4, 3], [2, 1]], $shapes);
    }

    /**
     * Ranking by tenants is the question "what is everybody doing", which is
     * not the question "what is slow" — here the shape every tenant plays is
     * the cheaper of the two, and both orders are right about their own key.
     */
    public function testRankingByTenantsIsNotRankingByTime(): void
    {
        $report = self::report([
            self::EVERYONE => [['acme', 10.0], ['globex', 10.0], ['initech', 10.0]],
            self::ONE_OF_THEM => [['globex', 500.0]],
        ]);

        self::assertSame(3, $report->rank(Report::TENANTS)[0]->tenants());
        self::assertSame(1, $report->rank(Report::TOTAL)[0]->tenants());
    }

    /**
     * Costliest first, whatever order the records arrived in — the cheapest
     * tenant here is the first one seen, so a ranking that was really insertion
     * order would pass every other assertion in this class.
     */
    public function testTenantsAreRankedByWhatTheyCost(): void
    {
        $report = self::report([
            self::EVERYONE => [['initech', 1.0], ['globex', 200.0], ['acme', 10.0]],
            self::ONE_OF_THEM => [['globex', 20.0], ['acme', 500.0]],
        ]);

        $ranked = [];
        foreach ($report->tenants() as $tenant) {
            $ranked[] = [$tenant->name(), $tenant->count(), $tenant->shapes(), $tenant->total()];
        }

        self::assertSame([
            ['acme', 2, 2, 510.0],
            ['globex', 2, 2, 220.0],
            ['initech', 1, 1, 1.0],
        ], $ranked);
    }

    /**
     * Two tenants that cost the same are ordered by calls and then by name, so
     * two runs over one stream rank identically. A report you cannot diff
     * cannot say what a deploy changed.
     */
    public function testTenantsCostingTheSameAreOrderedTheSameWayTwice(): void
    {
        $report = self::report([
            self::EVERYONE => [['zulu', 50.0], ['alpha', 25.0], ['mike', 50.0]],
            self::ONE_OF_THEM => [['alpha', 25.0]],
        ]);

        $names = [];
        foreach ($report->tenants() as $tenant) {
            $names[] = $tenant->name();
        }

        // All three total 50; alpha took two calls to get there, so it leads,
        // and the two that tie on both are ordered by name.
        self::assertSame(['alpha', 'mike', 'zulu'], $names);
    }

    /**
     * The number the whole axis exists for: one customer spending everything on
     * a single shape is not the same animal as one spreading it over many, and
     * before this they were one row of one table.
     */
    public function testATenantKnowsHowManyShapesItPlayed(): void
    {
        $report = self::report([
            self::EVERYONE => [['acme', 100.0], ['acme', 100.0], ['globex', 100.0]],
            self::ONE_OF_THEM => [['globex', 100.0]],
        ]);

        $shapes = [];
        foreach ($report->tenants() as $tenant) {
            $shapes[$tenant->name()] = [$tenant->count(), $tenant->shapes()];
        }

        self::assertSame(['acme' => [2, 1], 'globex' => [2, 2]], $shapes);
    }

    /**
     * The default stream carries no name — this library never writes one — so
     * the axis is simply absent rather than a column of zeroes or a tenant
     * called "".
     */
    public function testAStreamWithoutNamesHasNoTenants(): void
    {
        $report = new Report();
        $digest = Formatter::create()->describe(self::EVERYONE, 'catalog');

        $report->record($digest, 10.0);
        $report->record($digest, 10.0, null, '');

        self::assertSame([], $report->tenants());
        self::assertSame(0, $report->rank()[0]->tenants());
        self::assertSame(2, $report->rank()[0]->count(), 'The records still count.');
    }

    public function testATenantIsCountedWithoutADuration(): void
    {
        $report = new Report();
        $digest = Formatter::create()->describe(self::EVERYONE, 'catalog');

        $report->record($digest, null, null, 'acme');

        $tenant = $report->tenants()[0];
        self::assertSame(1, $tenant->count());
        self::assertSame(0, $tenant->measured());
        self::assertSame(0.0, $tenant->total());
        self::assertNull($tenant->mean());
    }

    public function testATenantSerialisesWithoutAnyLiteral(): void
    {
        $tenant = new Tenant('acme');
        $tenant->record('q6:abcdef123456', 10.0007);
        $tenant->record('q6:abcdef123456', 30.0);
        $tenant->record('q6:0123456789ab');

        // Three decimals, the same as a shape's: a cluster reports whole
        // milliseconds, and neither a sum nor a mean of them is more precise
        // than a thousandth.
        self::assertSame([
            'tenant' => 'acme',
            'count' => 3,
            'measured' => 2,
            'shapes' => 2,
            'total_ms' => 40.001,
            'mean_ms' => 20.0,
        ], $tenant->jsonSerialize());

        $tenant->record('q6:abcdef123456', 1.0);
        $encoded = $tenant->jsonSerialize();
        self::assertSame(41.001, $encoded['total_ms']);
        self::assertSame(13.667, $encoded['mean_ms']);
    }
}
