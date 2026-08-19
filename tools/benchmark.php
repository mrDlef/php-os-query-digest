<?php

declare(strict_types=1);

/**
 * What does a digest cost on the request path?
 *
 * Maintainer tool, not shipped in the Composer package:
 *
 *     make bench
 *     make bench ITERATIONS=20000
 *
 * The library's pitch is that you can afford to do this on every search, and
 * `lazy()` exists so a filtered-out log record costs nothing at all. Both were
 * arguments rather than measurements — this is the measurement.
 *
 * It runs on the committed fixtures rather than on invented inputs: those are
 * the requests the test suite already treats as representative, they range from
 * a single term to a 200-value terms clause, and using them means the benchmark
 * cannot quietly drift towards whatever makes the numbers look good.
 *
 * Every case is compared against `json_encode()` of the same body — what an
 * application pays today to log the raw request. The question is not whether
 * this is fast in the abstract but what it costs next to that.
 *
 * Deliberately not a CI gate: wall-clock on a shared runner is noise, and a
 * threshold tight enough to catch a regression would fail on a busy runner.
 * CI guards behaviour; this reports.
 */

// Namespaced because tools/ is analysed as one body of code and every script
// wants an entry point called main().

namespace MrDlef\OsQueryDigest\Tools\Benchmark;

// The per-version vendor tree when run in the container, the conventional one
// otherwise — so the same script measures 7.4 and 8.5.
$vendor = getenv('COMPOSER_VENDOR_DIR');
require (is_string($vendor) && $vendor !== '' ? $vendor : __DIR__ . '/../vendor') . '/autoload.php';

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Options;

const DEFAULT_ITERATIONS = 5000;

exit(main());

function main(): int
{
    $requested = getenv('ITERATIONS');
    $iterations = $requested === false || $requested === '' ? DEFAULT_ITERATIONS : (int) $requested;
    if ($iterations < 100) {
        fwrite(STDERR, "ITERATIONS must be at least 100.\n");

        return 2;
    }

    $cases = fixtures();
    if ($cases === []) {
        fwrite(STDERR, "No fixtures found.\n");

        return 1;
    }

    $formatter = Formatter::create();

    printf("php %s, %s iterations per case\n\n", PHP_VERSION, number_format($iterations));
    printf("%-28s %7s %10s %10s %10s\n", 'fixture', 'bytes', 'describe', 'json', 'ratio');
    printf("%s\n", str_repeat('-', 69));

    $totalDigest = 0.0;
    $totalJson = 0.0;

    foreach ($cases as $name => $case) {
        [$request, $index] = $case;

        $digest = measure($iterations, static function () use ($formatter, $request, $index): void {
            $formatter->describe($request, $index);
        });

        $json = measure($iterations, static function () use ($request): void {
            json_encode($request);
        });

        $totalDigest += $digest;
        $totalJson += $json;

        printf(
            "%-28s %7d %9.1fµs %9.1fµs %9.1fx\n",
            $name,
            strlen((string) json_encode($request)),
            $digest,
            $json,
            $json > 0 ? $digest / $json : 0,
        );
    }

    $count = count($cases);
    printf("%s\n", str_repeat('-', 69));
    printf(
        "%-28s %7s %9.1fµs %9.1fµs %9.1fx\n\n",
        'mean',
        '',
        $totalDigest / $count,
        $totalJson / $count,
        $totalJson > 0 ? $totalDigest / $totalJson : 0,
    );

    reportLaziness($iterations);
    reportStructural($iterations);

    return 0;
}

/**
 * The claim `lazy()` makes: a log record your handler drops must cost nothing.
 * Building the wrapper has to be orders of magnitude cheaper than the work it
 * defers, or the laziness is decoration.
 */
function reportLaziness(int $iterations): void
{
    $formatter = Formatter::create();
    $request = ['query' => ['bool' => ['filter' => [
        ['term' => ['service' => 'api']],
        ['range' => ['@timestamp' => ['gte' => 'now-15m']]],
    ]]], 'size' => 50];

    // The return value is kept so nothing can argue the call was elided; the
    // point of lazy() is that building this object parses nothing.
    $sink = null;
    $lazy = measure($iterations, static function () use ($formatter, $request, &$sink): void {
        $sink = $formatter->lazy($request, 'logs-2026.08.18');
    });

    $eager = measure($iterations, static function () use ($formatter, $request): void {
        $formatter->describe($request, 'logs-2026.08.18');
    });

    printf("lazy() versus describe(), on a record nothing reads:\n");
    printf("  lazy()      %8.2fµs\n", $lazy);
    printf("  describe()  %8.2fµs\n", $eager);
    printf(
        "  %.0fx cheaper — a debug record your handler filters out never parses anything.\n\n",
        $lazy > 0 ? $eager / $lazy : 0,
    );
}

/**
 * Cost against clause count, which is the shape that would hide an accidental
 * quadratic: the canonicaliser sorts and de-duplicates siblings, so a bool with
 * ten times the clauses must not cost a hundred times as much.
 */
function reportStructural(int $iterations): void
{
    $formatter = Formatter::create(Options::create()->withMaxClauses(null));

    printf("Cost against clause count — watching for anything worse than linear:\n");

    foreach ([10, 50, 250] as $clauses) {
        $request = ['query' => ['bool' => ['filter' => bools($clauses)]]];
        $per = measure(max(100, (int) ($iterations / $clauses)), static function () use ($formatter, $request): void {
            $formatter->describe($request);
        });

        printf(
            "  %4d clauses %9.1fµs   %5.2fµs per clause\n",
            $clauses,
            $per,
            $per / $clauses,
        );
    }

    printf("\n");
}

/**
 * @return array<int,array<mixed>>
 */
function bools(int $count): array
{
    $clauses = [];
    for ($i = 0; $i < $count; ++$i) {
        $clauses[] = ['term' => ['field_' . $i => 'value_' . $i]];
    }

    return $clauses;
}

/**
 * @return float microseconds per operation
 */
function measure(int $iterations, callable $operation): float
{
    // A warm-up pass so the first run does not pay for autoloading and for
    // whatever the opcode cache has not seen yet.
    for ($i = 0; $i < 100; ++$i) {
        $operation();
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $operation();
    }

    return (hrtime(true) - $start) / $iterations / 1000;
}

/**
 * @return array<string,array{0:array<mixed>,1:?string}>
 */
function fixtures(): array
{
    $cases = [];

    $files = glob(__DIR__ . '/../tests/fixtures/*/input.json');

    foreach ($files === false ? [] : $files as $file) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || !isset($decoded['request']) || !is_array($decoded['request'])) {
            continue;
        }

        $index = $decoded['index'] ?? null;
        $cases[basename(dirname($file))] = [
            $decoded['request'],
            is_string($index) ? $index : null,
        ];
    }

    ksort($cases);

    return $cases;
}
