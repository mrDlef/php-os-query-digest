<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Explain\Rule;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\IndexNormalizer;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;
use MrDlef\OsQueryDigest\Support\Arr;
use PHPUnit\Framework\TestCase;

/**
 * The browser playground ships two generated files: the library as one file,
 * and every fixture already digested. Both are committed, so both can go stale
 * — and a stale playground is worse than none, because it prints hashes that no
 * released version produces.
 *
 * These tests are the guard, and they need neither a browser nor wasm: the
 * bundle is PHP, so real PHP can run it, and the presets were produced by the
 * very library this suite already exercises.
 */
final class PlaygroundTest extends TestCase
{
    private const DATA = __DIR__ . '/../playground/data';

    private const PAGE = __DIR__ . '/../playground';

    /** The markup, which MkDocs renders at /playground/ for docs/playground.md. */
    private const TEMPLATE = __DIR__ . '/../overrides/playground.html';

    private const MARKDOWN = __DIR__ . '/../docs/playground.md';

    private const LOADER = __DIR__ . '/../docs/javascripts/playground.js';

    private const CONFIG = __DIR__ . '/../mkdocs.yml';

    /**
     * The page's own claim is that nothing it loads comes from anywhere but the
     * site serving it — which is why the wasm runtime is fetched at build time
     * rather than imported from a CDN.
     *
     * Matched on hosts rather than on the shape of a loader call: the URL that
     * used to be here reached `import()` through a constant, so a pattern
     * looking for `import('https://…')` would have passed the very code this
     * exists to forbid. No host at all is allowed now — the two links the
     * standalone page carried are the site's own header and footer since it
     * became a page of it.
     */
    public function testTheseFilesNameNoHostAtAll(): void
    {
        $files = [
            self::TEMPLATE,
            self::LOADER,
            self::PAGE . '/playground.js',
            self::PAGE . '/playground.css',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all('#\bhttps?://([a-z0-9.-]+)#i', $source, $found);

            self::assertSame(
                [],
                array_values(array_unique($found[1])),
                basename($file) . ' names a host. The runtime is fetched by '
                . 'tools/fetch-runtime.php and served from this site; nothing the '
                . 'playground loads may come from another origin.',
            );
        }
    }

    /**
     * The markup is in a template and the code that drives it is in a module, so
     * a renamed id breaks the page in a way no PHP test would otherwise see —
     * and the browser check that would see it is deliberately not in CI.
     */
    public function testEveryElementTheModuleAsksForExistsInTheMarkup(): void
    {
        $module = (string) file_get_contents(self::PAGE . '/playground.js');
        $markup = (string) file_get_contents(self::TEMPLATE);

        preg_match("/const el = \(id\) => document\.getElementById\('([a-z-]+)' \+ id\)/", $module, $matched);
        $prefix = $matched[1] ?? null;
        self::assertIsString(
            $prefix,
            'The module no longer looks elements up through one prefixed helper; this test reads that helper.',
        );

        preg_match_all("/\bel\('([a-zA-Z-]+)'\)/", $module, $matches);
        $asked = $matches[1];
        self::assertNotSame([], $asked, 'The module asks for no element; this test is reading the wrong file.');

        preg_match_all('/\bid="([a-zA-Z-]+)"/', $markup, $matches);
        $ids = $matches[1];

        foreach (array_unique($asked) as $id) {
            self::assertContains(
                $prefix . $id,
                $ids,
                'The module asks for #' . $prefix . $id . ', which the markup does not define.',
            );
        }

        self::assertContains($prefix . 'app', $ids, 'The markup has no root for the module to find.');
    }

