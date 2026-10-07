<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Cli;

use MrDlef\OsQueryDigest\Analysis\Report;
use MrDlef\OsQueryDigest\Analysis\Shape;
use MrDlef\OsQueryDigest\Analysis\Tenant;

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
        // The column is there only when the records named a tenant at all —
        // on the stream this library writes by default they do not, and a
        // column of zeroes would read as "nobody played this".
        $named = false;
        foreach ($shapes as $shape) {
            if ($shape->tenants() > 0) {
                $named = true;
                break;
            }
        }

        $headers = $named
            ? ['count', 'tenants', 'total ms', 'mean', 'p95', 'max']
            : ['count', 'total ms', 'mean', 'p95', 'max'];
        $rows = [];

        foreach ($shapes as $shape) {
            $row = [self::thousands((float) $shape->count())];
            if ($named) {
                $row[] = self::thousands((float) $shape->tenants());
            }

            $rows[] = array_merge($row, [
                self::duration($shape->measured() === 0 ? null : $shape->total()),
                self::duration($shape->mean()),
                self::duration($shape->p95()),
                self::duration($shape->max()),
            ]);
        }

        // The column the ranking used is starred, so a table pasted into a
        // ticket still says what it was ordered by.
        $ranked = $sort === 'total' ? 'total ms' : $sort;
        foreach ($headers as $column => $header) {
            if ($header === $ranked) {
                $headers[$column] = $header . '*';
            }
        }

        $widths = self::widths($headers, $rows);

        $out = '  ' . self::row($headers, $widths) . "  shape\n";
        $indent = 2 + array_sum($widths) + 2 * count($widths);

        foreach ($shapes as $position => $shape) {
            $out .= '  ' . self::row($rows[$position], $widths) . '  ' . $shape->hash() . "\n"
                . str_repeat(' ', $indent) . $shape->signature() . "\n";
        }

        return $out;
    }

    public static function footer(int $total, int $kept, string $noun = 'shape'): string
    {
        if ($kept >= $total) {
            return '';
        }

        $hidden = $total - $kept;

        return sprintf(
            "\n%s more %s (--top none for all)\n",
            self::thousands((float) $hidden),
            $hidden === 1 ? $noun : $noun . 's',
        );
    }

    /**
     * The same stream along its other axis: who played it, and what that cost.
     *
     * Printed under the shapes rather than instead of them — the two answer
     * different questions, and the second one only exists because the records
     * carried a name for it.
     *
     * @param array<int,Tenant> $tenants
     */
    public static function tenantTable(array $tenants): string
    {
        $headers = ['calls', 'shapes', 'total ms', 'mean'];
        $rows = [];

        foreach ($tenants as $tenant) {
            $rows[] = [
                self::thousands((float) $tenant->count()),
                self::thousands((float) $tenant->shapes()),
                self::duration($tenant->measured() === 0 ? null : $tenant->total()),
                self::duration($tenant->mean()),
            ];
        }

        $widths = self::widths($headers, $rows);

        $out = "\n  " . self::row($headers, $widths) . "  tenant\n";
        foreach ($tenants as $position => $tenant) {
            $out .= '  ' . self::row($rows[$position], $widths) . '  ' . $tenant->name() . "\n";
        }

        return $out;
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
     * Widest cell per column, the header included — read off the rows rather
     * than looked up in them, because the shape table has one column more when
     * the records named a tenant.
     *
     * @param array<int,string>            $headers
     * @param array<int,array<int,string>> $rows
     *
     * @return array<int,int>
     */
    private static function widths(array $headers, array $rows): array
    {
        $widths = [];
        foreach ($headers as $column => $header) {
            $widths[$column] = strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, strlen($cell));
            }
        }

        return $widths;
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
