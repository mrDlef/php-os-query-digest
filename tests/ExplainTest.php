<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Explain\Rule;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\IndexNormalizer;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * `explain()` exists to answer one question: *why do these two queries share a
 * hash?* So the contract under test is not "some rules are listed" but "the
 * rule that actually merged them is named, and nothing else is".
 */
final class ExplainTest extends TestCase
{
    private Formatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = Formatter::create();
    }

    /**
     * Found by mutation testing: `ABSORB_MATCH_NONE` is recorded from two
     * separate branches — an AND that hits `match_none` matches nothing, an OR
     * merely drops it — and only the AND one was covered. Deleting the OR
     * branch's record left every test green.
     */
    public function testAnOrDroppingMatchNoneReportsTheAbsorption(): void
    {
        $explanation = $this->formatter->explain(['query' => ['bool' => ['should' => [
            ['term' => ['env' => 'prod']],
            ['match_none' => []],
        ]]]]);

        self::assertTrue($explanation->has(Rule::ABSORB_MATCH_NONE));
        self::assertStringContainsString('env:prod', $explanation->digest()->text());
    }

    /**
     * An OR of nothing but match_none matches nothing. It must not fall
     * through to the empty-connector rule, which would turn it into match_all
     * — the exact opposite result set.
     */
    public function testAnOrOfOnlyMatchNoneStaysMatchNone(): void
    {
        $digest = $this->formatter->describe(['query' => ['bool' => ['should' => [
            ['match_none' => []],
            ['match_none' => []],
        ]]]]);

        self::assertSame('q=(none)', $digest->text());
    }

    /**
     * Also from mutation testing: `UNWRAP` is only a rewrite when the
     * connector had something to unwrap. A bool that arrives with one clause
     * was already reported by the parser, so the canonicaliser reporting it
     * again would double-count. The guard is `count($original) > 1`, and
     * nothing pinned it.
     */
    public function testUnwrappingIsReportedWhenAConnectorLosesItsOtherClause(): void
    {
        // Two clauses in, one out: match_all is dropped, and what is left is
        // no longer a connector at all.
        $explanation = $this->formatter->explain(['query' => ['bool' => ['filter' => [
            ['term' => ['env' => 'prod']],
            ['match_all' => []],
        ]]]]);

        self::assertTrue($explanation->has(Rule::DROP_MATCH_ALL));
        self::assertTrue($explanation->has(Rule::UNWRAP));
        self::assertSame('q=(env:prod)', $explanation->digest()->text());
    }

    /**
     * The empty-connector rule, which the two above must not be confused with.
     */
    public function testAConnectorLeftWithNothingBecomesMatchAll(): void
    {
        $explanation = $this->formatter->explain(['query' => ['bool' => ['filter' => []]]]);

        self::assertSame('q=(*)', $explanation->digest()->text());
    }

    public function testACanonicalQueryReportsNoRule(): void
    {
        $explanation = $this->formatter->explain(['query' => ['term' => ['env' => 'prod']]]);

        self::assertSame([], $explanation->rules());
        self::assertStringContainsString('already canonical', (string) $explanation);
    }

    public function testTheRuleThatMergedTwoQueriesIsNamed(): void
    {
        $written = ['query' => ['bool' => ['filter' => [
            ['term' => ['b' => 2]],
            ['bool' => ['filter' => [['term' => ['a' => 1]]]]],
        ]]]];

        $canonical = ['query' => ['bool' => ['filter' => [
            ['term' => ['a' => 1]],
            ['term' => ['b' => 2]],
        ]]]];

        $explanation = $this->formatter->explain($written);

        self::assertSame(
            $this->formatter->describe($canonical)->hash(),
            $explanation->digest()->hash(),
            'The two queries must converge, otherwise this test explains nothing.',
        );
        self::assertTrue($explanation->has(Rule::UNWRAP));
        self::assertTrue($explanation->has(Rule::REORDER));
    }

    public function testEachRuleIsReportedWhereItFires(): void
    {
        self::assertContains(Rule::MUST_FILTER_MERGED, $this->ruleIds(['query' => ['bool' => [
            'must' => [['term' => ['a' => 1]]],
            'filter' => [['term' => ['b' => 2]]],
        ]]]));

        self::assertContains(Rule::BOOST_DROPPED, $this->ruleIds(
            ['query' => ['term' => ['a' => ['value' => 1, 'boost' => 2.0]]]],
        ));

        self::assertContains(Rule::SHOULD_BOOST_ONLY, $this->ruleIds(['query' => ['bool' => [
            'filter' => [['term' => ['a' => 1]]],
            'should' => [['term' => ['b' => 2]], ['term' => ['c' => 3]]],
        ]]]));

        self::assertContains(Rule::CONSTANT_SCORE_UNWRAPPED, $this->ruleIds(
            ['query' => ['constant_score' => ['filter' => ['term' => ['a' => 1]]]]],
        ));

        self::assertContains(Rule::FUNCTION_SCORE_UNWRAPPED, $this->ruleIds(
            ['query' => ['function_score' => ['query' => ['term' => ['a' => 1]]]]],
        ));

        self::assertContains(Rule::BOOSTING_UNWRAPPED, $this->ruleIds(['query' => ['boosting' => [
            'positive' => ['term' => ['a' => 1]],
            'negative' => ['term' => ['b' => 2]],
        ]]]));

        self::assertContains(Rule::TERMS_LOOKUP, $this->ruleIds(
            ['query' => ['terms' => ['user' => ['index' => 'users', 'id' => '1', 'path' => 'friends']]]],
        ));

        self::assertContains(Rule::FLATTEN, $this->ruleIds(['query' => ['bool' => ['filter' => [
            ['term' => ['a' => 1]],
            ['bool' => ['filter' => [['term' => ['b' => 2]], ['term' => ['c' => 3]]]]],
        ]]]]));

        self::assertContains(Rule::DEDUPE, $this->ruleIds(['query' => ['bool' => ['filter' => [
            ['term' => ['a' => 1]],
            ['term' => ['a' => 1]],
            ['term' => ['b' => 2]],
        ]]]]));

        self::assertContains(Rule::DROP_MATCH_ALL, $this->ruleIds(['query' => ['bool' => ['filter' => [
            ['match_all' => new \stdClass()],
            ['term' => ['a' => 1]],
            ['term' => ['b' => 2]],
        ]]]]));

        self::assertContains(Rule::SECTION_IGNORED, $this->ruleIds([
            'query' => ['term' => ['a' => 1]],
            '_source' => false,
        ]));
    }

    public function testTheIndexRewriteIsReportedWithBothSides(): void
    {
        $explanation = Formatter::create(
            Options::create()->withIndexNormalizer(IndexNormalizer::datePatterns()),
        )->explain(['query' => ['term' => ['a' => 1]]], 'logs-2026.08.13');

        self::assertTrue($explanation->has(Rule::INDEX_PATTERN));

        foreach ($explanation->rules() as $rule) {
            if ($rule->id() === Rule::INDEX_PATTERN) {
                self::assertSame(['logs-2026.08.13 -> logs-*'], $rule->details());
            }
        }
    }

    public function testRepeatedFiringsAreCounted(): void
    {
        $explanation = $this->formatter->explain(['query' => ['bool' => ['filter' => [
            ['term' => ['a' => 1]],
            ['bool' => ['filter' => [['term' => ['b' => 2]], ['term' => ['c' => 3]]]]],
            ['bool' => ['filter' => [['term' => ['d' => 4]], ['term' => ['e' => 5]]]]],
        ]]]]);

        foreach ($explanation->rules() as $rule) {
            if ($rule->id() === Rule::FLATTEN) {
                self::assertSame(2, $rule->count());
                self::assertSame(['and'], $rule->details());

                return;
            }
        }

        self::fail('flatten was not reported.');
    }

    /**
     * Two runs of the same query must explain themselves identically —
     * otherwise the explanation cannot be diffed, which is its only use.
     */
    public function testTheExplanationIsDeterministic(): void
    {
        $request = ['query' => ['bool' => [
            'must' => [['term' => ['a' => 1]]],
            'filter' => [['bool' => ['filter' => [['term' => ['c' => 3]], ['term' => ['b' => 2]]]]]],
        ]]];

        self::assertSame(
            json_encode($this->formatter->explain($request)),
            json_encode($this->formatter->explain($request)),
        );
    }

    public function testExplainProducesTheSameDigestAsDescribe(): void
    {
        $request = ['query' => ['bool' => [
            'filter' => [['term' => ['env' => 'prod']], ['range' => ['@timestamp' => ['gte' => 'now-15m']]]],
            'should' => [['match' => ['msg' => 'timeout']]],
        ]], 'size' => 0];

        self::assertEquals(
            $this->formatter->describe($request, 'logs-2026.08.13')->toArray(),
            $this->formatter->explain($request, 'logs-2026.08.13')->digest()->toArray(),
        );
    }

    public function testItSerialisesToTheDigestPlusItsRules(): void
    {
        // Three clauses, already ordered: dedupe is then the only rule that can
        // fire — no unwrap (two survive) and no reorder.
        $explanation = $this->formatter->explain(['query' => ['bool' => ['filter' => [
            ['term' => ['a' => 1]],
            ['term' => ['a' => 1]],
            ['term' => ['b' => 2]],
        ]]]]);

        $array = $explanation->toArray();

        self::assertArrayHasKey('hash', $array);
        self::assertSame(
            [['rule' => Rule::DEDUPE, 'count' => 1, 'why' => (new Rule(Rule::DEDUPE, 1))->description(), 'on' => ['and']]],
            $array['rules'],
        );
    }

    /**
     * @param array<string,mixed> $request
     *
     * @return array<int,string>
     */
    private function ruleIds(array $request): array
    {
        return $this->formatter->explain($request)->ruleIds();
    }
}