    /**
     * The stylesheet and the module have to be the site's rather than the page's,
     * and the reason is not stylistic: instant navigation swaps neither the
     * <head> nor the scripts at the end of the body, so anything this page alone
     * declared is simply absent when a reader arrives by an internal link. The
     * page then renders unstyled, or inert, for every visitor who did not land on
     * it first — and `mkdocs build` is perfectly happy.
     *
     * `make playground-check` catches it in a browser. It is not in CI, so this
     * is the guard that is.
     */
    public function testTheAssetsAreDeclaredForTheSiteAndNotForThePage(): void
    {
        $config = (string) file_get_contents(self::CONFIG);

        self::assertStringContainsString(
            '- playground/playground.css',
            $config,
            'The stylesheet is not in extra_css, so it will not be there on an instant arrival.',
        );
        self::assertMatchesRegularExpression(
            '/-\s+path:\s+javascripts\/playground\.js\s+type:\s+module/',
            $config,
            'The loader is not in extra_javascript as a module, so nothing imports the playground.',
        );

        $template = (string) file_get_contents(self::TEMPLATE);

        self::assertStringNotContainsString(
            '<link',
            $template,
            'The template declares a stylesheet. Instant navigation does not swap the <head>.',
        );
        self::assertStringNotContainsString(
            '<script',
            $template,
            'The template declares a script. Instant navigation does not re-run the page\'s scripts.',
        );
    }

    /**
     * Without the template the page renders its prose and nothing else: no
     * markup, no stylesheet, no module. And a playground/index.html would be
     * copied over the page MkDocs generates at the same path, silently.
     */
    public function testThePageSelectsTheTemplateAndNothingShadowsIt(): void
    {
        $markdown = (string) file_get_contents(self::MARKDOWN);

        self::assertStringContainsString(
            'template: playground.html',
            $markdown,
            'docs/playground.md does not select the template that carries the application.',
        );

        self::assertFileDoesNotExist(
            self::PAGE . '/index.html',
            'playground/index.html is copied into docs/playground/ and would take the path '
            . 'MkDocs generates the page at.',
        );
    }

    /**
     * The runtime is gitignored, so this manifest is the only committed record of
     * what the page will execute. A hash that is not a hash would make
     * fetch-runtime.php's check pass on anything.
     */
    public function testTheRuntimeManifestPinsEveryFileToASha256(): void
    {
        $manifest = json_decode((string) file_get_contents(self::PAGE . '/runtime.lock.json'), true);
        self::assertIsArray($manifest);

        $version = $manifest['version'] ?? null;
        $files = $manifest['files'] ?? null;

        self::assertIsString($version, 'The manifest pins no version.');
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version, 'The version is not exact.');
        self::assertIsArray($files);
        self::assertNotSame([], $files, 'The manifest lists no files.');

