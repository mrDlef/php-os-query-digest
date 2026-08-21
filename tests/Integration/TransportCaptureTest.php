<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Http\DigestingClient;
use MrDlef\OsQueryDigest\Http\Guzzle\DigestMiddleware;
use MrDlef\OsQueryDigest\Http\ObservedSearch;
use MrDlef\OsQueryDigest\Http\SearchObserver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The transport capture against a node that answers.
 *
 * `DigestingClientTest` proves the decorator against a client that returns what
 * it is told to, which is the wrong instrument for two of the claims made about
 * it. Only a real request can show that a body read for its digest still
 * *arrives* — a mock never sends anything. And only a real response can show
 * that `took` is where the peek looks for it: that it sits at the front of the
 * body is an observation about OpenSearch's serialiser, not a rule anyone
 * published, so it is asserted here rather than assumed in `Recorder`.
 *
 *     OPENSEARCH_URL=http://localhost:9202 vendor/bin/phpunit --testsuite=integration
 *
 * Skipped without `OPENSEARCH_URL`, like everything else in this suite.
 */
final class TransportCaptureTest extends TestCase
{
    private const INDEX = 'os-query-digest-transport';

    private const BODY = '{"query":{"bool":{"filter":[{"term":{"service":"api"}}],'
        . '"must_not":[{"term":{"status":200}}]}},"size":5}';

    private string $url = '';

    protected function setUp(): void
    {
        $url = getenv('OPENSEARCH_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set OPENSEARCH_URL to run against a cluster.');
        }

        $this->url = rtrim($url, '/');

        $bare = new Client(['base_uri' => $this->url, 'http_errors' => false]);
        $bare->delete('/' . self::INDEX);
        $bare->put('/' . self::INDEX, [
            'json' => ['mappings' => ['properties' => [
                'service' => ['type' => 'keyword'],
                'status' => ['type' => 'integer'],
            ]]],
        ]);
        $bare->post('/' . self::INDEX . '/_doc?refresh=true', [
            'json' => ['service' => 'api', 'status' => 500],
        ]);
    }

    public function testTheSearchStillRunsAndTheDigestIsTheOneItWouldBe(): void
    {
        $observer = self::observer();
        $client = new DigestingClient(new Client(['http_errors' => false]), $observer);

        $response = $client->sendRequest(
            new Request(
                'POST',
                $this->url . '/' . self::INDEX . '/_search',
                ['Content-Type' => 'application/json'],
                self::BODY,
            ),
        );

        // The body reached the node: a request read for its digest and not put
        // back would have arrived empty, and an empty body is a match_all.
        self::assertSame(200, $response->getStatusCode());
        $answer = self::decode((string) $response->getBody());
        self::assertSame(1, self::hitCount($answer), 'The node did not run the query that was sent.');

        self::assertCount(1, $observer->seen);
        $seen = $observer->seen[0];

        self::assertSame(
            Formatter::create()->describe(self::BODY, self::INDEX)->hash(),
            $seen->digest()->digest()->hash(),
        );
        self::assertSame(self::INDEX, $seen->digest()->digest()->toArray()['idx']);
    }

    public function testTheTookThatIsReportedIsTheOneTheNodeReturned(): void
    {
        // The guard on the peek: it reads a fixed number of bytes off the front
        // of the response, which only finds `took` while OpenSearch keeps
        // writing it first. This fails the day that changes, on the version it
        // changed on, instead of quietly reporting null for every search.
        $observer = self::observer();
        $client = new DigestingClient(new Client(['http_errors' => false]), $observer);

        $response = $client->sendRequest(
            new Request(
                'POST',
                $this->url . '/' . self::INDEX . '/_search',
                ['Content-Type' => 'application/json'],
                self::BODY,
            ),
        );

        $answer = self::decode((string) $response->getBody());
        self::assertArrayHasKey('took', $answer);
        self::assertIsInt($answer['took']);

        self::assertSame($answer['took'], $observer->seen[0]->tookMillis());

        // Wall clock covers the node's own time plus the network, so it cannot
        // be the smaller of the two.
        self::assertGreaterThanOrEqual((float) $answer['took'], $observer->seen[0]->elapsedMillis());
    }

    public function testABatchIsCountedLineByLineAndItsTookIsNotSplit(): void
    {
        $body = '{}' . "\n" . self::BODY . "\n"
            . '{"index":"' . self::INDEX . '"}' . "\n" . '{"query":{"match_all":{}}}' . "\n";

        $observer = self::observer();
        $client = new DigestingClient(new Client(['http_errors' => false]), $observer);

        $response = $client->sendRequest(
            new Request(
                'POST',
                $this->url . '/' . self::INDEX . '/_msearch',
                ['Content-Type' => 'application/x-ndjson'],
                $body,
            ),
        );

        self::assertSame(200, $response->getStatusCode());
        $answer = self::decode((string) $response->getBody());

        // What the node sends back, and the reason each line reports no `took`:
        // the number at the top of this body is the batch's, and every line has
        // one of its own further in, past the hits of the line before it.
        self::assertArrayHasKey('took', $answer);
        self::assertArrayHasKey('responses', $answer);
        self::assertIsArray($answer['responses']);
        self::assertCount(2, $answer['responses']);

        self::assertCount(2, $observer->seen);
        self::assertSame([0, 1], [$observer->seen[0]->position(), $observer->seen[1]->position()]);
        self::assertNull($observer->seen[0]->tookMillis());
        self::assertNull($observer->seen[1]->tookMillis());

        // And both lines ran: the first filters to one document, the second
        // matches everything.
        $first = $answer['responses'][0] ?? null;
        self::assertIsArray($first);

        /** @var array<string,mixed> $first */
        self::assertSame(1, self::hitCount($first));
    }

    public function testTheGuzzleMiddlewareCapturesWhatItSendsAsynchronously(): void
    {
        // The path a PSR-18 decorator cannot see, against a real node.
        $observer = self::observer();

        $stack = HandlerStack::create();
        $stack->push(new DigestMiddleware($observer));
        $client = new Client(['handler' => $stack, 'base_uri' => $this->url, 'http_errors' => false]);

        $response = $client->postAsync('/' . self::INDEX . '/_search', [
            'body' => self::BODY,
            'headers' => ['Content-Type' => 'application/json'],
        ])->wait();

        self::assertInstanceOf(ResponseInterface::class, $response);
        $answer = self::decode((string) $response->getBody());
        self::assertSame(1, self::hitCount($answer));

        self::assertCount(1, $observer->seen);
        self::assertSame($answer['took'] ?? null, $observer->seen[0]->tookMillis());
    }

    /**
     * @param array<string,mixed> $answer
     */
    private static function hitCount(array $answer): int
    {
        $hits = $answer['hits'] ?? null;
        self::assertIsArray($hits);
        $list = $hits['hits'] ?? null;
        self::assertIsArray($list);

        return count($list);
    }

    /**
     * @return array<string,mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'The node did not answer with JSON.');

        /** @var array<string,mixed> $decoded */
        return $decoded;
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
}
