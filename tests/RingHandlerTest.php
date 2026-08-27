<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use GuzzleHttp\Ring\Exception\ConnectException;
use GuzzleHttp\Ring\Future\CompletedFutureArray;
use GuzzleHttp\Ring\Future\FutureArray;
use GuzzleHttp\Ring\Future\FutureArrayInterface;
use MrDlef\OsQueryDigest\Http\ObservedSearch;
use MrDlef\OsQueryDigest\Http\Ring\DigestingHandler;
use MrDlef\OsQueryDigest\Http\SearchObserver;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;

use function React\Promise\reject;

/**
 * The same capture, on the path a ringphp client actually takes.
 *
 * `DigestingClientTest` covers what the three integrations share. What is only
 * true here is why this class exists: ringphp predates PSR-7, so there is no
 * request object to decorate and no handler stack to push onto — and *every*
 * handler returns a future, including the synchronous one.
 */
final class RingHandlerTest extends TestCase
{
    private const BODY = '{"query":{"term":{"service":"api"}},"size":50}';

    /** @return array<mixed> */
    private static function request(string $uri = '/logs-2026.08.21/_search', string $body = self::BODY): array
    {
        return [
            'http_method' => 'POST',
            'uri' => $uri,
            'headers' => ['host' => ['localhost:9200']],
            'body' => $body,
        ];
    }

    public function testASearchIsDigestedWithWhatItCost(): void
    {
        $observer = self::observer();
        $handler = new DigestingHandler(
            static fn(array $request): FutureArrayInterface => new CompletedFutureArray(
                ['status' => 200, 'body' => '{"took":7,"hits":{"total":1}}'],
            ),
            $observer,
        );

        $response = $handler(self::request());

        self::assertInstanceOf(FutureArrayInterface::class, $response);
        self::assertSame(200, $response['status']);
        self::assertCount(1, $observer->seen);
        self::assertSame(7, $observer->seen[0]->tookMillis());
        self::assertNull($observer->seen[0]->position());
        self::assertSame(
            'logs-* | q=(service:api) | size=50',
            $observer->seen[0]->digest()->digest()->text(),
        );
    }

    /**
     * The reason `Core::proxy()` is used rather than reading the response: a key
     * read off an unresolved future blocks on it, which would turn every
     * asynchronous search the client sends into a serial one.
     */
    public function testAnUnresolvedFutureIsNotWaitedOn(): void
    {
        $observer = self::observer();
        $deferred = new Deferred();

        // An object rather than a `&$waited`: an arrow function captures by
        // value, so a flag closed over inside one would report on a copy and
        // this test would pass without proving anything.
        $waits = new class {
            public int $count = 0;
        };

        $handler = new DigestingHandler(
            static fn(array $request): FutureArrayInterface => new FutureArray(
                $deferred->promise(),
                static function () use ($deferred, $waits): void {
                    ++$waits->count;
                    $deferred->resolve(['status' => 200, 'body' => '{"took":3}']);
                },
            ),
            $observer,
        );

        $response = $handler(self::request());

        self::assertInstanceOf(FutureArrayInterface::class, $response);
        self::assertSame(0, $waits->count, 'The handler dereferenced the future.');
        self::assertCount(0, $observer->seen, 'Nothing is known until the response lands.');

        // Now the client asks, and only now.
        self::assertSame(200, $response['status']);
        self::assertSame(1, $waits->count);
        self::assertCount(1, $observer->seen);
        self::assertSame(3, $observer->seen[0]->tookMillis());
    }

    public function testAPlainArrayResponseIsAcceptedToo(): void
    {
        $observer = self::observer();
        $handler = new DigestingHandler(
            static fn(array $request): array => ['status' => 200, 'body' => '{"took":11}'],
            $observer,
        );

        $response = $handler(self::request());

        self::assertSame(['status' => 200, 'body' => '{"took":11}'], $response);
        self::assertSame(11, $observer->seen[0]->tookMillis());
    }

    /**
     * A handler breaking the format must not become a TypeError out of a logging
     * path — the search is reported as one that came back with nothing.
     */
    public function testAHandlerThatReturnsNeitherIsSurvived(): void
    {
        $observer = self::observer();
        $handler = new DigestingHandler(static fn(array $request) => null, $observer);

        self::assertNull($handler(self::request()));
        self::assertCount(1, $observer->seen);
        self::assertNull($observer->seen[0]->statusCode());
    }

