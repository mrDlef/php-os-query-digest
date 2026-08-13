<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Support;

/**
 * Last-resort character cap. Structural limits (max clauses, max values) do the
 * real work; this only guarantees a log line can never blow up.
 *
 * @internal
 */
final class Truncator
{
    private const ELLIPSIS = '…';

    public static function apply(string $value, ?int $maxLength): string
    {
        if ($maxLength === null || $maxLength <= 0) {
            return $value;
        }

        if (self::length($value) <= $maxLength) {
            return $value;
        }

        return self::cut($value, max(1, $maxLength - 1)) . self::ELLIPSIS;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function cut(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? (string) mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}
