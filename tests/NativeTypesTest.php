<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Explain\Rule;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * The query types promoted out of `type(?)`.
 *
 * Each one is tested on the two things that make a native rendering worth the
 * hash change: the line says what the query does, and the signature keeps the
 * shape while dropping the parameters.
 */
final class NativeTypesTest extends TestCase
{
    /** @var Formatter */
    private $formatter;

    protected function setUp(): void
    {
        $this->formatter = Formatter::create();
    }

    public function testKnnKeepsTheFieldAndTheCutOff(): void
    {
        $digest = $this->formatter->describe(['query' => ['knn' => [
            'image_embedding' => ['vector' => [0.1, 0.2, 0.3], 'k' => 20],
        ]]]);

        self::assertSame('q=(image_embedding:knn(k=20))', $digest->text());
        self::assertSame('q=(image_embedding:knn(k=?))', $digest->signature());
    }

    /**
     * The vector is what makes a knn query unloggable: thousands of floats,
     * different on every request. Dropping it is the whole point.
     */
    public function testTwoKnnSearchesOnTheSameFieldShareAFingerprint(): void
    {
        $one = ['query' => ['knn' => ['v' => ['vector' => [0.1, 0.2], 'k' => 10]]]];
        $two = ['query' => ['knn' => ['v' => ['vector' => [0.9, 0.4], 'k' => 10]]]];

        self::assertSame(
            $this->formatter->describe($one)->hash(),
            $this->formatter->describe($two)->hash()
        );
    }

    /**
     * A knn filter is not a detail of the vector search: it decides which
     * documents can be returned at all, so it has to read as a filter.
     */
    public function testAKnnFilterIsRenderedAsAConjunction(): void
    {
        $digest = $this->formatter->describe(['query' => ['knn' => [
            'v' => ['vector' => [0.1], 'k' => 5, 'filter' => ['term' => ['in_stock' => true]]],
        ]]]);

        self::assertSame('q=(in_stock:true and v:knn(k=5))', $digest->text());
    }

    public function testNeuralKeepsTheQueryTextOutOfTheSignature(): void
    {
        $digest = $this->formatter->describe(['query' => ['neural' => [
            'text_embedding' => ['query_text' => 'waterproof boots', 'model_id' => 'm1', 'k' => 10],
        ]]]);

        self::assertSame('q=(text_embedding:neural(query="waterproof boots",k=10))', $digest->text());
        self::assertSame('q=(text_embedding:neural(query=?,k=?))', $digest->signature());
    }

    public function testGeoDistanceKeepsItsRadius(): void
    {
        $digest = $this->formatter->describe(['query' => ['geo_distance' => [
            'distance' => '25km',
            'location' => ['lat' => 48.85, 'lon' => 2.35],
        ]]]);

        self::assertSame('q=(location:geo_distance(25km))', $digest->text());
        self::assertSame('q=(location:geo_distance(?))', $digest->signature());
    }

    public function testGeoBoundingBoxRendersAsFieldAndShape(): void
    {
        $digest = $this->formatter->describe(['query' => ['geo_bounding_box' => [
            'delivery_area' => [
                'top_left' => ['lat' => 49.2, 'lon' => 1.9],
                'bottom_right' => ['lat' => 48.5, 'lon' => 2.8],
            ],
        ]]]);

        self::assertSame('q=(delivery_area:geo_bbox())', $digest->text());
    }

    public function testScriptShowsItsSourceInTheLineAndHidesItInTheSignature(): void
    {
        $digest = $this->formatter->describe(['query' => ['script' => [
            'script' => ['source' => "doc['cpu'].value > params.max", 'params' => ['max' => 90]],
        ]]]);

        self::assertSame("q=(script(doc['cpu'].value > params.max))", $digest->text());
        self::assertSame('q=(script(?))', $digest->signature());
    }