    /**
     * ringphp reports a transport failure as a *fulfilled* response carrying
     * `error` and no status — not as an exception and not as a rejection. It is
     * counted anyway: the shape that times out is the shape worth finding.
     */
    public function testAFailedRequestIsCountedWithNoStatus(): void
    {
        $observer = self::observer();
        $handler = new DigestingHandler(
            static fn(array $request): FutureArrayInterface => new CompletedFutureArray([
                'status' => null,
                'error' => new ConnectException('cluster unreachable'),
            ]),
            $observer,
        );

        $response = $handler(self::request());

        self::assertInstanceOf(FutureArrayInterface::class, $response);
        self::assertInstanceOf(ConnectException::class, $response['error']);
        self::assertCount(1, $observer->seen);
        self::assertNull($observer->seen[0]->statusCode());
        self::assertNull($observer->seen[0]->tookMillis());
        self::assertGreaterThanOrEqual(0.0, $observer->seen[0]->elapsedMillis());
    }

    public function testAThrownHandlerIsCountedAndTheErrorRethrown(): void
    {
        $observer = self::observer();
        $error = new \RuntimeException('the handler blew up');
        $handler = new DigestingHandler(
            static function (array $request) use ($error): array {
                throw $error;
            },
            $observer,
        );

        try {
            $handler(self::request());
            self::fail('The error was swallowed.');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }

        self::assertCount(1, $observer->seen);
        self::assertNull($observer->seen[0]->statusCode());
    }

    /**
     * A rejection is kept a rejection, with the reason the caller would have
     * seen: an `$onRejected` that returned a value would turn a failed request
     * into a successful one.
     */
    public function testARejectedFutureIsCountedAndTheRejectionKept(): void
    {
        $observer = self::observer();
        $error = new ConnectException('cluster unreachable');

        // Rejected already, and with nothing to wait on: a future that rejects
        // *while waiting* would surface the reason through `wait()` whatever
        // this handler did with it, which would test ringphp rather than this.
        $handler = new DigestingHandler(
            static fn(array $request): FutureArrayInterface => new FutureArray(reject($error)),
            $observer,
        );

        $response = $handler(self::request());
        self::assertInstanceOf(FutureArrayInterface::class, $response);

        try {
            $response->wait();
            self::fail('The rejection was swallowed.');
        } catch (ConnectException $caught) {
            self::assertSame($error, $caught);
        }

        self::assertCount(1, $observer->seen);
        self::assertNull($observer->seen[0]->statusCode());
    }

    public function testEverythingThatIsNotASearchPassesThrough(): void
    {
        $observer = self::observer();
        $calls = new class {
            /** @var array<int,array<mixed>> */
            public array $requests = [];
        };
        $handler = new DigestingHandler(
            static function (array $request) use ($calls): array {
                $calls->requests[] = $request;

                return ['status' => 201, 'body' => '{"result":"created"}'];
            },
            $observer,
        );

        $returned = $handler(self::request('/logs-*/_doc'));

        self::assertCount(0, $observer->seen);
        // Once, not twice: the request is handed on and the method is done with
        // it, rather than falling through and sending it again.
        self::assertCount(1, $calls->requests);
        self::assertSame(self::request('/logs-*/_doc'), $calls->requests[0], 'The request was altered.');
        self::assertSame(
            ['status' => 201, 'body' => '{"result":"created"}'],
            $returned,
            'The answer to a request that is not a search must come back untouched.',
        );
    }

    /**
     * "Straight through, untouched" is about the object too: a future handed
     * back for a request that is not a search must be *the* future, not one
     * proxied around it.
     */
    public function testAFutureForSomethingElseIsNotEvenProxied(): void
    {
        $future = new CompletedFutureArray(['status' => 201, 'body' => '{"result":"created"}']);

        $handler = new DigestingHandler(
            static fn(array $request): FutureArrayInterface => $future,
            self::observer(),
        );

        self::assertSame($future, $handler(self::request('/logs-*/_doc')));
    }

    public function testEveryLineOfABatchIsItsOwnSearch(): void
    {
        $body = "{}\n" . self::BODY . "\n"
            . '{"index":"metrics-*"}' . "\n" . '{"query":{"match_all":{}}}' . "\n";

        $observer = self::observer();
        $handler = new DigestingHandler(
            static fn(array $request): FutureArrayInterface => new CompletedFutureArray(
                ['status' => 200, 'body' => '{"took":19,"responses":[]}'],
            ),
            $observer,
        );

        $handler(self::request('/logs-*/_msearch', $body));

        self::assertCount(2, $observer->seen);
        self::assertSame([0, 1], [$observer->seen[0]->position(), $observer->seen[1]->position()]);
        self::assertNull($observer->seen[0]->tookMillis(), 'A batch reports one took for all of its lines.');
        self::assertSame('metrics-* | q=(*)', $observer->seen[1]->digest()->digest()->text());
    }

