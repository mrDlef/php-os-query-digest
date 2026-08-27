<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Http;

use MrDlef\OsQueryDigest\Capture\CapturedSearch;
use MrDlef\OsQueryDigest\Capture\SearchExtractor;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Support\Arr;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Everything the transport integrations share: read the request, digest what is
 * a search in it, and tell the observer once the response is in.
 *
 * The three integrations differ only in how they get hold of a request and a
 * response. Two of them talk PSR-7 and one talks ringphp's arrays, so the
 * shapes meet here and the pairing, the timing and the reporting below are
 * written once.
 *
 * **Nothing here throws.** It sits in the path of a request the application is
 * about to make, and a digest is never worth a failed request — so every step
 * is wrapped, including the observer's own work.
 *
 * @internal
 */
final class Recorder
{
    /**
     * How much of a response to look at for its `took`.
     *
     * A search response opens with it, so a fixed peek at the front finds it
     * without decoding a body that may hold a megabyte of hits. A response that
     * puts it elsewhere yields null, which is the same answer as a response
     * that could not be read at all.
     */
    private const TOOK_PEEK = 64;

    private SearchObserver $observer;

    private Formatter $formatter;

    private SearchExtractor $extractor;

    public function __construct(SearchObserver $observer, ?Formatter $formatter = null, string $basePath = '')
    {
        $this->observer = $observer;
        $this->formatter = $formatter ?? Formatter::create();
        $this->extractor = SearchExtractor::create($basePath);
    }

    /**
     * @return array<int,CapturedSearch> empty when the request is not a search,
     *                                   or when its body could not be read
     *                                   without disturbing what is about to be
     *                                   sent
     */
    public function searches(RequestInterface $request): array
    {
        try {
            $body = self::peek($request->getBody(), null);
            if ($body === null) {
                return [];
            }

            return $this->extractor->extract(
                $request->getMethod(),
                $request->getUri()->getPath(),
                $body,
            );
        } catch (\Throwable $error) {
            return [];
        }
    }

    /**
     * The same, off a ringphp request array. `http_method` and `uri` are the
     * keys the format defines; a missing one reads as empty and yields no
     * searches.
     *
     * @param array<mixed> $request
     *
     * @return array<int,CapturedSearch>
     */
    public function ringSearches(array $request): array
    {
        try {
            $body = self::peekAny($request['body'] ?? null, null);
            if ($body === null) {
                return [];
            }

            return $this->extractor->extract(
                Arr::str($request['http_method'] ?? null),
                Arr::str($request['uri'] ?? null),
                $body,
            );
        } catch (\Throwable $error) {
            return [];
        }
    }

    /**
     * A ringphp response, which is where its failures live too: a transport
     * error is a response array carrying `error` and no `status`, not a thrown
     * exception and not a rejected promise. So a response without a status is
     * reported the way {@see self::failed()} reports one — counted, with a null
     * status, because a shape that times out is the shape worth finding.
     *
     * @param array<int,CapturedSearch> $searches
     * @param array<mixed>              $response
     * @param float                     $startedAt as `microtime(true)` returned
     *                                             it, just before the call
     */
    public function ringCompleted(array $searches, array $response, float $startedAt): void
    {
        $elapsed = self::elapsed($startedAt);

        $status = null;
        $took = null;

        try {
            $raw = $response['status'] ?? null;
            $status = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : null;

            // Only worth looking for when the answer belongs to one search: a
            // batch reports one `took` for all of its lines.
            if ($status !== null && count($searches) === 1 && $searches[0]->position() === null) {
                $head = self::peekAny($response['body'] ?? null, self::TOOK_PEEK);
                $took = $head === null ? null : self::tookIn($head);
            }
        } catch (\Throwable $error) {
            // Whatever was read stands; the rest stays null.
        }

        $this->report($searches, $took, $elapsed, $status);
    }

    /**
     * @param array<int,CapturedSearch> $searches
     * @param float                     $startedAt as `microtime(true)` returned
     *                                             it, just before the call
     */
    public function succeeded(array $searches, ResponseInterface $response, float $startedAt): void
    {
        $elapsed = self::elapsed($startedAt);

        $status = null;
        $took = null;

        try {
            $status = $response->getStatusCode();

            // Only worth looking for when the answer belongs to one search: a
            // batch reports one `took` for all of its lines.
            if (count($searches) === 1 && $searches[0]->position() === null) {
                $took = self::took($response);
            }
        } catch (\Throwable $error) {
            // Whatever was read stands; the rest stays null.
        }

        $this->report($searches, $took, $elapsed, $status);
    }

