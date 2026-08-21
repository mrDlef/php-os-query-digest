<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Capture\CapturedSearch;
use MrDlef\OsQueryDigest\Capture\SearchExtractor;
use PHPUnit\Framework\TestCase;

/**
 * What the transport integrations recognise as a search, and what they leave
 * alone.
 *
 * The stakes are asymmetric, and the cases below are chosen for it. A search
 * this misses costs a digest. A request it *wrongly* reads as a search costs a
 * fingerprint minted for a body that has no query in it — and one read with the
 * wrong index costs a fingerprint that no dashboard built on the real one will
 * ever match. So the near-misses are pinned as hard as the hits.
 */
final class SearchExtractorTest extends TestCase
{
    private const BODY = '{"query":{"term":{"service":"api"}}}';

    public function testReadsASearch(): void
    {
        $paths = [
            'every index' => ['/_search', null],
            'one index' => ['/logs-2026.08.21/_search', 'logs-2026.08.21'],
            // Left whole: splitting the list is IndexNormalizer's job, and it
            // sorts and dedupes them on the way.
            'a list of indices' => ['/logs-a,logs-b/_search', 'logs-a,logs-b'],
            'a pattern' => ['/logs-*/_search', 'logs-*'],
            // An index expression may start with an underscore, which is why
            // the endpoint is found from the right rather than the left.
            'every index, spelled out' => ['/_all/_search', '_all'],
            'no leading slash' => ['logs-*/_search', 'logs-*'],
            'a trailing slash' => ['/logs-*/_search/', 'logs-*'],
            'query parameters' => ['/logs-*/_search?routing=api&preference=_local', 'logs-*'],
            'a fragment' => ['/logs-*/_search#anchor', 'logs-*'],
            // Split on `/` first, decode after: this name contains a slash.
            'a date-math name' => ['/%3Clogs-%7Bnow%2Fd%7D%3E/_search', '<logs-{now/d}>'],
            'an encoded space' => ['/my%20index/_search', 'my index'],
        ];

        $extractor = SearchExtractor::create();

        foreach ($paths as $case => [$path, $index]) {
            $searches = $extractor->extract('POST', $path, self::BODY);

            self::assertCount(1, $searches, $case);
            self::assertSame($index, $searches[0]->index(), $case);
            self::assertSame(self::BODY, $searches[0]->body(), $case);
        }
    }

    public function testReadsASearchOnGet(): void
    {
        // GET with a body is legal on `_search`, and some clients still send it.
        self::assertCount(1, SearchExtractor::create()->extract('GET', '/_search', self::BODY));
    }

    public function testLeavesEverythingElseAlone(): void
    {
        $requests = [
            // `_search` is a prefix of three endpoints that carry no query.
            'a scroll' => ['POST', '/_search/scroll', '{"scroll_id":"abc"}'],
            'a search template' => ['POST', '/logs-*/_search/template', '{"id":"t","params":{}}'],
            'an msearch template' => ['POST', '/_msearch/template', "{}\n{\"id\":\"t\"}\n"],
            'a point in time' => ['POST', '/logs-*/_search/point_in_time', '{}'],

            'an indexing request' => ['POST', '/logs-*/_doc', self::BODY],
            'a bulk' => ['POST', '/_bulk', "{\"index\":{}}\n{\"a\":1}\n"],
            'a count' => ['POST', '/logs-*/_count', self::BODY],
            'a mapping read' => ['GET', '/logs-*/_mapping', self::BODY],
            'a document read' => ['GET', '/logs-*/_doc/1', self::BODY],
            'no endpoint at all' => ['GET', '/logs-*', self::BODY],
            'the root' => ['GET', '/', self::BODY],

            // The removed mapping-type form. Calling either segment the index
            // would be a name of our choosing in someone's fingerprint.
            'a typed search' => ['POST', '/logs-*/doc/_search', self::BODY],

            // A URI search puts everything in the query string, so there is no
            // shape to read.
            'an empty body' => ['GET', '/logs-*/_search?q=service:api', ''],

            'a method that carries no query' => ['DELETE', '/logs-*/_search', self::BODY],
        ];

        $extractor = SearchExtractor::create();

        foreach ($requests as $case => [$method, $path, $body]) {
            self::assertSame([], $extractor->extract($method, $path, $body), $case);
        }
    }

    public function testAMethodIsMatchedWhateverItsCase(): void
    {
        self::assertCount(1, SearchExtractor::create()->extract('post', '/_search', self::BODY));
    }

    public function testAPrefixedClusterNamesItsPrefix(): void
    {
        $extractor = SearchExtractor::create('/opensearch');

        $searches = $extractor->extract('POST', '/opensearch/logs-*/_search', self::BODY);

        self::assertCount(1, $searches);
        self::assertSame('logs-*', $searches[0]->index());
    }

    public function testAPrefixIsNotReadAsAnIndex(): void
    {
        // The point of configuring it: without the prefix, `opensearch` is
        // indistinguishable from an index name and lands in the signature.
        $unaware = SearchExtractor::create()->extract('POST', '/opensearch/_search', self::BODY);
        self::assertSame('opensearch', $unaware[0]->index());

        $aware = SearchExtractor::create('opensearch')->extract('POST', '/opensearch/_search', self::BODY);
        self::assertNull($aware[0]->index());
    }

