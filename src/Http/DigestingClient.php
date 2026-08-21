<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Http;

use MrDlef\OsQueryDigest\Formatter;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that digests every search passing through it.
 *
 *     $client = new DigestingClient($client, new LoggingObserver($logger));
 *
 * Wrap the client your OpenSearch library already uses and every `_search` and
 * `_msearch` it sends is digested, joined to what it cost, and handed to the
 * observer. **No call site changes**, which is the point: the Monolog processor
 * needs an application that already logs its request bodies, and this needs
 * only one that talks to the cluster over PSR-18 — `elasticsearch-php` 8 and
 * anything built on HTTPlug included.
 *
 * Requests that are not searches pass straight through, untouched and
 * unmeasured.
 *
 * **It cannot break the call it wraps.** Reading the request is wrapped, so is
 * reading the response, so is the observer's own work; a body that cannot be
 * put back exactly as it was found is not read at all. The failure mode is a
 * missing digest.
 *
 * @api
 */
final class DigestingClient implements ClientInterface
{
    private ClientInterface $inner;

    private Recorder $recorder;

    /**
     * @param string $basePath the path prefix the cluster is mounted under, if
     *                         it is behind a proxy. Without it that prefix is
     *                         read as the index name and lands in every
     *                         fingerprint.
     */
    public function __construct(
        ClientInterface $inner,
        SearchObserver $observer,
        ?Formatter $formatter = null,
        string $basePath = ''
    ) {
        $this->inner = $inner;
        $this->recorder = new Recorder($observer, $formatter, $basePath);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $searches = $this->recorder->searches($request);

        if ($searches === []) {
            return $this->inner->sendRequest($request);
        }

        $startedAt = microtime(true);

        try {
            $response = $this->inner->sendRequest($request);
        } catch (\Throwable $error) {
            // Observed, then rethrown unchanged: the caller's error handling is
            // none of our business, and a search that failed is still a search.
            $this->recorder->failed($searches, $startedAt);

            throw $error;
        }

        $this->recorder->succeeded($searches, $response, $startedAt);

        return $response;
    }
}
