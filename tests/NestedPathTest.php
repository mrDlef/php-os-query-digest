<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * Inside `path:{ … }`, nothing repeats the path.
 *
 * The cheap half of {@see Integration\DqlRoundTripTest}: that one asks
 * Dashboards and needs its image, this one is a string check and runs in the
 * default suite. It only catches this one mistake — but it catches it on every
 * PHP version, offline, in milliseconds.
 *
 * @internal
 */
final class NestedPathTest extends TestCase
{
    /**
     * @return array<string,array{0:array<mixed>,1:string}>
     */
    public static function queries(): array
    {
        return [
            'one level' => [
                ['nested' => [
                    'path' => 'variants',
                    'query' => ['term' => ['variants.color' => 'red']],
                ]],
                'variants:{ color:red }',
            ],
            'two clauses under one path' => [
                ['nested' => [
                    'path' => 'variants',
                    'query' => ['bool' => ['filter' => [
                        ['term' => ['variants.color' => 'red']],
                        ['range' => ['variants.price' => ['lte' => 100]]],
                    ]]],
                ]],
                'variants:{ color:red and price <= 100 }',
            ],
            // The path of an inner clause is relative too — found by the
            // round-trip after the field-level fix had already landed.
            'nested inside nested' => [
                ['nested' => [
                    'path' => 'order',
                    'query' => ['nested' => [
                        'path' => 'order.lines',
                        'query' => ['term' => ['order.lines.sku' => 'abc']],
                    ]],
                ]],
                'order:{ lines:{ sku:abc } }',
            ],
            'a field list under a path' => [
                ['nested' => [
                    'path' => 'tags',
                    'query' => ['multi_match' => [
                        'query' => 'x',
                        'fields' => ['tags.fr', 'tags.en'],
                    ]],
                ]],
                'tags:{ fr|en:x }',
            ],
        ];
    }

    public function testNoClauseRepeatsThePathItSitsUnder(): void
    {
        $formatter = Formatter::create();

        foreach (self::queries() as $name => $case) {
            [$query, $expected] = $case;

            $text = $formatter->describe(['query' => $query])->text();

            self::assertSame('q=(' . $expected . ')', $text, $name);
        }
    }

    /**
     * The display is shortened; the value renderer is not. A redactor decides
     * whether a value may be logged from the field the *query* named, which a
     * relative name would misspell.
     */
    public function testTheRedactorStillSeesTheFullFieldName(): void
    {
        $seen = [];
        $options = Options::create()->withRedactor(
            static function (string $field, $value) use (&$seen) {
                $seen[] = $field;

                return $value;
            },
        );

        $text = Formatter::create($options)->describe(['query' => ['nested' => [
            'path' => 'variants',
            'query' => ['term' => ['variants.color' => 'red']],
        ]]])->text();

        self::assertSame('q=(variants:{ color:red })', $text);

        self::assertSame(['variants.color'], $seen);
    }
}
