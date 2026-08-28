<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Digest;
use MrDlef\OsQueryDigest\Kind;
use PHPUnit\Framework\TestCase;

/**
 * The value object, as opposed to {@see ClassifierTest}, which is the taxonomy.
 *
 * @internal
 */
final class KindTest extends TestCase
{
    /**
     * The list is published, so a consumer builds a legend or a `<select>` from
     * it. A constant added without a line here would be a kind the library
     * mints and no front end knows about.
     */
    public function testEveryConstantIsInTheListAndNothingElseIs(): void
    {
        $constants = (new \ReflectionClass(Kind::class))->getConstants();
        unset($constants['KINDS']);

        $names = array_values($constants);
        sort($names);

        $listed = Kind::KINDS;
        sort($listed);

        self::assertSame($names, $listed);
    }

    public function testEachNamedConstructorCarriesItsOwnName(): void
    {
        self::assertSame(Kind::SUGGEST, Kind::suggest()->name());
        self::assertSame(Kind::AGGREGATE, Kind::aggregate()->name());
        self::assertSame(Kind::SCAN, Kind::scan()->name());
        self::assertSame(Kind::LOOKUP, Kind::lookup()->name());
        self::assertSame(Kind::BROWSE, Kind::browse()->name());
        self::assertSame(Kind::UNKNOWN, Kind::unknown()->name());
    }

    public function testItComparesAndPrintsAsItsName(): void
    {
        $kind = Kind::browse();

        self::assertTrue($kind->is(Kind::BROWSE));
        self::assertFalse($kind->is(Kind::SCAN));
        self::assertSame('browse', (string) $kind);
    }

    /**
     * A digest built by hand rather than parsed — the shape a test double or a
     * stored record takes — knows what it knows.
     */
    public function testADigestWithNoKindSaysSo(): void
    {
        $digest = new Digest('idx', 'text', 'sig', 'q5:000000000000');

        self::assertSame(Kind::UNKNOWN, $digest->kind()->name());
        self::assertSame(Kind::UNKNOWN, $digest->toArray()['kind']);
    }
}
