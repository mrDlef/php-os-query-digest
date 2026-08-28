<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Kind;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * The taxonomy, as a table of requests.
 *
 * Read through `describe()` rather than against the classifier directly: a kind
 * is only worth anything if it is the one a caller gets, and the parsing and
 * canonicalisation between the two are exactly where a classification made from
 * the raw body goes wrong.
 *
 * One test looping a table, like {@see FixtureTest} and for the same reason —
 * doc-comment data providers are gone in PHPUnit 12 and PHP 7.4 has no
 * attributes, so this is the shape that runs unchanged across the matrix. It
 * also fails with every disagreement at once rather than the first.
 *
 * @internal
 */
final class ClassifierTest extends TestCase
{
    /**
     * Each case is one request and the kind it is. The names are the
     * documentation: a case whose name does not say why it is that kind is a
     * case nobody can review.
     *
     * @var array<string,array{0:array<mixed>,1:string}>
     */
    private const CASES = [
        // ---- suggest -------------------------------------------------------
        'a completion suggester is a type-ahead whatever else it carries' => [
            ['suggest' => ['names' => ['prefix' => 'ad', 'completion' => ['field' => 'name_suggest']]], 'size' => 0],
            Kind::SUGGEST,
        ],
        'match_phrase_prefix has no second reading' => [
            ['query' => ['match_phrase_prefix' => ['title' => 'water']], 'size' => 8],
            Kind::SUGGEST,
        ],
        'match_bool_prefix likewise' => [
            ['query' => ['match_bool_prefix' => ['title' => 'water boo']], 'size' => 8],
            Kind::SUGGEST,
        ],
        'a bare prefix query is the whole question' => [
            ['query' => ['prefix' => ['sku' => 'AB-']], 'size' => 10],
            Kind::SUGGEST,
        ],
        'and so is one spread over the fields an autocomplete searches' => [
            [
                'query' => ['bool' => ['should' => [
                    ['prefix' => ['name' => 'ad']],
                    ['prefix' => ['email' => 'ad']],
                ], 'minimum_should_match' => 1]],
                'size' => 10,
            ],
            Kind::SUGGEST,
        ],
        'a prefix among other clauses is an ordinary filter' => [
            [
                'query' => ['bool' => ['filter' => [
                    ['match_phrase' => ['message' => 'connection refused']],
                    ['prefix' => ['host' => 'web-']],
                ]]],
                'size' => 20,
            ],
            Kind::BROWSE,
        ],

        // ---- aggregate -----------------------------------------------------
        'size 0 with buckets is the dashboard panel' => [
            [
                'query' => ['term' => ['service' => 'api']],
                'aggs' => ['per_host' => ['terms' => ['field' => 'host']]],
                'size' => 0,
            ],
            Kind::AGGREGATE,
        ],
        'no size but no source either: the buckets-only intent, spelled the other way' => [
            [
                'query' => ['term' => ['service' => 'api']],
                'aggs' => ['per_host' => ['terms' => ['field' => 'host']]],
                '_source' => false,
            ],
            Kind::AGGREGATE,
        ],
        'aggregations with no size at all still come back with ten documents' => [
            [
                'query' => ['term' => ['service' => 'api']],
                'aggs' => ['per_host' => ['terms' => ['field' => 'host']]],
            ],
            Kind::BROWSE,
        ],
        'a filtered source is still asking for documents' => [
            [
                'query' => ['term' => ['service' => 'api']],
                'aggs' => ['per_host' => ['terms' => ['field' => 'host']]],
                '_source' => ['host', 'took'],
            ],
            Kind::BROWSE,
        ],
        'twenty documents beside the buckets is a faceted page, not a count' => [
            [
                'query' => ['term' => ['service' => 'api']],
                'aggs' => ['per_host' => ['terms' => ['field' => 'host']]],
                'size' => 20,
                '_source' => false,
            ],
            Kind::BROWSE,
        ],
        'a size 0 with no buckets asks for a total, which is also no documents' => [
            ['query' => ['term' => ['service' => 'api']], 'size' => 0],
            Kind::AGGREGATE,
        ],
        'a facet aggregation may hold a match, and it is not what the request asks' => [
            [
                'query' => ['term' => ['category' => 'boots']],
                'aggs' => ['matching' => [
                    'filter' => ['match_phrase_prefix' => ['title' => 'water']],
                    'aggs' => ['per_brand' => ['terms' => ['field' => 'brand']]],
                ]],
                'size' => 20,
            ],
            Kind::BROWSE,
        ],

        // ---- scan ----------------------------------------------------------
        'search_after walks a result set' => [
            [
                'query' => ['term' => ['service' => 'api']],
                'sort' => [['@timestamp' => 'asc']],
                'search_after' => [1724832000000],
                'size' => 500,
            ],
            Kind::SCAN,
        ],
        'so does a point in time' => [
            [
                'query' => ['match_all' => []],
                'pit' => ['id' => 'abc', 'keep_alive' => '1m'],
                'size' => 1000,
            ],
            Kind::SCAN,
        ],
        'and a slice, which is one worker of a parallel walk' => [
            [
                'query' => ['match_all' => []],
                'slice' => ['id' => 0, 'max' => 4],
                'size' => 1000,
            ],
            Kind::SCAN,
        ],
        'a big page is not a walk: no cursor, no scan' => [
            ['query' => ['term' => ['service' => 'api']], 'size' => 5000],
            Kind::BROWSE,
        ],

        // ---- lookup --------------------------------------------------------
        'ids names documents outright' => [
            ['query' => ['ids' => ['values' => ['a1', 'b2', 'c3']]]],
            Kind::LOOKUP,
        ],
        'a tenant filter beside the ids is still a fetch' => [
            [
                'query' => ['bool' => ['filter' => [
                    ['term' => ['tenant' => 178]],
                    ['ids' => ['values' => ['a1', 'b2']]],
                ]]],
            ],
            Kind::LOOKUP,
        ],
        'one clause on a business key is what fetching by key looks like' => [
            ['query' => ['term' => ['sku' => 'AB-1234']], 'size' => 1],
            Kind::LOOKUP,
        ],
        'eight statuses and a country is a filtered list, not a fetch' => [
            [
                'query' => ['bool' => ['filter' => [
                    ['terms' => ['status' => ['new', 'paid', 'shipped', 'cancelled']]],
                    ['term' => ['country' => 'FR']],
                ]]],
            ],
            Kind::BROWSE,
        ],
        'asking for an order is asking for a result set' => [
            ['query' => ['term' => ['sku' => 'AB-1234']], 'sort' => [['price' => 'asc']]],
            Kind::BROWSE,
        ],
        'but page one written out is not asking for a page at all' => [
            ['query' => ['term' => ['sku' => 'AB-1234']], 'from' => 0],
            Kind::LOOKUP,
        ],
        'so is asking for page three' => [
            ['query' => ['term' => ['sku' => 'AB-1234']], 'from' => 40],
            Kind::BROWSE,
        ],
        'a fetch asks for a document, not for five thousand of them' => [
            ['query' => ['term' => ['service' => 'api']], 'size' => 5000],
            Kind::BROWSE,
        ],
        'but naming ids may ask for as many as it named' => [
            ['query' => ['ids' => ['values' => ['a1', 'b2']]], 'size' => 5000],
            Kind::LOOKUP,
        ],
        'a nested identity clause is still naming a document' => [
            ['query' => ['nested' => [
                'path' => 'items',
                'query' => ['term' => ['items.sku' => 'AB-1234']],
            ]]],
            Kind::LOOKUP,
        ],
        'and so is one behind a join' => [
            ['query' => ['has_child' => [
                'type' => 'review',
                'query' => ['ids' => ['values' => ['r1']]],
            ]]],
            Kind::LOOKUP,
        ],
        'a fetch does not count things beside itself' => [
            [
                'query' => ['term' => ['sku' => 'AB-1234']],
                'aggs' => ['per_shop' => ['terms' => ['field' => 'shop']]],
            ],
            Kind::BROWSE,
        ],
        'excluding is describing, not naming' => [
            [
                'query' => ['bool' => ['must_not' => [['term' => ['status' => 'draft']]]]],
            ],
            Kind::BROWSE,
        ],

        // ---- unknown -------------------------------------------------------
        'documents come back and nothing in the query can be read' => [
            [
                'query' => ['span_near' => [
                    'clauses' => [['span_term' => ['message' => 'connection']]],
                    'slop' => 2,
                ]],
                'size' => 10,
            ],
            Kind::UNKNOWN,
        ],
        'a clause nobody could read is not a fetch, whatever sits beside it' => [
            [
                'query' => ['bool' => ['filter' => [
                    ['span_near' => ['clauses' => [['span_term' => ['message' => 'connection']]], 'slop' => 2]],
                    ['term' => ['service' => 'api']],
                ]]],
                'size' => 10,
            ],
            Kind::BROWSE,
        ],
        'an unreadable query that returns no documents is still a count' => [
            [
                'query' => ['span_near' => [
                    'clauses' => [['span_term' => ['message' => 'connection']]],
                    'slop' => 2,
                ]],
                'size' => 0,
            ],
            Kind::AGGREGATE,
        ],

        'a negation still reaches what it negates: this excludes the unreadable' => [
            [
                'query' => ['bool' => ['must_not' => [
                    ['span_near' => ['clauses' => [['span_term' => ['msg' => 'refused']]], 'slop' => 2]],
                ]]],
                'size' => 10,
            ],
            Kind::UNKNOWN,
        ],

        // ---- browse --------------------------------------------------------
        'the ordinary search' => [
            [
                'query' => ['bool' => [
                    'must' => [['match' => ['title' => 'waterproof boots']]],
                    'filter' => [['term' => ['shop' => 'fr']]],
                ]],
                'size' => 20,
                'sort' => [['_score' => 'desc']],
            ],
            Kind::BROWSE,
        ],
        'and an empty request, which asks for ten of everything' => [
            [],
            Kind::BROWSE,
        ],
    ];

