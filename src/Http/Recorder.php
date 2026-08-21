<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Http;

use MrDlef\OsQueryDigest\Capture\CapturedSearch;
use MrDlef\OsQueryDigest\Capture\SearchExtractor;
use MrDlef\OsQueryDigest\Formatter;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Everything the transport integrations share: read the request, digest what is
 * a search in it, and tell the observer once the response is in.
 *
 * The PSR-18 decorator and the Guzzle middleware differ only in how they get
 * hold of a request and a response. Both talk PSR-7, so all of the care lives
 * here and neither of them repeats it.
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
        if ($head === null) {
            return null;
        }

        return preg_match('/\A\s*\{\s*"took"\s*:\s*(\d+)/', $head, $matched) === 1
            ? (int) $matched[1]
            : null;
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