    /**
     * ringphp allows a resource under `body`, and its own curl handlers put one
     * there. Reading it must leave the pointer where it was found, or the client
     * reads an empty response after us.
     */
    public function testAResourceResponseBodyIsReadWithoutConsumingIt(): void
    {
        $answer = '{"took":13,"hits":{"hits":[{"_id":"1"}]}}';
        $body = fopen('php://temp', 'r+');
        self::assertIsResource($body);
        fwrite($body, $answer);
        rewind($body);

        $observer = self::observer();
        $handler = new DigestingHandler(
            static fn(array $request): FutureArrayInterface => new CompletedFutureArray(
                ['status' => 200, 'body' => $body],
            ),
            $observer,
        );

        // Left five bytes in, not at the start: the position is the client's,
        // and putting it back at zero would be a different kind of wrong.
        self::assertIsString(fread($body, 5));

        $handler(self::request());

        self::assertSame(13, $observer->seen[0]->tookMillis());
        self::assertSame(5, ftell($body), 'The pointer was moved.');
        self::assertSame(
            substr($answer, 5),
            stream_get_contents($body),
            'The client would have read the wrong thing.',
        );

        fclose($body);
    }

    /**
     * The other half of the same care: a request body ringphp put a resource
     * under is read whole, and put back — or the search is sent without its
     * query.
     */
    public function testAResourceRequestBodyIsReadWithoutConsumingIt(): void
    {
        $body = fopen('php://temp', 'r+');
        self::assertIsResource($body);
        fwrite($body, self::BODY);
        rewind($body);

        $observer = self::observer();
        $sent = null;
        $handler = new DigestingHandler(
            static function (array $request) use (&$sent): array {
                self::assertIsResource($request['body']);
                $sent = stream_get_contents($request['body']);

                return ['status' => 200, 'body' => '{"took":2}'];
            },
            $observer,
        );

        // `array_merge`, not `+`: the union operator keeps the left-hand `body`.
        $handler(array_merge(self::request(), ['body' => $body]));

        self::assertCount(1, $observer->seen);
        self::assertSame('logs-* | q=(service:api) | size=50', $observer->seen[0]->digest()->digest()->text());
        self::assertSame(self::BODY, $sent, 'The search would have gone out without its query.');

        fclose($body);
    }

    public function testAnEmptyResponseBodyLeavesTookNull(): void
    {
        $body = fopen('php://temp', 'r+');
        self::assertIsResource($body);

        $observer = self::observer();
        $handler = new DigestingHandler(
            static fn(array $request): array => ['status' => 200, 'body' => $body],
            $observer,
        );

        $handler(self::request());

        self::assertSame(200, $observer->seen[0]->statusCode());
        self::assertNull($observer->seen[0]->tookMillis());

        fclose($body);
    }

    /**
     * An unseekable body is one nobody can put back, so it is not read at all —
     * the cost is a missing `took`, never a missing response.
     */
    public function testAnUnseekableResponseBodyCostsOnlyTheTook(): void
    {
        $body = fopen('php://output', 'w');
        self::assertIsResource($body);

        $observer = self::observer();
        $handler = new DigestingHandler(
            static fn(array $request): FutureArrayInterface => new CompletedFutureArray(
                ['status' => 200, 'body' => $body],
            ),
            $observer,
        );

        $handler(self::request());

        self::assertCount(1, $observer->seen);
        self::assertSame(200, $observer->seen[0]->statusCode());
        self::assertNull($observer->seen[0]->tookMillis());

        fclose($body);
    }

    public function testARequestWithoutTheKeysTheFormatDefinesIsNotASearch(): void
    {
        $observer = self::observer();
        $handler = new DigestingHandler(
            static fn(array $request): array => ['status' => 200, 'body' => '{}'],
            $observer,
        );

        $handler(['body' => self::BODY]);                      // no method, no uri
        $handler(['http_method' => 'POST', 'uri' => '/logs-*/_search']);   // no body

        self::assertCount(0, $observer->seen);
    }

    public function testAProxyPrefixIsStrippedBeforeTheIndexIsRead(): void
    {
        $observer = self::observer();
        $handler = new DigestingHandler(
            static fn(array $request): array => ['status' => 200, 'body' => '{"took":1}'],
            $observer,
            null,
            '/opensearch',
        );

        $handler(self::request('/opensearch/logs-2026.08.21/_search'));

        self::assertSame('logs-*', $observer->seen[0]->digest()->digest()->index());
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
