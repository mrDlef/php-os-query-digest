<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Http\Guzzle;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Http\Recorder;
use MrDlef\OsQueryDigest\Http\SearchObserver;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware that digests every search passing through the stack.
 *
 *     $stack = HandlerStack::create();
 *     $stack->push(new DigestMiddleware(new LoggingObserver($logger)));
 *     $client = new Client(['handler' => $stack]);
 *
 * A Guzzle client is already a PSR-18 client, so
 * {@see \MrDlef\OsQueryDigest\Http\DigestingClient} would cover it — for the
 * requests it sends synchronously. This exists for the rest: `requestAsync`,
 * pooled requests, and the libraries built on them, none of which go through
 * `sendRequest` at all. `opensearch-php` is one of them.
 *
 * **Where in the stack matters, and it is a choice rather than a default.**
 * `push()` puts a middleware nearer the handler than everything pushed before
 * it, so a middleware pushed after `retry` runs once per *attempt* — which is
 * how many searches the cluster actually ran, and usually what you want. Push
 * it before `retry` to count one search per call instead.
 *
 * Everything the decorator promises holds here: requests that are not searches
 * pass through untouched, and nothing this does can fail the call.
 *
 * @api
 */
final class DigestMiddleware
{
    private Recorder $recorder;

    /**
     * @param string $basePath the path prefix the cluster is mounted under, if
     *                         it is behind a proxy. Without it that prefix is
     *                         read as the index name and lands in every
     *                         fingerprint.
     */
    public function __construct(
        SearchObserver $observer,
        ?Formatter $formatter = null,
        string $basePath = ''
    ) {
        $this->recorder = new Recorder($observer, $formatter, $basePath);
    }

    /**
     * @param callable(RequestInterface, array<string,mixed>):PromiseInterface $handler
     *
     * @return callable(RequestInterface, array<string,mixed>):PromiseInterface
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            /** @var array<string,mixed> $options */
            $searches = $this->recorder->searches($request);

            if ($searches === []) {
                return $handler($request, $options);
            }

            $startedAt = microtime(true);

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($searches, $startedAt): ResponseInterface {
                    $this->recorder->succeeded($searches, $response, $startedAt);

                    return $response;
                },
                /**
                 * Re-rejected with the reason exactly as it arrived: a promise
                 * may be rejected with something that is not a Throwable, and
                 * rethrowing would change what the caller catches.
                 *
                 * @param mixed $reason
                 */
                function ($reason) use ($searches, $startedAt): PromiseInterface {
                    $this->recorder->failed($searches, $startedAt);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }
}
