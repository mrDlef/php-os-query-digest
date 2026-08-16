<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Support;

/**
 * Array helpers that have to work on PHP 7.4 (no array_is_list before 8.1).
 *
 * Everything here is typed `array<mixed>` — that is, `array<array-key, mixed>`
 * — and not `array<string, mixed>`. A search body reaches us from
 * `json_decode(..., true)`, and a JSON object whose key is `"0"` decodes to an
 * *integer* key. Claiming string keys would be a lie the analyser cannot catch
 * and a cast the code would skip; every reader of a key here casts it instead.
 *
 * @internal
 */
final class Arr
{
    /**
     * @param array<mixed> $value
     */
    public static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * A DSL clause slot may hold either a single clause object or a list of them.
     * Normalise both shapes to a list.
     *
     * @param mixed $value
     *
     * @return array<int,array<mixed>>
     */
    public static function clauses($value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }

        if (self::isList($value)) {
            $out = [];
            foreach ($value as $clause) {
                if (is_array($clause) && $clause !== []) {
                    $out[] = $clause;
                }
            }

            return $out;
        }

        return [$value];
    }

    /**
     * @param array<mixed> $value
     */
    public static function firstKey(array $value): ?string
    {
        foreach (array_keys($value) as $key) {
            return (string) $key;
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     * @param mixed        $default
     *
     * @return mixed
     */
    public static function get(array $value, string $key, $default = null)
    {
        return array_key_exists($key, $value) ? $value[$key] : $default;
    }

    /**
     * The value at `$key` when it is itself an array, `[]` otherwise.
     *
     * Saves every caller from repeating the same `is_array()` dance around a
     * `mixed` they cannot use until it is narrowed.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    public static function arr(array $value, string $key): array
    {
        $found = self::get($value, $key);

        return is_array($found) ? $found : [];
    }

    /**
     * A scalar as a string. Anything else — an array, an object, null —
     * collapses to the empty string rather than throwing: this runs inside a
     * logging path, where blowing up on a malformed query would be worse than
     * the malformed query.
     *
     * @param mixed $value
     */
    public static function str($value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Every element of a list rendered as a string. Not an array: `[]`.
     *
     * @param mixed $value
     *
     * @return array<int,string>
     */
    public static function strings($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $out[] = self::str($item);
        }

        return $out;
    }
}
