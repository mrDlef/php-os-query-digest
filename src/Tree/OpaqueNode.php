<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

/**
 * A clause the library understands the *existence* of but not the contents:
 * `script`, `function_score`, unknown/plugin query types.
 *
 * It is never silently dropped — it renders as `<type>(…)` so a reader can tell
 * something is there, and it still contributes to the fingerprint.
 */
final class OpaqueNode implements Node
{
    /** @var string */
    private $type;

    public function __construct(string $type)
    {
        $this->type = $type;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function sortKey(): string
    {
        return 'opaque:' . $this->type;
    }
}