    public function testEveryRequestIsTheKindTheTableSaysItIs(): void
    {
        $formatter = Formatter::create();
        $expected = [];
        $actual = [];

        foreach (self::CASES as $name => [$request, $kind]) {
            $expected[$name] = $kind;
            $actual[$name] = $formatter->describe($request, 'catalog')->kind()->name();
        }

        self::assertSame($expected, $actual);
    }

    /**
     * `post_filter` narrows the hits, so it selects — and the model keeps it in
     * a slot of its own, which is precisely the slot a classifier reading the
     * body's `query` key would miss.
     */
    public function testThePostFilterSelectsToo(): void
    {
        $digest = Formatter::create()->describe([
            'query' => ['match_all' => []],
            'post_filter' => ['match_phrase_prefix' => ['title' => 'water']],
            'size' => 10,
        ], 'catalog');

        self::assertSame(Kind::SUGGEST, $digest->kind()->name());
    }

    /**
     * The kind describes the request, not the settings a digest was minted
     * with. Two deployments grouping the same traffic differently must still
     * agree on what that traffic *is*.
     */
    public function testTheKindDoesNotDependOnHowMuchTheSignatureErases(): void
    {
        $request = [
            'query' => ['bool' => ['filter' => [['terms' => ['status' => [500, 502]]]]]],
            'size' => 20,
            'from' => 40,
        ];

        $kinds = [];
        foreach (Normalization::LEVELS as $level) {
            $kinds[] = Formatter::create(
                Options::create()->withNormalization(Normalization::fromLevel($level)),
            )->describe($request, 'logs')->kind()->name();
        }

        self::assertSame([Kind::BROWSE, Kind::BROWSE, Kind::BROWSE], $kinds);
    }

