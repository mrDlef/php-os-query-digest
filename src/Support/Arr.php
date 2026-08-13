<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Support;

/**
 * Array helpers that have to work on PHP 7.4 (no array_is_list before 8.1).
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
     * @return array<int,array<string,mixed>>
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

        /** @var array<string,mixed> $value */
        return [$value];
    }

    /**
     * @param array<string,mixed> $value
     */
    public static function firstKey(array $value): ?string
    {
        foreach ($value as $key => $_) {
            return (string) $key;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $value
     * @param mixed               $default
     *
     * @return mixed
     */
    public static function get(array $value, string $key, $default = null)
    {
        return array_key_exists($key, $value) ? $value[$key] : $default;
    }
}