    public function testAPrefixIsMatchedOnSegmentBoundaries(): void
    {
        // `/oscar/_search` is not a request to a cluster mounted at `/os`, and
        // reading it as one would leave `car` as the index name.
        self::assertSame(
            [],
            SearchExtractor::create('/os')->extract('POST', '/oscar/_search', self::BODY),
        );
    }

    public function testARequestOutsideThePrefixIsNotOurs(): void
    {
        self::assertSame(
            [],
            SearchExtractor::create('/opensearch')->extract('POST', '/other/logs-*/_search', self::BODY),
        );
    }

    public function testTheMsearchLinesEachBecomeASearch(): void
    {
        $body = implode("\n", [
            '{}',
            '{"query":{"term":{"service":"api"}}}',
            '{"index":"metrics-*"}',
            '{"query":{"match_all":{}}}',
            '{"index":["a","b"]}',
            '{"query":{"term":{"status":500}}}',
        ]) . "\n";

        $searches = SearchExtractor::create()->extract('POST', '/logs-*/_msearch', $body);

        self::assertSame(
            [
                // An empty header takes the URL's index…
                ['logs-*', '{"query":{"term":{"service":"api"}}}'],
                // …and a header that names one overrides it.
                ['metrics-*', '{"query":{"match_all":{}}}'],
                ['a,b', '{"query":{"term":{"status":500}}}'],
            ],
            self::pairs($searches),
        );
    }

    public function testAnMsearchWithoutAnIndexInTheUrl(): void
    {
        $body = "{}\n{\"query\":{\"match_all\":{}}}\n";

        $searches = SearchExtractor::create()->extract('POST', '/_msearch', $body);

        self::assertSame([[null, '{"query":{"match_all":{}}}']], self::pairs($searches));
    }

    public function testAnUnreadableMsearchHeaderKeepsItsSearch(): void
    {
        // The pairing is positional, so the body on the next line is still a
        // search — one aimed at the URL's index rather than a more precise one.
        $body = "not json\n{\"query\":{\"match_all\":{}}}\n";

        $searches = SearchExtractor::create()->extract('POST', '/logs-*/_msearch', $body);

        self::assertSame([['logs-*', '{"query":{"match_all":{}}}']], self::pairs($searches));
    }

    public function testAnEmptyIndexInAnMsearchHeaderFallsBackToTheUrl(): void
    {
        $body = "{\"index\":\"\"}\n{\"query\":{\"match_all\":{}}}\n"
            . "{\"index\":[]}\n{\"query\":{\"term\":{\"a\":1}}}\n";

        $searches = SearchExtractor::create()->extract('POST', '/logs-*/_msearch', $body);

        self::assertSame(
            [
                ['logs-*', '{"query":{"match_all":{}}}'],
                ['logs-*', '{"query":{"term":{"a":1}}}'],
            ],
            self::pairs($searches),
        );
    }

    public function testATruncatedMsearchDropsTheDanglingHeader(): void
    {
        // A header with nothing after it is not a search, and digesting it
        // would fingerprint a routing line.
        $body = "{}\n{\"query\":{\"match_all\":{}}}\n{\"index\":\"metrics-*\"}\n";

        $searches = SearchExtractor::create()->extract('POST', '/logs-*/_msearch', $body);

        self::assertSame([['logs-*', '{"query":{"match_all":{}}}']], self::pairs($searches));
    }

    public function testABlankLineBetweenPairsIsSkippedRatherThanFinal(): void
    {
        // A blank line is padding, not a terminator: stopping there would drop
        // every search after it and report a short batch as a complete one.
        $body = "{}\n" . self::BODY . "\n"
            . "  \n"
            . '{"index":"metrics-*"}' . "\n" . '{"query":{"term":{"a":1}}}' . "\n";

        $searches = SearchExtractor::create()->extract('POST', '/logs-*/_msearch', $body);

        self::assertSame(
            [
                ['logs-*', self::BODY],
                ['metrics-*', '{"query":{"term":{"a":1}}}'],
            ],
            self::pairs($searches),
        );
    }

    public function testMsearchLineEndingsAndBlankLines(): void
    {
        // CRLF. The `\r` has to come off the line: it would otherwise ride
        // along inside the body handed to the parser.
        $body = "{}\r\n{\"query\":{\"match_all\":{}}}\r\n\r\n";

        $searches = SearchExtractor::create()->extract('POST', '/logs-*/_msearch', $body);

        self::assertSame([['logs-*', '{"query":{"match_all":{}}}']], self::pairs($searches));
    }

    /**
     * @param array<int,CapturedSearch> $searches
     *
     * @return array<int,array{0:?string,1:string}>
     */
    private static function pairs(array $searches): array
    {
        $pairs = [];
        foreach ($searches as $search) {
            $pairs[] = [$search->index(), $search->body()];
        }

        return $pairs;
    }
}