    /**
     * A value-free digest keeps its kind. That is the whole reason the kind is
     * read off the parsed model rather than off the values: the deployment that
     * cannot ship what a user typed is the one that most needs to know what its
     * traffic is made of.
     */
    public function testTheKindSurvivesADigestWithNoValuesInIt(): void
    {
        $request = ['query' => ['match_phrase_prefix' => ['name' => 'ada lov']], 'size' => 8];

        $digest = Formatter::create(Options::create()->withText(false))->describe($request, 'members');

        self::assertSame(Kind::SUGGEST, $digest->kind()->name());
        self::assertSame(Kind::SUGGEST, $digest->toArray()['kind']);
        self::assertStringNotContainsString('ada lov', (string) json_encode($digest));
    }

    /**
     * `scroll` travels beside `body`, not in it — the one signal that is only
     * visible in the envelope, and the reason the parser reads it there.
     */
    public function testAScrollingSearchIsAScanEvenThoughScrollIsNotInTheBody(): void
    {
        $digest = Formatter::create()->describe([
            'index' => 'logs-2026.08.13',
            'scroll' => '1m',
            'body' => ['query' => ['term' => ['service' => 'api']], 'size' => 1000],
        ]);

        self::assertSame(Kind::SCAN, $digest->kind()->name());

        // And the same search without the scroll is not one — the envelope is
        // read, rather than every large page being called a walk.
        $page = Formatter::create()->describe([
            'index' => 'logs-2026.08.13',
            'body' => ['query' => ['term' => ['service' => 'api']], 'size' => 1000],
        ]);

        self::assertSame(Kind::BROWSE, $page->kind()->name());
    }

