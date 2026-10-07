<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Support;

/**
 * The character cap on the readable line: the last limit to fire, and the only
 * one that knows nothing about what it is cutting. The limits above it are
 * priced in whole clauses, so a schema with long field names reaches this one
 * first — it is a guard that runs, not one kept for the pathological line.
 *
 * It ends the line on a separator rather than wherever the budget ran out: a
 * line cut mid-field reads as a different field, and the one promise `text`
 * makes is that you can paste it into a search bar.
 *
 * @internal
 */
final class Truncator
{
    private const ELLIPSIS = '…';

    /**
     * What the renderer puts between two whole things. All ASCII, which is what
     * lets the back-off below work on bytes over a UTF-8 string: no byte of a
     * multibyte character can be mistaken for one of these.
     */
    private const SEPARATORS = [', ', ' and ', ' or ', ' | '];

    public static function apply(string $value, ?int $maxLength): string
    {
        if ($maxLength === null || $maxLength <= 0) {
            return $value;
        }

        if (self::length($value) <= $maxLength) {
            return $value;
        }

        // The ellipsis is spent out of the budget, not added to it: a cap of one
        // character buys the ellipsis and nothing else.
        return self::boundary(self::cut($value, $maxLength - 1)) . self::ELLIPSIS;
    }

    /**
     * The window back to its last separator, the separator kept — so the line
     * ends `a and b and …` the way a capped clause list already does, instead
     * of `a and b and taxon_text_postal_co…`.
     *
     * A window holding no separator at all is a single clause longer than the
     * whole budget: it is cut where it was, because the cap has to hold.
     */
    private static function boundary(string $cut): string
    {
        $end = 0;
        foreach (self::SEPARATORS as $separator) {
            $at = strrpos($cut, $separator);
            if ($at !== false) {
                $end = max($end, $at + strlen($separator));
            }
        }

        return $end === 0 ? $cut : substr($cut, 0, $end);
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function cut(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}
