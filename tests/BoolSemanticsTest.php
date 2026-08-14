<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * `should` is the clause everybody gets wrong: beside a must/filter and without
 * minimum_should_match it does not restrict anything, it only boosts. Rendering
 * it as a filter would make the line lie.
 */
final class BoolSemanticsTest extends TestCase
{
    /** @var Formatter */
    private $formatter;

    protected function setUp(): void
    {
        $this->formatter = Formatter::create();
    }

    public function testShouldAloneFilters(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => ['should' => [
            ['term' => ['a' => 1]],
            ['term' => ['b' => 2]],
        ]]]]);

        self::assertSame('q=(a:1 or b:2)', $digest->text());
        self::assertSame([], $digest->notes());
    }

    public function testShouldBesideAFilterIsBoostOnlyAndMovedToNotes(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => [
            'filter' => [['term' => ['env' => 'prod']]],
            'should' => [['term' => ['brand' => 'acme']], ['term' => ['promo' => true]]],
        ]]]);

        self::assertSame('q=(env:prod) | should=2', $digest->text());
        self::assertSame(['should=2'], $digest->notes());
    }

    public function testMinimumShouldMatchMakesItFilterAgain(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => [
            'filter' => [['term' => ['env' => 'prod']]],
            'should' => [['term' => ['a' => 1]], ['term' => ['b' => 2]]],
            'minimum_should_match' => 1,
        ]]]);

        self::assertSame('q=(env:prod and (a:1 or b:2))', $digest->text());
    }

    public function testMinimumShouldMatchAboveOneIsAnnotated(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => [
            'should' => [['term' => ['a' => 1]], ['term' => ['b' => 2]], ['term' => ['c' => 3]]],
            'minimum_should_match' => 2,
        ]]]);

        self::assertSame('q=((a:1 or b:2 or c:3){msm=2})', $digest->text());
    }

    public function testNonNumericMinimumShouldMatchIsNoted(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => [
            'should' => [['term' => ['a' => 1]], ['term' => ['b' => 2]]],
            'minimum_should_match' => '75%',
        ]]]);

        self::assertSame(['msm=75%'], $digest->notes());
    }

    public function testFunctionScoreKeepsTheFilteringPartAndNotesTheRest(): void
    {
        $digest = $this->formatter->describe(['query' => ['function_score' => [
            'query' => ['term' => ['env' => 'prod']],
            'functions' => [['random_score' => []]],
        ]]]);

        self::assertSame('q=(env:prod) | function_score', $digest->text());
    }

    public function testUnknownClausesStaySignalledInsteadOfDropped(): void
    {
        // A span query: deliberately left opaque — nobody debugs those from a
        // log line — which makes it the right probe for "signalled, not
        // dropped".
        $digest = $this->formatter->describe(['query' => ['bool' => ['filter' => [
            ['span_term' => ['a' => 'x']],
            ['exists' => ['field' => 'host']],
        ]]]]);

        self::assertStringContainsString('span_term(?)', $digest->text());
        self::assertStringContainsString('host:*', $digest->text());
    }

    public function testUnsupportedTopLevelSectionsAreListed(): void
    {
        $digest = $this->formatter->describe([
            'query' => ['match_all' => []],
            'highlight' => ['fields' => ['msg' => []]],
            'collapse' => ['field' => 'host'],
            '_source' => false,
        ]);

        // _source is noise and stays out; highlight and collapse are signalled.
        self::assertSame(['+collapse', '+highlight'], $digest->notes());
    }
}
