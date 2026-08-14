<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * `post_filter` runs after the aggregations: it narrows the hits while the
 * buckets keep counting the whole result set. That is the faceted-search
 * pattern, and it is the reason the clause gets its own segment instead of
 * being folded into `q=(…)`.
 */
final class PostFilterTest extends TestCase
{
    /** @var Formatter */
    private $formatter;

    protected function setUp(): void
    {
        $this->formatter = Formatter::create();
    }

    public function testItIsRenderedInItsOwnSegment(): void
    {
        $digest = $this->formatter->describe([
            'query' => ['match' => ['name' => 'shoes']],
            'post_filter' => ['term' => ['brand' => 'acme']],
        ]);

        self::assertSame('q=(name:shoes) | post=(brand:acme)', $digest->text());
    }

    /**
     * The old behaviour: acknowledged in the notes, invisible in the line. That
     * under-described the query, which is what this chantier fixed.
     */
    public function testItIsNoLongerPushedToTheNotes(): void
    {
        $digest = $this->formatter->describe([
            'query' => ['match_all' => []],
            'post_filter' => ['term' => ['brand' => 'acme']],
        ]);

        self::assertSame([], $digest->notes());
    }

    public function testMovingAClauseIntoThePostFilterChangesTheFingerprint(): void
    {
        $filtered = [
            'query' => ['bool' => ['filter' => [
                ['match' => ['name' => 'shoes']],
                ['term' => ['brand' => 'acme']],
            ]]],
            'aggs' => ['by_brand' => ['terms' => ['field' => 'brand']]],
        ];

        $postFiltered = [
            'query' => ['match' => ['name' => 'shoes']],
            'post_filter' => ['term' => ['brand' => 'acme']],
            'aggs' => ['by_brand' => ['terms' => ['field' => 'brand']]],
        ];

        self::assertNotSame(
            $this->formatter->describe($filtered)->hash(),
            $this->formatter->describe($postFiltered)->hash(),
            'The two return different buckets, so they must not share a fingerprint.'
        );
    }

    public function testItIsCanonicalisedLikeAnyOtherQuery(): void
    {
        $a = ['post_filter' => ['bool' => ['filter' => [
            ['term' => ['b' => 2]],
            ['bool' => ['filter' => [['term' => ['a' => 1]]]]],
        ]]]];

        $b = ['post_filter' => ['bool' => ['filter' => [
            ['term' => ['a' => 1]],
            ['term' => ['b' => 2]],
        ]]]];

        self::assertSame(
            $this->formatter->describe($a)->hash(),
            $this->formatter->describe($b)->hash()
        );
    }

    public function testItsValuesAreErasedFromTheSignature(): void
    {
        $digest = $this->formatter->describe([
            'post_filter' => ['terms' => ['brand' => ['acme', 'globex']]],
        ]);

        self::assertSame('post=(brand:(acme or globex))', $digest->text());
        self::assertSame('post=(brand:(? or ?))', $digest->signature());
    }

    /**
     * A request that is *only* a post_filter is legal, and the notes from its
     * clauses must survive — the parser resets them on every call, so this is
     * the regression that guards the second parse.
     */
    public function testNotesFromThePostFilterAreKept(): void
    {
        $digest = $this->formatter->describe([
            'query' => ['term' => ['env' => 'prod']],
            'post_filter' => ['bool' => [
                'filter' => [['term' => ['a' => 1]]],
                'should' => [['term' => ['b' => 2]], ['term' => ['c' => 3]]],
            ]],
        ]);

        self::assertSame(['should=2'], $digest->notes());
    }
}
