<?php

declare(strict_types=1);

/**
 * Download the PHP-in-WebAssembly runtime the playground needs, and verify it.
 *
 *     make playground-runtime          fetch what is missing, check what is there
 *     php tools/fetch-runtime.php --check    verify only, download nothing
 *     php tools/fetch-runtime.php --update   re-pin the manifest to what npm serves
 *
 * Maintainer tool. It is not shipped in the Composer package.
 *
 * The page used to `import()` this straight from a CDN, which had two costs. Any
 * visitor was disclosed to a third party the page never named, and a dynamic
 * `import()` takes no `integrity` attribute — so nothing checked that what
 * arrived was what was published. The usual workaround, fetching the module and
 * importing a verified blob URL, cannot work here: the runtime resolves its wasm
 * with `new URL(…, import.meta.url)`, which a blob URL breaks.
 *
 * So the files are fetched here instead, at build time, and checked against the
 * hashes committed beside this script. A substituted artefact fails the build
 * rather than reaching a browser. The runtime itself is gitignored — 12.5 MB
 * does not belong in the history of a library whose own package is measured in
 * kilobytes — and the Pages workflow runs this before publishing, the same way
 * it installs MkDocs and for the same reason.
 *
 * Only PHP 8.3 is fetched. `PhpWeb.mjs` names every build it can load but
 * imports them dynamically, and the page pins one version.
 */
const REGISTRY = 'https://cdn.jsdelivr.net/npm/php-wasm@';
const MANIFEST = __DIR__ . '/../playground/runtime.lock.json';
const TARGET = __DIR__ . '/../playground/runtime';

$arguments = $_SERVER['argv'] ?? [];
$checking = is_array($arguments) && in_array('--check', $arguments, true);
$updating = is_array($arguments) && in_array('--update', $arguments, true);

$manifest = manifest();
$version = $manifest['version'];
$files = $manifest['files'];

if ($updating) {
    update($version, array_keys($files));
    exit(0);
}

if (!is_dir(TARGET) && !$checking && !mkdir(TARGET, 0o755, true) && !is_dir(TARGET)) {
    fwrite(STDERR, 'Could not create ' . TARGET . "\n");
    exit(1);
}

$missing = [];
$wrong = [];
$fetched = 0;

foreach ($files as $name => $expected) {
    $path = TARGET . '/' . $name;

    if (!is_file($path)) {
        if ($checking) {
            $missing[] = $name;
            continue;
        }

        $body = download($version, $name);
        if (file_put_contents($path, $body) === false) {
            fwrite(STDERR, 'Could not write ' . $name . "\n");
            exit(1);
        }

        ++$fetched;
    }

    $actual = hash_file('sha256', $path);

    if ($actual !== $expected) {
        $wrong[] = $name . "\n      expected " . $expected . "\n      got      " . $actual;
    }
}

if ($wrong !== []) {
    fwrite(STDERR, "The runtime does not match playground/runtime.lock.json:\n");
    foreach ($wrong as $line) {
        fwrite(STDERR, '  - ' . $line . "\n");
    }
    fwrite(STDERR, "\nDelete playground/runtime and refetch. If npm genuinely republished\n");
    fwrite(STDERR, "this version, re-pin with --update and review the diff.\n");
    exit(1);
}

if ($missing !== []) {
    fwrite(STDERR, "The runtime is not present:\n");
    foreach ($missing as $name) {
        fwrite(STDERR, '  - ' . $name . "\n");
    }
    fwrite(STDERR, "Run: make playground-runtime\n");
    exit(1);
}

echo $fetched === 0
    ? "The runtime is present and matches its hashes.\n"
    : '  fetched ' . $fetched . ' file(s), all matching their hashes' . "\n";

exit(0);

/**
 * @return array{version:string,files:array<string,string>}
 */
function manifest(): array
{
    $raw = is_file(MANIFEST) ? file_get_contents(MANIFEST) : false;

    if ($raw === false) {
        fwrite(STDERR, "Missing playground/runtime.lock.json. Re-pin it with --update.\n");
        exit(1);
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded) || !isset($decoded['version'], $decoded['files'])
        || !is_string($decoded['version']) || !is_array($decoded['files'])) {
        fwrite(STDERR, "playground/runtime.lock.json is not a {version, files} manifest.\n");
        exit(1);
    }

    /** @var array<string,string> $files */
    $files = $decoded['files'];

    return ['version' => $decoded['version'], 'files' => $files];
}

/**
 * Re-pin every hash to what the registry serves now. Prints the manifest rather
 * than the reasoning: the diff is the review.
 *
 * @param array<int,string> $names
 */
function update(string $version, array $names): void
{
    $files = [];

    foreach ($names as $name) {
        $files[$name] = hash('sha256', download($version, $name));
        echo '  ', str_pad($name, 46), $files[$name], "\n";
    }

    $json = json_encode(
        ['version' => $version, 'files' => $files],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    );

    if ($json === false || file_put_contents(MANIFEST, $json . "\n") === false) {
        fwrite(STDERR, "Could not write the manifest.\n");
        exit(1);
    }

    echo "\nplayground/runtime.lock.json rewritten. Review the diff before committing.\n";
}

function download(string $version, string $name): string
{
    $url = REGISTRY . $version . '/' . $name;

    $handle = curl_init($url);

    if ($handle === false) {
        fwrite(STDERR, 'Could not open a connection for ' . $name . "\n");
        exit(1);
    }

    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($handle, CURLOPT_TIMEOUT, 300);

    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);

    if (!is_string($body) || $status !== 200) {
        fwrite(STDERR, 'Could not fetch ' . $url . ' (HTTP ' . $status . ")\n");
        exit(1);
    }

    return $body;
}
