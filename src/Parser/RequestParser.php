<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Parser;

use MrDlef\OsQueryDigest\Explain\Rule;
use MrDlef\OsQueryDigest\Explain\Trace;
use MrDlef\OsQueryDigest\Extension\ClauseRenderer;
use MrDlef\OsQueryDigest\Support\Arr;
use MrDlef\OsQueryDigest\Tree\QueryModel;

/**
 * Reads a whole search request: index, query, aggs, and the options that carry
 * intent (size / from / sort).
 *
 * @internal
 */
final class RequestParser
{
    /**
     * Ubiquitous keys that say nothing about what a query is for. Kept out of
     * the notes so the digest does not turn into noise.
     */
    private const NOISE = [
        '_source', 'track_total_hits', 'track_scores', 'timeout', 'terminate_after',
        'version', 'seq_no_primary_term', 'stored_fields', 'docvalue_fields', 'fields',
        'explain', 'profile', 'preference', 'ext', 'stats',
    ];

    private const RENDERED = ['query', 'post_filter', 'aggs', 'aggregations', 'size', 'from', 'sort'];

    /**
     * Rendered keys the search endpoint also accepts beside `body`, where they
     * travel as query-string parameters — both `elasticsearch-php` 7.x and
     * `opensearch-php` whitelist them. Reading only `body` handed the same
     * fingerprint to a paging request and an unpaged one.
     *
     * `sort` belongs here too and is missing on purpose: it is merged rather
     * than overridden, and in a different syntax. See {@see self::uriSort()}.
     */
    private const ENVELOPE_PARAMS = ['size', 'from'];

    /**
     * Body sections that make a request a walk over a result set rather than a
     * page of one. They are notes already — this reads them a second time, as a
     * fact rather than as a string.
     */
    private const CURSORS = ['search_after', 'pit', 'slice'];

    private QueryParser $queryParser;

    private AggParser $aggParser;

    /**
     * @param array<string,ClauseRenderer> $renderers for query types this
     *                                                library does not model
     */
    public function __construct(array $renderers = [])
    {
        $this->queryParser = new QueryParser($renderers);
        $this->aggParser = new AggParser();
    }

    /**
     * @param array<mixed> $request a search body, or an
     *                              `['index' => …, 'body' => …]` envelope
     *                              as produced by opensearch-php
     */
    public function parse(array $request, ?string $index = null, ?Trace $trace = null): QueryModel
    {
        $trace ??= new Trace();
        $body = $request;
        $envelopeSort = null;
        $scroll = false;

        if (array_key_exists('body', $request) && is_array($request['body'])) {
            $envelopeIndex = Arr::get($request, 'index');
            if ($index === null && $envelopeIndex !== null) {
                $index = is_array($envelopeIndex)
                    ? implode(',', Arr::strings($envelopeIndex))
                    : Arr::str($envelopeIndex);
            }
            $body = $request['body'];

            // The cluster applies the query string *after* the body, so an
            // envelope `size` or `from` overrides the one inside. `sort` is the
            // exception: the URL sorts are appended to the body's rather than
            // replacing them, so the body keeps the primary key. Both rules were
            // read off a live node, not off the clients' documentation.
            foreach (self::ENVELOPE_PARAMS as $param) {
                if (array_key_exists($param, $request)) {
                    $body[$param] = $request[$param];
                }
            }
            $envelopeSort = Arr::get($request, 'sort');

            // Read for the classification only, and never noted: `scroll` is a
            // query-string parameter, so promoting it into the signature would
            // move the fingerprint of every scrolling search — and this reads
            // it precisely to say that such a search is a scan.
            $scroll = Arr::get($request, 'scroll') !== null;
        }

        $notes = [];

        $query = null;
        $rawQuery = Arr::get($body, 'query');
        if (is_array($rawQuery) && $rawQuery !== []) {
            $query = $this->queryParser->parse($rawQuery, $trace);
            $notes = array_merge($notes, $this->queryParser->notes());
        }

        // Parsed second, and its notes collected right away: the parser resets
        // them on every call.
        $postFilter = null;
        $rawPostFilter = Arr::get($body, 'post_filter');
        if (is_array($rawPostFilter) && $rawPostFilter !== []) {
            $postFilter = $this->queryParser->parse($rawPostFilter, $trace);
            $notes = array_merge($notes, $this->queryParser->notes());
        }

        $aggs = [];
        foreach (['aggs', 'aggregations'] as $slot) {
            $rawAggs = Arr::get($body, $slot);
            if (is_array($rawAggs)) {
                $aggs = array_merge($aggs, $this->aggParser->parse($rawAggs));
            }
        }

        foreach (array_keys($body) as $key) {
            $key = (string) $key;
            if (in_array($key, self::RENDERED, true)) {
                continue;
            }
            if (in_array($key, self::NOISE, true)) {
                $trace->record(Rule::SECTION_IGNORED, $key);
                continue;
            }
            $notes[] = '+' . $key;
        }

        sort($notes);

        return new QueryModel(
            $index ?? '',
            $query,
            $postFilter,
            $aggs,
            $this->intOrNull(Arr::get($body, 'size')),
            $this->intOrNull(Arr::get($body, 'from')),
            array_merge($this->sort(Arr::get($body, 'sort')), $this->uriSort($envelopeSort)),
            array_values(array_unique($notes)),
            self::filled(Arr::get($body, 'suggest')),
            $scroll || self::cursored($body),
            // `_source: false` exactly. A list or an object is a *filtered*
            // source, and a request asking for two fields is still asking for
            // documents.
            Arr::get($body, '_source') === false,
        );
    }

