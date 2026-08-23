<?php

declare(strict_types=1);

/**
 * Build the CLI as one executable file:
 *
 *   build/os-query-digest.phar
 *
 *     make phar
 *
 * Maintainer tool. It is not shipped in the Composer package.
 *
 * **A phar needs `phar.readonly` off**, which is on by default, so this refuses
 * to guess: run it as `php -d phar.readonly=0 tools/build-phar.php`, which is
 * what the Makefile does.
 *
 * No Box, no bundler, no `vendor/` inside: the library requires nothing but
 * `php` and `ext-json`, so there is nothing to resolve and the stub below is a
 * PSR-4 autoloader in nine lines. A build tool with its own dependency tree
 * would be the largest thing in this repository.
 *
 * The point of the file is the audience it reaches. `slowlog` answers "which
 * query shape is costing us" from a file every cluster already writes — and the
 * people holding that file are as often SREs with no PHP toolchain as they are
 * PHP developers. One file they can `curl`, or the image built from it, is the
 * difference between reading their slow log and installing a language first.
 */
const ROOT = __DIR__ . '/..';

const ALIAS = 'os-query-digest.phar';

/** The entry point, which stays the one `composer require` installs. */
const BIN = 'bin/os-query-digest';

$destination = $argv[1] ?? ROOT . '/build/' . ALIAS;

if (!\Phar::canWrite()) {
    fwrite(STDERR, "phar.readonly is on, so nothing can be built.\n"
        . "Run: php -d phar.readonly=0 tools/build-phar.php\n");
    exit(1);
}

// Phar refuses a name it does not recognise, with an uncaught
// UnexpectedValueException from the constructor. Say so instead: the executable
// on a PATH is usually named without the extension, and copying it there after
// the build is the way to get that.
if (substr($destination, -5) !== '.phar') {
    fwrite(STDERR, $destination . " must end in .phar — Phar will not create anything else.\n");
    exit(1);
}

$directory = dirname($destination);
if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
    fwrite(STDERR, 'cannot create ' . $directory . "\n");
    exit(1);
}

// Rebuilt from scratch every time: Phar::addFile on an existing archive updates
// it, so a file deleted from src/ would live on in the artefact.
if (is_file($destination)) {
    unlink($destination);
}

$phar = new \Phar($destination, 0, ALIAS);
$phar->startBuffering();

foreach (sources() as $relative) {
    $phar->addFromString($relative, read($relative));
}

$phar->setStub(stub());
$phar->stopBuffering();

chmod($destination, 0755);

$size = filesize($destination);

printf(
    "%s\n  %d files%s\n",
    $destination,
    count($phar),
    $size === false ? '' : ', ' . number_format($size / 1024, 1) . ' KiB',
);

/**
 * Every file the phar carries: the library, and the shim that runs it.
 *
 * Found rather than listed, so a new directory under `src/` cannot be forgotten
 * — that is the one mistake a hand-written manifest guarantees, and it surfaces
 * as a class-not-found on someone else's machine. Sorted, so two builds of the
 * same tree produce the same archive.
 *
 * @return array<int,string>
 */
function sources(): array
{
    $files = [BIN];

    $tree = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(ROOT . '/src', \FilesystemIterator::SKIP_DOTS),
    );

    foreach ($tree as $file) {
        if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $files[] = 'src' . str_replace('\\', '/', substr($path, strlen(ROOT . '/src')));
    }

    sort($files);

    return $files;
}

/**
 * A source file, with the shim's shebang taken off.
 *
 * `bin/os-query-digest` opens with `#!/usr/bin/env php` so it can be executed
 * directly. Inside the phar it is *required*, and a require echoes everything
 * before the first `<?php` — which would put that line on stdout, in front of
 * whatever the tool was asked to print.
 */
function read(string $relative): string
{
    $code = file_get_contents(ROOT . '/' . $relative);
    if ($code === false) {
        fwrite(STDERR, 'cannot read ' . $relative . "\n");
        exit(1);
    }

    if ($relative !== BIN) {
        return $code;
    }

    return (string) preg_replace('/\A#![^\n]*\n/', '', $code, 1);
}

/**
 * The stub: an autoloader, then the same entry point the Composer binary runs.
 *
 * Registered *before* the shim, on purpose. The shim looks for a Composer
 * autoloader — reasonably, it is the installed binary — and in a directory that
 * has one, or with `COMPOSER_VENDOR_DIR` set, it would find that project's
 * classes. Registering first means this phar's own copy of the library answers,
 * whatever else is lying around.
 */
function stub(): string
{
    $alias = ALIAS;
    $bin = BIN;
    $build = version();

    return <<<STUB
        #!/usr/bin/env php
        <?php

        declare(strict_types=1);

        \Phar::mapPhar('{$alias}');

        define('OS_QUERY_DIGEST_BUILD', '{$build}');

        spl_autoload_register(static function (\$class) {
            \$prefix = 'MrDlef\\\\OsQueryDigest\\\\';

            if (strpos(\$class, \$prefix) !== 0) {
                return;
            }

            \$path = 'phar://{$alias}/src/'
                . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';

            if (is_file(\$path)) {
                require \$path;
            }
        });

        require 'phar://{$alias}/{$bin}';

        __HALT_COMPILER();
        STUB;
}

/**
 * Which build this is, for `--version`.
 *
 * A phar carries no `composer.json` and is copied around without its URL, so
 * the file itself has to be able to say. `git describe` rather than a constant
 * in `src/`: a version written into the source is one more thing a release has
 * to remember, and it would be wrong in every checkout between two tags.
 *
 * `OS_QUERY_DIGEST_VERSION` overrides it for builds from an export with no git
 * history, which is what a release workflow has.
 */
function version(): string
{
    $given = getenv('OS_QUERY_DIGEST_VERSION');
    if (is_string($given) && $given !== '') {
        return trim($given);
    }

    $described = @shell_exec('git -C ' . escapeshellarg(ROOT) . ' describe --tags --dirty 2>/dev/null');

    return is_string($described) && trim($described) !== '' ? trim($described) : 'dev';
}
