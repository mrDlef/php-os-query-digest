<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MrDlef\OsQueryDigest\Http\Guzzle\DigestMiddleware;
use MrDlef\OsQueryDigest\Http\ObservedSearch;
use MrDlef\OsQueryDigest\Http\SearchObserver;
use PHPUnit\Framework\TestCase;

/**
 * The same capture, on the path a Guzzle client actually takes.
 *
 * `DigestingClientTest` covers what the two integrations share, and there is no
 * point repeating it. What is only true here is the reason this class exists:
 * the requests a Guzzle stack carries do not all go through `sendRequest`, so a
 * PSR-18 decorator sees none of the asynchronous ones — which is most of what
 * `opensearch-php` sends.
 */
final class DigestMiddlewareTest extends TestCase
{
    private const BODY = '{"query":{"term":{"service":"api"}}}';

    public function testASynchronousSearchIsDigested(): void
    {
        $observer = self::observer();
        $client = self::client($observer, [new Response(200, [], '{"took":7,"hits":{"total":1}}')]);

        $response = $client->post('http://localhost:9200/logs-2026.08.21/_search', ['body' => self::BODY]);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $observer->seen);
        self::assertSame(7, $observer->seen[0]->tookMillis());
        self::assertStringContainsString('logs-*', $observer->seen[0]->digest()->digest()->text());
    }

    public function testAnAsynchronousSearchIsDigestedToo(): void
    {
        // The whole reason for a middleware beside the decorator: nothing here
        // goes through ClientInterface::sendRequest().
        $observer = self::observer();
        $client = self::client($observer, [new Response(200, [], '{"took":3}')]);

        $response = $client->postAsync('http://localhost:9200/logs-*/_search', ['body' => self::BODY])->wait();

        self::assertInstanceOf(Response::class, $response);
        self::assertCount(1, $observer->seen);
        self::assertSame(3, $observer->seen[0]->tookMillis());
    }

    public function testTheRequestAndTheResponseSurviveIntact(): void
    {
        $answer = '{"took":7,"hits":{"hits":[{"_id":"1"}]}}';
        $handler = new MockHandler([new Response(200, [], $answer)]);
        $client = self::clientOn($handler, self::observer());

        $response = $client->post('http://localhost:9200/logs-*/_search', ['body' => self::BODY]);

        $sent = $handler->getLastRequest();
        self::assertNotNull($sent);
        self::assertSame(self::BODY, (string) $sent->getBody());
        self::assertSame($answer, (string) $response->getBody());
    }

    public function testEverythingThatIsNotASearchPassesThrough(): void
    {
        $observer = self::observer();
        $client = self::client($observer, [new Response(201, [], '{"result":"created"}')]);

        $response = $client->post('http://localhost:9200/logs-*/_doc', ['body' => self::BODY]);

        self::assertSame([], $observer->seen);
        self::assertSame(201, $response->getStatusCode());
    }

    public function testARejectedSearchIsCountedAndTheRejectionKept(): void
    {
        $request = new Request('POST', 'http://localhost:9200/logs-*/_search', [], self::BODY);
        $error = new ConnectException('cluster unreachable', $request);

        $observer = self::observer();
        $client = self::client($observer, [$error]);

        try {
            $client->send($request);
            self::fail('The rejection was swallowed.');
        } catch (ConnectException $caught) {
            self::assertSame($error, $caught);
        }

        self::assertCount(1, $observer->seen);
        self::assertNull($observer->seen[0]->statusCode());
    }

    public function testEveryLineOfABatchIsItsOwnSearch(): void
    {
        $body = "{}\n" . self::BODY . "\n"
            . '{"index":"metrics-*"}' . "\n" . '{"query":{"match_all":{}}}' . "\n";

        $observer = self::observer();
        $client = self::client($observer, [new Response(200, [], '{"took":19,"responses":[]}')]);

        $client->post('http://localhost:9200/logs-*/_msearch', ['body' => $body]);

        self::assertCount(2, $observer->seen);
        self::assertSame([0, 1], [$observer->seen[0]->position(), $observer->seen[1]->position()]);
        self::assertNull($observer->seen[0]->tookMillis());
    }

    /**
     * @param array<int,mixed>                                       $queue
     * @param SearchObserver&object{seen: array<int,ObservedSearch>} $observer
     */
    private static function client(object $observer, array $queue): Client
    {
        return self::clientOn(new MockHandler($queue), $observer);
    }

    private static function clientOn(MockHandler $handler, SearchObserver $observer): Client
    {
        $stack = HandlerStack::create($handler);
        $stack->push(new DigestMiddleware($observer));

        return new Client(['handler' => $stack, 'http_errors' => false]);
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
