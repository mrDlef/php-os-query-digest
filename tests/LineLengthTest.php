<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * The character cap is the last of the four limits to fire, and the only one
 * that knows nothing about what it is cutting. Left to count characters alone
 * it ends the line wherever the budget ran out — in the middle of a field name,
 * which then reads as a different, shorter field, and a value the search bar
 * will not match.
 *
 * So it ends on a separator instead. The line gives up whatever sat between
 * that separator and the budget; on a real week of traffic that is 24
 * characters of 511 at the median, 106 at the worst.
 *
 * What it does not do is make the line less ambiguous. Two queries that only
 * differ past the cut printed one line before and still print one line now —
 * ending sooner can only merge more of them. Discrimination lives in the hash,
 * which the cap never sees, and the shipped dashboards group on the hash for
 * exactly that reason.
 */
final class LineLengthTest extends TestCase
{
    /** @param array<mixed> $search */
    private static function signature(array $search, ?int $maxLength): string
    {
        return Formatter::create(Options::create()->withMaxLength($maxLength))
            ->describe($search)
            ->signature();
    }

    /** @return array<mixed> */
    private static function clauses(string $occur): array
    {
        return ['query' => ['bool' => [$occur => [
            ['term' => ['first_field_name' => 1]],
            ['term' => ['second_field_name' => 2]],
            ['term' => ['third_field_name' => 3]],
        ]]]];
    }

    public function testTheLineEndsOnAWholeClause(): void
    {
        $search = self::clauses('should');

        self::assertSame(
            'q=(first_field_name:? or second_field_name:? or third_field_name:?)',
            self::signature($search, null),
        );
        self::assertSame(
            'q=(first_field_name:? or second_field_name:? or …',
            self::signature($search, 50),
        );
    }

    public function testTheLineEndsOnAWholeAggregation(): void
    {
        $search = ['size' => 0, 'aggs' => [
            'a' => ['terms' => ['field' => 'first_field_name']],
            'b' => ['terms' => ['field' => 'second_field_name']],
            'c' => ['terms' => ['field' => 'third_field_name']],
        ]];

        self::assertSame(
            'aggs=terms(first_field_name), terms(second_field_name), …',
            self::signature($search, 70),
        );
    }

    public function testTheSectionSeparatorIsABoundaryToo(): void
    {
        $search = [
            'query' => ['term' => ['status' => 'open']],
            'aggs' => ['a' => ['terms' => ['field' => 'a_rather_long_field_name_here', 'size' => 20]]],
            'size' => 0,
        ];

        self::assertSame('q=(status:?) | …', self::signature($search, 30));
    }

    /**
     * The window held `…second_field_name:? an`, which is not a separator yet,
     * so the back-off lands on the one before it and a clause that did fit is
     * given up. That is the cost of the rule, and it is bounded by one clause.
     */
    public function testAWindowEndingInsideASeparatorBacksOffFurther(): void
    {
        self::assertSame(
            'q=(first_field_name:? and …',
            self::signature(self::clauses('filter'), 50),
        );
    }

    /**
     * A single clause longer than the whole budget has no separator to fall
     * back to. The cap still has to hold, so the line is cut where it was.
     */
    public function testAClauseLongerThanTheBudgetIsStillCut(): void
    {
        $search = ['query' => ['term' => ['a_very_long_field_name_without_any_separator' => 'x']]];

        self::assertSame(
            'q=(a_very_long_field_name_wit…',
            self::signature($search, 30),
        );
    }

    /**
     * The ellipsis marks something given up, so a line that gave up nothing
     * must not carry one — including the line that fills the budget exactly.
     * A cap of zero is no cap at all: there is no line that fits in it, and
     * refusing to render is worse than rendering whole.
     */
    public function testALineThatFitsIsLeftAlone(): void
    {
        $search = self::clauses('should');
        $whole = self::signature($search, null);

        self::assertSame($whole, self::signature($search, mb_strlen($whole)));
        self::assertSame($whole, self::signature($search, mb_strlen($whole) + 1));
        self::assertSame($whole, self::signature($search, 0));
    }

    /**
     * Down to one character, where the budget buys the ellipsis and nothing
     * else — the back-off may give up everything, but never the cap.
     */
    public function testTheCapIsNeverExceeded(): void
    {
        $search = self::clauses('should');

        foreach ([80, 50, 30, 20, 10, 5, 2, 1] as $cap) {
            self::assertLessThanOrEqual(
                $cap,
                mb_strlen(self::signature($search, $cap)),
                "A signature capped at {$cap} characters is longer than that.",
            );
        }
    }

    /**
     * The back-off works on bytes over a string the cut already made character
     * safe — every separator is ASCII, so no byte of a multibyte character can
     * be mistaken for one.
     */
    public function testAMultibyteValueIsNeverSplit(): void
    {
        $search = ['query' => ['term' => ['city' => 'Besançon-où-il-pleut-énormément']]];

        foreach ([30, 25, 20] as $cap) {
            $text = Formatter::create(Options::create()->withMaxLength($cap))
                ->describe($search)
                ->text();

            self::assertTrue(mb_check_encoding($text, 'UTF-8'), "Capped at {$cap}, the text is not UTF-8.");
            self::assertLessThanOrEqual($cap, mb_strlen($text));
        }
    }

    public function testTheCapNeverMovesTheHash(): void
    {
        $search = self::clauses('should');

        $uncapped = Formatter::create(Options::create()->withMaxLength(null))->describe($search);
        $capped = Formatter::create(Options::create()->withMaxLength(50))->describe($search);
        $tight = Formatter::create(Options::create()->withMaxLength(10))->describe($search);

        self::assertSame($uncapped->hash(), $capped->hash());
        self::assertSame($uncapped->hash(), $tight->hash());
        self::assertNotSame($uncapped->signature(), $capped->signature());
    }
}
