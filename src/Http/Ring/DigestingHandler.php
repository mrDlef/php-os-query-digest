<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Http\Ring;

use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\Future\FutureArrayInterface;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Http\Recorder;
use MrDlef\OsQueryDigest\Http\SearchObserver;

/**
 * ringphp handler that digests every search passing through it.
 *
 *     $client = ClientBuilder::create()
 *         ->setHandler(new DigestingHandler(
 *             ClientBuilder::defaultHandler(),
 *             new LoggingObserver($logger),
 *         ))
 *         ->build();
 *
 * This is the third way in, and the one for the clients the other two cannot
 * reach: `elasticsearch-php` 7.x and `opensearch-php` ≤ 2.3 both transport over
 * `ezimuel/ringphp`, which predates PSR-7. There is no `sendRequest()` to
 * decorate and no Guzzle handler stack to push onto — a ring handler is a
 * `callable(array): array|FutureArrayInterface`, and this is one.
 *
 * The request array is already the shape the capture wants: `http_method`,
 * `uri`, and a body that is a JSON string on both of those clients.
 *
 * Everything the other two integrations promise holds here: a request that is
 * not a search passes through untouched and unmeasured, and nothing this does
 * can fail the call.
 *
 * **Two things about futures are load-bearing.** Every ringphp handler returns
 * one — even the synchronous `CurlHandler` hands back a `CompletedFutureArray`
 * — and reading a key off an unresolved future *blocks*, which would turn the
 * asynchronous request the client meant into a serial one. So the response is
 * wrapped with {@see Core::proxy()} and only read inside the callback, which is
 * also the honest place to read `took` and the status: after the response has
 * actually landed.
 *
 * @api
 */
final class DigestingHandler
{
    /**
     * Not typed beyond `callable`: what a handler returns is the format's
     * promise, not one this can check, and it is passed back untouched.
     *
     * @var callable(array<mixed>): mixed
     */
    private $next;

    private Recorder $recorder;

    /**
     * @param callable $next     the handler to wrap —
     *                           `ClientBuilder::defaultHandler()`, or whatever
     *                           the client was already using
     * @param string   $basePath the path prefix the cluster is mounted under, if
     *                           it is behind a proxy. Without it that prefix is
     *                           read as the index name and lands in every
     *                           fingerprint.
     */
    public function __construct(
        callable $next,
        SearchObserver $observer,
        ?Formatter $formatter = null,
        string $basePath = ''
    ) {
        $this->next = $next;
        $this->recorder = new Recorder($observer, $formatter, $basePath);
    }

    /**
     * @param array<mixed> $request
     *
     * @return mixed whatever the wrapped handler returned — an array or a
     *               `FutureArrayInterface`, as the format defines — passed back
     *               untouched, except for a future, which is proxied
     */
    public function __invoke(array $request)
    {
        $searches = $this->recorder->ringSearches($request);

        if ($searches === []) {
            return ($this->next)($request);
        }

        $startedAt = microtime(true);

        try {
            $response = ($this->next)($request);
        } catch (\Throwable $error) {
            // A ringphp failure is normally a response array carrying `error`,
            // not an exception — but a handler is free to throw, and a search
            // that never came back is still one worth reporting.
            $this->recorder->failed($searches, $startedAt);

            throw $error;
        }

        if (!$response instanceof FutureArrayInterface) {
            // The format allows a plain array, and a hand-written handler in an
            // application's test suite is usually one. Anything else is a
            // handler breaking the format — reported as a request that came back
            // with nothing, because the alternative is a TypeError out of a
            // logging path.
            $this->recorder->ringCompleted($searches, is_array($response) ? $response : [], $startedAt);

            return $response;
        }

        return Core::proxy(
            $response,
            /**
             * Returns the response: `Core::proxy()` resolves to whatever this
             * hands back, so anything else would replace the answer the client
             * is waiting for.
             *
             * @param array<mixed> $resolved
             *
             * @return array<mixed>
             */
            function (array $resolved) use ($searches, $startedAt): array {
                $this->recorder->ringCompleted($searches, $resolved, $startedAt);

                return $resolved;
            },
            /**
             * Rethrown rather than returned: an `$onRejected` that returns a
             * value turns a failed request into a successful one. react/promise
             * catches this and rejects the derived promise with the same reason,
             * so the caller sees what it would have seen without the handler —
             * and the reason is a Throwable, since ringphp's own `wait()`
             * rethrows it.
             */
            function (\Throwable $reason) use ($searches, $startedAt): void {
                $this->recorder->failed($searches, $startedAt);

                throw $reason;
            },
        );
    }
}
