<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Classify;

use MrDlef\OsQueryDigest\Kind;
use MrDlef\OsQueryDigest\Tree\AndNode;
use MrDlef\OsQueryDigest\Tree\JoinNode;
use MrDlef\OsQueryDigest\Tree\LeafNode;
use MrDlef\OsQueryDigest\Tree\NestedNode;
use MrDlef\OsQueryDigest\Tree\Node;
use MrDlef\OsQueryDigest\Tree\NotNode;
use MrDlef\OsQueryDigest\Tree\OpaqueNode;
use MrDlef\OsQueryDigest\Tree\OrNode;
use MrDlef\OsQueryDigest\Tree\QueryModel;

/**
 * Reads a parsed request as a {@see Kind}.
 *
 * Two rules hold the whole thing up, and both are easy to get wrong from the
 * raw body — which is where this was prototyped before it moved in here:
 *
 * - **Only `query` and `post_filter` select.** A faceted browse legitimately
 *   carries `filter` aggregations with `match` clauses inside them; classifying
 *   over the whole body reports every one of them as a text search. The
 *   aggregations say what is *counted*, never what is asked.
 * - **`size` absent is not `size: 0`.** A request with aggregations and no
 *   `size` still comes back with ten documents, so the buckets-only intent is
 *   only legible by also reading `_source`.
 *
 * The order below is the classification: first match wins, most specific first.
 *
 * @internal
 */
final class Classifier
{
    /**
     * The two ops that exist only to complete what someone is typing. A
     * `match_phrase_prefix` in a request is a type-ahead; there is no second
     * reading of it.
     */
    private const COMPLETION = [
        LeafNode::OP_PHRASE_PREFIX,
        LeafNode::OP_BOOL_PREFIX,
    ];

    /** Naming documents rather than describing them. */
    private const IDENTITY = [
        LeafNode::OP_TERM,
        LeafNode::OP_TERMS,
    ];

    /**
     * The only field this library knows to be an identity. `ids` parses to a
     * `terms` on it, so that query needs no case of its own.
     */
    private const ID = '_id';

    /**
     * The cluster's own default page size — deliberately its number and not one
     * of ours. A request that names no `_id` and asks for five thousand
     * documents is reading in bulk, whatever its single `term` clause looks
     * like; one that takes the default, or less, is fetching.
     */
    private const DEFAULT_PAGE = 10;

    public function of(QueryModel $model): Kind
    {
        $survey = self::survey($model);

        if ($model->suggests() || self::completes($survey)) {
            return Kind::suggest();
        }

        if (self::answersWithoutDocuments($model)) {
            return Kind::aggregate();
        }

        if ($model->cursored()) {
            return Kind::scan();
        }

        // Documents come back and the query is there but unreadable — a plugin
        // type, a script, a clause a registered renderer described. Saying
        // `browse` here would be a guess dressed as a measurement.
        if ($survey['ops'] === [] && $survey['opaque']) {
            return Kind::unknown();
        }

        if (self::namesDocuments($model, $survey)) {
            return Kind::lookup();
        }

        return Kind::browse();
    }

    /**
     * Someone is still typing.
     *
     * A `prefix` clause counts only when it is the *whole* question — one op,
     * however many fields it is spread over, which is what an autocomplete
     * across `name` and `email` looks like. Among other clauses it is an
     * ordinary filter: `prefix: {host: "web-"}` beside a phrase search and a
     * highlight is a search results page, and reading every one of those as a
     * type-ahead would empty the kind of its meaning.
     *
     * @param array{ops:array<int,string>,fields:array<int,string>,leaves:int,opaque:bool,negated:bool} $survey
     */
    private static function completes(array $survey): bool
    {
        if (array_intersect(self::COMPLETION, $survey['ops']) !== []) {
            return true;
        }

        // Every clause a prefix, however many there are: an autocomplete over
        // `name` and `email` is two of them. Written as a difference rather
        // than as an equality so it does not depend on the survey deduplicating
        // or re-indexing what it collected.
        return $survey['ops'] !== [] && array_diff($survey['ops'], [LeafNode::OP_PREFIX]) === [];
    }

