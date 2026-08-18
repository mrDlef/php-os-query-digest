<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

/**
 * @internal
 */
final class MatchAllNode implements Node
{
    public function sortKey(): string
    {
        return 'all';
    }
}