    /**
     * No response came back. Reported anyway, with no status: a shape that
     * times out is exactly the shape worth finding, and dropping it would hide
     * the worst queries from the report.
     *
     * @param array<int,CapturedSearch> $searches
     */
    public function failed(array $searches, float $startedAt): void
    {
        $this->report($searches, null, self::elapsed($startedAt), null);
    }

    /**
     * @param array<int,CapturedSearch> $searches
     */
    private function report(array $searches, ?int $took, float $elapsed, ?int $status): void
    {
        foreach ($searches as $search) {
            $position = $search->position();

            try {
                $this->observer->observe(new ObservedSearch(
                    $this->formatter->lazy($search->body(), $search->index()),
                    $position === null ? $took : null,
                    $elapsed,
                    $status,
                    $position,
                ));
            } catch (\Throwable $error) {
                // Per search, not per request: one observer that fails on one
                // line of a batch must not take the other lines with it.
            }
        }
    }

    private static function elapsed(float $startedAt): float
    {
        return (microtime(true) - $startedAt) * 1000.0;
    }

    private static function took(ResponseInterface $response): ?int
    {
        $head = self::peek($response->getBody(), self::TOOK_PEEK);

        return $head === null ? null : self::tookIn($head);
    }

    /**
     * The number off the front of a response, or null when it does not open
     * with one.
     */
    private static function tookIn(string $head): ?int
    {
        return preg_match('/\A\s*\{\s*"took"\s*:\s*(\d+)/', $head, $matched) === 1
            ? (int) $matched[1]
            : null;
    }

    /**
     * A look at whatever ringphp put under `body`, leaving it as it was found.
     *
     * The format allows a string, a resource or a stream object, and the two
     * clients that matter send a JSON string — but a handler further down the
     * chain may have replaced it, and `Core::body()` is not usable here: it
     * casts a stream to string and `stream_get_contents()` a resource, both of
     * which leave the pointer at the end. That is fine for ringphp, which calls
     * `Core::rewindBody()` after; it is not fine for a middleware, where a body
     * read and not put back is a request sent without its query.
     *
     * A stream object yields null unless it is a PSR-7 one. ringphp's own
     * streams implement `GuzzleHttp\Stream\StreamInterface`, a second stream
     * abstraction this library does not depend on — and the cost of not reading
     * one is a missing `took`, never a missing search.
     *
     * @param mixed             $body
     * @param positive-int|null $length how much to read, or null for all of it
     */
    private static function peekAny($body, ?int $length): ?string
    {
        // Whole, however little was asked for: the point of a length is to avoid
        // pulling a megabyte of hits through a stream, and a string is already
        // in memory. `tookIn()` is anchored at the front either way.
        if (is_string($body)) {
            return $body === '' ? null : $body;
        }

        if ($body instanceof StreamInterface) {
            return self::peek($body, $length);
        }

        if (!is_resource($body)) {
            return null;
        }

        $meta = stream_get_meta_data($body);
        if (!$meta['seekable']) {
            return null;
        }

        // Restored to where it was rather than to the start: the position is
        // the client's, not ours.
        $at = ftell($body);
        if ($at === false) {
            return null;
        }

        rewind($body);
        $content = $length === null ? stream_get_contents($body) : fread($body, $length);
        fseek($body, $at);

        return is_string($content) && $content !== '' ? $content : null;
    }

    /**
     * A look at a stream that leaves it exactly as it was found.
     *
     * A body is read only when it can be put back: an unseekable stream is one
     * the client has not sent yet, or a response the caller has not read yet,
     * and consuming it would take the request or the answer with it. There is no
     * digest worth that, so an unseekable stream yields null.
     *
     * @param int|null $length how much to read, or null for all of it
     */
    private static function peek(StreamInterface $stream, ?int $length): ?string
    {
        if (!$stream->isSeekable() || !$stream->isReadable()) {
            return null;
        }

        // Restored to where it was rather than to the start: the position is
        // the client's, not ours.
        $at = $stream->tell();
        $stream->rewind();
        $content = $length === null ? $stream->getContents() : $stream->read($length);
        $stream->seek($at);

        return $content === '' ? null : $content;
    }
}
