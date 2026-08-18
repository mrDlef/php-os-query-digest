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
    private Formatter $formatter;

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
            $this->formatter->describe($two)->hash(),
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

    public function testGeoPolygonRendersAsFieldAndShape(): void
    {
        $digest = $this->formatter->describe(['query' => ['geo_polygon' => [
            'location' => ['points' => [
                ['lat' => 48.9, 'lon' => 2.2],
                ['lat' => 48.8, 'lon' => 2.4],
                ['lat' => 48.9, 'lon' => 2.5],
            ]],
        ]]]);

        self::assertSame('q=(location:geo_polygon())', $digest->text());
    }

    /**
     * The relation is not a parameter of a shape query, it *is* the query:
     * `within` and `disjoint` on the same polygon return opposite result sets.
     * Erasing it would be the geo equivalent of erasing a `not`.
     */
    public function testAShapeRelationSurvivesIntoTheSignature(): void
    {
        $within = ['query' => ['geo_shape' => ['zone' => [
            'shape' => ['type' => 'polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]],
            'relation' => 'within',
        ]]]];
        $disjoint = ['query' => ['geo_shape' => ['zone' => [
            'shape' => ['type' => 'polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]],
            'relation' => 'disjoint',
        ]]]];

        self::assertSame('q=(zone:geo_shape(polygon,within))', $this->formatter->describe($within)->text());
        self::assertSame('q=(zone:geo_shape(polygon,within))', $this->formatter->describe($within)->signature());
        self::assertNotSame(
            $this->formatter->describe($within)->hash(),
            $this->formatter->describe($disjoint)->hash(),
        );
    }

    /**
     * The coordinates are the value: the same search over a slightly different
     * polygon is the same kind of query.
     */
    public function testTwoGeometriesOfTheSameKindShareAFingerprint(): void
    {
        $one = ['query' => ['geo_shape' => ['zone' => [
            'shape' => ['type' => 'envelope', 'coordinates' => [[0, 1], [1, 0]]],
        ]]]];
        $two = ['query' => ['geo_shape' => ['zone' => [
            'shape' => ['type' => 'envelope', 'coordinates' => [[10, 11], [11, 10]]],
        ]]]];

        self::assertSame(
            $this->formatter->describe($one)->hash(),
            $this->formatter->describe($two)->hash(),
        );
    }

    /**
     * `intersects` is what OpenSearch assumes when the query says nothing, so
     * writing it and omitting it must produce the same fingerprint.
     */
    public function testAnAbsentRelationRendersAsTheDefaultOpenSearchApplies(): void
    {
        $implicit = ['query' => ['geo_shape' => ['zone' => [
            'shape' => ['type' => 'point', 'coordinates' => [0, 0]],
        ]]]];
        $explicit = ['query' => ['geo_shape' => ['zone' => [
            'shape' => ['type' => 'point', 'coordinates' => [0, 0]],
            'relation' => 'INTERSECTS',
        ]]]];

        self::assertSame('q=(zone:geo_shape(point,intersects))', $this->formatter->describe($implicit)->text());
        self::assertSame(
            $this->formatter->describe($implicit)->hash(),
            $this->formatter->describe($explicit)->hash(),
        );
    }

    public function testXyShapeReadsLikeItsGeoCounterpart(): void
    {
        $digest = $this->formatter->describe(['query' => ['xy_shape' => ['geometry' => [
            'shape' => ['type' => 'envelope', 'coordinates' => [[0, 10], [10, 0]]],
            'relation' => 'contains',
        ]]]]);

        self::assertSame('q=(geometry:xy_shape(envelope,contains))', $digest->text());
    }

    /**
     * A pre-indexed geometry cannot be read from the query at all — only that
     * it is indexed. Saying so beats inventing a shape.
     */
    public function testAnIndexedShapeIsSignalledRatherThanGuessed(): void
    {
        $digest = $this->formatter->describe(['query' => ['geo_shape' => ['zone' => [
            'indexed_shape' => ['index' => 'shapes', 'id' => 'dept-75', 'path' => 'geometry'],
            'relation' => 'within',
        ]]]]);

        self::assertSame('q=(zone:geo_shape(indexed,within)) | indexed_shape', $digest->text());

        $explanation = $this->formatter->explain(['query' => ['geo_shape' => ['zone' => [
            'indexed_shape' => ['index' => 'shapes', 'id' => 'dept-75'],
        ]]]]);
        self::assertTrue($explanation->has(Rule::INDEXED_SHAPE));
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
            $digest->text(),
        );
    }

    public function testTheJoinedRelationIsPartOfTheShape(): void
    {
        $review = ['query' => ['has_child' => ['type' => 'review', 'query' => ['term' => ['a' => 1]]]]];
        $comment = ['query' => ['has_child' => ['type' => 'comment', 'query' => ['term' => ['a' => 1]]]]];

        self::assertNotSame(
            $this->formatter->describe($review)->hash(),
            $this->formatter->describe($comment)->hash(),
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

    public function testScriptScoreIsReplacedByTheQueryItRescores(): void
    {
        $digest = $this->formatter->describe(['query' => ['script_score' => [
            'query' => ['term' => ['env' => 'prod']],
            'script' => ['source' => 'decay(doc[\'age\'].value)'],
        ]]]);

        self::assertSame('q=(env:prod) | script_score', $digest->text());
    }

    /**
     * The rescoring script is where the tuning happens: it changes on every
     * experiment while the query it wraps stays the same. Two variants of the
     * same search must not look like two different searches.
     */
    public function testTwoScoringScriptsOverTheSameQueryShareAFingerprint(): void
    {
        $one = ['query' => ['script_score' => [
            'query' => ['term' => ['env' => 'prod']],
            'script' => ['source' => '_score * 2'],
        ]]];
        $two = ['query' => ['script_score' => [
            'query' => ['term' => ['env' => 'prod']],
            'script' => ['source' => 'saturation(doc[\'views\'].value, 10)'],
        ]]];

        self::assertSame(
            $this->formatter->describe($one)->hash(),
            $this->formatter->describe($two)->hash(),
        );
    }

    /**
     * `min_score` is the one part of a script_score that is not pure rescoring:
     * it drops documents. The line would otherwise claim a wider result set than
     * the query returns, so the note has to change — and with it the hash.
     */
    public function testAScriptScoreThresholdIsCalledOutInTheNotes(): void
    {
        $withThreshold = ['query' => ['script_score' => [
            'query' => ['term' => ['env' => 'prod']],
            'script' => ['source' => '_score * 2'],
            'min_score' => 5.0,
        ]]];

        $digest = $this->formatter->describe($withThreshold);

        self::assertSame('q=(env:prod) | script_score:min_score', $digest->text());
        self::assertNotSame(
            $this->formatter->describe(['query' => ['script_score' => [
                'query' => ['term' => ['env' => 'prod']],
                'script' => ['source' => '_score * 2'],
            ]]])->hash(),
            $digest->hash(),
            'A threshold that excludes documents must not share a fingerprint with one that does not.',
        );
    }

    public function testTheScriptScoreUnwrappingIsReportedByExplain(): void
    {
        $explanation = $this->formatter->explain(['query' => ['script_score' => [
            'query' => ['term' => ['env' => 'prod']],
            'script' => ['source' => '_score * 2'],
        ]]]);

        self::assertTrue($explanation->has(Rule::SCRIPT_SCORE_UNWRAPPED));
    }

    public function testParentIdKeepsTheRelationAndErasesTheParent(): void
    {
        $digest = $this->formatter->describe(['query' => ['parent_id' => [
            'type' => 'comment',
            'id' => '4712',
        ]]]);

        self::assertSame('q=(parent_id(comment):4712)', $digest->text());
        self::assertSame('q=(parent_id(comment):?)', $digest->signature());
    }

    /**
     * Same reasoning as `has_child`: which relation is walked is the shape of
     * the query, not one of its parameters.
     */
    public function testTheParentRelationIsPartOfTheShape(): void
    {
        $comment = ['query' => ['parent_id' => ['type' => 'comment', 'id' => '1']]];
        $review = ['query' => ['parent_id' => ['type' => 'review', 'id' => '1']]];

        self::assertNotSame(
            $this->formatter->describe($comment)->hash(),
            $this->formatter->describe($review)->hash(),
        );
    }

    public function testTwoParentLookupsOfTheSameRelationShareAFingerprint(): void
    {
        self::assertSame(
            $this->formatter->describe(['query' => ['parent_id' => ['type' => 'comment', 'id' => '1']]])->hash(),
            $this->formatter->describe(['query' => ['parent_id' => ['type' => 'comment', 'id' => '2']]])->hash(),
        );
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
            $digest->hash(),
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
            Options::create()->withNormalization(Normalization::structural()),
        );

        self::assertSame(
            $formatter->describe(['query' => ['knn' => ['v' => ['vector' => [0.1], 'k' => 10]]]])->hash(),
            $formatter->describe(['query' => ['knn' => ['v' => ['vector' => [0.2], 'k' => 50]]]])->hash(),
            'At structural level, two knn searches on the same field are the same shape.',
        );
    }

    /**
     * The flagship OpenSearch pattern — a lexical clause and a vector clause
     * combined by a search pipeline — used to collapse to `hybrid(?)`, which
     * said nothing at all about a query whose whole point is what it combines.
     */
    public function testHybridRendersItsBranchesInsteadOfHidingThem(): void
    {
        $digest = $this->formatter->describe(['query' => ['hybrid' => ['queries' => [
            ['match' => ['title' => 'waterproof boots']],
            ['knn' => ['embedding' => ['vector' => [0.1, 0.2], 'k' => 10]]],
        ]]]]);

        self::assertSame('q=(embedding:knn(k=10) or title:"waterproof boots")', $digest->text());
        self::assertSame('q=(embedding:knn(k=?) or title:~?)', $digest->signature());
    }

    /**
     * The pipeline decides how the two scores are blended, never which
     * documents come back — so hybrid restricts exactly the way dis_max does.
     */
    public function testHybridAndDisMaxOverTheSameBranchesShareAFingerprint(): void
    {
        $branches = [['term' => ['env' => 'prod']], ['term' => ['env' => 'staging']]];

        self::assertSame(
            $this->formatter->describe(['query' => ['dis_max' => ['queries' => $branches]]])->hash(),
            $this->formatter->describe(['query' => ['hybrid' => ['queries' => $branches]]])->hash(),
        );
    }

    public function testCombinedFieldsReadsLikeTheMultiMatchItMatches(): void
    {
        $digest = $this->formatter->describe(['query' => ['combined_fields' => [
            'query' => 'connection timeout',
            'fields' => ['title', 'body'],
            'operator' => 'and',
        ]]]);

        self::assertSame('q=(title|body:"connection timeout")', $digest->text());
        self::assertSame('q=(title|body:~?)', $digest->signature());
    }

    public function testTheDeprecatedCommonQueryReadsAsAMatch(): void
    {
        $digest = $this->formatter->describe(['query' => ['common' => [
            'msg' => ['query' => 'timeout', 'cutoff_frequency' => 0.001],
        ]]]);

        self::assertSame('q=(msg:timeout)', $digest->text());
        self::assertSame('q=(msg:~?)', $digest->signature());
    }

    /**
     * The field says which set of saved queries is being replayed, which is the
     * whole diagnostic content. The document is a value.
     */
    public function testPercolateKeepsTheFieldAndDropsTheDocument(): void
    {
        $one = ['query' => ['percolate' => ['field' => 'alerts', 'document' => ['msg' => 'timeout']]]];
        $two = ['query' => ['percolate' => ['field' => 'alerts', 'document' => ['msg' => 'refused']]]];

        self::assertSame('q=(alerts:percolate())', $this->formatter->describe($one)->text());
        self::assertSame(
            $this->formatter->describe($one)->hash(),
            $this->formatter->describe($two)->hash(),
        );
    }

    /**
     * A percolated document fetched from another index is the same blind spot
     * as a terms lookup, and gets the same warning rather than silence.
     */
    public function testAPercolateLookupIsCalledOut(): void
    {
        $digest = $this->formatter->describe(['query' => ['percolate' => [
            'field' => 'alerts', 'index' => 'docs', 'id' => '7',
        ]]]);

        self::assertSame('q=(alerts:percolate(indexed)) | percolate_lookup', $digest->text());
        self::assertContains('percolate_lookup', $digest->notes());
        self::assertTrue(
            $this->formatter->explain(['query' => ['percolate' => [
                'field' => 'alerts', 'index' => 'docs', 'id' => '7',
            ]]])->has(Rule::PERCOLATE_LOOKUP),
        );
    }

    public function testRankFeatureKeepsTheFieldAndDropsTheScoringFunction(): void
    {
        $saturation = ['query' => ['rank_feature' => [
            'field' => 'popularity', 'saturation' => ['pivot' => 8],
        ]]];
        $log = ['query' => ['rank_feature' => [
            'field' => 'popularity', 'log' => ['scaling_factor' => 4],
        ]]];

        self::assertSame('q=(popularity:rank_feature())', $this->formatter->describe($saturation)->text());
        self::assertSame(
            $this->formatter->describe($saturation)->hash(),
            $this->formatter->describe($log)->hash(),
            'The curve reorders results, it does not change which ones come back.',
        );
    }

    public function testDistanceFeatureKeepsWhichKnobWasTurned(): void
    {
        $digest = $this->formatter->describe(['query' => ['distance_feature' => [
            'field' => 'created_at', 'pivot' => '7d', 'origin' => 'now',
        ]]]);

        self::assertSame('q=(created_at:distance_feature(pivot=7d))', $digest->text());
        self::assertSame('q=(created_at:distance_feature(pivot=?))', $digest->signature());
    }

    /**
     * They read as boosting, but a document without the field does not match —
     * so unlike function_score they cannot be unwrapped away.
     */
    public function testAFeatureQueryStillNarrowsTheConjunction(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => ['must' => [
            ['term' => ['env' => 'prod']],
            ['rank_feature' => ['field' => 'popularity']],
        ]]]]);

        self::assertSame('q=(env:prod and popularity:rank_feature())', $digest->text());
    }

    public function testIntervalsKeepsTheFieldItRunsOn(): void
    {
        $digest = $this->formatter->describe(['query' => ['intervals' => ['msg' => [
            'match' => ['query' => 'connection timeout', 'max_gaps' => 2, 'ordered' => true],
        ]]]]);

        self::assertSame('q=(msg:intervals())', $digest->text());
        self::assertSame('q=(msg:intervals())', $digest->signature());
    }

    /**
     * The one promotion that recovers a whole query rather than summarising
     * one: base64 in, real tree out.
     */
    public function testAWrapperIsDecodedIntoTheQueryItCarries(): void
    {
        $inner = '{"bool":{"filter":[{"term":{"env":"prod"}},{"range":{"took":{"gte":500}}}]}}';

        $digest = $this->formatter->describe(['query' => [
            'wrapper' => ['query' => base64_encode($inner)],
        ]]);

        self::assertSame('q=(env:prod and took >= 500)', $digest->text());
    }

    /**
     * Wrapping a query changes nothing about what it matches, so it must not
     * change its fingerprint either.
     */
    public function testAWrappedQueryHashesLikeTheSameQueryUnwrapped(): void
    {
        $plain = ['query' => ['term' => ['env' => 'prod']]];
        $wrapped = ['query' => ['wrapper' => ['query' => base64_encode('{"term":{"env":"prod"}}')]]];

        self::assertSame(
            $this->formatter->describe($plain)->hash(),
            $this->formatter->describe($wrapped)->hash(),
        );
        self::assertTrue($this->formatter->explain($wrapped)->has(Rule::WRAPPER_DECODED));
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
            'parent_id' => ['query' => ['parent_id' => ['type' => 'review']]],
            'geo_polygon' => ['query' => ['geo_polygon' => ['validation_method' => 'STRICT']]],
            'geo_shape' => ['query' => ['geo_shape' => ['zone' => ['relation' => 'within']]]],
            'xy_shape' => ['query' => ['xy_shape' => []]],
            'percolate' => ['query' => ['percolate' => ['document' => ['a' => 1]]]],
            'rank_feature' => ['query' => ['rank_feature' => ['saturation' => ['pivot' => 8]]]],
            'distance_feature' => ['query' => ['distance_feature' => ['pivot' => '7d']]],
            'intervals' => ['query' => ['intervals' => ['boost' => 2]]],
            'wrapper' => ['query' => ['wrapper' => ['query' => 'not base64 %%%']]],
        ];

        foreach ($cases as $type => $request) {
            self::assertSame(
                'q=(' . $type . '(?))',
                $this->formatter->describe($request)->text(),
                $type . ' was not signalled.',
            );
        }
    }
}
