<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

/**
 * A node of the normalised logical tree a DSL query is reduced to.
 *
 * The tree is deliberately *logical* (and / or / not / leaf) rather than a
 * mirror of the DSL: `bool.must` and `bool.filter` both collapse to AND, which
 * is what makes two differently-written but equivalent queries converge on the
 * same fingerprint.
 *
 * @internal
 */
interface Node
{
    /**
     * A deterministic, renderer-independent key used to order commutative
     * children before hashing. Must be stable across PHP versions and never
     * depend on the order the query was written in.
     */
    public function sortKey(): string;
}
