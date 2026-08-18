<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

/**
 * `match_none` — the query that matches nothing.
 *
 * Its own node rather than an opaque clause because it *absorbs*: an AND that
 * contains it returns no document at all, whatever its siblings say. The
 * canonicaliser relies on that.
 *
 * @internal
 */
final class MatchNoneNode implements Node
{
    public function sortKey(): string
    {
        return 'none';
    }
}
