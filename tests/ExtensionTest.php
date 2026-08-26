<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Exception\InvalidOptionException;
use MrDlef\OsQueryDigest\Explain\Rule;
use MrDlef\OsQueryDigest\Extension\ClauseRenderer;
use MrDlef\OsQueryDigest\Extension\RenderedClause;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * Teaching the library a query type it does not model.
 *
 * Two things are under test, and the second matters more than the first: that
 * an extension can read a clause the library cannot, and that it cannot reach
 * anything else while doing so.
 */
final class ExtensionTest extends TestCase
{
    public function testAnOpaqueTypeBecomesReadable(): void
    {
        $formatter = $this->formatterWithSltr();

        $digest = $formatter->describe(['query' => ['sltr' => [
            'model' => 'ltr_model_v3',
            'params' => ['keywords' => 'hiking boots'],
        ]]]);

        self::assertSame('q=(_score:sltr(model=ltr_model_v3))', $digest->text());
        self::assertSame('q=(_score:sltr(model=?))', $digest->signature());
    }

    /**
     * Without the renderer, the same query is the blank it was before.
     */
    public function testTheSameQueryStaysOpaqueWithoutTheRenderer(): void
    {
        $digest = Formatter::create()->describe(['query' => ['sltr' => ['model' => 'ltr_model_v3']]]);

        self::assertSame('q=(sltr(?))', $digest->text());
    }

    /**
     * Parameter values are erased in the signature like every other clause's,
     * so two searches that turned the same knobs share a fingerprint.
     */
    public function testTwoSearchesThroughTheSameModelShareAFingerprint(): void
    {
        $formatter = $this->formatterWithSltr();

        self::assertSame(
            $formatter->describe(['query' => ['sltr' => ['model' => 'a']]])->hash(),
            $formatter->describe(['query' => ['sltr' => ['model' => 'b']]])->hash(),
        );
    }

    /**
     * The guarantee that makes this safe to ship: the hook lives in the
     * parser's default branch, so a renderer registered for a type the library
     * *does* model never runs. Someone's plugin code cannot move the
     * fingerprint of a `term` query, by accident or otherwise.
     */
    public function testARendererCannotReachANativelyModelledType(): void
    {
        $formatter = Formatter::create(
            Options::create()->withClauseRenderer('term', new class implements ClauseRenderer {
                // Narrowed to non-null: this one always describes something.
                // Legal covariance, and truthful about what it does.
                public function render(array $body): RenderedClause
                {
                    return RenderedClause::on('hijacked', 'hijacked');
                }
            }),
        );

        $digest = $formatter->describe(['query' => ['term' => ['env' => 'prod']]]);

        self::assertSame('q=(env:prod)', $digest->text());
        self::assertStringNotContainsString('hijacked', $digest->text());
    }

    /**
     * The stock fingerprint of a stock query must not move because an
     * unrelated extension happens to be registered.
     */
    public function testRegisteringARendererDoesNotDisturbOtherQueries(): void
    {
        $request = ['query' => ['bool' => ['filter' => [
            ['term' => ['env' => 'prod']],
            ['range' => ['took' => ['gte' => 500]]],
        ]]]];

        self::assertSame(
            Formatter::create()->describe($request)->signature(),
            $this->formatterWithSltr()->describe($request)->signature(),
        );
    }

    /**
     * But the *hash* of even an untouched query is marked, because the rules in
     * force are no longer this library's alone. A digest minted with someone's
     * plugin in the loop must not be mistaken for a stock one.
     */
    public function testTheHashVersionIsMarkedWhenARendererIsRegistered(): void
    {
        $request = ['query' => ['term' => ['env' => 'prod']]];

        $stock = Formatter::create()->describe($request)->hash();
        $extended = $this->formatterWithSltr()->describe($request)->hash();

        self::assertStringStartsWith('q5:', $stock);
        self::assertStringStartsWith('q5x:', $extended);

        // Same shape, same twelve characters — only the marker differs, so the
        // two are still recognisable as the same query.
        self::assertSame(substr($stock, 3), substr($extended, 4));
    }

    /**
     * A renderer that does not recognise a body has to say so. `type(?)` is
     * then the true answer; a guess would be a fingerprint built on a
     * misreading.
     */
    public function testARendererReturningNullLeavesTheClauseOpaque(): void
    {
        $formatter = Formatter::create(
            Options::create()->withClauseRenderer('sltr', new class implements ClauseRenderer {
                public function render(array $body): ?RenderedClause
                {
                    return null;
                }
            }),
        );

        $digest = $formatter->describe(['query' => ['sltr' => ['unexpected' => true]]]);

        self::assertSame('q=(sltr(?))', $digest->text());
    }

    public function testExplainNamesTheExtensionThatTookPart(): void
    {
        $explanation = $this->formatterWithSltr()
            ->explain(['query' => ['sltr' => ['model' => 'ltr_model_v3']]]);

        self::assertTrue($explanation->has(Rule::EXTENSION_RENDERED));
        self::assertStringContainsString('sltr', (string) $explanation);
    }

    public function testAFieldlessClauseRendersWithoutOne(): void
    {
        $formatter = Formatter::create(
            Options::create()->withClauseRenderer('agentic', new class implements ClauseRenderer {
                public function render(array $body): RenderedClause
                {
                    $agent = $body['agent_id'] ?? null;

                    return RenderedClause::fieldless('agentic')
                        ->withParam('agent', is_scalar($agent) ? $agent : null);
                }
            }),
        );

        $digest = $formatter->describe(['query' => ['agentic' => [
            'query_text' => 'find me something', 'agent_id' => 'a-42',
        ]]]);

        self::assertSame('q=(agentic(agent=a-42))', $digest->text());
        self::assertSame('q=(agentic(agent=?))', $digest->signature());
    }

    /**
     * A numeric parameter name would be cast to the integer key that carries
     * the label, and either vanish or displace it. Refused rather than
     * silently mangled.
     */
    public function testANumericParameterNameIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);

        RenderedClause::on('f', 'plugin')->withParam('0', 'x');
    }

    /**
     * Registration is a copy, like every other option: a Formatter already
     * built cannot gain an extension behind its back.
     */
    public function testOptionsStayImmutable(): void
    {
        $options = Options::create();
        $extended = $options->withClauseRenderer('sltr', $this->sltrRenderer());

        self::assertSame([], $options->clauseRenderers());
        self::assertArrayHasKey('sltr', $extended->clauseRenderers());
    }

    private function formatterWithSltr(): Formatter
    {
        return Formatter::create(
            Options::create()->withClauseRenderer('sltr', $this->sltrRenderer()),
        );
    }

    private function sltrRenderer(): ClauseRenderer
    {
        return new class implements ClauseRenderer {
            public function render(array $body): ?RenderedClause
            {
                // Plain array access, not the library's internal helpers: a
                // renderer lives in someone else's codebase and must only need
                // the public surface.
                $model = $body['model'] ?? null;
                if (!is_string($model)) {
                    return null;
                }

                // Learning-to-Rank rescores the whole result set rather than
                // running on a field, but which model did it is worth reading.
                return RenderedClause::on('_score', 'sltr')->withParam('model', $model);
            }
        };
    }
}