    /**
     * The inner query runs against *other* documents. Hoisting its clauses into
     * the parent expression would claim the parent matches them itself.
     */
    public function testJoinQueriesKeepTheirInnerQueryScoped(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => ['filter' => [
            ['has_child' => ['type' => 'review', 'query' => ['range' => ['rating' => ['gte' => 4]]]]],
            ['has_parent' => ['parent_type' => 'chain', 'query' => ['term' => ['active' => true]]]],
        ]]]]);

        self::assertSame(
            'q=(has_child(review):{ rating >= 4 } and has_parent(chain):{ active:true })',
            $digest->text()
        );
    }

    public function testTheJoinedRelationIsPartOfTheShape(): void
    {
        $review = ['query' => ['has_child' => ['type' => 'review', 'query' => ['term' => ['a' => 1]]]]];
        $comment = ['query' => ['has_child' => ['type' => 'comment', 'query' => ['term' => ['a' => 1]]]]];

        self::assertNotSame(
            $this->formatter->describe($review)->hash(),
            $this->formatter->describe($comment)->hash()
        );
    }

    public function testMoreLikeThisKeepsTheFieldsAndErasesTheText(): void
    {
        $digest = $this->formatter->describe(['query' => ['more_like_this' => [
            'fields' => ['title', 'body'],
            'like' => 'distributed consensus',
        ]]]);

        self::assertSame('q=(title|body:like("distributed consensus"))', $digest->text());
        self::assertSame('q=(title|body:like(?))', $digest->signature());
    }

    public function testMoreLikeThisAcceptsDocumentsInsteadOfText(): void
    {
        $digest = $this->formatter->describe(['query' => ['more_like_this' => [
            'fields' => ['title'],
            'like' => [['_index' => 'articles', '_id' => '1']],
        ]]]);

        // Quoted like any other value: the angle brackets would break the
        // surrounding DQL expression otherwise.
        self::assertSame('q=(title:like("<doc>"))', $digest->text());
    }

    public function testMatchNoneRendersAsTheOppositeOfMatchAll(): void
    {
        $digest = $this->formatter->describe(['query' => ['match_none' => []]]);

        self::assertSame('q=(none)', $digest->text());
    }

    /**
     * `AND(a, none)` returns nothing, whatever `a` says. Any query that
     * contains one is the same query: the one that matches nothing.
     */
    public function testMatchNoneAbsorbsItsConjunction(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => ['filter' => [
            ['term' => ['env' => 'prod']],
            ['match_none' => []],
        ]]]]);

        self::assertSame('q=(none)', $digest->text());
        self::assertSame(
            $this->formatter->describe(['query' => ['match_none' => []]])->hash(),
            $digest->hash()
        );
    }

    public function testMatchNoneContributesNothingToADisjunction(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => ['should' => [
            ['term' => ['env' => 'prod']],
            ['match_none' => []],
        ]]]]);

        self::assertSame('q=(env:prod)', $digest->text());
    }

    /**
     * Dropping every clause of an OR must not silently widen it to match_all —
     * that would turn "no documents" into "all documents".
     */
    public function testADisjunctionOfNothingButMatchNoneStillMatchesNothing(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => ['should' => [
            ['match_none' => []],
            ['match_none' => []],
        ]]]]);

        self::assertSame('q=(none)', $digest->text());
    }

    /**
     * A weighted should group counts its clauses, so removing one changes which
     * documents come back.
     */
    public function testMatchNoneIsKeptInsideAWeightedShouldGroup(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => [
            'should' => [
                ['term' => ['a' => 1]],
                ['term' => ['b' => 2]],
                ['match_none' => []],
            ],
            'minimum_should_match' => 2,
        ]]]);

        self::assertStringContainsString('none', $digest->text());
    }

    public function testTheAbsorptionIsReportedByExplain(): void
    {
        $explanation = $this->formatter->explain(['query' => ['bool' => ['filter' => [
            ['term' => ['env' => 'prod']],
            ['match_none' => []],
        ]]]]);

        self::assertTrue($explanation->has(Rule::ABSORB_MATCH_NONE));
    }

    public function testStructuralNormalisationErasesVectorParameters(): void
    {
        $formatter = Formatter::create(
            Options::create()->withNormalization(Normalization::structural())
        );

        self::assertSame(
            $formatter->describe(['query' => ['knn' => ['v' => ['vector' => [0.1], 'k' => 10]]]])->hash(),
            $formatter->describe(['query' => ['knn' => ['v' => ['vector' => [0.2], 'k' => 50]]]])->hash(),
            'At structural level, two knn searches on the same field are the same shape.'
        );
    }

    /**
     * A body the parser cannot make sense of must still be signalled rather
     * than swallowed — the promotion must not open a hole.
     */
    public function testMalformedBodiesFallBackToOpaque(): void
    {
        $cases = [
            'knn' => ['query' => ['knn' => []]],
            'geo_distance' => ['query' => ['geo_distance' => ['distance' => '1km']]],
            'script' => ['query' => ['script' => []]],
            'has_child' => ['query' => ['has_child' => ['type' => 'review']]],
            'more_like_this' => ['query' => ['more_like_this' => ['fields' => ['a']]]],
        ];

        foreach ($cases as $type => $request) {
            self::assertSame(
                'q=(' . $type . '(?))',
                $this->formatter->describe($request)->text(),
                $type . ' was not signalled.'
            );
        }
    }
}
