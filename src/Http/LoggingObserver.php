<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Http;

use MrDlef\OsQueryDigest\RecordLayout;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Writes one log record per search, in the shape the shipped dashboards read.
 *
 *     new DigestingClient($client, new LoggingObserver($logger));
 *
 * The record's context is `dsl` — `{idx, kind, q, sig, hash}` — beside `took`,
 * which is exactly the mapping in
 * `resources/dashboards/index-template.json`. Import that template and the four
 * panels, point this at your log channel, and the dashboard fills itself.
 *
 * **`dsl`, and not `os`, which it was called until v0.15.0.** The key is a
 * contract people type into queries and dashboards every day, so it has to stay
 * true for as long as they keep them: the library digests Query DSL, which is
 * what OpenSearch, Elasticsearch and every API compatible with them speak, and
 * naming it after one of those engines would have gone stale the day a second
 * one was supported. The package keeps its own name — that is a brand, not a
 * field someone greps.
 *
 * `elapsed_ms` is not in the template, and is here anyway: it is wall clock, so
 * it is there even when `took` is not, and the gap between the two is the
 * network rather than the query. Map it if you want to see that gap. Whole
 * milliseconds, like `took` — the two are only worth reading side by side, and
 * a microsecond tail on one of them says nothing about the other.
 *
 * The digest is wrapped so that a request it cannot read costs the digest and
 * not the log line — the same trade {@see \MrDlef\OsQueryDigest\Monolog\SafeDigest} exists for.
 *
 * @api
 */
final class LoggingObserver implements SearchObserver
{
    private LoggerInterface $logger;

    private string $level;

    private string $message;

    private RecordLayout $layout;

    /**
     * @param RecordLayout|null $layout where the digest's fields go — nested
     *                                  under `dsl` by default, which is what the
     *                                  shipped dashboard pack maps
     */
    public function __construct(
        LoggerInterface $logger,
        string $level = LogLevel::INFO,
        string $message = 'opensearch.search',
        ?RecordLayout $layout = null
    ) {
        $this->logger = $logger;
        $this->level = $level;
        $this->message = $message;
        $this->layout = $layout ?? RecordLayout::nested();
    }

    public function observe(ObservedSearch $search): void
    {
        // The digest first, so a record reads the way the guides print it
        // whichever layout wrote it.
        $context = $this->layout->apply([], $search->digest());
        $context['took'] = $search->tookMillis();
        $context['elapsed_ms'] = (int) $search->elapsedMillis();
        $context['status'] = $search->statusCode();

        // Only on a batch, where it says which line this was. On a plain search
        // it would be a null in every record for no information.
        $position = $search->position();
        if ($position !== null) {
            $context['line'] = $position;
        }

        $this->logger->log($this->level, $this->message, $context);
    }
}
