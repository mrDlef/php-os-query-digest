<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Render;

/**
 * @internal
 */
interface ValueRenderer
{
    /**
     * @param mixed $value
     */
    public function scalar(string $field, $value): string;

    /**
     * A value that must always be quoted (phrase matches).
     *
     * @param mixed $value
     */
    public function phrase(string $field, $value): string;

    /**
     * An already-formatted payload the library does not parse: the body of a
     * query_string, a terms lookup. It is still a *value*, so a normalising
     * renderer has to erase it — otherwise every user search term would mint a
     * new fingerprint.
     */
    public function raw(string $field, string $value): string;
}
