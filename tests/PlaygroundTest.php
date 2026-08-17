<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Explain\Rule;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;
use MrDlef\OsQueryDigest\Support\Arr;
use MrDlef\OsQueryDigest\Support\IndexNormalizer;
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
