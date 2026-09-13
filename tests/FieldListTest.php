<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * A `multi_match` and its relatives search several fields at once, and the
 * boosted field list they carry is long: on a search built over one, it takes
 * more of the rendered line than everything else put together.
 *
 * It is also the least discriminating part of that line — the schema, near
 * enough constant across an application's searches, while the filters beside it
 * are what actually vary. Left uncapped it starves them of the display budget,
 * and two searches that differ only in their filters print the same truncated
 * line under two different hashes.
 *
 * So the list is capped like a terms clause is, and like every display limit
 * the hash never sees it.
 */
final class FieldListTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function search(string ...$fields): array
    {
        return ['query' => ['multi_match' => ['query' => 'shoes', 'fields' => $fields]]];
    }

    public function testTheListIsSummarisedAfterTheCap(): void
    {
        $digest = Formatter::create()->describe(
            self::search('title^10', 'subtitle^5', 'content', 'tags', 'author', 'isbn'),
        );

        self::assertSame('q=(title^10|subtitle^5|content|+3 more:~?)', $digest->signature());
        self::assertSame('q=(title^10|subtitle^5|content|+3 more:shoes)', $digest->text());
    }

    public function testAShortListIsLeftAlone(): void
    {
        $digest = Formatter::create()->describe(self::search('title', 'content', 'tags'));

        self::assertSame('q=(title|content|tags:~?)', $digest->signature());
    }

    public function testTheCapNeverMovesTheHash(): void
    {
        $search = self::search('title^10', 'subtitle^5', 'content', 'tags', 'author', 'isbn');

        $capped = Formatter::create()->describe($search);
        $lifted = Formatter::create(Options::create()->withMaxFields(null))->describe($search);
        $tight = Formatter::create(Options::create()->withMaxFields(1))->describe($search);

        self::assertSame($capped->hash(), $lifted->hash());
        self::assertSame($capped->hash(), $tight->hash());
        self::assertNotSame($capped->signature(), $lifted->signature());
    }

    /**
     * The point of the whole exercise: what the cap hides must still tell two
     * searches apart, or the fingerprint would inherit the ambiguity the line
     * has.
     */
    public function testTwoSearchesDifferingOnlyPastTheCapStayDistinct(): void
    {
        $formatter = Formatter::create();

        $first = $formatter->describe(self::search('title', 'content', 'tags', 'author'));
        $second = $formatter->describe(self::search('title', 'content', 'tags', 'isbn'));

        self::assertSame($first->signature(), $second->signature());
        self::assertNotSame($first->hash(), $second->hash());
    }

    public function testEveryClauseThatJoinsFieldsIsCapped(): void
    {
        $formatter = Formatter::create();
        $fields = ['title', 'subtitle', 'content', 'tags', 'author'];

        $queryString = $formatter->describe([
            'query' => ['query_string' => ['query' => 'shoes AND red', 'fields' => $fields]],
        ]);
        $moreLikeThis = $formatter->describe([
            'query' => ['more_like_this' => ['like' => 'shoes', 'fields' => $fields]],
        ]);

        self::assertStringContainsString('title|subtitle|content|+2 more:', $queryString->signature());
        self::assertStringContainsString('title|subtitle|content|+2 more:', $moreLikeThis->signature());
    }

    /**
     * The renderer shows a shortened list and hands the whole one to the value
     * renderer, so a redactor keyed on a field name still sees the fields the
     * query named. Deciding whether a value may be logged from a display string
     * would be deciding it from the wrong thing.
     */
    public function testTheRedactorSeesTheFieldsTheQueryNamed(): void
    {
        $seen = [];
        $options = Options::create()->withRedactor(
            static function (string $field, $value) use (&$seen) {
                $seen[] = $field;

                return $value;
            },
        );

        Formatter::create($options)->describe(
            self::search('title^10', 'subtitle^5', 'content', 'tags', 'author', 'isbn'),
        );

        self::assertSame(['title^10|subtitle^5|content|tags|author|isbn'], array_unique($seen));
    }
}
