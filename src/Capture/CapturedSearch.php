<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Capture;

/**
 * One search taken off the wire: the body, and the index it was aimed at.
 *
 * The body stays the raw JSON string the client was about to send. Decoding it
 * here would cost a `json_decode` on every request for a digest the handler may
 * never format — {@see \MrDlef\OsQueryDigest\Formatter::lazy()} takes the
 * string and decodes it only if someone asks.
 *
 * @internal
 */
final class CapturedSearch
{
    private ?string $index;

    private string $body;

    private ?int $position;

    /**
     * @param int|null $position the line this search came off, for a `_msearch`;
     *                           null when the request carried one search and
     *                           only one
     */
    public function __construct(?string $index, string $body, ?int $position = null)
    {
        $this->index = $index;
        $this->body = $body;
        $this->position = $position;
    }

    /**
     * The index expression as the request spelled it — `logs-a,logs-b` and
     * `logs-*` included, left for {@see \MrDlef\OsQueryDigest\IndexNormalizer}
     * to collapse. Null when the request named none, which means every index.
     */
    public function index(): ?string
    {
        return $this->index;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Which line of a batch this was, counting from zero — and null when the
     * request was not a batch.
     *
     * That distinction is not cosmetic: a search response reports the `took` of
     * the one search in it, and an `_msearch` response reports the `took` of the
     * whole batch. Null here is what says the number may be attributed to this
     * search alone.
     */
    public function position(): ?int
    {
        return $this->position;
    }
}
