<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Parser;

use MrDlef\OsQueryDigest\Explain\Rule;
use MrDlef\OsQueryDigest\Explain\Trace;
use MrDlef\OsQueryDigest\Support\Arr;
use MrDlef\OsQueryDigest\Tree\AndNode;
use MrDlef\OsQueryDigest\Tree\JoinNode;
use MrDlef\OsQueryDigest\Tree\LeafNode;
use MrDlef\OsQueryDigest\Tree\MatchAllNode;
use MrDlef\OsQueryDigest\Tree\MatchNoneNode;
use MrDlef\OsQueryDigest\Tree\NestedNode;
use MrDlef\OsQueryDigest\Tree\Node;
use MrDlef\OsQueryDigest\Tree\NotNode;
use MrDlef\OsQueryDigest\Tree\OpaqueNode;
use MrDlef\OsQueryDigest\Tree\OrNode;

/**
 * Turns a `query` object into the normalised logical tree.
 *
 * Anything it does not model exactly is either kept as an OpaqueNode or pushed
 * to {@see self::notes()} — nothing is dropped silently.
 */
final class QueryParser
{
    /**
     * Every key of a geo clause that is an option rather than the field it runs
     * on.
     */
    private const GEO_OPTIONS = [
        'distance', 'distance_type', 'type', 'validation_method',
        'ignore_unmapped', '_name', 'boost',
    ];

    /**
     * The same, for the shape queries. `type` is absent on purpose: it is a
     * key *inside* the geometry, never beside the field.
     */
    private const SHAPE_OPTIONS = ['ignore_unmapped', '_name', 'boost'];

    /** @var array<int,string> */
    private array $notes = [];

    private Trace $trace;

    public function __construct()
    {
        $this->trace = new Trace();
    }

    /**
     * @param array<mixed> $query
     */
    public function parse(array $query, ?Trace $trace = null): Node
    {
        $this->notes = [];
        $this->trace = $trace ?? new Trace();

        return $this->clause($query);
    }

