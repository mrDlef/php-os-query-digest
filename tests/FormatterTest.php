<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Digest;
use MrDlef\OsQueryDigest\Exception\InvalidQueryException;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\LazyDigest;
use MrDlef\OsQueryDigest\Normalization;
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
            'chosen',
        );

        self::assertSame('chosen', $digest->index());
    }

    /**
     * `size`, `from` and `sort` are legitimate envelope-level keys — the search
     * endpoint's parameter whitelist carries all three — so a request built
     * that way must not read as an unpaged one.
     */
    public function testEnvelopeSearchParametersReachTheDigest(): void
    {
        $formatter = Formatter::create();
        $body = ['query' => ['term' => ['status' => 'active']]];

        $beside = $formatter->describe(['index' => 'members', 'body' => $body, 'size' => 20, 'from' => 40]);
        $inside = $formatter->describe(['index' => 'members', 'body' => $body + ['size' => 20, 'from' => 40]]);
        $neither = $formatter->describe(['index' => 'members', 'body' => $body]);

        self::assertSame('members | q=(status:active) | size=20 from=40', $beside->text());
        self::assertSame($inside->hash(), $beside->hash(), 'The same search, spelled two ways.');
        self::assertNotSame($neither->hash(), $beside->hash(), 'A paging query is not an unpaged one.');
    }

    /**
     * Read off a live node rather than off the clients' documentation: the
     * cluster parses the body first and then applies the query string, so the
     * envelope overrides — the opposite of what the shape suggests.
     */
    public function testTheEnvelopeWinsOverTheBodyForSizeAndFrom(): void
    {
        $digest = Formatter::create()->describe([
            'index' => 'members',
            'body' => ['query' => ['match_all' => []], 'size' => 5, 'from' => 0],
            'size' => 20,
            'from' => 40,
        ]);

        self::assertSame('members | q=(*) | size=20 from=40', $digest->text());
    }

    /**
     * `sort` is the exception: the query string appends to what the body
     * already sorts on, so the body keeps the primary key.
     */
    public function testEnvelopeSortIsAppendedToTheBodySort(): void
    {
        $digest = Formatter::create()->describe([
            'index' => 'members',
            'body' => ['query' => ['match_all' => []], 'sort' => [['joined_at' => 'asc']]],
            'sort' => 'last_name:desc',
        ]);

        self::assertSame('members | q=(*) | sort=joined_at:asc,last_name:desc', $digest->text());
    }

    /**
     * Envelope `sort` travels in the URI syntax, comma-joined or as a list —
     * not the body's structural form. A suffix the cluster does not read is
     * part of the field name.
     */
    public function testEnvelopeSortIsReadInTheUriSyntax(): void
    {
        $formatter = Formatter::create();
        $body = ['query' => ['match_all' => []]];

        $joined = $formatter->describe(['index' => 'm', 'body' => $body, 'sort' => 'a, b:desc']);
        $list = $formatter->describe(['index' => 'm', 'body' => $body, 'sort' => ['a', 'b:desc']]);

        self::assertSame('m | q=(*) | sort=a:asc,b:desc', $joined->text());
        self::assertSame($joined->hash(), $list->hash(), 'A comma-joined list is written with spaces as often as without.');

        self::assertSame(
            'm | q=(*) | sort=weird:field:asc',
            $formatter->describe(['index' => 'm', 'body' => $body, 'sort' => 'weird:field'])->text(),
        );
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
        self::assertMatchesRegularExpression('/^q5:[0-9a-f]{12}$/', $digest->hash());
    }

    public function testDigestSerialisesToASmallLogPayload(): void
    {
        $digest = Formatter::create()->describe(['query' => ['term' => ['a' => 1]]], 'idx');

        self::assertSame(
            ['idx' => 'idx', 'q' => 'idx | q=(a:1)', 'sig' => 'idx | q=(a:?)', 'hash' => $digest->hash()],
            $digest->toArray(),
        );
        self::assertSame(json_encode($digest->toArray()), json_encode($digest));
    }

    /**
     * The record a regulated deployment may ship: no field holding what a user
     * typed. `q` is omitted rather than emptied — the question is answered per
     * field, and a `q` that duplicated `sig` would have to be inspected first.
     */
    public function testTheValuesLineCanBeLeftOutOfWhatIsEmitted(): void
    {
        $request = ['query' => ['term' => ['email' => 'ada@example.com']]];

        $digest = Formatter::create(Options::create()->withText(false))
            ->describe($request, 'members');

        self::assertSame(['idx', 'sig', 'hash'], array_keys($digest->toArray()));
        self::assertSame('members | q=(email:?)', $digest->toArray()['sig']);

        $encoded = json_encode($digest);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('ada@example.com', $encoded);

        // Every accessor shows the shape rather than a value or an empty string,
        // so no caller can leak one by reading the wrong one.
        self::assertSame('members | q=(email:?)', $digest->text());
        self::assertSame('members | q=(email:?)', (string) $digest);

        // And the hash is the one the same query gets with the line on: what is
        // emitted must not change what a shape is called.
        self::assertSame(
            Formatter::create()->describe($request, 'members')->hash(),
            $digest->hash(),
        );
    }

    /**
     * The gap the option does not close, stated where it will be read: at
     * `Normalization::none()` the signature *is* the readable line.
     */
    public function testWithoutNormalizationTheSignatureStillCarriesTheValues(): void
    {
        $digest = Formatter::create(
            Options::create()->withText(false)->withNormalization(Normalization::none()),
        )->describe(['query' => ['term' => ['email' => 'ada@example.com']]], 'members');

        self::assertSame('members | q=(email:ada@example.com)', $digest->toArray()['sig']);
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
            static fn(int $i): array => ['term' => ['field_' . $i => 'some-fairly-long-value-' . $i]],
            range(1, 30),
        )]]];

        $short = Formatter::create(Options::create()->withMaxLength(80)->withMaxClauses(null));
        $long = Formatter::create(Options::create()->withMaxLength(null)->withMaxClauses(null));

        $shortDigest = $short->describe($request);

        $length = function_exists('mb_strlen')
            ? mb_strlen($shortDigest->text(), 'UTF-8')
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
