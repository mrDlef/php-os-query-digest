<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Digest;
use MrDlef\OsQueryDigest\Exception\InvalidQueryException;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\LazyDigest;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    public function testAcceptsAJsonString(): void
    {
        $digest = Formatter::create()->describe('{"query":{"term":{"a":1}}}', 'idx');

        self::assertSame('idx | q=(a:1)', $digest->text());
    }

    public function testAcceptsTheOpenSearchPhpEnvelope(): void
    {
        $digest = Formatter::create()->describe([
            'index' => 'logs-2026.08.13',
            'body' => ['query' => ['term' => ['a' => 1]], 'size' => 10],
        ]);

        self::assertSame('logs-* | q=(a:1) | size=10', $digest->text());
    }

    public function testExplicitIndexWinsOverTheEnvelope(): void
    {
        $digest = Formatter::create()->describe(
            ['index' => 'ignored', 'body' => ['query' => ['match_all' => []]]],
            'chosen'
        );

        self::assertSame('chosen', $digest->index());
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(InvalidQueryException::class);

        Formatter::create()->describe('{not json');
    }

    public function testEmptyRequestIsHarmless(): void
    {
        $digest = Formatter::create()->describe([], 'idx');

        self::assertSame('idx', $digest->text());
        self::assertMatchesRegularExpression('/^q1:[0-9a-f]{12}$/', $digest->hash());
    }

    public function testDigestSerialisesToASmallLogPayload(): void
    {
        $digest = Formatter::create()->describe(['query' => ['term' => ['a' => 1]]], 'idx');

        self::assertSame(
            ['idx' => 'idx', 'q' => 'idx | q=(a:1)', 'sig' => 'idx | q=(a:?)', 'hash' => $digest->hash()],
            $digest->toArray()
        );
        self::assertSame(json_encode($digest->toArray()), json_encode($digest));
    }

    public function testLazyDigestDoesNothingUntilItIsRead(): void
    {
        $formatter = Formatter::create();
        $calls = 0;

        $lazy = new LazyDigest(function () use ($formatter, &$calls): Digest {
            ++$calls;

            return $formatter->describe(['query' => ['term' => ['a' => 1]]], 'idx');
        });

        self::assertSame(0, $calls, 'Building the wrapper must not parse anything.');

        self::assertSame('idx | q=(a:1)', (string) $lazy);
        self::assertSame('idx | q=(a:1)', $lazy->digest()->text());
        self::assertSame(1, $calls, 'The digest must be computed once and memoised.');
    }

    public function testFormatterHandsOutLazyDigests(): void
    {
        $lazy = Formatter::create()->lazy(['query' => ['term' => ['a' => 1]]], 'idx');

        self::assertInstanceOf(LazyDigest::class, $lazy);
        self::assertSame(['idx' => 'idx', 'q' => 'idx | q=(a:1)', 'sig' => 'idx | q=(a:?)', 'hash' => $lazy->digest()->hash()], $lazy->jsonSerialize());
    }

    public function testHardLengthCapAppliesToTheLineButNotTheHash(): void
    {
        $request = ['query' => ['bool' => ['filter' => array_map(
            static function (int $i): array {
                return ['term' => ['field_' . $i => 'some-fairly-long-value-' . $i]];
            },
            range(1, 30)
        )]]];

        $short = Formatter::create(Options::create()->withMaxLength(80)->withMaxClauses(null));
        $long = Formatter::create(Options::create()->withMaxLength(null)->withMaxClauses(null));

        $shortDigest = $short->describe($request);

        $length = function_exists('mb_strlen')
            ? (int) mb_strlen($shortDigest->text(), 'UTF-8')
            : strlen($shortDigest->text());

        self::assertLessThanOrEqual(80, $length);
        self::assertStringEndsWith('…', $shortDigest->text());
        self::assertSame($long->describe($request)->hash(), $shortDigest->hash());
    }

    public function testAggregationNamesAreOptional(): void
    {
        $request = ['aggs' => ['by_host' => ['terms' => ['field' => 'host', 'size' => 10]]], 'size' => 0];

        $without = Formatter::create()->describe($request);
        $with = Formatter::create(Options::create()->withAggNames(true))->describe($request);

        self::assertSame('aggs=terms(host,10) | size=0', $without->text());
        self::assertSame('aggs=by_host:terms(host,10) | size=0', $with->text());
        self::assertNotSame($without->hash(), $with->hash());
    }
}
