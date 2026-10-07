<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * A faceted page sends one aggregation per facet, and a rendered aggregation is
 * the most expensive thing on the line — 78 characters at the median against a
 * clause's 54, on a day of real traffic. A dozen facets is the whole line, and
 * the filters that say what the page was asking for never fit in the budget.
 *
 * So the sibling list is capped like a field list is, at every level rather
 * than only the outer one: the facets of a facet are a list too, and a cap the
 * children escape is a cap on nothing in particular.
 *
 * Like every display limit, the hash never sees it.
 */
final class AggListTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function facets(): array
    {
        return ['size' => 0, 'aggs' => [
            'a' => ['terms' => ['field' => 'brand', 'size' => 20], 'aggs' => [
                'label' => ['terms' => ['field' => 'brand_label', 'size' => 1]],
                'high' => ['max' => ['field' => 'price']],
                'low' => ['min' => ['field' => 'price']],
                'mean' => ['avg' => ['field' => 'price']],
            ]],
            'b' => ['terms' => ['field' => 'colour', 'size' => 20]],
            'c' => ['terms' => ['field' => 'country', 'size' => 20]],
            'd' => ['terms' => ['field' => 'material', 'size' => 20]],
            'e' => ['terms' => ['field' => 'size_label', 'size' => 20]],
        ]];
    }

    private static function signature(?int $maxAggs): string
    {
        return Formatter::create(
            Options::create()->withMaxAggs($maxAggs)->withMaxLength(null),
        )->describe(self::facets())->signature();
    }

    public function testTheListIsSummarisedAfterTheCap(): void
    {
        self::assertSame(
            'aggs=terms(brand,20)>{avg(price), max(price), min(price), +1 more},'
            . ' terms(colour,20), terms(country,20), +2 more | size=0',
            self::signature(3),
        );
    }

    /**
     * The children are a sibling list of their own, and the one that overflows
     * first on a facet page: each facet asks for its own label and its own
     * extremes. Capping the outer list alone would keep the line long for
     * exactly the request the cap exists for.
     */
    public function testTheCapReachesTheChildrenToo(): void
    {
        self::assertSame(
            'aggs=terms(brand,20)>{avg(price), +3 more}, +4 more | size=0',
            self::signature(1),
        );
    }

    public function testAShortListIsLeftAlone(): void
    {
        $search = ['size' => 0, 'aggs' => [
            'a' => ['terms' => ['field' => 'brand', 'size' => 20]],
            'b' => ['terms' => ['field' => 'colour', 'size' => 20]],
        ]];

        self::assertSame(
            'aggs=terms(brand,20), terms(colour,20) | size=0',
            Formatter::create()->describe($search)->signature(),
        );
    }

    /**
     * A single aggregation with a single child is the shape most searches
     * actually send — `terms(host,10)>p95(latency_ms)` — and it carries no
     * list, so no cap and no braces.
     */
    public function testAPipelineOfOneIsNotAList(): void
    {
        $search = ['size' => 0, 'aggs' => ['by_host' => [
            'terms' => ['field' => 'host', 'size' => 10],
            'aggs' => ['slow' => ['percentiles' => ['field' => 'latency_ms']]],
        ]]];

        self::assertSame(
            'aggs=terms(host,10)>percentiles(latency_ms) | size=0',
            Formatter::create()->describe($search)->signature(),
        );
    }

    /**
     * Zero is not "no cap" — it is a list of none, summarised whole, which is
     * how many a request sent still reaches the line. `null` is what lifts a
     * limit here, as everywhere else on `Options`.
     */
    public function testACapOfZeroKeepsOnlyTheCount(): void
    {
        self::assertSame('aggs=+5 more | size=0', self::signature(0));
    }

    public function testTheCapNeverMovesTheHash(): void
    {
        $formatter = static fn(?int $max): Formatter => Formatter::create(
            Options::create()->withMaxAggs($max),
        );

        $capped = $formatter(null)->describe(self::facets());

        foreach ([null, 5, 3, 1, 0] as $max) {
            self::assertSame(
                $capped->hash(),
                $formatter($max)->describe(self::facets())->hash(),
                'A cap of ' . var_export($max, true) . ' moved the hash.',
            );
        }
    }

    /**
     * What the cap hides still has to tell two pages apart, or the fingerprint
     * would inherit the ambiguity of the line.
     */
    public function testTwoPagesDifferingOnlyPastTheCapStayDistinct(): void
    {
        $formatter = Formatter::create();

        $facets = self::facets();
        $other = self::facets();
        $aggs = $other['aggs'];
        self::assertIsArray($aggs);
        $aggs['e'] = ['terms' => ['field' => 'warehouse', 'size' => 20]];
        $other['aggs'] = $aggs;

        $first = $formatter->describe($facets);
        $second = $formatter->describe($other);

        self::assertSame($first->signature(), $second->signature());
        self::assertNotSame($first->hash(), $second->hash());
    }

    public function testTheCapCanBeLifted(): void
    {
        self::assertSame(
            'aggs=terms(brand,20)>{avg(price), max(price), min(price), terms(brand_label,1)},'
            . ' terms(colour,20), terms(country,20), terms(material,20), terms(size_label,20) | size=0',
            self::signature(null),
        );
    }
}
