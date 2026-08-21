<?php

declare(strict_types=1);

/**
 * Fills a cluster with the afternoon the Use cases pages describe, under the
 * index pattern the dashboard pack ships.
 *
 *     php tools/demo-index.php http://localhost:9202
 *
 * For looking at the pack with your own eyes, and for the screenshot in the
 * documentation. The data is `tools/scenario.php` — the same four shapes over
 * the same six hours that `UseCaseTest` measures the pages against — and the
 * mapping is the template in `resources/dashboards/`, so what you see is what a
 * reader installing both would get.
 */
$vendor = getenv('COMPOSER_VENDOR_DIR');
require is_string($vendor) && $vendor !== ''
    ? rtrim($vendor, '/') . '/autoload.php'
    : __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/scenario.php';

const DEMO_INDEX = 'app-logs-2026.08.19';
const DEMO_TEMPLATE = __DIR__ . '/../resources/dashboards/index-template.json';

$arguments = [];
foreach ((array) ($_SERVER['argv'] ?? []) as $argument) {
    if (is_string($argument)) {
        $arguments[] = $argument;
    }
}

$url = rtrim($arguments[1] ?? 'http://localhost:9202', '/');
$index = $arguments[2] ?? DEMO_INDEX;

$template = (string) file_get_contents(DEMO_TEMPLATE);
send($url, 'PUT', '/_index_template/os-query-digest', $template);
send($url, 'DELETE', '/' . $index, null);

$scenario = osQueryDigestScenario($index);
$bulk = implode("\n", $scenario['lines']) . "\n";

$response = send($url, 'POST', '/_bulk?refresh=wait_for', $bulk, 'application/x-ndjson');
if (strpos($response, '"errors":true') !== false) {
    fwrite(STDERR, "Bulk indexing reported errors.\n");
    exit(1);
}

printf("  %-24s %s\n", $index, number_format(count($scenario['lines']) / 2) . ' records');
foreach ($scenario['hashes'] as $role => $hash) {
    printf("  %-24s %s\n", $role, $hash);
}
echo "\nSelect 2026-08-19 10:00 → 16:00 UTC in the time picker.\n";

function send(string $url, string $method, string $path, ?string $body, string $type = 'application/json'): string
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => 'Content-Type: ' . $type,
        'content' => $body ?? '',
        'timeout' => 60,
        'ignore_errors' => true,
    ]]);

    $response = @file_get_contents($url . $path, false, $context);
    if ($response === false) {
        fwrite(STDERR, 'No cluster answering at ' . $url . "\n");
        exit(1);
    }

    return $response;
}
