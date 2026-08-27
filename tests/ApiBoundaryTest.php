<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins which classes are a promise and which are plumbing.
 *
 * Under semantic versioning every public class is a contract, so "what is
 * public" cannot be left to whichever namespace a class happened to land in.
 * The parser, the tree and the renderers change every time a query type is
 * promoted; if they were contract, each promotion would be a major release and
 * the library could never improve.
 *
 * So the boundary is declared, and this test is what keeps the declaration
 * honest — the same trade `SpecCoverageTest` makes for query types. A class
 * added without a stance fails the suite rather than defaulting into the public
 * surface by silence.
 */
final class ApiBoundaryTest extends TestCase
{
    /**
     * The public surface, in full. Adding a line here is widening a promise
     * that cannot be narrowed again without a major release — so it is a
     * deliberate, reviewable diff rather than a side effect.
     *
     * @var array<int,class-string>
     */
    private const PUBLIC_API = [
        'MrDlef\OsQueryDigest\Digest',
        'MrDlef\OsQueryDigest\Exception\InvalidOptionException',
        'MrDlef\OsQueryDigest\Exception\InvalidQueryException',
        'MrDlef\OsQueryDigest\Explain\Explanation',
        'MrDlef\OsQueryDigest\Explain\Rule',
        'MrDlef\OsQueryDigest\Extension\ClauseRenderer',
        'MrDlef\OsQueryDigest\Extension\RenderedClause',
        'MrDlef\OsQueryDigest\Formatter',
        'MrDlef\OsQueryDigest\Http\DigestingClient',
        'MrDlef\OsQueryDigest\Http\Guzzle\DigestMiddleware',
        'MrDlef\OsQueryDigest\Http\LoggingObserver',
        'MrDlef\OsQueryDigest\Http\ObservedSearch',
        'MrDlef\OsQueryDigest\Http\Ring\DigestingHandler',
        'MrDlef\OsQueryDigest\Http\SearchObserver',
        'MrDlef\OsQueryDigest\LazyDigest',
        'MrDlef\OsQueryDigest\Monolog\DigestProcessor',
        'MrDlef\OsQueryDigest\Monolog\SafeDigest',
        'MrDlef\OsQueryDigest\Normalization',
        'MrDlef\OsQueryDigest\Options',
        'MrDlef\OsQueryDigest\IndexNormalizer',
    ];

    public function testEveryClassDeclaresWhetherItIsPublicOrInternal(): void
    {
        $undeclared = [];

        foreach (self::classes() as $class) {
            $doc = self::docComment($class);
            $api = self::hasTag($doc, 'api');
            $internal = self::hasTag($doc, 'internal');

            if ($api === $internal) {
                $undeclared[] = $class . ($api ? ' (both)' : ' (neither)');
            }
        }

        self::assertSame(
            [],
            $undeclared,
            "Every class must be marked @api or @internal, and not both.\n"
            . "A new class is plumbing until someone decides otherwise: mark it @internal\n"
            . 'unless you mean to promise it forever. Offenders: ' . implode(', ', $undeclared),
        );
    }

    public function testThePublicSurfaceIsExactlyWhatIsDeclaredHere(): void
    {
        $marked = [];
        foreach (self::classes() as $class) {
            if (self::hasTag(self::docComment($class), 'api')) {
                $marked[] = $class;
            }
        }

        $expected = self::PUBLIC_API;
        sort($marked);
        sort($expected);

        self::assertSame(
            $expected,
            $marked,
            'The @api annotations and this test disagree about the public surface.',
        );
    }

    /**
     * The boundary is only worth anything if it does not leak: a public method
     * that hands back an internal type has made that type public in practice,
     * whatever its annotation says. This is the assertion that would have
     * caught it.
     */
    public function testNoPublicMethodExposesAnInternalType(): void
    {
        $leaks = [];

        foreach (self::PUBLIC_API as $class) {
            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $types = [];
                foreach ($method->getParameters() as $parameter) {
                    $types[] = $parameter->getType();
                }
                $types[] = $method->getReturnType();

                foreach (self::classNames($types) as $name) {
                    if (self::isOurs($name) && !in_array($name, self::PUBLIC_API, true)) {
                        $leaks[] = $class . '::' . $method->getName() . '() → ' . $name;
                    }
                }
            }
        }

        self::assertSame(
            [],
            $leaks,
            "A public method exposes an internal type, which makes it public in practice:\n  "
            . implode("\n  ", $leaks),
        );
    }

    /**
     * @param array<int,\ReflectionType|null> $types
     *
     * @return array<int,string>
     */
    private static function classNames(array $types): array
    {
        $names = [];

        foreach ($types as $type) {
            if ($type === null) {
                continue;
            }

            // A single type on PHP 7.4; a union or intersection from 8.0 on.
            // `instanceof` against a class that does not exist on this runtime
            // is false rather than an error, so no version guard is needed.
            $parts = [$type];
            if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                $parts = $type->getTypes();
            }

            foreach ($parts as $part) {
                if ($part instanceof \ReflectionNamedType && !$part->isBuiltin()) {
                    $names[] = $part->getName();
                }
            }
        }

        return $names;
    }

    private static function isOurs(string $class): bool
    {
        return strpos($class, 'MrDlef\OsQueryDigest\\') === 0;
    }

    /**
     * An annotation is a tag opening a line, not any occurrence of the word.
     * A docblock is allowed to *discuss* `@internal` — the extension point's
     * does, explaining why the tree it deliberately avoids is marked that way —
     * without thereby claiming to be internal.
     */
    private static function hasTag(string $doc, string $tag): bool
    {
        return preg_match('/^\s*\*\s*@' . $tag . '\b/m', $doc) === 1;
    }

    /**
     * @param class-string $class
     */
    private static function docComment(string $class): string
    {
        $doc = (new \ReflectionClass($class))->getDocComment();

        return $doc === false ? '' : $doc;
    }

    /**
     * Every class shipped in src/, derived from the PSR-4 root rather than from
     * a list — a file that is never named here still has to answer for itself.
     *
     * @return array<int,class-string>
     */
    private static function classes(): array
    {
        $root = dirname(__DIR__) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $classes = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $name = 'MrDlef\OsQueryDigest\\' . str_replace('/', '\\', $relative);

            // Also catches a file whose path and namespace have drifted apart.
            if (!class_exists($name) && !interface_exists($name)) {
                self::fail($name . ' does not autoload — path and namespace disagree.');
            }

            $classes[] = $name;
        }

        sort($classes);
        self::assertNotSame([], $classes, 'Found no classes under src/.');

        return $classes;
    }
}
