<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Render;

/**
 * Erases every literal. This is what makes two runs of the same query with
 * different parameters share a fingerprint.
 *
 * @internal
 */
final class PlaceholderValueRenderer implements ValueRenderer
{
    /**
     * @param mixed $value
     */
    public function scalar(string $field, $value): string
    {
        return '?';
    }

    /**
     * @param mixed $value
     */
    public function phrase(string $field, $value): string
    {
        return '"?"';
    }

    public function raw(string $field, string $value): string
    {
        return '?';
    }
}
