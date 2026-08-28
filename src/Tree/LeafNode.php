<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tree;

/**
 * @internal
 */
final class LeafNode implements Node
{
    public const OP_TERM = 'term';
    public const OP_TERMS = 'terms';
    public const OP_MATCH = 'match';
    public const OP_PHRASE = 'phrase';
    public const OP_PREFIX = 'prefix';

    /**
     * The two completion spellings of a match: `match_phrase_prefix` and
     * `match_bool_prefix`. They are modelled apart from the ops they refine —
     * and *only* apart — because an autocomplete is a recognisable kind of
     * search and nothing else in the tree says so; see
     * {@see \MrDlef\OsQueryDigest\Classify\Classifier}.
     *
     * They render exactly like {@see self::OP_PHRASE} and {@see self::OP_MATCH}:
     * the last term of a type-ahead is the same clause a reader wants to see,
     * and rendering it differently would move every fingerprint that has one.
     */
    public const OP_PHRASE_PREFIX = 'phrase_prefix';
    public const OP_BOOL_PREFIX = 'bool_prefix';
    public const OP_WILDCARD = 'wildcard';
    public const OP_REGEXP = 'regexp';
    public const OP_EXISTS = 'exists';
    public const OP_RANGE = 'range';
    public const OP_RAW = 'raw';

    /** Vector search. Values are the search parameters, keyed: k, min_score… */
    public const OP_KNN = 'knn';
    public const OP_NEURAL = 'neural';

    /** Geo. The shape itself is a value; only the radius survives rendering. */
    public const OP_GEO_DISTANCE = 'geo_distance';
    public const OP_GEO_BBOX = 'geo_bbox';
    public const OP_GEO_POLYGON = 'geo_polygon';

    /**
     * `geo_shape` / `xy_shape`. Values are the two structural parts, in order:
     * the kind of geometry and the relation. Unlike every other op, they are
     * *not* erased in the signature — see {@see \MrDlef\OsQueryDigest\Parser\QueryParser}.
     */
    public const OP_GEO_SHAPE = 'geo_shape';
    public const OP_XY_SHAPE = 'xy_shape';

    /** A painless script. Fieldless: it can read anything. */
    public const OP_SCRIPT = 'script';

    /** `more_like_this`. Values are the documents or texts to look like. */
    public const OP_LIKE = 'like';

    /**
     * `parent_id`. The field slot carries the joined relation, not a mapping
     * field: the query matches children of one parent, and which relation it
     * walks is part of the shape.
     */
    public const OP_PARENT_ID = 'parent_id';

    /**
     * `percolate`. The field is the one holding the stored queries; the
     * document being tested against them is a value. A single value may be
     * present — `indexed` — when the document is fetched rather than inlined.
     */
    public const OP_PERCOLATE = 'percolate';

    /**
     * `rank_feature` and `distance_feature`. Scoring queries, but not pure
     * rescoring: a document without the field does not match, so they cannot be
     * unwrapped the way `function_score` is. Values are the keyed knobs.
     */
    public const OP_RANK_FEATURE = 'rank_feature';
    public const OP_DISTANCE_FEATURE = 'distance_feature';

    /** `intervals`. The field only — see the parser for why the rules go. */
    public const OP_INTERVALS = 'intervals';

    /**
     * A clause described by a user-registered
     * {@see \MrDlef\OsQueryDigest\Extension\ClauseRenderer}. The label it
     * chose is the first value; the rest are its keyed parameters.
     */
    public const OP_EXTENSION = 'extension';

    private string $field;

    private string $op;

    /**
     * For OP_RANGE: an ordered map of bound => value (gte/gt/lte/lt).
     * For every other op: a list of scalar values.
     *
     * @var array<string|int,mixed>
     */
    private array $values;

    /**
     * @param array<string|int,mixed> $values
     */
    public function __construct(string $field, string $op, array $values = [])
    {
        $this->field = $field;
        $this->op = $op;
        $this->values = $values;
    }

    public function field(): string
    {
        return $this->field;
    }

    public function op(): string
    {
        return $this->op;
    }

    /**
     * @return array<string|int,mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * @param array<string|int,mixed> $values
     */
    public function withValues(array $values): self
    {
        return new self($this->field, $this->op, $values);
    }

    /**
     * The op a clause *sorts* as.
     *
     * A completion op renders as the op it refines, so it has to sort as that
     * op too. Siblings are ordered by this key before rendering: left to sort
     * under their own names, `phrase_prefix` and `bool_prefix` would slot
     * elsewhere among their siblings and move the fingerprint of every query
     * mixing one with a clause whose key falls between — a hash move with
     * nothing to show for it in the rendered line.
     *
     * @var array<string,string>
     */
    private const SORTS_AS = [
        self::OP_PHRASE_PREFIX => self::OP_PHRASE,
        self::OP_BOOL_PREFIX => self::OP_MATCH,
    ];

    public function sortKey(): string
    {
        // Values are part of the key: two `term` clauses on the same field but
        // different values must keep a stable relative order.
        $flat = [];
        foreach ($this->values as $key => $value) {
            $flat[] = (is_string($key) ? $key . '=' : '') . self::scalarKey($value);
        }
        if ($this->op !== self::OP_RANGE) {
            sort($flat);
        }

        return $this->field . ':' . (self::SORTS_AS[$this->op] ?? $this->op) . ':' . implode(',', $flat);
    }

    /**
     * @param mixed $value
     */
    private static function scalarKey($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return md5((string) json_encode($value));
    }
}
