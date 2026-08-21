<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Http;

use MrDlef\OsQueryDigest\LazyDigest;

/**
 * One search seen going out, and what came back.
 *
 * This is what a transport integration hands a {@see SearchObserver}, and it is
 * the reason to capture at the transport rather than at a call site: a client
 * sees the request *and* the response, so the digest arrives already joined to
 * what the query cost. Nothing in the application had to pass the two to the
 * same log call.
 *
 * @api
 */
final class ObservedSearch
{
    private LazyDigest $digest;

    private ?int $tookMillis;

    private float $elapsedMillis;

    private ?int $statusCode;

    private ?int $position;

    public function __construct(
        LazyDigest $digest,
        ?int $tookMillis,
        float $elapsedMillis,
        ?int $statusCode,
        ?int $position
    ) {
        $this->digest = $digest;
        $this->tookMillis = $tookMillis;
        $this->elapsedMillis = $elapsedMillis;
        $this->statusCode = $statusCode;
        $this->position = $position;
    }

    /**
     * Still lazy: an observer that drops this search — below a threshold, or
     * outside a sample — has not paid to parse it.
     *
     * It parses when read, so it can throw when read. An observer that hands it
     * to a logger should wrap it, the way {@see LoggingObserver} does.
     */
    public function digest(): LazyDigest
    {
        return $this->digest;
    }

    /**
     * What the cluster says it spent on this search, in milliseconds.
     *
     * Null when it could not be attributed to this search alone — every line of
     * an `_msearch`, whose response reports one `took` for the whole batch — and
     * when the response body could not be read without disturbing it.
     */
    public function tookMillis(): ?int
    {
        return $this->tookMillis;
    }

    /**
     * Wall clock around the call: the cluster's time plus the network, the
     * queue and everything else between the two.
     *
     * Always there, which is why it is not nullable — and always at least as
     * large as {@see tookMillis()}. The gap between the two is its own signal.
     */
    public function elapsedMillis(): float
    {
        return $this->elapsedMillis;
    }

    /**
     * Null when the request never got a response at all. A shape that times out
     * is worth counting, so it is reported rather than dropped.
     */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Which line of an `_msearch` this was, counting from zero; null when the
     * request carried one search.
     */
    public function position(): ?int
    {
        return $this->position;
    }
}
