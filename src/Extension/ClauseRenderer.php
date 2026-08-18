<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Extension;

/**
 * Teaches the library a query type it does not model.
 *
 * Thirteen of the fifty-nine types in the OpenSearch specification render as
 * `type(?)`, and four of those are unreadable in principle rather than by
 * omission — `sltr` needs the Learning-to-Rank plugin, `agentic` hands the
 * result set to a model. The library cannot model them; someone running the
 * plugin can. This is how they say so, without a fork and without touching the
 * parser.
 *
 * A renderer is only ever consulted for a type the library leaves opaque. That
 * is not a rule enforced by a list that could drift — the hook sits in the
 * parser's default branch, so a natively modelled type never reaches it. Your
 * fingerprints for `term` cannot be moved by an extension, deliberately or by
 * accident.
 *
 * @api
 */
interface ClauseRenderer
{
    /**
     * Describe one clause, or return null to leave it opaque.
     *
     * Null is the honest answer for a body this renderer does not recognise:
     * `type(?)` says "something is here and it was not read", which is true,
     * while a guess would be a fingerprint built on a misreading.
     *
     * @param array<mixed> $body the clause body — for `{"sltr": {…}}`, the `{…}`
     */
    public function render(array $body): ?RenderedClause;
}
