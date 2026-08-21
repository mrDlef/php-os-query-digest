<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Http\DigestingClient;
use MrDlef\OsQueryDigest\Http\LoggingObserver;
use MrDlef\OsQueryDigest\Http\ObservedSearch;
use MrDlef\OsQueryDigest\Http\SearchObserver;
use MrDlef\OsQueryDigest\IndexNormalizer;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

/**
 * Digesting at the transport, where nothing in the application had to change.
 *
 * Most of what is asserted here is *absence of damage*. A decorator in the path
 * of every search has one way to be useful and several ways to be a liability:
 * a request body read and not put back is a request that leaves without its
 * query, a response body consumed for its `took` is an answer the caller never
 * sees, and an exception raised while digesting is an outage caused by logging.
 * So the tests that matter are the ones where the digest is *not* the thing
 * being checked.
 */
final class DigestingClientTest extends TestCase
{
    private const BODY = '{"query":{"bool":{"filter":[{"term":{"service":"api"}}]}}}';

    public function testASearchIsDigestedWithWhatItCost(): void
    {
        $observer = self::observer();
        $client = new DigestingClient(
            self::client(new Response(200, [], '{"took":7,"timed_out":false,"hits":{"total":1}}')),
            $observer,
        );

        $client->sendRequest(new Request('POST', 'http://localhost:9200/logs-2026.08.21/_search', [], self::BODY));

        self::assertCount(1, $observer->seen);
        $seen = $observer->seen[0];

        // The body and the index reached the formatter intact — the hash is the
        // one a direct call on the same two produces.
        self::assertSame(
            Formatter::create()->describe(self::BODY, 'logs-2026.08.21')->hash(),
            $seen->digest()->digest()->hash(),
        );
        self::assertStringContainsString('logs-*', $seen->digest()->digest()->text());
        self::assertSame(7, $seen->tookMillis());
        self::assertSame(200, $seen->statusCode());
        self::assertNull($seen->position());
        // Bounded rather than merely non-negative: the arithmetic that turns
        // two microtime() readings into milliseconds has several ways to be
        // wrong that all still produce a plausible-looking number.
        //
        // And non-negative rather than positive. A client that answers from
        // memory can return inside one microtime() tick, so a strict `> 0`
        // here fails a few runs in a hundred — which is worse than the
        // assertion is worth.
        self::assertGreaterThanOrEqual(0.0, $seen->elapsedMillis());
        self::assertLessThan(60000.0, $seen->elapsedMillis());
    }

    public function testTheRequestReachesTheInnerClientWhole(): void
    {
        // The failure this guards is a search leaving without its query: the
        // body was read for the digest and never rewound.
        $inner = self::client(new Response(200, [], '{"took":1}'));
        $client = new DigestingClient($inner, self::observer());

        $request = new Request('POST', 'http://localhost:9200/logs-*/_search', [], self::BODY);
        $client->sendRequest($request);

        self::assertNotNull($inner->seen);

        // getContents() rather than a string cast, and that is the whole point:
        // the cast rewinds first, so it would pass over a body left sitting at
        // its end. A client that reads it the plain way would have sent nothing.
        self::assertSame(self::BODY, $inner->seen->getBody()->getContents());

        // And the caller's own handle on it still reads.
        self::assertSame(self::BODY, (string) $request->getBody());
    }

    public function testTheResponseIsStillWhole(): void
    {
        $answer = '{"took":7,"timed_out":false,"hits":{"hits":[{"_id":"1"}]}}';
        $client = new DigestingClient(self::client(new Response(200, [], $answer)), self::observer());

        $response = $client->sendRequest(new Request('POST', '/logs-*/_search', [], self::BODY));

        // Peeking at the front for `took` must leave nothing consumed.
        self::assertSame($answer, (string) $response->getBody());
    }

    public function testABodyTheClientHadAlreadyReadIsReadWhole(): void
    {
        // Nothing promises the stream arrives at its start. Digesting from
        // wherever it happens to sit would fingerprint a fragment of the query
        // — and the position has to go back exactly where it was found.
        $stream = Utils::streamFor(self::BODY);
        $stream->read(9);

        $inner = self::client(new Response(200, [], '{"took":1}'));
        $observer = self::observer();

        (new DigestingClient($inner, $observer))->sendRequest(
            (new Request('POST', '/logs-*/_search'))->withBody($stream),
        );

        self::assertCount(1, $observer->seen);
        self::assertSame(
            Formatter::create()->describe(self::BODY, 'logs-*')->hash(),
            $observer->seen[0]->digest()->digest()->hash(),
        );
        self::assertSame(9, $stream->tell());
    }