        foreach ($files as $name => $hash) {
            self::assertIsString($name);
            self::assertIsString($hash, $name . ' has no hash.');
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash, $name . ' is not a SHA-256.');
        }

        self::assertArrayHasKey('PhpWeb.mjs', $files, 'The entry point is not pinned.');
    }

    public function testTheGeneratedFilesAreWhatTheToolWouldWriteNow(): void
    {
        [$status, $out, $err] = self::php([dirname(__DIR__) . '/tools/build-playground.php', '--check']);

        self::assertSame(0, $status, 'php tools/build-playground.php --check failed:' . "\n" . $out . $err);
    }

    /**
     * The claim the whole playground rests on: what the browser requires is the
     * library, not a copy of it that drifted. Run in a subprocess because this
     * process has already autoloaded every one of those classes.
     */
    public function testTheBundleReproducesEveryFixture(): void
    {
        $script = <<<'PHP'
            <?php
            require $argv[1];
            $out = [];
            foreach (glob($argv[2] . '/*', GLOB_ONLYDIR) as $directory) {
                $input = json_decode(file_get_contents($directory . '/input.json'), true);
                $options = \MrDlef\OsQueryDigest\Options::fromArray($input['options'] ?? []);
                $out[basename($directory)] = \MrDlef\OsQueryDigest\Formatter::create($options)
                    ->describe($input['request'], $input['index'] ?? null)
                    ->toArray();
            }
            echo json_encode($out);
            PHP;

        $file = tempnam(sys_get_temp_dir(), 'bundle-check');
        self::assertIsString($file);
        file_put_contents($file, $script);

        try {
            [$status, $out, $err] = self::php([
                $file,
                self::DATA . '/library.php.txt',
                __DIR__ . '/fixtures',
            ]);
        } finally {
            unlink($file);
        }

        self::assertSame(0, $status, 'The bundle could not run: ' . $err);

        $actual = json_decode($out, true);
        self::assertIsArray($actual);
        self::assertNotSame([], $actual);

        $expected = [];
        foreach ($actual as $name => $ignored) {
            $expected[$name] = self::readJson(__DIR__ . '/fixtures/' . $name . '/expected.json');
        }

        self::assertSame($expected, $actual, 'The bundled library no longer agrees with the golden files.');
    }

    public function testEveryPresetHoldsWhatTheLibraryProducesToday(): void
    {
        $data = self::readJson(self::DATA . '/presets.json');
        $presets = Arr::arr($data, 'presets');

        $fixtures = glob(__DIR__ . '/fixtures/*', GLOB_ONLYDIR);
        self::assertIsArray($fixtures);
        self::assertCount(count($fixtures), $presets, 'Every fixture should be a preset.');

        foreach ($presets as $preset) {
            self::assertIsArray($preset);
            $id = Arr::str(Arr::get($preset, 'id'));

            $options = Options::fromArray(Arr::arr($preset, 'options'));
            $index = Arr::get($preset, 'index');
            $explanation = Formatter::create($options)->explain(
                Arr::str(Arr::get($preset, 'body')),
                $index === null ? null : Arr::str($index),
            );

            self::assertSame(
                $explanation->digest()->toArray(),
                Arr::arr($preset, 'digest'),
                'The committed digest of ' . $id . ' is stale.',
            );

            $rules = [];
            foreach ($explanation->rules() as $rule) {
                $rules[] = $rule->toArray();
            }
            self::assertSame($rules, Arr::arr($preset, 'rules'), 'The committed rules of ' . $id . ' are stale.');
        }
    }

    /**
     * The page builds its normalisation radios, its index switch and its rule
     * descriptions from this block. If it drifts, the playground offers options
     * the library does not have — or hides ones it does.
     */
    public function testTheMetaBlockMirrorsTheLibrary(): void
    {
        $meta = Arr::arr(self::readJson(self::DATA . '/presets.json'), 'meta');
        $defaults = Options::create();

        self::assertSame(Options::KEYS, Arr::strings(Arr::get($meta, 'keys')));
        self::assertSame(Normalization::LEVELS, Arr::strings(Arr::get($meta, 'levels')));
        self::assertSame(IndexNormalizer::MODES, Arr::strings(Arr::get($meta, 'indexModes')));

        self::assertSame([
            'normalization' => $defaults->normalization()->level(),
            'maxClauses' => $defaults->maxClauses(),
            'maxValues' => $defaults->maxValues(),
            'maxLength' => $defaults->maxLength(),
            'indexNormalizer' => IndexNormalizer::DATE_PATTERNS,
            'aggNames' => $defaults->includeAggNames(),
            'hashVersion' => $defaults->hashVersion(),
            'hashLength' => $defaults->hashLength(),
        ], Arr::arr($meta, 'defaults'));

        $described = Arr::arr($meta, 'rules');
        foreach ((new \ReflectionClass(Rule::class))->getConstants() as $name => $value) {
            if (!is_string($value)) {
                continue;
            }

            self::assertArrayHasKey($value, $described, 'Rule::' . $name . ' has no description in meta.rules.');
            self::assertSame((new Rule($value, 1))->description(), Arr::str($described[$value]));
        }
    }

    /**
     * @param array<int,string> $arguments
     *
     * @return array{0:int,1:string,2:string}
     */
    private static function php(array $arguments): array
    {
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open(array_merge([PHP_BINARY], $arguments), $descriptors, $pipes);
        self::assertIsResource($process);

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /**
     * @return array<mixed>
     */
    private static function readJson(string $file): array
    {
        self::assertFileExists($file, 'Run: make playground-data');

        $contents = file_get_contents($file);
        self::assertIsString($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded, 'Invalid JSON in ' . $file);

        return $decoded;
    }
}
