<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Http;

use MrDlef\OsQueryDigest\Monolog\SafeDigest;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Writes one log record per search, in the shape the shipped dashboards read.
 *
 *     new DigestingClient($client, new LoggingObserver($logger));
 *
 * The record's context is `os` — `{idx, q, sig, hash}` — beside `took`, which is
 * exactly the mapping in `resources/dashboards/index-template.json`. Import that
 * template and the four panels, point this at your log channel, and the
 * dashboard fills itself.
 *
 * `elapsed_ms` is not in the template, and is here anyway: it is wall clock, so
 * it is there even when `took` is not, and the gap between the two is the
 * network rather than the query. Map it if you want to see that gap. Whole
 * milliseconds, like `took` — the two are only worth reading side by side, and
 * a microsecond tail on one of them says nothing about the other.
 *
 * The digest is wrapped so that a request it cannot read costs the digest and
 * not the log line — the same trade {@see SafeDigest} exists for.
 *
 * @api
 */
final class LoggingObserver implements SearchObserver
{
    private LoggerInterface $logger;

    private string $level;

    private string $message;

    public function __construct(
        LoggerInterface $logger,
        string $level = LogLevel::INFO,
        string $message = 'opensearch.search'
    ) {
        $this->logger = $logger;
        $this->level = $level;
        $this->message = $message;
    }

    public function observe(ObservedSearch $search): void
    {
        $context = [
            'os' => new SafeDigest($search->digest()),
            'took' => $search->tookMillis(),
            'elapsed_ms' => (int) $search->elapsedMillis(),
            'status' => $search->statusCode(),
        ];

        // Only on a batch, where it says which line this was. On a plain search
        // it would be a null in every record for no information.
        $position = $search->position();
        if ($position !== null) {
            $context['line'] = $position;
        }

        $this->logger->log($this->level, $this->message, $context);
    }
}