    public function testTheFormatterItIsGivenIsTheOneItUses(): void
    {
        // Options reach the digest through the decorator, or configuring them
        // is silently ignored on every search the transport captures.
        $observer = self::observer();
        $formatter = Formatter::create(Options::create()->withIndexNormalizer(IndexNormalizer::identity()));

        (new DigestingClient(self::client(new Response(200, [], '{"took":1}')), $observer, $formatter))
            ->sendRequest(new Request('POST', '/logs-2026.08.21/_search', [], self::BODY));

        self::assertStringContainsString(
            'logs-2026.08.21',
            $observer->seen[0]->digest()->digest()->text(),
        );
    }

    public function testAnUnseekableRequestBodyIsNotTouched(): void
    {
        // Nothing could put it back, so it is not read. The digest is lost and
        // the request is not.
        $inner = self::client(new Response(200, [], '{"took":1}'));
        $observer = self::observer();

        $request = (new Request('POST', '/logs-*/_search'))
            ->withBody(new NoSeekStream(Utils::streamFor(self::BODY)));

        (new DigestingClient($inner, $observer))->sendRequest($request);

        self::assertSame([], $observer->seen);
        self::assertNotNull($inner->seen);
        self::assertSame(self::BODY, (string) $inner->seen->getBody());
    }

    public function testAnUnseekableResponseBodyCostsOnlyTheTook(): void
    {
        $observer = self::observer();
        $response = (new Response(200))->withBody(new NoSeekStream(Utils::streamFor('{"took":7}')));

        (new DigestingClient(self::client($response), $observer))
            ->sendRequest(new Request('POST', '/logs-*/_search', [], self::BODY));

        self::assertCount(1, $observer->seen);
        self::assertNull($observer->seen[0]->tookMillis());
        self::assertSame(200, $observer->seen[0]->statusCode());
    }

    public function testAResponseThatDoesNotOpenWithItsTook(): void
    {
        $observer = self::observer();

        (new DigestingClient(self::client(new Response(200, [], '{"hits":{"total":0}}')), $observer))
            ->sendRequest(new Request('POST', '/logs-*/_search', [], self::BODY));

        self::assertNull($observer->seen[0]->tookMillis());
    }

    public function testEverythingThatIsNotASearchPassesThrough(): void
    {
        $inner = self::client(new Response(201, [], '{"result":"created"}'));
        $observer = self::observer();

        $response = (new DigestingClient($inner, $observer))
            ->sendRequest(new Request('POST', '/logs-*/_doc', [], self::BODY));

        self::assertSame([], $observer->seen);
        self::assertSame(201, $response->getStatusCode());
    }

    public function testAFailedSearchIsCountedAndTheErrorRethrown(): void
    {
        // The shape that times out is the shape worth finding, so it is
        // observed — and the caller's error handling is left exactly as it was.
        $error = new class ('gone') extends \RuntimeException implements ClientExceptionInterface {};

        $observer = self::observer();
        $client = new DigestingClient(self::failing($error), $observer);

        try {
            $client->sendRequest(new Request('POST', '/logs-*/_search', [], self::BODY));
            self::fail('The client exception was swallowed.');
        } catch (ClientExceptionInterface $caught) {
            self::assertSame($error, $caught);
        }

        self::assertCount(1, $observer->seen);
        self::assertNull($observer->seen[0]->statusCode());
        self::assertNull($observer->seen[0]->tookMillis());
    }

