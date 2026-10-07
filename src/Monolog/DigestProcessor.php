<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Monolog;

use Monolog\LogRecord;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\RecordLayout;

/**
 * Replaces a raw OpenSearch request in a log record's context with its digest.
 *
 *     $logger->pushProcessor(new DigestProcessor());
 *     $logger->info('opensearch.search', [
 *         'query' => $request,
 *         'index' => 'logs-2026.08.16',
 *         'took'  => $response['took'],
 *     ]);
 *
 * The `query` key comes out as `{"idx": "logs-*", "q": "…", "sig": "…",
 * "hash": "q6:…"}` instead of the wall of nested braces. Nothing else in the
 * context is touched.
 *
 * The point is that you do not have to change every call site: an application
 * already logging its request bodies gets the digest by pushing one processor.
 *
 * Monolog is a **suggested** dependency, never a required one. This class is
 * the only part of the library that knows Monolog exists, and it is written to
 * work with both major versions — see {@see __invoke()} for how, and why it
 * does not implement `ProcessorInterface`.
 *
 * @api
 */
final class DigestProcessor
{
    private Formatter $formatter;

    private string $requestKey;

    private string $indexKey;

    private ?RecordLayout $layout;

    /**
     * @param string            $requestKey the context key holding the search request: a
     *                                      body, an `['index' => …, 'body' => …]` envelope,
     *                                      or the JSON of either
     * @param string            $indexKey   the context key holding the index name, if the
     *                                      request does not carry one
     * @param RecordLayout|null $layout     where the digest's fields go. By default the
     *                                      digest simply takes the request's place, which
     *                                      is what makes this a one-line change at no call
     *                                      site; {@see RecordLayout::flat()} spreads it over
     *                                      sibling keys instead, for a collector that cannot
     *                                      query a nested object
     */
    public function __construct(
        ?Formatter $formatter = null,
        string $requestKey = 'query',
        string $indexKey = 'index',
        ?RecordLayout $layout = null
    ) {
        $this->formatter = $formatter ?? Formatter::create();
        $this->requestKey = $requestKey;
        $this->indexKey = $indexKey;
        $this->layout = $layout;
    }

    /**
     * Monolog 2 hands processors an array and expects one back; Monolog 3 hands
     * a {@see LogRecord} and expects one back. Implementing `ProcessorInterface`
     * would pin this class to one of them, and the interface's signature differs
     * between the two — so it stays a plain callable, which both versions accept
     * wherever a processor is expected.
     *
     * `instanceof` against a class that does not exist is false rather than an
     * error, and does not autoload, so the Monolog 3 branch simply never runs
     * under Monolog 2.
     *
     * @param mixed $record
     *
     * @return mixed
     */
    public function __invoke($record)
    {
        if ($record instanceof LogRecord) {
            $context = $this->rewrite($record->context);

            if ($context === $record->context) {
                return $record;
            }

            // `context` is readonly in Monolog 3, so the record is rebuilt
            // rather than assigned to. The route Monolog documents for that is
            // with(context: …), which reads named arguments only — and this
            // file has to parse on 7.4, which has no syntax for one, neither
            // the named argument nor the string-keyed unpack PHP 8 turns into
            // one, which is how it reads to a static analyser held at that
            // floor. The constructor takes the same fields positionally, so
            // every one of them is carried over here by hand.
            return new LogRecord(
                $record->datetime,
                $record->channel,
                $record->level,
                $record->message,
                $context,
                $record->extra,
                $record->formatted,
            );
        }

        if (is_array($record) && isset($record['context']) && is_array($record['context'])) {
            $record['context'] = $this->rewrite($record['context']);
        }

        return $record;
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private function rewrite(array $context): array
    {
        $request = $context[$this->requestKey] ?? null;

        // Anything else is not a search request, and a processor that guessed
        // would corrupt someone's log line. Left exactly as it was found.
        if (!is_array($request) && !is_string($request)) {
            return $context;
        }

        $index = $context[$this->indexKey] ?? null;
        $index = is_string($index) ? $index : null;

        // Lazy: processors run before the handlers decide what to keep, so a
        // record dropped by a FingersCrossed or a level filter must not have
        // cost a parse. Every layout keeps that property.
        $digest = $this->formatter->lazy($request, $index);

        // The request's own key by default: the digest lands where the body
        // was, and nothing else in the record moves. A flat layout replaces it
        // with siblings, so the key it occupied has to go.
        $layout = $this->layout ?? RecordLayout::nested($this->requestKey);
        if ($layout->isFlat()) {
            unset($context[$this->requestKey]);
        }

        return $layout->apply($context, $digest);
    }
}