    /**
     * @return array<int,string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @param array<mixed> $clause
     */
    private function clause(array $clause): Node
    {
        $type = Arr::firstKey($clause);
        if ($type === null) {
            return new MatchAllNode();
        }

        $body = $clause[$type];
        $body = is_array($body) ? $body : [];

        switch ($type) {
            case 'bool':
                return $this->bool($body);

            case 'match_all':
                return new MatchAllNode();

            case 'match_none':
                return new MatchNoneNode();

            case 'term':
                return $this->single($body, LeafNode::OP_TERM);

            case 'terms':
            case 'terms_set':
                return $this->terms($body);

            case 'match':
            case 'match_bool_prefix':
                return $this->single($body, LeafNode::OP_MATCH);

            case 'match_phrase':
            case 'match_phrase_prefix':
                return $this->single($body, LeafNode::OP_PHRASE);

            case 'prefix':
                return $this->single($body, LeafNode::OP_PREFIX);

            case 'wildcard':
                return $this->single($body, LeafNode::OP_WILDCARD);

            case 'regexp':
                return $this->single($body, LeafNode::OP_REGEXP);

            case 'fuzzy':
            case 'common':
                // The deprecated cutoff_frequency query. Same body as a match,
                // and legacy applications are exactly the ones whose logs get
                // read back.
                return $this->single($body, LeafNode::OP_MATCH);

            case 'exists':
                $field = Arr::get($body, 'field');

                return is_string($field)
                    ? new LeafNode($field, LeafNode::OP_EXISTS)
                    : new OpaqueNode('exists');

            case 'range':
                return $this->range($body);

            case 'ids':
                $values = Arr::get($body, 'values', []);

                return new LeafNode('_id', LeafNode::OP_TERMS, is_array($values) ? array_values($values) : []);

            case 'multi_match':
            case 'combined_fields':
                // combined_fields scores as though the fields were one field.
                // That is a scoring difference, and this library already
                // refuses to distinguish those: the set of documents a text
                // query over a field list can match is the same shape.
                return $this->multiMatch($body);

            case 'query_string':
            case 'simple_query_string':
                return $this->queryString($body, $type);

            case 'nested':
                return $this->nested($body);

            case 'knn':
                return $this->vector($body, LeafNode::OP_KNN, ['k', 'min_score', 'max_distance']);

            case 'neural':
                return $this->vector($body, LeafNode::OP_NEURAL, ['k', 'min_score', 'max_distance']);

            case 'geo_distance':
                return $this->geoDistance($body);

            case 'geo_bounding_box':
                return $this->geoArea($body, LeafNode::OP_GEO_BBOX, $type);

            case 'geo_polygon':
                return $this->geoArea($body, LeafNode::OP_GEO_POLYGON, $type);

            case 'geo_shape':
                return $this->shape($body, LeafNode::OP_GEO_SHAPE);

            case 'xy_shape':
                return $this->shape($body, LeafNode::OP_XY_SHAPE);

            case 'script':
                return $this->script($body);

            case 'has_child':
                return $this->join($body, JoinNode::HAS_CHILD, 'type');

            case 'has_parent':
                return $this->join($body, JoinNode::HAS_PARENT, 'parent_type');

            case 'parent_id':
                return $this->parentId($body);

            case 'more_like_this':
                return $this->moreLikeThis($body);

            case 'constant_score':
                $filter = Arr::get($body, 'filter');
                if (!is_array($filter)) {
                    return new OpaqueNode('constant_score');
                }
                $this->trace->record(Rule::CONSTANT_SCORE_UNWRAPPED);

                return $this->clause($filter);

            case 'function_score':
                // Only the inner query filters; the functions merely rescore.
                $inner = Arr::get($body, 'query');
                $this->note('function_score');
                $this->trace->record(Rule::FUNCTION_SCORE_UNWRAPPED);

                return is_array($inner) ? $this->clause($inner) : new MatchAllNode();

            case 'script_score':
                // Same shape as function_score: only the inner query decides
                // which documents match, the script reorders them.
                return $this->scriptScore($body);

            case 'boosting':
                // `negative` only demotes, it does not exclude.
                $positive = Arr::get($body, 'positive');
                $this->note('boosting');
                $this->trace->record(Rule::BOOSTING_UNWRAPPED);

                return is_array($positive) ? $this->clause($positive) : new MatchAllNode();

            case 'dis_max':
            case 'hybrid':
                // Both take a list of queries and return the union of what they
                // match; they differ only in how the scores are combined —
                // hybrid needs a search pipeline to normalise them. Which is a
                // scoring concern, so the two read the same here.
                return $this->union($body);

            case 'percolate':
                return $this->percolate($body);

            case 'rank_feature':
                return $this->feature($body, LeafNode::OP_RANK_FEATURE, []);

            case 'distance_feature':
                return $this->feature($body, LeafNode::OP_DISTANCE_FEATURE, ['pivot']);

            case 'intervals':
                return $this->intervals($body);

            case 'wrapper':
                return $this->wrapper($body);

            default:
                return new OpaqueNode($type);
        }
    }

    /**
     * `{"bool": {...}}` → and / or / not.
     *
     * @param array<mixed> $body
     */
    private function bool(array $body): Node
    {
        $and = [];

        // must and filter differ only in scoring, not in which documents match.
        $slotsUsed = 0;
        foreach (['must', 'filter'] as $slot) {
            $before = count($and);
            foreach (Arr::clauses(Arr::get($body, $slot, [])) as $sub) {
                $and[] = $this->clause($sub);
            }
            if (count($and) > $before) {
                ++$slotsUsed;
            }
        }

        if ($slotsUsed === 2) {
            $this->trace->record(Rule::MUST_FILTER_MERGED);
        }

        // must_not: [A, B] means (NOT A) AND (NOT B), *not* NOT (A AND B).
        foreach (Arr::clauses(Arr::get($body, 'must_not', [])) as $sub) {
            $and[] = new NotNode($this->clause($sub));
        }

        $should = [];
        foreach (Arr::clauses(Arr::get($body, 'should', [])) as $sub) {
            $should[] = $this->clause($sub);
        }

        if ($should !== []) {
            $raw = Arr::get($body, 'minimum_should_match');
            $msm = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : null;

            // A `should` group only restricts the result set when there is no
            // must/filter beside it, or when minimum_should_match forces it.
            // Otherwise it is pure boosting and must not be rendered as a filter.
            $filtering = $and === [] || $raw !== null;

            if ($filtering) {
                if ($raw !== null && $msm === null) {
                    $this->note('msm=' . (is_scalar($raw) ? (string) $raw : '?'));
                }
                $and[] = new OrNode($should, $msm !== null && $msm > 1 ? $msm : null);
            } else {
                $this->note('should=' . count($should));
                $this->trace->record(
                    Rule::SHOULD_BOOST_ONLY,
                    count($should) . (count($should) === 1 ? ' clause' : ' clauses'),
                );
            }
        }

        if ($and === []) {
            return new MatchAllNode();
        }

        if (count($and) === 1) {
            $this->trace->record(Rule::UNWRAP, 'bool');

            return $and[0];
        }

        return new AndNode($and);
    }

