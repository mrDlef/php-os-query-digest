<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Explain;

use JsonSerializable;
use MrDlef\OsQueryDigest\Digest;

/**
 * A digest plus the list of rules that produced it.
 *
 * This is the answer to the only question that decides whether a fingerprint is
 * trusted: *why do these two queries share a hash?* Diff the two explanations
 * and the rule that merged them is right there.
 */
final class Explanation implements JsonSerializable
{
    /** @var Digest */
    private $digest;

    /** @var array<int,Rule> */
    private $rules;

    /**
     * @param array<int,Rule> $rules
     */
    public function __construct(Digest $digest, array $rules)
    {
        $this->digest = $digest;
        $this->rules = array_values($rules);
    }

    public function digest(): Digest
    {
        return $this->digest;
    }

    /**
     * Every rule that actually fired, ordered by identifier.
     *
     * @return array<int,Rule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function has(string $rule): bool
    {
        foreach ($this->rules as $applied) {
            if ($applied->id() === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    public function ruleIds(): array
    {
        $ids = [];
        foreach ($this->rules as $rule) {
            $ids[] = $rule->id();
        }

        return $ids;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = $this->digest->toArray();

        $rules = [];
        foreach ($this->rules as $rule) {
            $rules[] = $rule->toArray();
        }
        $out['rules'] = $rules;

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

    /**
     * A human-readable report, meant for a terminal rather than a log line.
     */
    public function __toString(): string
    {
        $lines = [
            'text: ' . $this->digest->text(),
            'sig:  ' . $this->digest->signature(),
            'hash: ' . $this->digest->hash(),
        ];

        $notes = $this->digest->notes();
        if ($notes !== []) {
            $lines[] = 'notes: ' . implode(' ', $notes);
        }

        if ($this->rules === []) {
            $lines[] = '';
            $lines[] = 'No normalisation rule fired: the query was already canonical.';

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'rules applied:';

        $width = 0;
        foreach ($this->rules as $rule) {
            $width = max($width, strlen((string) $rule));
        }

        foreach ($this->rules as $rule) {
            $lines[] = '  ' . str_pad((string) $rule, $width) . '  ' . $rule->description();
        }

        return implode("\n", $lines);
    }
}
