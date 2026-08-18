<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Extension;

use MrDlef\OsQueryDigest\Exception\InvalidOptionException;

/**
 * What a {@see ClauseRenderer} hands back: the two or three things a log line
 * needs from a clause the library cannot read on its own.
 *
 * Deliberately not a node of the internal tree. That tree changes whenever a
 * query type is promoted, and an extension point built on it would freeze it —
 * the whole reason the tree is `@internal`. This carries a field, a label and
 * keyed parameters, which is exactly what the vector and geo clauses already
 * reduce to, and the library renders it the same way:
 *
 *     model_id:sltr(window=100)     the line
 *     model_id:sltr(window=?)       the signature
 *
 * Parameters follow the rule every other clause follows: the *knobs* are part
 * of the shape, their values are not. Two searches that turned the same knobs
 * to different settings share a fingerprint.
 *
 * @api
 */
final class RenderedClause
{
    private string $field;

    private string $label;

    /** @var array<string,mixed> */
    private array $params = [];

    private function __construct(string $field, string $label)
    {
        $this->field = $field;
        $this->label = $label;
    }

    /**
     * A clause that runs on one field: `rating:my_plugin()`.
     *
     * @param string $label how the clause reads — conventionally the query
     *                      type, so a reader can find it in their own request
     */
    public static function on(string $field, string $label): self
    {
        return new self($field, $label);
    }

    /**
     * A clause with no field of its own — `script` is the modelled example, a
     * ranking plugin scoring the whole result set is another.
     */
    public static function fieldless(string $label): self
    {
        return new self('', $label);
    }

    /**
     * A named knob: `k`, `window_size`, `model`. The name survives into the
     * signature, the value does not.
     *
     * A numeric name is refused rather than accepted and quietly mangled: PHP
     * casts `"0"` to the integer key that carries the label, so such a
     * parameter would either vanish or displace it. Loudly wrong beats a
     * clause that renders as something nobody wrote.
     *
     * @param scalar|null $value
     *
     * @throws InvalidOptionException on a numeric parameter name
     */
    public function withParam(string $name, $value): self
    {
        if ($name === '' || is_numeric($name)) {
            throw InvalidOptionException::wrongType(
                'parameter name',
                'a non-numeric, non-empty string',
                $name,
            );
        }

        $clone = clone $this;
        $clone->params[$name] = $value;

        return $clone;
    }

    public function field(): string
    {
        return $this->field;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return array<string,mixed>
     */
    public function params(): array
    {
        return $this->params;
    }
}
