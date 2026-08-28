<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Analysis\Shape;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic, now that it is a promise rather than a detail of the CLI.
 *
 * @internal
 */
final class ShapeTest extends TestCase
{
    private const REQUEST = '{"query":{"term":{"email":"ada@example.com"}},"size":20}';

    /**
     * Nearest rank, and it is pinned because publishing a percentile is
     * publishing its definition: over twenty records the 95th is the nineteenth
     * smallest, and no interpolation invents a value between two samples.
     */
    public function testTheP95IsTheNearestRankAndNotAnInterpolation(): void
    {
        $shape = $this->shape(range(1, 20));

        self::assertSame(19.0, $shape->p95());
        self::assertSame(20.0, $shape->max());
        self::assertSame(10.5, $shape->mean());
        self::assertSame(210.0, $shape->total());
    }

    /**
     * On a handful of records the rank lands on the maximum, which is the
     * honest answer: five samples do not know what their 95th percentile is.
     */
    public function testASmallShapeHasItsMaximumForAP95(): void
    {
        $shape = $this->shape([10, 20, 30, 40, 50]);

        self::assertSame(50.0, $shape->p95());
    }

    public function testOneRecordIsItsOwnEveryStatistic(): void
    {
        $shape = $this->shape([7]);

        self::assertSame(7.0, $shape->p95());
        self::assertSame(7.0, $shape->mean());
        self::assertSame(7.0, $shape->max());
    }

    /**
     * Above twenty records the nearest rank finally stops landing on the
     * maximum, and a shape with one outlier reports the cost of the other
     * twenty rather than of the outlier — which is the whole reason a report
     * offers p95 beside max.
     */
    public function testPastTwentyRecordsTheP95IsNoLongerTheMaximum(): void
    {
        $shape = $this->shape(array_merge(array_fill(0, 20, 10), [1000]));

        self::assertSame(10.0, $shape->p95());
        self::assertSame(1000.0, $shape->max());
    }

    /**
     * Ties keep the first sample seen. Nothing forces that choice, but a report
     * that shows a different record every run over one file is a report nobody
     * can compare with yesterday's.
     */
    public function testTwoRecordsAtTheSameCostKeepTheFirstAsTheSample(): void
    {
        $formatter = Formatter::create();
        $first = $formatter->describe('{"query":{"term":{"email":"ada@example.com"}},"size":20}', 'members');
        $second = $formatter->describe('{"query":{"term":{"email":"grace@example.com"}},"size":20}', 'members');

        $shape = new Shape($first);
        $shape->record($first, 100.0);
        $shape->record($second, 100.0);

        $slowest = $shape->jsonSerialize()['slowest'];
        self::assertIsArray($slowest);
        self::assertIsString($slowest['text']);
        self::assertStringContainsString('ada@example.com', $slowest['text']);
    }

    /**
     * Three decimals, everywhere a duration is serialised. A published number
     * has a published precision: milliseconds to the microsecond is more than
     * any cluster measures, and rounding is what keeps a JSON report diffable
     * rather than full of binary-float tails.
     */
    public function testEveryDurationIsSerialisedToThreeDecimals(): void
    {
        $shape = $this->shape([]);
        $digest = Formatter::create()->describe(self::REQUEST, 'members');

        foreach ([1.23456, 2.0] as $millis) {
            $shape->record($digest, $millis);
        }

        $json = $shape->jsonSerialize();

        // Every one of these has a fourth decimal to lose, so a report rounding
        // to two or to four would show a different number here.
        self::assertSame(3.235, $json['total_ms']);
        self::assertSame(1.617, $json['mean_ms']);
        self::assertSame(1.61728, $shape->mean(), 'The accessor rounds nothing.');
        self::assertSame(2.0, $json['max_ms']);
    }

    /**
     * The window is the two ends whatever order the records arrived in — a
     * merged pair of rotated files is not in order, and reporting the last line
     * as the last moment would be wrong.
     */
    public function testTheWindowIsTheTwoEndsWhicheverOrderTheRecordsArrivedIn(): void
    {
        $digest = Formatter::create()->describe(self::REQUEST, 'members');
        $shape = new Shape($digest);

        foreach (['2026-08-20T14:01:00,000', '2026-08-20T13:00:00,000', '2026-08-20T15:30:00,000'] as $at) {
            $shape->record($digest, 1.0, $at);
        }

        $json = $shape->jsonSerialize();

        self::assertSame('2026-08-20T13:00:00,000', $json['first']);
        self::assertSame('2026-08-20T15:30:00,000', $json['last']);
    }

    /**
     * The one field that can hold a literal, and it holds the *slowest* record's
     * — labelled as a sample, beside the signature which is the group's.
     */
    public function testTheKeptSampleIsTheSlowestOne(): void
    {
        $formatter = Formatter::create();
        $fast = $formatter->describe('{"query":{"term":{"email":"ada@example.com"}},"size":20}', 'members');
        $slow = $formatter->describe('{"query":{"term":{"email":"grace@example.com"}},"size":20}', 'members');

        self::assertSame($fast->hash(), $slow->hash(), 'The two are one shape; only the value differs.');

        $shape = new Shape($fast);
        $shape->record($fast, 10.0);
        $shape->record($slow, 900.0);

        $slowest = $shape->jsonSerialize()['slowest'];
        self::assertIsArray($slowest);

        self::assertSame(900.0, $slowest['took_ms']);
        self::assertIsString($slowest['text']);
        self::assertStringContainsString('grace@example.com', $slowest['text']);

        $signature = $shape->jsonSerialize()['sig'];
        self::assertIsString($signature);
        self::assertStringNotContainsString('grace@example.com', $signature, 'The signature is the group.');
    }

    /**
     * Under `withText(false)` there is no literal line to keep, so the sample
     * holds the signature — a report built from value-free digests carries no
     * value anywhere, without the caller having to know which field to drop.
     */
    public function testAValueFreeDigestLeavesNoValueInTheSample(): void
    {
        $digest = Formatter::create(Options::create()->withText(false))->describe(self::REQUEST, 'members');

        $shape = new Shape($digest);
        $shape->record($digest, 10.0);

        $encoded = json_encode($shape);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('ada@example.com', $encoded);

        $slowest = $shape->jsonSerialize()['slowest'];
        self::assertIsArray($slowest);
        self::assertSame($digest->signature(), $slowest['text']);
    }

    public function testAShapeCarriesWhatItsSearchesAre(): void
    {
        $digest = Formatter::create()->describe(self::REQUEST, 'members-2026.08');
        $shape = new Shape($digest);

        // One `term`, but twenty documents asked for: a page, not a fetch.
        self::assertSame('browse', $shape->kind()->name());
        self::assertSame('members-*', $shape->index());
        self::assertSame($digest->hash(), $shape->hash());
        self::assertSame($digest->signature(), $shape->signature());
    }

    /**
     * @param array<int,int> $durations
     */
    private function shape(array $durations): Shape
    {
        $digest = Formatter::create()->describe(self::REQUEST, 'members');
        $shape = new Shape($digest);

        foreach ($durations as $millis) {
            $shape->record($digest, (float) $millis);
        }

        return $shape;
    }
}
