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

    /** @var array<int,string> */
    private $notes = [];

    /** @var Trace */
    private $trace;

    public function __construct()
    {
        $this->trace = new Trace();
    }

    /**
     * @param array<string,mixed> $query
     */
    public function parse(array $query, ?Trace $trace = null): Node
    {
        $this->notes = [];
        $this->trace = $trace !== null ? $trace : new Trace();

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
     * @param array<string,mixed> $clause
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
                return $this->geoBoundingBox($body);

            case 'script':
                return $this->script($body);

            case 'has_child':
                return $this->join($body, JoinNode::HAS_CHILD, 'type');

            case 'has_parent':
                return $this->join($body, JoinNode::HAS_PARENT, 'parent_type');

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

            case 'boosting':
                // `negative` only demotes, it does not exclude.
                $positive = Arr::get($body, 'positive');
                $this->note('boosting');
                $this->trace->record(Rule::BOOSTING_UNWRAPPED);

                return is_array($positive) ? $this->clause($positive) : new MatchAllNode();

            case 'dis_max':
                $queries = Arr::get($body, 'queries', []);
                $children = [];
                foreach (Arr::clauses($queries) as $sub) {
                    $children[] = $this->clause($sub);
                }

                return $children === [] ? new MatchAllNode() : new OrNode($children);

            default:
                return new OpaqueNode($type);
        }
    }

    /**
     * `{"bool": {...}}` → and / or / not.
     *
     * @param array<string,mixed> $body
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
     * @param array<string,mixed> $body
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
                    : (array_key_exists('query', $value) ? $value['query'] : null);
            }

            return new LeafNode((string) $field, $op, [$value]);
        }

        return new OpaqueNode($op);
    }

    /**
     * @param array<string,mixed> $body
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
     * @param array<string,mixed> $body
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
     * @param array<string,mixed> $body
     */
    private function multiMatch(array $body): Node
    {
        $fields = Arr::get($body, 'fields', []);
        $field = is_array($fields) && $fields !== [] ? implode('|', array_map('strval', $fields)) : '*';

        return new LeafNode($field, LeafNode::OP_MATCH, [Arr::get($body, 'query')]);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function queryString(array $body, string $type): Node
    {
        $query = Arr::get($body, 'query');
        if (!is_string($query)) {
            return new OpaqueNode($type);
        }

        $fields = Arr::get($body, 'fields', []);
        $field = is_array($fields) && $fields !== [] ? implode('|', array_map('strval', $fields)) : '';

        // The payload is already Lucene-ish syntax: keep it verbatim so the
        // rendered line stays pasteable.
        return new LeafNode($field, LeafNode::OP_RAW, [$query]);
    }

    /**
     * @param array<string,mixed> $body
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
     * @param array<string,mixed> $body
     * @param array<int,string>   $params
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
     * @param array<string,mixed> $body
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
     * @param array<string,mixed> $body
     */
    private function geoBoundingBox(array $body): Node
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

            // A box carries no scalar worth logging — and it comes in five
            // encodings (object, array, string, WKT, geohash). The field and
            // the fact that it is a box are the whole shape.
            return new LeafNode($field, LeafNode::OP_GEO_BBOX);
        }

        return new OpaqueNode('geo_bounding_box');
    }

    /**
     * @param array<string,mixed> $body
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
     * @param array<string,mixed> $body
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
     * @param array<string,mixed> $body
     */
    private function moreLikeThis(array $body): Node
    {
        $fields = Arr::get($body, 'fields', []);
        $field = is_array($fields) && $fields !== [] ? implode('|', array_map('strval', $fields)) : '*';

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

    private function note(string $note): void
    {
        if (!in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }
}
