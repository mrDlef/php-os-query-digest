<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Support;

/**
 * One field of a flat record, still unresolved.
 *
 * A flat layout has to put something under every key before the handler decides
 * whether the record survives, and a plain value there would have cost a parse.
 * So each key holds one of these, all of them sharing a single
 * {@see RecordFields}: whichever the encoder reaches first resolves the digest,
 * and the rest read what it left.
 *
 * @internal
 */
final class LazyField implements \JsonSerializable
{
    private RecordFields $fields;

    private string $field;

    public function __construct(RecordFields $fields, string $field)
    {
        $this->fields = $fields;
        $this->field = $field;
    }

    /**
     * The value, or null where this record has no such field. Null rather than
     * an omitted key: the keys are placed before anything is known, and a
     * formatter that drops nulls will drop them itself.
     *
     * @return string|int|float|bool|null
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $value = $this->fields->value($this->field);

        // A list of notes is a node of its own, and a flat layout exists
        // precisely for collectors that cannot query one.
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $note) {
                $parts[] = is_scalar($note) ? (string) $note : '';
            }

            return implode('; ', $parts);
        }

        return is_scalar($value) ? $value : null;
    }

    /**
     * Formatters that write context as text rather than JSON — Monolog's
     * `LineFormatter` among them — cast rather than serialise.
     */
    public function __toString(): string
    {
        $value = $this->jsonSerialize();

        return is_scalar($value) ? (string) $value : '';
    }
}