    /**
     * `{"term": {"field": value}}` and every other single-field clause.
     *
     * @param array<mixed> $body
     */
    private function single(array $body, string $op): Node
    {
        foreach ($body as $field => $value) {
            if ($field === 'boost') {
                $this->trace->record(Rule::BOOST_DROPPED);
                continue;
            }

            if (is_array($value)) {
                if (array_key_exists('boost', $value)) {
                    $this->trace->record(Rule::BOOST_DROPPED, (string) $field);
                }

                $value = array_key_exists('value', $value)
                    ? $value['value']
                    : ($value['query'] ?? null);
            }

            return new LeafNode((string) $field, $op, [$value]);
        }

        return new OpaqueNode($op);
    }

    /**
     * @param array<mixed> $body
     */
    private function terms(array $body): Node
    {
        foreach ($body as $field => $value) {
            if ($field === 'boost') {
                $this->trace->record(Rule::BOOST_DROPPED);
                continue;
            }

            if ($field === 'minimum_should_match_field') {
                continue;
            }

            if (is_array($value) && Arr::isList($value)) {
                return new LeafNode((string) $field, LeafNode::OP_TERMS, array_values($value));
            }

            if (is_array($value)) {
                // terms lookup: {"terms": {"user": {"index": …, "id": …}}}
                $this->note('terms_lookup');
                $this->trace->record(Rule::TERMS_LOOKUP, (string) $field);

                return new LeafNode((string) $field, LeafNode::OP_RAW, ['<terms-lookup>']);
            }

            return new LeafNode((string) $field, LeafNode::OP_TERMS, [$value]);
        }

        return new OpaqueNode('terms');
    }

    /**
     * @param array<mixed> $body
     */
    private function range(array $body): Node
    {
        foreach ($body as $field => $bounds) {
            if ($field === 'boost') {
                $this->trace->record(Rule::BOOST_DROPPED);
                continue;
            }

            if (!is_array($bounds)) {
                continue;
            }

            $values = [];
            // Fixed order: the same range written gte-then-lt or lt-then-gte
            // has to produce the same output.
            foreach (['gte', 'gt', 'lte', 'lt'] as $bound) {
                if (array_key_exists($bound, $bounds)) {
                    $values[$bound] = $bounds[$bound];
                }
            }

            if ($values === []) {
                continue;
            }

            return new LeafNode((string) $field, LeafNode::OP_RANGE, $values);
        }

        return new OpaqueNode('range');
    }

    /**
     * @param array<mixed> $body
     */
    private function multiMatch(array $body): Node
    {
        $fields = Arr::get($body, 'fields', []);
        $field = is_array($fields) && $fields !== [] ? implode('|', Arr::strings($fields)) : '*';

        return new LeafNode($field, LeafNode::OP_MATCH, [Arr::get($body, 'query')]);
    }

    /**
     * @param array<mixed> $body
     */
    private function queryString(array $body, string $type): Node
    {
        $query = Arr::get($body, 'query');
        if (!is_string($query)) {
            return new OpaqueNode($type);
        }

        $fields = Arr::get($body, 'fields', []);
        $field = is_array($fields) && $fields !== [] ? implode('|', Arr::strings($fields)) : '';

        // The payload is already Lucene-ish syntax: keep it verbatim so the
        // rendered line stays pasteable.
        return new LeafNode($field, LeafNode::OP_RAW, [$query]);
    }

