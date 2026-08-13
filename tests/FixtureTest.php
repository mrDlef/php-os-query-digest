<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file tests.
 *
 * Every fixture pins the exact rendered lines *and the hash*. That is
 * deliberate: the hash is a public contract — if a normalisation change makes
 * one move, it has to show up as a reviewable diff, never as a silent
 * invalidation of someone's dashboard.
 *
 * Regenerate with: UPDATE_FIXTURES=1 vendor/bin/phpunit
 */
final class FixtureTest extends TestCase
{
    /**
     * Deliberately one test looping over every fixture rather than a data
     * provider: doc-comment metadata is gone in PHPUnit 12 and PHP 7.4 cannot
     * use attributes, so this is the only shape that runs unchanged across the
     * whole supported matrix. It also diffs every fixture at once.
     */
    public function testFixturesMatchTheirGoldenFiles(): void
    {
        $updating = getenv('UPDATE_FIXTURES') !== false;
        $expected = [];
        $actual = [];

        foreach (self::directories() as $name => $directory) {
            $input = self::readJson($directory . '/input.json');

            $options = self::options(
                isset($input['options']) && is_array($input['options']) ? $input['options'] : []
            );
            /** @var array<string,mixed> $request */
            $request = $input['request'];

            $digest = Formatter::create($options)
                ->describe($request, isset($input['index']) ? (string) $input['index'] : null)
                ->toArray();

            $file = $directory . '/expected.json';

            if ($updating) {
                $written = file_put_contents(
                    $file,
                    json_encode($digest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
                );
                self::assertNotFalse($written, 'Could not rewrite ' . $file);
                continue;
            }

            self::assertFileExists($file, 'Missing golden file. Run: UPDATE_FIXTURES=1 vendor/bin/phpunit');
            $expected[$name] = self::readJson($file);
            $actual[$name] = $digest;
        }

        if ($updating) {
            return;
        }

        self::assertNotSame([], $actual, 'No fixture was found.');
        self::assertSame($expected, $actual, 'A fixture drifted. Review the diff — hashes are a public contract.');
    }

    /**
     * @return array<string,string>
     */
    private static function directories(): array
    {
        $directories = glob(__DIR__ . '/fixtures/*', GLOB_ONLYDIR);
        $out = [];

        foreach ($directories === false ? [] : $directories as $directory) {
            $out[basename($directory)] = $directory;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $spec
     */
    private static function options(array $spec): Options
    {
        $options = Options::create();

        if (isset($spec['normalization'])) {
            $level = (string) $spec['normalization'];
            if ($level === Normalization::NONE) {
                $options = $options->withNormalization(Normalization::none());
            } elseif ($level === Normalization::STRUCTURAL) {
                $options = $options->withNormalization(Normalization::structural());
            }
        }

        if (array_key_exists('maxValues', $spec)) {
            $options = $options->withMaxValues($spec['maxValues'] === null ? null : (int) $spec['maxValues']);
        }

        if (array_key_exists('maxClauses', $spec)) {
            $options = $options->withMaxClauses($spec['maxClauses'] === null ? null : (int) $spec['maxClauses']);
        }

        if (!empty($spec['aggNames'])) {
            $options = $options->withAggNames(true);
        }

        return $options;
    }

    /**
     * @return array<string,mixed>
     */
    private static function readJson(string $file): array
    {
        $contents = file_get_contents($file);
        self::assertIsString($contents, 'Unreadable fixture: ' . $file);

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded, 'Invalid JSON in ' . $file);

        return $decoded;
    }
}
