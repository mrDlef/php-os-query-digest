<?php

declare(strict_types=1);

/**
 * The afternoon the Use cases pages describe, as documents.
 *
 * Four query shapes over six hours: a workhorse that never changes, a dashboard
 * aggregation that regresses at 14:00, a report that is always slow, and a
 * vector search that does not exist before 15:00. Two events an hour apart, and
 * telling them apart is what those pages are for.
 *
 * It lives here rather than in the test that reads it because two things need
 * it now: `UseCaseTest`, which measures the pages against it, and
 * `tools/demo-index.php`, which fills a cluster so the dashboard pack has
 * something to draw. One afternoon, one definition — the screenshot in the
 * documentation is then the same data the pages quote.
 *
 * Every digest comes from the library, so a hash here is one a reader gets.
 */

use MrDlef\OsQueryDigest\Formatter;

/** Fixed: a documentation example that moves cannot be reproduced. */
const SCENARIO_BASE = '2026-08-19T10:00:00Z';

const SCENARIO_HOURS = 6;

/** The hour the slow one starts being slow, and the hour the release ships. */
const SCENARIO_SLOWDOWN_HOUR = 4;

const SCENARIO_RELEASE_HOUR = 5;

/**
 * role => [fixture, per-hour rate, ms before the slowdown, ms after, first hour]
 */
const SCENARIO_SHAPES = [
    'workhorse' => ['01-error-rate-filter', 1200, 8, 8, 0],
    'regressed' => ['02-dashboard-aggs', 60, 42, 910, 0],
    'deployed' => ['12-vector-search', 90, 25, 25, SCENARIO_RELEASE_HOUR],
    'alwaysSlow' => ['04-terms-overflow', 5, 1400, 1400, 0],
];

/**
 * The scenario as bulk lines for one index, and the hash of each role.
 *
 * @return array{lines:array<int,string>,hashes:array<string,string>}
 */
function osQueryDigestScenario(string $index): array
{
    $formatter = Formatter::create();
    $base = strtotime(SCENARIO_BASE);
    $lines = [];
    $hashes = [];

    foreach (SCENARIO_SHAPES as $role => [$fixture, $rate, $before, $after, $from]) {
        $digest = osQueryDigestScenarioDigest($formatter, $fixture);
        $hashes[$role] = $digest['hash'];

        for ($hour = $from; $hour < SCENARIO_HOURS; $hour++) {
            $took = $hour >= SCENARIO_SLOWDOWN_HOUR ? $after : $before;

            for ($i = 0; $i < $rate; $i++) {
                // Deterministic spread, so a percentile is not one value.
                $jitter = 1 + (($i % 7) - 3) * 0.06;

                $lines[] = (string) json_encode(['index' => ['_index' => $index]]);
                $lines[] = (string) json_encode([
                    '@timestamp' => gmdate('c', $base + $hour * 3600 + intdiv($i * 3600, $rate)),
                    'release' => $hour >= SCENARIO_RELEASE_HOUR ? 'v2.31.0' : 'v2.30.1',
                    'took' => (int) round($took * $jitter),
                    'os' => $digest,
                ]);
            }
        }
    }

    return ['lines' => $lines, 'hashes' => $hashes];
}

/**
 * One fixture, digested into the four fields a log record carries.
 *
 * @return array{idx:string,q:string,sig:string,hash:string}
 */
function osQueryDigestScenarioDigest(Formatter $formatter, string $fixture): array
{
    $path = __DIR__ . '/../tests/fixtures/' . $fixture . '/input.json';
    $input = json_decode((string) file_get_contents($path), true);

    $request = is_array($input) ? ($input['request'] ?? null) : null;
    $index = is_array($input) ? ($input['index'] ?? null) : null;

    if (!is_array($request) || !is_string($index)) {
        throw new RuntimeException($fixture . ' is not an {index, request} envelope.');
    }

    $digest = $formatter->describe($request, $index);

    return [
        'idx' => $digest->index(),
        'q' => $digest->text(),
        'sig' => $digest->signature(),
        'hash' => $digest->hash(),
    ];
}