    /**
     * @param array<mixed> $body
     */
    private function nested(array $body): Node
    {
        $path = Arr::get($body, 'path');
        $inner = Arr::get($body, 'query');

        if (!is_string($path) || !is_array($inner)) {
            return new OpaqueNode('nested');
        }

        return new NestedNode($path, $this->clause($inner));
    }

    /**
     * `knn` and `neural`: vector search. The vector itself, the model id and
     * the query image are values — rendering them would be a wall of floats or
     * a base64 blob for no diagnostic gain. What is worth reading back is which
     * field is searched, with which cut-off.
     *
     * @param array<mixed>      $body
     * @param array<int,string> $params
     */
    private function vector(array $body, string $op, array $params): Node
    {
        foreach ($body as $field => $spec) {
            if ($field === 'boost') {
                $this->trace->record(Rule::BOOST_DROPPED);
                continue;
            }

            if (!is_array($spec)) {
                continue;
            }

            $values = [];
            foreach (['query_text', 'query_image'] as $key) {
                if (array_key_exists($key, $spec)) {
                    $values['query'] = $spec[$key];
                    break;
                }
            }
            foreach ($params as $key) {
                if (array_key_exists($key, $spec)) {
                    $values[$key] = $spec[$key];
                }
            }

            $leaf = new LeafNode((string) $field, $op, $values);

            // The inner filter restricts which documents can come back at all,
            // so it is a genuine AND rather than a detail of the vector search.
            $filter = Arr::get($spec, 'filter');
            if (is_array($filter) && $filter !== []) {
                return new AndNode([$leaf, $this->clause($filter)]);
            }

            return $leaf;
        }

        return new OpaqueNode($op);
    }

    /**
     * @param array<mixed> $body
     */
    private function geoDistance(array $body): Node
    {
        $distance = Arr::get($body, 'distance');

        foreach (array_keys($body) as $field) {
            $field = (string) $field;
            if ($field === 'boost') {
                $this->trace->record(Rule::BOOST_DROPPED);
                continue;
            }
            if (in_array($field, self::GEO_OPTIONS, true)) {
                continue;
            }

            // The radius is the one part worth reading in a log line: a 1km and
            // a 500km search are different queries. The centre is a value.
            return new LeafNode($field, LeafNode::OP_GEO_DISTANCE, [$distance]);
        }

        return new OpaqueNode('geo_distance');
    }

    /**
     * `geo_bounding_box` and `geo_polygon`: an area given inline.
     *
     * Neither carries a scalar worth logging. A box comes in five encodings
     * (object, array, string, WKT, geohash) and a polygon is a list of points —
     * the field and the kind of area are the whole shape.
     *
     * @param array<mixed> $body
     */
    private function geoArea(array $body, string $op, string $type): Node
    {
        foreach (array_keys($body) as $field) {
            $field = (string) $field;
            if ($field === 'boost') {
                $this->trace->record(Rule::BOOST_DROPPED);
                continue;
            }
            if (in_array($field, self::GEO_OPTIONS, true)) {
                continue;
            }

            return new LeafNode($field, $op);
        }

        return new OpaqueNode($type);
    }