    public function testAnObserverThatThrowsDoesNotCostTheRequest(): void
    {
        $client = new DigestingClient(
            self::client(new Response(200, [], '{"took":7}')),
            new class implements SearchObserver {
                public function observe(ObservedSearch $search): void
                {
                    throw new \RuntimeException('the metrics backend is down');
                }
            },
        );

        $response = $client->sendRequest(new Request('POST', '/logs-*/_search', [], self::BODY));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAnUnparseableSearchStillPassesThrough(): void
    {
        // Laziness means the failure lands on whoever reads the digest, not on
        // the request. The request is what must survive.
        $observer = self::observer();

        $response = (new DigestingClient(self::client(new Response(200, [], '{"took":1}')), $observer))
            ->sendRequest(new Request('POST', '/logs-*/_search', [], '{"query":'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $observer->seen);
    }

    public function testEveryLineOfABatchIsItsOwnSearch(): void
    {
        $body = "{}\n" . self::BODY . "\n"
            . '{"index":"metrics-*"}' . "\n" . '{"query":{"match_all":{}}}' . "\n";

        $observer = self::observer();

        (new DigestingClient(self::client(new Response(200, [], '{"took":19,"responses":[]}')), $observer))
            ->sendRequest(new Request('POST', '/logs-*/_msearch', [], $body));

        self::assertCount(2, $observer->seen);
        self::assertSame(0, $observer->seen[0]->position());
        self::assertSame(1, $observer->seen[1]->position());
        self::assertStringContainsString('metrics-*', $observer->seen[1]->digest()->digest()->text());

        // 19 ms is what the batch cost, not what either line cost. Attributing
        // it to both would put the same number twice in a `took` aggregation.
        self::assertNull($observer->seen[0]->tookMillis());
        self::assertNull($observer->seen[1]->tookMillis());
    }

    public function testAPrefixedClusterIsConfigured(): void
    {
        $observer = self::observer();

        (new DigestingClient(
            self::client(new Response(200, [], '{"took":1}')),
            $observer,
            null,
            '/opensearch',
        ))->sendRequest(new Request('POST', 'http://gateway/opensearch/logs-*/_search', [], self::BODY));

        self::assertStringContainsString('logs-*', $observer->seen[0]->digest()->digest()->text());
    }

    public function testTheLoggingObserverWritesWhatTheDashboardsRead(): void
    {
        $logger = self::logger();

        (new DigestingClient(self::client(new Response(200, [], '{"took":7}')), new LoggingObserver($logger)))
            ->sendRequest(new Request('POST', '/logs-2026.08.21/_search', [], self::BODY));

        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];

        self::assertSame('info', $level);
        self::assertSame('opensearch.search', $message);
        self::assertSame(7, $context['took']);
        self::assertSame(200, $context['status']);
        // Whole milliseconds, the same unit as `took`.
        self::assertIsInt($context['elapsed_ms']);
        self::assertArrayNotHasKey('line', $context, 'A plain search has no line to report.');

        // `os` is the sub-object the index template maps.
        $encoded = json_encode($context['os']);
        self::assertIsString($encoded);
        $decoded = json_decode($encoded, true);
        self::assertIsArray($decoded);
        self::assertSame(['idx', 'q', 'sig', 'hash'], array_keys($decoded));
        self::assertSame('logs-*', $decoded['idx']);
    }

    public function testTheLoggingObserverKeepsTheLineWhenTheDigestFails(): void
    {
        $logger = self::logger();

        (new DigestingClient(self::client(new Response(200, [], '{"took":1}')), new LoggingObserver($logger)))
            ->sendRequest(new Request('POST', '/logs-*/_search', [], '{"query":'));

        self::assertCount(1, $logger->records);

        $encoded = json_encode($logger->records[0][2]['os']);
        self::assertIsString($encoded);
        self::assertStringContainsString('could not read this request', $encoded);
    }

    public function testTheLoggingObserverNumbersTheLinesOfABatch(): void
    {
        $logger = self::logger();
        $body = "{}\n" . self::BODY . "\n{}\n" . '{"query":{"match_all":{}}}' . "\n";

        (new DigestingClient(self::client(new Response(200, [], '{"took":9}')), new LoggingObserver($logger)))
            ->sendRequest(new Request('POST', '/logs-*/_msearch', [], $body));

        self::assertSame([0, 1], [$logger->records[0][2]['line'], $logger->records[1][2]['line']]);
        self::assertNull($logger->records[0][2]['took']);
    }

    /**
     * @return ClientInterface&object{seen: ?RequestInterface}
     */
    private static function client(ResponseInterface $response): object
    {
        return new class ($response) implements ClientInterface {
            public ?RequestInterface $seen = null;

            private ResponseInterface $response;

            public function __construct(ResponseInterface $response)
            {
                $this->response = $response;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return $this->response;
            }
        };
    }

    private static function failing(\Throwable $error): ClientInterface
    {
        return new class ($error) implements ClientInterface {
            private \Throwable $error;

            public function __construct(\Throwable $error)
            {
                $this->error = $error;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw $this->error;
            }
        };
    }

    /**
     * @return SearchObserver&object{seen: array<int,ObservedSearch>}
     */
    private static function observer(): object
    {
        return new class implements SearchObserver {
            /** @var array<int,ObservedSearch> */
            public array $seen = [];

            public function observe(ObservedSearch $search): void
            {
                $this->seen[] = $search;
            }
        };
    }

    /**
     * @return AbstractLogger&object{records: array<int,array{0:string,1:string,2:array<string,mixed>}>}
     */
    private static function logger(): object
    {
        return new class extends AbstractLogger {
            /** @var array<int,array{0:string,1:string,2:array<string,mixed>}> */
            public array $records = [];

            /**
             * Untyped where the two psr/log majors disagree: version 1 declares
             * no types on this method and version 3 declares several. Widening
             * is legal against both, and this only ever records.
             *
             * @param mixed        $level
             * @param mixed        $message
             * @param array<mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                /** @var array<string,mixed> $context */
                $this->records[] = [
                    is_string($level) ? $level : '',
                    is_string($message) ? $message : '',
                    $context,
                ];
            }
        };
    }
}
