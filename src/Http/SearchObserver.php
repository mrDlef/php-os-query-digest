<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Http;

/**
 * What to do with a search once it has been seen.
 *
 * Implement it to count, sample or forward; {@see LoggingObserver} logs, which
 * is what most people want and what the shipped dashboards read.
 *
 * **It may throw.** The transport integrations catch everything an observer
 * does, because a digest is never worth a failed request — so an observer is
 * free to be simple rather than defensive.
 *
 * @api
 */
interface SearchObserver
{
    public function observe(ObservedSearch $search): void;
}
