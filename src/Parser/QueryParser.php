<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Parser;

use MrDlef\OsQueryDigest\Support\Arr;
use MrDlef\OsQueryDigest\Tree\AndNode;
use MrDlef\OsQueryDigest\Tree\LeafNode;
use MrDlef\OsQueryDigest\Tree\MatchAllNode;
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
    /** @var array<int,string> */
    private $notes = [];

    /**
     * @param array<string,mixed> $query
     */
    public function parse(array $query): Node
    {
        $this->notes = [];

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

            case 'constant_score':
                $filter = Arr::get($body, 'filter');

                return is_array($filter) ? $this->clause($filter) : new OpaqueNode('constant_score');

            case 'function_score':
                // Only the inner query filters; the functions merely rescore.
                $inner = Arr::get($body, 'query');
                $this->note('function_score');

                return is_array($inner) ? $this->clause($inner) : new MatchAllNode();

            case 'boosting':
                // `negative` only demotes, it does not exclude.
                $positive = Arr::get($body, 'positive');
                $this->note('boosting');

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
        foreach (['must', 'filter'] as $slot) {
            foreach (Arr::clauses(Arr::get($body, $slot, [])) as $sub) {
                $and[] = $this->clause($sub);
            }
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
            }
        }

        if ($and === []) {
            return new MatchAllNode();
        }

        return count($and) === 1 ? $and[0] : new AndNode($and);
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
                continue;
            }

            if (is_array($value)) {
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
            if ($field === 'boost' || $field === 'minimum_should_match_field') {
                continue;
            }

            if (is_array($value) && Arr::isList($value)) {
                return new LeafNode((string) $field, LeafNode::OP_TERMS, array_values($value));
            }

            if (is_array($value)) {
                // terms lookup: {"terms": {"user": {"index": …, "id": …}}}
                $this->note('terms_lookup');

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
            if ($field === 'boost' || !is_array($bounds)) {
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

    private function note(string $note): void
    {
        if (!in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }
}
