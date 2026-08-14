<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Explain;

/**
 * Collects the normalisation rules that fire while one request is processed.
 *
 * The parser and the canonicaliser write into it unconditionally: recording a
 * handful of strings costs nothing next to walking the query, and making it
 * optional would mean {@see \MrDlef\OsQueryDigest\Formatter::explain()} could
 * disagree with {@see \MrDlef\OsQueryDigest\Formatter::describe()} about what
 * happened.
 */
final class Trace
{
    /** @var array<string,array{count:int,details:array<int,string>}> */
    private $rules = [];

    public function record(string $rule, string $detail = ''): void
    {
        if (!isset($this->rules[$rule])) {
            $this->rules[$rule] = ['count' => 0, 'details' => []];
        }

        ++$this->rules[$rule]['count'];

        if ($detail !== '' && !in_array($detail, $this->rules[$rule]['details'], true)) {
            $this->rules[$rule]['details'][] = $detail;
        }
    }

    /**
     * Ordered by identifier, never by the order the rules happened to fire in:
     * two runs of the same query must produce the same explanation.
     *
     * @return array<int,Rule>
     */
    public function rules(): array
    {
        $ids = array_keys($this->rules);
        sort($ids);

        $out = [];
        foreach ($ids as $id) {
            $details = $this->rules[$id]['details'];
            sort($details);
            $out[] = new Rule($id, $this->rules[$id]['count'], $details);
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }
}