    /**
     * `geo_shape` and `xy_shape`: does the document's geometry relate to this
     * one, and how.
     *
     * Two parts of that survive into the signature, because both decide which
     * documents match rather than merely which values are searched: the kind of
     * geometry, and the relation. `within` and `disjoint` on the same polygon
     * return opposite result sets — collapsing them would be the geo equivalent
     * of erasing a `not`. Both are drawn from a closed vocabulary, so keeping
     * them cannot inflate the number of distinct fingerprints. The coordinates
     * are values and go.
     *
     * @param array<mixed> $body
     */
    private function shape(array $body, string $op): Node
    {
        foreach ($body as $field => $spec) {
            $field = (string) $field;
            if ($field === 'boost') {
                $this->trace->record(Rule::BOOST_DROPPED);
                continue;
            }
            if (in_array($field, self::SHAPE_OPTIONS, true) || !is_array($spec)) {
                continue;
            }

            $geometry = Arr::get($spec, 'shape');
            if (is_array($geometry)) {
                $kind = Arr::get($geometry, 'type');
                $kind = is_string($kind) ? strtolower($kind) : '?';
            } elseif (is_array(Arr::get($spec, 'indexed_shape'))) {
                // The geometry lives in another document — the same blind spot
                // as a terms lookup, and worth the same warning.
                $this->note('indexed_shape');
                $this->trace->record(Rule::INDEXED_SHAPE, $field);
                $kind = 'indexed';
            } else {
                continue;
            }

            // intersects is what OpenSearch assumes when the query says
            // nothing, so an absent relation renders as the relation it is.
            $relation = Arr::get($spec, 'relation');
            $relation = is_string($relation) ? strtolower($relation) : 'intersects';

            return new LeafNode($field, $op, [$kind, $relation]);
        }

        return new OpaqueNode($op);
    }

    /**
     * @param array<mixed> $body
     */
    private function script(array $body): Node
    {
        $script = Arr::get($body, 'script');

        if (is_string($script)) {
            return new LeafNode('', LeafNode::OP_SCRIPT, [$script]);
        }

        if (is_array($script)) {
            // A stored script has an id instead of a source; both identify what
            // runs, and both are erased in the signature.
            $source = Arr::get($script, 'source');
            $source = is_string($source) ? $source : Arr::get($script, 'id');

            if (is_string($source)) {
                return new LeafNode('', LeafNode::OP_SCRIPT, [$source]);
            }
        }

        return new OpaqueNode('script');
    }

    /**
     * @param array<mixed> $body
     */
    private function join(array $body, string $kind, string $relationKey): Node
    {
        $relation = Arr::get($body, $relationKey);
        $inner = Arr::get($body, 'query');

        if (!is_string($relation) || !is_array($inner)) {
            return new OpaqueNode($kind);
        }

        return new JoinNode($kind, $relation, $this->clause($inner));
    }

    /**
     * `parent_id`: the children of one parent document. There is no inner query
     * to scope — the whole clause is "this relation, that parent" — so it reads
     * as a term rather than as a {@see JoinNode}.
     *
     * @param array<mixed> $body
     */
    private function parentId(array $body): Node
    {
        $relation = Arr::get($body, 'type');
        $id = Arr::get($body, 'id');

        if (!is_string($relation) || !is_scalar($id)) {
            return new OpaqueNode('parent_id');
        }

        // The relation is part of the shape, the parent id is a value: two
        // lookups of the same relation share a fingerprint.
        return new LeafNode($relation, LeafNode::OP_PARENT_ID, [$id]);
    }

