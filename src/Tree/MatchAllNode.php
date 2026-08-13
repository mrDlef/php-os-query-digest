<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

final class MatchAllNode implements Node
{
    public function sortKey(): string
    {
        return 'all';
    }
}