    /**
     * An empty section is what a request builder leaves behind when the feature
     * is switched off, and it does not make the request a type-ahead.
     */
    public function testAnEmptySectionIsNotASection(): void
    {
        $digest = Formatter::create()->describe([
            'query' => ['match' => ['title' => 'boots']],
            'suggest' => [],
            'search_after' => [],
            'size' => 20,
        ], 'catalog');

        self::assertSame(Kind::BROWSE, $digest->kind()->name());
    }

    /**
     * Nor is a section of the wrong shape. Every section read for the kind is
     * written as an object or a list; anything else is a body this library was
     * handed rather than one a client built, and reading it as a feature being
     * on would classify junk confidently.
     */
    public function testASectionOfTheWrongShapeIsNotASectionEither(): void
    {
        $digest = Formatter::create()->describe([
            'query' => ['match' => ['title' => 'boots']],
            'suggest' => 'off',
            'pit' => 'abc',
            'size' => 20,
        ], 'catalog');

        self::assertSame(Kind::BROWSE, $digest->kind()->name());
    }

    /**
     * The classification must not reach the line, the signature or the hash. A
     * `match_phrase_prefix` renders as the phrase it refines — see `LeafNode` —
     * so promoting the op moved no fingerprint, and this is the assertion that
     * says so in one place rather than across nineteen golden files.
     */
    public function testACompletionOpRendersAsTheOpItRefines(): void
    {
        $formatter = Formatter::create();

        $completion = $formatter->describe(['query' => ['match_phrase_prefix' => ['title' => 'water']]], 'catalog');
        $phrase = $formatter->describe(['query' => ['match_phrase' => ['title' => 'water']]], 'catalog');

        self::assertSame($phrase->text(), $completion->text());
        self::assertSame($phrase->signature(), $completion->signature());
        self::assertSame($phrase->hash(), $completion->hash());

        // Same again for the bool spelling, which refines `match`.
        $boolPrefix = $formatter->describe(['query' => ['match_bool_prefix' => ['title' => 'water']]], 'catalog');
        $match = $formatter->describe(['query' => ['match' => ['title' => 'water']]], 'catalog');

        self::assertSame($match->hash(), $boolPrefix->hash());

        // And they are not the same *kind*, which is the whole point of having
        // told them apart in the tree.
        self::assertSame(Kind::SUGGEST, $completion->kind()->name());
        self::assertSame(Kind::BROWSE, $phrase->kind()->name());
    }

    /**
     * Siblings are ordered by a key that includes the op, so a completion op
     * sorting under its own name would reorder the clauses around it — and move
     * a fingerprint with nothing to show for it in the line. `phrase_prefix`
     * falls between `match` and `phrase`, so this pair is the one that would
     * flip.
     */
    public function testACompletionOpSortsAsTheOpItRefines(): void
    {
        $formatter = Formatter::create();

        $completion = $formatter->describe(['query' => ['bool' => ['filter' => [
            ['match_phrase_prefix' => ['a' => 'x']],
            ['match' => ['b' => 'y']],
        ]]]], 'catalog');

        $phrase = $formatter->describe(['query' => ['bool' => ['filter' => [
            ['match_phrase' => ['a' => 'x']],
            ['match' => ['b' => 'y']],
        ]]]], 'catalog');

        self::assertSame($phrase->signature(), $completion->signature());
    }
}
