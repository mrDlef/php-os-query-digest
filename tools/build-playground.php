<?php

declare(strict_types=1);

/**
 * Build the two files the browser playground needs, and nothing else:
 *
 *   playground/data/library.php.txt  the whole library as one file
 *   playground/data/presets.json     the fixtures, already digested
 *
 *     make playground-data
 *
 * Maintainer tool. It is not shipped in the Composer package.
 *
 * Why one bundled file rather than src/ plus an autoloader: the playground hands
 * PHP source to a wasm runtime whose filesystem API is not ours to depend on.
 * One string prepended to the user's snippet works on any runtime, costs one
 * request instead of forty, and can be executed by real PHP in the test suite —
 * so the guard needs neither a browser nor wasm in CI. See PlaygroundTest.
 *
 * Presets are precomputed because the page must be useful before 12 MB of wasm
 * arrives. Every fixture is rendered here by the library itself, so they cannot
 * drift, and a visitor who only clicks never pays for the runtime.
 */
// COMPOSER_VENDOR_DIR first, for the same reason bin/os-query-digest consults
// it: the Docker matrix gives each PHP version its own vendor directory, and
// requiring the default one there loads a vendor built for another version.
$vendor = getenv('COMPOSER_VENDOR_DIR');
require is_string($vendor) && $vendor !== ''
    ? rtrim($vendor, '/') . '/autoload.php'
    : __DIR__ . '/../vendor/autoload.php';

use MrDlef\OsQueryDigest\Explain\Rule;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\IndexNormalizer;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;

const SRC = __DIR__ . '/../src';
const FIXTURES = __DIR__ . '/../tests/fixtures';
const OUTPUT = __DIR__ . '/../playground/data';

/**
 * Interfaces first: PHP resolves `implements` at compile time, so a bundle
 * that declares LeafNode before Node does not parse. Everything else is
 * alphabetical, which keeps the diff of a regenerated bundle readable.
 *
 * @var array<int,string>
 */
const FIRST = [
    'Tree/Node.php',
    'Render/ValueRenderer.php',
];

/**
 * Left out of the bundle: neither is reachable from a browser — one wants stream
 * resources, the other a logger that is not there — and the page pays for every
 * kilobyte.
 *
 * @var array<int,string>
 */
const SKIP = ['Cli/', 'Monolog/'];

/**
 * `--check` writes nothing and fails if the committed files are not what this
 * tool would produce now. That is the whole guard: the test suite runs it, so a
 * change to src/ that nobody regenerated the playground for turns CI red
 * instead of shipping a page that quietly runs last month's library.
 */
$arguments = $_SERVER['argv'] ?? [];
$checking = is_array($arguments) && in_array('--check', $arguments, true);

// Deliberately not `.php`: this is a text asset the page fetches, and a dev
// server pointed at the directory would *execute* it instead of serving it.
// `php -S` does, and the page then receives the empty output of a file of class
// declarations.
$artifacts = [
    'library.php.txt' => bundle(sources()),
    'presets.json' => encode(presets()),
];

$stale = [];

foreach ($artifacts as $name => $contents) {
    $file = OUTPUT . '/' . $name;

    if ($checking) {
        $committed = is_file($file) ? file_get_contents($file) : '';
        if ($committed !== $contents) {
            $stale[] = $name . (is_file($file) ? ' differs' : ' is missing');
        }
        continue;
    }

    if (file_put_contents($file, $contents) === false) {
        fwrite(STDERR, 'Could not write ' . $name . "\n");
        exit(1);
    }

    echo '  ', str_pad($name, 18), number_format(strlen($contents) / 1024, 0), " KB\n";
}

if (!$checking) {
    exit(0);
}

if ($stale !== []) {
    fwrite(STDERR, "The playground data is out of date:\n");
    foreach ($stale as $line) {
        fwrite(STDERR, '  - ' . $line . "\n");
    }
    fwrite(STDERR, "Run: make playground-data\n");
    exit(1);
}

echo "The playground data is up to date.\n";
exit(0);

/**
 * @param array<string,mixed> $presets
 */
function encode(array $presets): string
{
    $json = json_encode($presets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "Could not encode presets.json\n");
        exit(1);
    }

    return $json . "\n";
}

/**
 * Every source file, interfaces first, then alphabetical.
 *
 * @return array<int,string> paths relative to src/
 */
function sources(): array
{
    $found = [];
    $directory = new RecursiveDirectoryIterator(SRC, FilesystemIterator::SKIP_DOTS);

    /** @var \SplFileInfo $file */
    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace(SRC . '/', '', $file->getPathname());
        foreach (SKIP as $prefix) {
            if (strpos($path, $prefix) === 0) {
                continue 2;
            }
        }

        $found[] = $path;
    }

    sort($found);

    $ordered = FIRST;
    foreach ($found as $path) {
        if (!in_array($path, FIRST, true)) {
            $ordered[] = $path;
        }
    }

    foreach (FIRST as $required) {
        if (!in_array($required, $found, true)) {
            fwrite(STDERR, 'FIRST names ' . $required . ", which no longer exists.\n");
            exit(1);
        }
    }

    return $ordered;
}

