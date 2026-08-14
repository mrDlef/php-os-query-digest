<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Explain;

use JsonSerializable;

/**
 * One normalisation rule that actually fired, with how many times and on what.
 *
 * A rule is only ever reported when it changed the query. "Configured but with
 * nothing to do" is not a rule that fired — reporting it would drown the real
 * answer to "why do these two queries share a hash?".
 */
final class Rule implements JsonSerializable
{
    // Parse-time rewrites: the DSL shape the library refuses to distinguish.
    public const MUST_FILTER_MERGED = 'must_filter_merged';
    public const BOOST_DROPPED = 'boost_dropped';
    public const SHOULD_BOOST_ONLY = 'should_boost_only';
    public const CONSTANT_SCORE_UNWRAPPED = 'constant_score_unwrapped';
    public const FUNCTION_SCORE_UNWRAPPED = 'function_score_unwrapped';
    public const BOOSTING_UNWRAPPED = 'boosting_unwrapped';
    public const TERMS_LOOKUP = 'terms_lookup';

    // Tree rewrites applied by the canonicaliser.
    public const FLATTEN = 'flatten';
    public const UNWRAP = 'unwrap';
    public const DEDUPE = 'dedupe';
    public const REORDER = 'reorder';
    public const DROP_MATCH_ALL = 'drop_match_all';
    public const EMPTY_TO_MATCH_ALL = 'empty_to_match_all';

    // Request-level rewrites.
    public const INDEX_PATTERN = 'index_pattern';
    public const SECTION_IGNORED = 'section_ignored';

    /**
     * Why each rule exists, in one line. Kept next to the identifiers so the
     * explanation is readable without a trip to the documentation.
     *
     * @var array<string,string>
     */
    private const DESCRIPTIONS = [
        self::MUST_FILTER_MERGED => 'bool.must and bool.filter both became AND: they differ in scoring, not in which documents match.',
        self::BOOST_DROPPED => 'A boost was ignored: it reorders results, it does not change them.',
        self::SHOULD_BOOST_ONLY => 'A should group beside a must/filter, with no minimum_should_match, only boosts — moved to the notes instead of being rendered as a filter.',
        self::CONSTANT_SCORE_UNWRAPPED => 'constant_score was replaced by its filter: it only flattens scoring.',
        self::FUNCTION_SCORE_UNWRAPPED => 'function_score was replaced by its inner query: the functions only rescore.',
        self::BOOSTING_UNWRAPPED => 'boosting was replaced by its positive clause: negative demotes, it does not exclude.',
        self::TERMS_LOOKUP => 'A terms lookup became a placeholder: its values live in another document.',
        self::FLATTEN => 'Nested connectors of the same kind were flattened.',
        self::UNWRAP => 'A single-clause connector was replaced by that clause.',
        self::DEDUPE => 'Identical sibling clauses were de-duplicated.',
        self::REORDER => 'Commutative siblings were reordered by a stable key, so the order they were written in stops mattering.',
        self::DROP_MATCH_ALL => 'match_all was dropped from a multi-clause AND: it constrains nothing.',
        self::EMPTY_TO_MATCH_ALL => 'A connector with no clause left became match_all.',
        self::INDEX_PATTERN => 'The index was collapsed to a rolling pattern, so a daily index does not mint a new fingerprint every midnight.',
        self::SECTION_IGNORED => 'Top-level sections that say nothing about what a query is for were ignored.',
    ];

    /** @var string */
    private $id;

    /** @var int */
    private $count;

    /** @var array<int,string> */
    private $details;

    /**
     * @param array<int,string> $details
     */
    public function __construct(string $id, int $count, array $details = [])
    {
        $this->id = $id;
        $this->count = $count;
        $this->details = array_values($details);
    }

    public function id(): string
    {
        return $this->id;
    }

    /** How many times the rule fired on this query. */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * What it fired on — a connector kind, an ignored section name, an index
     * rewrite. Empty when the identifier already says everything.
     *
     * @return array<int,string>
     */
    public function details(): array
    {
        return $this->details;
    }

    public function description(): string
    {
        return isset(self::DESCRIPTIONS[$this->id]) ? self::DESCRIPTIONS[$this->id] : '';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'rule' => $this->id,
            'count' => $this->count,
            'why' => $this->description(),
        ];

        if ($this->details !== []) {
            $out['on'] = $this->details;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        // Deliberately ASCII: the caller pads these to a column with strlen(),
        // and a multi-byte glyph would misalign it.
        $line = $this->id;
        if ($this->count > 1) {
            $line .= ' x' . $this->count;
        }
        if ($this->details !== []) {
            $line .= ' [' . implode(', ', $this->details) . ']';
        }

        return $line;
    }
}