    /**
     * @param array<mixed> $body
     */
    private function moreLikeThis(array $body): Node
    {
        $fields = Arr::get($body, 'fields', []);
        $field = is_array($fields) && $fields !== [] ? implode('|', Arr::strings($fields)) : '*';

        $like = Arr::get($body, 'like');
        $entries = is_array($like) && Arr::isList($like) ? $like : [$like];

        $values = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $values[] = $entry;
                continue;
            }
            // {"_index": …, "_id": …}: a document to look like, not a text.
            if (is_array($entry)) {
                $values[] = '<doc>';
            }
        }

        if ($values === []) {
            return new OpaqueNode('more_like_this');
        }

        return new LeafNode($field, LeafNode::OP_LIKE, $values);
    }

    /**
     * `script_score`: the inner query filters, the script only rescores — so it
     * unwraps like `function_score`.
     *
     * One caveat, and it is why the note is not always the same word:
     * `min_score` *does* exclude documents, on a threshold applied to a score
     * this library never computes. When it is set, the rendered line describes a
     * wider result set than the query returns, and the note has to say so.
     *
     * @param array<mixed> $body
     */
    private function scriptScore(array $body): Node
    {
        $inner = Arr::get($body, 'query');

        $this->note(array_key_exists('min_score', $body) ? 'script_score:min_score' : 'script_score');
        $this->trace->record(Rule::SCRIPT_SCORE_UNWRAPPED);

        return is_array($inner) ? $this->clause($inner) : new MatchAllNode();
    }

    /**
     * `dis_max` and `hybrid`: a list of alternatives, any of which is enough.
     *
     * @param array<mixed> $body
     */
    private function union(array $body): Node
    {
        $children = [];
        foreach (Arr::clauses(Arr::get($body, 'queries', [])) as $sub) {
            $children[] = $this->clause($sub);
        }

        return $children === [] ? new MatchAllNode() : new OrNode($children);
    }

    /**
     * `percolate`: run the *stored* queries of a field against a document.
     *
     * The field is the whole diagnostic content — it says which set of saved
     * queries is being replayed. The document is a value: percolating two
     * different documents through the same field is the same query twice.
     *
     * @param array<mixed> $body
     */
    private function percolate(array $body): Node
    {
        $field = Arr::get($body, 'field');
        if (!is_string($field)) {
            return new OpaqueNode('percolate');
        }

        // The document can live in another index instead of being inlined —
        // the same blind spot as a terms lookup, and worth the same warning.
        if (array_key_exists('id', $body)) {
            $this->note('percolate_lookup');
            $this->trace->record(Rule::PERCOLATE_LOOKUP, $field);

            return new LeafNode($field, LeafNode::OP_PERCOLATE, ['indexed']);
        }

        return new LeafNode($field, LeafNode::OP_PERCOLATE);
    }

    /**
     * `rank_feature` and `distance_feature`.
     *
     * They read as boosting and are written where a boost would go, but they
     * are not pure rescoring: a document that has no value for the field does
     * not match at all. So they stay in the tree rather than unwrapping like
     * `function_score`. The scoring function — saturation, log, sigmoid, and
     * `origin` for a distance — is dropped: it reorders, it does not exclude.
     *
     * @param array<mixed>      $body
     * @param array<int,string> $params
     */
    private function feature(array $body, string $op, array $params): Node
    {
        $field = Arr::get($body, 'field');
        if (!is_string($field)) {
            return new OpaqueNode($op);
        }

        $values = [];
        foreach ($params as $key) {
            if (array_key_exists($key, $body)) {
                $values[$key] = $body[$key];
            }
        }

        return new LeafNode($field, $op, $values);
    }

    /**
     * `intervals`: ordered, gap-constrained matching over one field.
     *
     * Only the field is kept. The rule tree underneath — `all_of`, `any_of`,
     * `max_gaps`, `ordered` — is a small language of its own, and modelling it
     * would be a parser inside the parser for the rarest query type that has a
     * field at all. The same call the geo queries make: the field and the kind
     * of clause are what a log line needs, the rest is the payload.
     *
     * @param array<mixed> $body
     */
    private function intervals(array $body): Node
    {
        foreach (array_keys($body) as $field) {
            $field = (string) $field;
            if ($field === 'boost') {
                $this->trace->record(Rule::BOOST_DROPPED);
                continue;
            }
            if ($field === '_name') {
                continue;
            }

            return new LeafNode($field, LeafNode::OP_INTERVALS);
        }

        return new OpaqueNode('intervals');
    }

    /**
     * `wrapper`: a whole query, base64-encoded, so a client can pass one
     * through without the surrounding builder parsing it.
     *
     * Decoding it is two calls and turns the one clause the library was
     * completely blind to into the real tree. Nothing is lost, so this is an
     * unwrapping rather than a note. A wrapper inside a wrapper recurses, and
     * cannot run away: each level base64-encodes the one below it, so depth
     * costs 4/3 the bytes in the request that carried it.
     *
     * @param array<mixed> $body
     */
    private function wrapper(array $body): Node
    {
        $encoded = Arr::get($body, 'query');
        if (!is_string($encoded)) {
            return new OpaqueNode('wrapper');
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            return new OpaqueNode('wrapper');
        }

        $inner = json_decode($decoded, true);
        if (!is_array($inner)) {
            return new OpaqueNode('wrapper');
        }

        $this->trace->record(Rule::WRAPPER_DECODED);

        return $this->clause($inner);
    }

    private function note(string $note): void
    {
        if (!in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }
}