/**
 * @param array<int,string> $paths
 */
function bundle(array $paths): string
{
    // declare() shares the opening tag's line on purpose. Real PHP allows
    // comments before it, but a wasm runtime that wraps the script in a
    // prologue does not — php-wasm answers "strict_types declaration must be
    // the very first statement". Leaving it out instead would run the library
    // in weak mode, which is not the library.
    $out = "<?php declare(strict_types=1);\n\n"
        . "// Generated by tools/build-playground.php — do not edit.\n"
        . "// The library as one file, for a runtime with no autoloader.\n";

    foreach ($paths as $path) {
        $out .= "\n" . block($path);
    }

    return $out;
}

/**
 * One source file as a braced namespace block.
 *
 * The shape is asserted rather than parsed: every file in src/ opens with
 * `<?php`, `declare(strict_types=1);` and a single `namespace X;`. A file that
 * does not stops the build, because silently emitting it unchanged would
 * produce a bundle that parses and misbehaves.
 */
function block(string $path): string
{
    $file = SRC . '/' . $path;
    $code = file_get_contents($file);
    if ($code === false) {
        fwrite(STDERR, 'Could not read ' . $file . "\n");
        exit(1);
    }

    $matched = preg_match(
        '/\A<\?php\s+declare\(strict_types=1\);\s+namespace ([A-Za-z0-9\\\\]+);\s*(.*)\z/s',
        $code,
        $parts,
    );

    if ($matched !== 1) {
        fwrite(STDERR, $path . " does not open with <?php + declare + namespace.\n");
        exit(1);
    }

    $body = trim($parts[2]);
    $indented = '';
    foreach (explode("\n", $body) as $line) {
        $indented .= ($line === '' ? '' : '    ' . $line) . "\n";
    }

    return '// src/' . $path . "\n"
        . 'namespace ' . $parts[1] . " {\n"
        . $indented
        . "}\n";
}

/**
 * @return array{meta:array<string,mixed>,presets:array<int,array<string,mixed>>}
 */
function presets(): array
{
    $directories = glob(FIXTURES . '/*', GLOB_ONLYDIR);
    $presets = [];

    foreach ($directories === false ? [] : $directories as $directory) {
        $input = readJson($directory . '/input.json');

        $spec = isset($input['options']) && is_array($input['options']) ? $input['options'] : [];
        $request = isset($input['request']) && is_array($input['request']) ? $input['request'] : [];
        $index = isset($input['index']) && is_string($input['index']) ? $input['index'] : null;

        $explanation = Formatter::create(Options::fromArray($spec))->explain($request, $index);

        $body = json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            fwrite(STDERR, 'Could not re-encode the body of ' . basename($directory) . "\n");
            exit(1);
        }

        $presets[] = [
            'id' => basename($directory),
            'label' => label(basename($directory)),
            'index' => $index,
            'options' => $spec === [] ? new stdClass() : $spec,
            'body' => $body,
            'digest' => $explanation->digest()->toArray(),
            'rules' => array_map(static fn(Rule $rule): array => $rule->toArray(), $explanation->rules()),
        ];
    }

    return ['meta' => meta(), 'presets' => $presets];
}

/**
 * What the page needs in order not to hard-code the library's vocabulary.
 *
 * @return array<string,mixed>
 */
function meta(): array
{
    $defaults = Options::create();

    $rules = [];
    foreach ((new ReflectionClass(Rule::class))->getConstants() as $name => $value) {
        if (is_string($value) && $name !== 'DESCRIPTIONS') {
            $rules[$value] = (new Rule($value, 1))->description();
        }
    }
    ksort($rules);

    return [
        'keys' => Options::KEYS,
        'levels' => Normalization::LEVELS,
        'indexModes' => IndexNormalizer::MODES,
        'defaults' => [
            'normalization' => $defaults->normalization()->level(),
            'maxClauses' => $defaults->maxClauses(),
            'maxValues' => $defaults->maxValues(),
            'maxLength' => $defaults->maxLength(),
            'indexNormalizer' => IndexNormalizer::DATE_PATTERNS,
            'aggNames' => $defaults->includeAggNames(),
            'hashVersion' => $defaults->hashVersion(),
            'hashLength' => $defaults->hashLength(),
        ],
        'rules' => $rules,
    ];
}

/** `01-error-rate-filter` → `Error rate filter`. */
function label(string $id): string
{
    $name = (string) preg_replace('/^\d+-/', '', $id);

    return ucfirst(str_replace('-', ' ', $name));
}

/**
 * @return array<mixed>
 */
function readJson(string $file): array
{
    $contents = file_get_contents($file);
    if ($contents === false) {
        fwrite(STDERR, 'Could not read ' . $file . "\n");
        exit(1);
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, 'Invalid JSON in ' . $file . "\n");
        exit(1);
    }

    return $decoded;
}
