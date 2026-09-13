<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Cli;

use MrDlef\OsQueryDigest\Analysis\Report;
use MrDlef\OsQueryDigest\Analysis\Shape;

/**
 * A ranking, as a terminal reads it.
 *
 * Two sub-commands print the same table from the same {@see Report} — `slowlog`
 * from what the cluster wrote, `report` from what the application did — and two
 * copies of it would be two chances for one of them to round differently, star
 * the wrong column, or forget that `--top` hid something. So the table lives
 * here and the commands own only the reading of their own input.
 *
 * @internal
 */
final class ReportPrinter
{
    /**
     * The header line, and the counts that put the ranking in proportion.
     *
     * @param array<int,string> $notes what this file made of its own input, each
     *                                 the caller's business rather than this one's
     */
    public static function summary(int $lines, int $records, Report $report, array $notes = []): string
    {
        $summary = sprintf(
            '%s, %s, %s, %s ms total',
            self::plural($lines, 'line'),
            self::plural($records, 'record'),
            self::plural($report->count(), 'shape'),
            self::thousands($report->total()),
        );

        if ($notes !== []) {
            $summary .= ' (' . implode('; ', $notes) . ')';
        }

        return $summary . "\n\n";
    }

    /**
     * @param array<int,Shape> $shapes
     */
    public static function table(array $shapes, string $sort): string
    {
        $headers = ['count', 'total ms', 'mean', 'p95', 'max'];
        $rows = [];

        foreach ($shapes as $shape) {
            $rows[] = [
                self::thousands((float) $shape->count()),
                self::duration($shape->measured() === 0 ? null : $shape->total()),
                self::duration($shape->mean()),
                self::duration($shape->p95()),
                self::duration($shape->max()),
            ];
        }

        // The column the ranking used is starred, so a table pasted into a
        // ticket still says what it was ordered by.
        $ranked = $sort === 'total' ? 'total ms' : $sort;
        foreach ($headers as $column => $header) {
            if ($header === $ranked) {
                $headers[$column] = $header . '*';
            }
        }

        $widths = [];
        foreach ($headers as $column => $header) {
            $width = strlen($header);
            foreach ($rows as $row) {
                $width = max($width, strlen($row[$column]));
            }
            $widths[$column] = $width;
        }

        $out = '  ' . self::row($headers, $widths) . "  shape\n";
        $indent = 2 + array_sum($widths) + 2 * count($widths);

        foreach ($shapes as $position => $shape) {
            $out .= '  ' . self::row($rows[$position], $widths) . '  ' . $shape->hash() . "\n"
                . str_repeat(' ', $indent) . $shape->signature() . "\n";
        }

        return $out;
    }

    public static function footer(int $total, int $kept): string
    {
        if ($kept >= $total) {
            return '';
        }

        $hidden = $total - $kept;

        return sprintf(
            "\n%s more %s (--top none for all)\n",
            self::thousands((float) $hidden),
            $hidden === 1 ? 'shape' : 'shapes',
        );
    }

    /**
     * The ranking as JSON, or null when it holds a byte sequence that is not
     * UTF-8 — which a log file can, and which the caller reports as its own
     * failure rather than emitting half a document.
     *
     * @param array<int,Shape> $shapes
     */
    public static function json(array $shapes): ?string
    {
        $encoded = json_encode($shapes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $encoded === false ? null : $encoded . "\n";
    }

    public static function plural(int $count, string $noun): string
    {
        return self::thousands((float) $count) . ' ' . $noun . ($count === 1 ? '' : 's');
    }

    private static function duration(?float $millis): string
    {
        return $millis === null ? '-' : self::thousands($millis);
    }

    /** Milliseconds, whole: the appenders report them whole. */
    public static function thousands(float $value): string
    {
        return number_format(round($value), 0, '.', ',');
    }

    /**
     * @param array<int,string> $cells
     * @param array<int,int>    $widths
     */
    private static function row(array $cells, array $widths): string
    {
        $padded = [];
        foreach ($cells as $column => $cell) {
            $padded[] = str_pad($cell, $widths[$column], ' ', STR_PAD_LEFT);
        }

        return implode('  ', $padded);
    }
}