    /**
     * @param array<mixed> $body
     */
    private static function cursored(array $body): bool
    {
        foreach (self::CURSORS as $section) {
            if (self::filled(Arr::get($body, $section))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A section that is there and says something. Every section read this way
     * is written as an object or a list, and an empty one is what a request
     * builder leaves behind when the feature is switched off — it does not make
     * the request a type-ahead or a walk.
     *
     * @param mixed $section
     */
    private static function filled($section): bool
    {
        return is_array($section) && $section !== [];
    }

    /**
     * @param mixed $value
     */
    private function intOrNull($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Envelope `sort` is a query-string parameter, so it carries the URI
     * syntax — `field:direction`, comma-joined — not the body's structural
     * form. Left to {@see self::sort()} it would mint a field called
     * `timestamp:desc` and claim ascending.
     *
     * A suffix that is neither `asc` nor `desc` is part of the field name: the
     * cluster only reads those two, and a field is likelier than a direction
     * nobody supports.
     *
     * @param mixed $sort
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function uriSort($sort): array
    {
        if ($sort === null) {
            return [];
        }

        $entries = is_array($sort) && Arr::isList($sort) ? $sort : [$sort];
        $structural = [];

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                $structural[] = $entry;
                continue;
            }

            foreach (explode(',', $entry) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }

                $colon = strrpos($part, ':');
                $direction = $colon === false ? '' : substr($part, $colon + 1);

                $field = $colon === false ? '' : substr($part, 0, $colon);

                $structural[] = $field !== '' && ($direction === 'asc' || $direction === 'desc')
                    ? [$field => $direction]
                    : $part;
            }
        }

        return $this->sort($structural);
    }

    /**
     * `sort` accepts a string, an object, or a list mixing both.
     *
     * @param mixed $sort
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function sort($sort): array
    {
        if ($sort === null) {
            return [];
        }

        $entries = is_array($sort) && Arr::isList($sort) ? $sort : [$sort];
        $out = [];

        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $out[] = [$entry, self::defaultDirection($entry)];
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            foreach ($entry as $field => $spec) {
                $field = (string) $field;
                $direction = self::defaultDirection($field);
                if (is_string($spec)) {
                    $direction = $spec;
                } elseif (is_array($spec)) {
                    $order = Arr::get($spec, 'order');
                    if (is_string($order)) {
                        $direction = $order;
                    }
                }
                $out[] = [$field, $direction];
            }
        }

        return $out;
    }

    /**
     * Sorting defaults to ascending, except on _score which defaults to
     * descending — otherwise the digest would claim the opposite of what the
     * query does.
     */
    private static function defaultDirection(string $field): string
    {
        return $field === '_score' ? 'desc' : 'asc';
    }
}