    /**
     * No documents reach the caller.
     *
     * Two spellings, and the second is the one a prototype gets wrong: aggs
     * with no `size` at all is *not* buckets-only — the cluster still returns
     * ten documents — unless `_source` is off, which is how an application says
     * it will not look at them.
     *
     * A `size: 0` with no aggregations lands here too. It asks for a total
     * rather than for buckets, but it is the same animal from the outside: the
     * traffic that never asks for a document.
     */
    private static function answersWithoutDocuments(QueryModel $model): bool
    {
        if ($model->size() === 0) {
            return true;
        }

        return $model->size() === null && $model->sourceDisabled() && $model->aggs() !== [];
    }

    /**
     * "Give me these documents", as opposed to "give me a page of what
     * matches".
     *
     * Exact clauses only, and nothing that makes it a listing: no `from`, no
     * `sort` — a caller who asks for an order is asking for a *result set*,
     * even when the selection is a handful of keys — no aggregations, and no
     * negation, since excluding is describing rather than naming.
     *
     * What is left still has to be *naming* rather than filtering, and the
     * difference is not in the op: `terms` over eight order statuses and a
     * country is a filtered list, not a fetch. So either the request names
     * `_id`, the one identity this library can recognise — a multi-tenant fetch
     * puts a tenant `term` beside its `ids`, and is still a fetch — or it is a
     * single clause asking for no more than a page, which is what fetching by a
     * business key looks like. `term: {service: "api"}` with `size: 5000` is
     * the same clause and is not a fetch.
     *
     * An unreadable clause anywhere disqualifies it: naming documents is a
     * claim about the whole question, and part of this one was not read.
     *
     * @param array{ops:array<int,string>,fields:array<int,string>,leaves:int,opaque:bool,negated:bool} $survey
     */
    private static function namesDocuments(QueryModel $model, array $survey): bool
    {
        if ($survey['ops'] === [] || $survey['negated'] || $survey['opaque'] || $model->aggs() !== []) {
            return false;
        }

        // Spelled out rather than `($model->from() ?? 0) > 0`: `??` binds looser
        // than `||`, so the compact form reads as a different expression than it
        // looks like, and one that no test can tell apart from this one.
        $from = $model->from();
        if ($model->sort() !== [] || ($from !== null && $from > 0)) {
            return false;
        }

        if (array_diff($survey['ops'], self::IDENTITY) !== []) {
            return false;
        }

        if (in_array(self::ID, $survey['fields'], true)) {
            return true;
        }

        return $survey['leaves'] === 1 && ($model->size() ?? self::DEFAULT_PAGE) <= self::DEFAULT_PAGE;
    }

    /**
     * What the selecting trees hold. `post_filter` is one of them: it narrows
     * the hits, so it selects — that is exactly why the model keeps it in its
     * own slot rather than merged into the query.
     *
     * @return array{ops:array<int,string>,fields:array<int,string>,leaves:int,opaque:bool,negated:bool}
     */
    private static function survey(QueryModel $model): array
    {
        $found = ['ops' => [], 'fields' => [], 'leaves' => 0, 'opaque' => false, 'negated' => false];

        foreach ([$model->query(), $model->postFilter()] as $node) {
            if ($node !== null) {
                self::walk($node, $found);
            }
        }

        return $found;
    }

    /**
     * @param array{ops:array<int,string>,fields:array<int,string>,leaves:int,opaque:bool,negated:bool} $found
     */
    private static function walk(Node $node, array &$found): void
    {
        // One chain rather than a guard per case: a wrapper is walked *and*
        // recorded — a negation still has to reach what it negates, or a
        // request that excludes an unreadable clause would read as readable.
        if ($node instanceof LeafNode) {
            $found['ops'][] = $node->op();
            $found['fields'][] = $node->field();
            $found['leaves']++;
        } elseif ($node instanceof OpaqueNode) {
            $found['opaque'] = true;
        } elseif ($node instanceof NotNode) {
            $found['negated'] = true;
            self::walk($node->child(), $found);
        } elseif ($node instanceof NestedNode || $node instanceof JoinNode) {
            self::walk($node->child(), $found);
        } elseif ($node instanceof AndNode || $node instanceof OrNode) {
            foreach ($node->children() as $child) {
                self::walk($child, $found);
            }
        }

        // Whatever is left — match_all, match_none — says nothing about the
        // kind of work, and deliberately contributes nothing.
    }
}
