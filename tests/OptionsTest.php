<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Extension\ClauseRenderer;
use MrDlef\OsQueryDigest\Extension\RenderedClause;
use MrDlef\OsQueryDigest\IndexNormalizer;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * `Options` is immutable, and every wither says so in its own way — `$clone =
 * clone $this`. Nothing asserted it, so removing any one of those `clone`
 * keywords left the whole suite green while turning a configuration object that
 * is shared between formatters into one that is edited in place.
 *
 * One test for all of them, and a second that fails when a wither is added
 * without a line here: enumerating by hand is only safe when something checks
 * the enumeration.
 */
final class OptionsTest extends TestCase
{
    /**
     * Every wither, called with an argument that differs from the default — one
     * handed the value already in place would clone or not clone unobservably.
     *
     * Written as calls rather than as a name and an argument list: a variable
     * method call is invisible to static analysis, and the point of the second
     * test is that this list is checked rather than trusted.
     *
     * @return array<string,callable(Options):Options>
     */
    private static function withers(): array
    {
        return [
            'withNormalization' => static fn(Options $o): Options => $o->withNormalization(Normalization::structural()),
            'withMaxClauses' => static fn(Options $o): Options => $o->withMaxClauses(3),
            'withMaxValues' => static fn(Options $o): Options => $o->withMaxValues(2),
            'withMaxFields' => static fn(Options $o): Options => $o->withMaxFields(1),
            'withMaxLength' => static fn(Options $o): Options => $o->withMaxLength(80),
            'withIndexNormalizer' => static fn(Options $o): Options => $o->withIndexNormalizer(IndexNormalizer::identity()),
            'withRedactor' => static fn(Options $o): Options => $o->withRedactor(static fn(string $field, $value) => $value),
            'withAggNames' => static fn(Options $o): Options => $o->withAggNames(true),
            'withText' => static fn(Options $o): Options => $o->withText(false),
            'withHashLength' => static fn(Options $o): Options => $o->withHashLength(8),
            'withHashVersion' => static fn(Options $o): Options => $o->withHashVersion('q9'),
            'withClauseRenderer' => static fn(Options $o): Options => $o->withClauseRenderer('sltr', self::renderer()),
        ];
    }

    private static function renderer(): ClauseRenderer
    {
        return new class implements ClauseRenderer {
            public function render(array $body): RenderedClause
            {
                return RenderedClause::on('_score', 'sltr');
            }
        };
    }

    public function testEveryWitherReturnsACopyAndLeavesTheOriginalAlone(): void
    {
        foreach (self::withers() as $method => $call) {
            $options = Options::create();
            $before = clone $options;

            $returned = $call($options);

            self::assertNotSame($options, $returned, $method . '() returned the object it was called on.');
            self::assertEquals($before, $options, $method . '() edited the object it was called on.');
        }
    }

    public function testEveryWitherIsListedHere(): void
    {
        $declared = [];
        foreach ((new \ReflectionClass(Options::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (strpos($method->getName(), 'with') === 0) {
                $declared[] = $method->getName();
            }
        }

        sort($declared);
        $covered = array_keys(self::withers());
        sort($covered);

        self::assertSame($declared, $covered, 'A wither was added or renamed without a line in withers().');
    }
}
