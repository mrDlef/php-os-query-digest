<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Exception\InvalidOptionException;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;
use MrDlef\OsQueryDigest\Support\IndexNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * `Options::fromArray()` is what every non-PHP front end configures through: a
 * CLI flag, a YAML block, a query string. It has to fail loudly, because the
 * alternative — an ignored key — shows up as a silently regrouped dashboard.
 */
final class OptionsFromArrayTest extends TestCase
{
    public function testAnEmptySpecIsTheDefaults(): void
    {
        $defaults = Options::create();
        $built = Options::fromArray([]);

        self::assertSame($defaults->normalization()->level(), $built->normalization()->level());
        self::assertSame($defaults->maxClauses(), $built->maxClauses());
        self::assertSame($defaults->maxValues(), $built->maxValues());
        self::assertSame($defaults->maxLength(), $built->maxLength());
        self::assertSame($defaults->includeAggNames(), $built->includeAggNames());
        self::assertSame($defaults->hashVersion(), $built->hashVersion());
        self::assertSame($defaults->hashLength(), $built->hashLength());
    }

    public function testEveryKeyIsApplied(): void
    {
        $options = Options::fromArray([
            'normalization' => Normalization::STRUCTURAL,
            'maxClauses' => 3,
            'maxValues' => 2,
            'maxLength' => 80,
            'indexNormalizer' => IndexNormalizer::IDENTITY,
            'aggNames' => true,
            'hashVersion' => 'q9',
            'hashLength' => 8,
        ]);

        self::assertSame(Normalization::STRUCTURAL, $options->normalization()->level());
        self::assertSame(3, $options->maxClauses());
        self::assertSame(2, $options->maxValues());
        self::assertSame(80, $options->maxLength());
        self::assertSame('q9', $options->hashVersion());
        self::assertSame(8, $options->hashLength());
        self::assertTrue($options->includeAggNames());
        // identity leaves a rolling name alone; the default would collapse it.
        self::assertSame('logs-2026.08.13', $options->indexNormalizer()->normalize('logs-2026.08.13'));
    }

    public function testEveryDocumentedKeyIsAccepted(): void
    {
        // KEYS is what a front end lists in its own help text; a key that made
        // it into the list but not into the switch would be a lie.
        foreach (Options::KEYS as $key) {
            self::assertTrue(
                in_array($key, self::acceptedKeys(), true),
                'Options::KEYS advertises "' . $key . '", which fromArray() rejects.',
            );
        }
    }

    public function testNullLiftsACap(): void
    {
        $options = Options::fromArray(['maxClauses' => null, 'maxValues' => null, 'maxLength' => null]);

        self::assertNull($options->maxClauses());
        self::assertNull($options->maxValues());
        self::assertNull($options->maxLength());
    }

    public function testAnUnknownKeyThrows(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Unknown option "maxvalues"');

        Options::fromArray(['maxvalues' => 5]);
    }

    public function testTheKnownKeysAreListedInTheError(): void
    {
        try {
            Options::fromArray(['nope' => 1]);
            self::fail('An unknown key should throw.');
        } catch (InvalidOptionException $exception) {
            foreach (Options::KEYS as $key) {
                self::assertStringContainsString($key, $exception->getMessage());
            }
        }
    }

    public function testANumericStringIsNotCoerced(): void
    {
        // Deliberate: a front end that guesses at "12" also accepts "twelve".
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('expects an integer or null, got string');

        Options::fromArray(['maxValues' => '12']);
    }

    public function testAWrongTypeThrows(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Option "aggNames" expects a boolean, got string');

        Options::fromArray(['aggNames' => 'yes']);
    }

    public function testANonStringNormalizationThrows(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Option "normalization" expects a string, got integer');

        Options::fromArray(['normalization' => 2]);
    }

    public function testAnUnknownNormalizationLevelThrows(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Allowed: none, values, structural.');

        Options::fromArray(['normalization' => 'loose']);
    }

    public function testAnUnknownIndexModeThrows(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Allowed: date-patterns, identity.');

        Options::fromArray(['indexNormalizer' => 'none']);
    }

    public function testEveryNormalizationLevelHasAConstructor(): void
    {
        foreach (Normalization::LEVELS as $level) {
            self::assertSame($level, Normalization::fromLevel($level)->level());
        }
    }

    public function testEveryIndexModeHasAConstructor(): void
    {
        foreach (IndexNormalizer::MODES as $mode) {
            // Only the collapsing mode rewrites a dated name; both must build.
            $normalized = IndexNormalizer::fromMode($mode)->normalize('logs-2026.08.13');
            self::assertSame(
                $mode === IndexNormalizer::IDENTITY ? 'logs-2026.08.13' : 'logs-*',
                $normalized,
            );
        }
    }

    /**
     * @return array<int,string>
     */
    private static function acceptedKeys(): array
    {
        $accepted = [];

        foreach (Options::KEYS as $key) {
            try {
                Options::fromArray([$key => self::sampleFor($key)]);
                $accepted[] = $key;
            } catch (InvalidOptionException $exception) {
                // An unknown key is a missing switch arm; a type error means the
                // arm exists and this sample was simply the wrong shape.
                if (strpos($exception->getMessage(), 'Unknown option') !== 0) {
                    $accepted[] = $key;
                }
            }
        }

        return $accepted;
    }

    /**
     * @return mixed
     */
    private static function sampleFor(string $key)
    {
        switch ($key) {
            case 'normalization':
                return Normalization::VALUES;
            case 'indexNormalizer':
                return IndexNormalizer::DATE_PATTERNS;
            case 'aggNames':
                return true;
            case 'hashVersion':
                return 'q2';
        }

        return 4;
    }
}
