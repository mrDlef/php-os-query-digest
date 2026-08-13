<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * The whole point of the fingerprint: equivalent queries must converge,
 * different queries must not.
 */
final class CanonicalizationTest extends TestCase
{
    /** @var Formatter */
    private $formatter;

    protected function setUp(): void
    {
        $this->formatter = Formatter::create();
    }

    public function testClauseOrderDoesNotMatter(): void
    {
        $a = ['query' => ['bool' => ['filter' => [
            ['term' => ['service' => 'api']],
            ['term' => ['env' => 'prod']],
        ]]]];

        $b = ['query' => ['bool' => ['filter' => [
            ['term' => ['env' => 'prod']],
            ['term' => ['service' => 'api']],
        ]]]];

        self::assertSame($this->hash($a), $this->hash($b));
    }

    public function testMustAndFilterAreTheSameShape(): void
    {
        $must = ['query' => ['bool' => ['must' => [['term' => ['a' => 1]], ['term' => ['b' => 2]]]]]];
        $filter = ['query' => ['bool' => ['filter' => [['term' => ['a' => 1]], ['term' => ['b' => 2]]]]]];

        self::assertSame($this->hash($must), $this->hash($filter));
    }

    public function testNestedBoolsAreFlattened(): void
    {
        $flat = ['query' => ['bool' => ['filter' => [
            ['term' => ['a' => 1]],
            ['term' => ['b' => 2]],
            ['term' => ['c' => 3]],
        ]]]];

        $nested = ['query' => ['bool' => ['filter' => [
            ['term' => ['a' => 1]],
            ['bool' => ['filter' => [
                ['term' => ['b' => 2]],
                ['term' => ['c' => 3]],
            ]]],
        ]]]];

        self::assertSame($this->hash($flat), $this->hash($nested));
    }

    public function testSingleClauseBoolIsUnwrapped(): void
    {
        $wrapped = ['query' => ['bool' => ['filter' => [['term' => ['a' => 1]]]]]];
        $bare = ['query' => ['term' => ['a' => 1]]];

        self::assertSame($this->hash($wrapped), $this->hash($bare));
    }

    public function testDuplicateSiblingsCollapse(): void
    {
        $once = ['query' => ['bool' => ['filter' => [['term' => ['a' => 1]], ['term' => ['b' => 2]]]]]];
        $twice = ['query' => ['bool' => ['filter' => [
            ['term' => ['a' => 1]],
            ['term' => ['b' => 2]],
            ['term' => ['a' => 1]],
        ]]]];

        self::assertSame($this->hash($once), $this->hash($twice));
    }

    public function testRangeBoundOrderDoesNotMatter(): void
    {
        $a = ['query' => ['range' => ['ts' => ['gte' => 'now-1d', 'lt' => 'now']]]];
        $b = ['query' => ['range' => ['ts' => ['lt' => 'now', 'gte' => 'now-1d']]]];

        self::assertSame($this->hash($a), $this->hash($b));
    }

    public function testMustNotIsNotDeMorganed(): void
    {
        // must_not: [A, B] is (NOT A) AND (NOT B), never NOT (A AND B).
        $digest = $this->formatter->describe(['query' => ['bool' => ['must_not' => [
            ['term' => ['a' => 1]],
            ['term' => ['b' => 2]],
        ]]]]);

        self::assertSame('q=(not a:1 and not b:2)', $digest->text());
    }

    public function testDifferentFieldsDoNotCollide(): void
    {
        $a = ['query' => ['term' => ['service' => 'api']]];
        $b = ['query' => ['term' => ['env' => 'api']]];

        self::assertNotSame($this->hash($a), $this->hash($b));
    }

    public function testTermAndMatchDoNotCollide(): void
    {
        // They render identically in DQL, but the signature keeps them apart:
        // it uses `~` for analysed matches.
        $term = ['query' => ['term' => ['title' => 'shoes']]];
        $match = ['query' => ['match' => ['title' => 'shoes']]];

        self::assertSame('q=(title:shoes)', $this->formatter->describe($term)->text());
        self::assertSame('q=(title:shoes)', $this->formatter->describe($match)->text());
        self::assertNotSame($this->hash($term), $this->hash($match));
    }

    /**
     * @param array<string,mixed> $request
     */
    private function hash(array $request): string
    {
        return $this->formatter->describe($request)->hash();
    }
}
