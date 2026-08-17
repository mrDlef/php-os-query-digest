<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Options;
use MrDlef\OsQueryDigest\Support\Arr;
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

            $options = Options::fromArray(Arr::arr($input, 'options'));
            $request = Arr::arr($input, 'request');
            $index = Arr::get($input, 'index');

            $digest = Formatter::create($options)
                ->describe($request, $index === null ? null : Arr::str($index))
                ->toArray();

            $file = $directory . '/expected.json';

            if ($updating) {
                $written = file_put_contents(
                    $file,
                    json_encode($digest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
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
     * @return array<mixed>
     */
    private static function readJson(string $file): array
    {
        $contents = file_get_contents($file);
        self::assertIsString($contents, 'Unreadable fixture: ' . $file);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded, 'Invalid JSON in ' . $file);

        return $decoded;
    }
}
