<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Exception\InvalidOptionException;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\IndexNormalizer;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

final class IndexNormalizerTest extends TestCase
{
    public function testNormalize(): void
    {
        $cases = [
            'dotted date' => ['logs-2026.08.13', 'logs-*'],
            'dashed date' => ['logs-2026-08-13', 'logs-*'],
            'compact date' => ['logs-20260813', 'logs-*'],
            'rollover suffix' => ['metrics-000042', 'metrics-*'],
            'date and rollover' => ['logs-2026.08.13-000001', 'logs-*'],
            'version segment survives' => ['catalog-v2', 'catalog-v2'],
            'already a pattern' => ['logs-*', 'logs-*'],
            'no date at all' => ['products', 'products'],
            'multi index collapses to one' => ['logs-2026.08.12,logs-2026.08.13', 'logs-*'],
            'multi index stays sorted' => ['b-index,a-index', 'a-index,b-index'],
        ];

        $normalizer = IndexNormalizer::datePatterns();
        $expected = [];
        $actual = [];

        foreach ($cases as $label => $case) {
            $expected[$label] = $case[1];
            $actual[$label] = $normalizer->normalize($case[0]);
        }

        self::assertSame($expected, $actual);
    }

    public function testRollingIndicesShareAFingerprint(): void
    {
        $formatter = Formatter::create();
        $request = ['query' => ['term' => ['service' => 'api']]];

        $monday = $formatter->describe($request, 'logs-2026.08.13');
        $tuesday = $formatter->describe($request, 'logs-2026.08.14');

        self::assertSame('logs-*', $monday->index());
        self::assertSame($monday->hash(), $tuesday->hash());
    }

    /**
     * The blue/green reindex pattern: the alias moves, the physical index
     * carries a hash of the mapping. Every mapping change would otherwise mint
     * a fresh fingerprint for every shape — which is the thing this class exists
     * to prevent, for the one kind of suffix it cannot recognise on its own.
     */
    public function testACustomRuleCollapsesWhatTheShippedOneCannot(): void
    {
        $normalizer = IndexNormalizer::custom(
            static fn(string $index): string => (string) preg_replace('/_[0-9a-f]{32}$/', '', $index),
        );

        $cases = [
            // The rule strips the mapping hash; the shipped rule then collapses
            // the tenant number, which is the composition being asserted.
            'mapping hash and tenant' => [
                'tenant_0178_members_4f171971a955af948fae1c7a964c49b8',
                'tenant_*_members',
            ],
            'a different mapping, the same shape' => [
                'tenant_0178_members_0000000000000000000000000000ffff',
                'tenant_*_members',
            ],
            'no suffix to strip' => ['tenant_0178_members', 'tenant_*_members'],
            'the shipped rules still run' => ['logs-2026.08.13', 'logs-*'],
            'and still leave a version alone' => ['products-v3', 'products-v3'],
            'two tenants of one shape are one name' => [
                'tenant_0178_members_4f171971a955af948fae1c7a964c49b8'
                    . ',tenant_0179_members_4f171971a955af948fae1c7a964c49b8',
                'tenant_*_members',
            ],
        ];

        $expected = [];
        $actual = [];

        foreach ($cases as $label => $case) {
            $expected[$label] = $case[1];
            $actual[$label] = $normalizer->normalize($case[0]);
        }

        self::assertSame($expected, $actual);
    }

    public function testACustomRuleHoldsTheFingerprintAcrossAReindex(): void
    {
        $formatter = Formatter::create(Options::create()->withIndexNormalizer(
            IndexNormalizer::custom(
                static fn(string $index): string => (string) preg_replace('/_[0-9a-f]{32}$/', '', $index),
            ),
        ));
        $request = ['query' => ['term' => ['status' => 'active']], 'size' => 20];

        $before = $formatter->describe($request, 'tenant_0178_members_4f171971a955af948fae1c7a964c49b8');
        $after = $formatter->describe($request, 'tenant_0178_members_0000000000000000000000000000ffff');

        self::assertSame('tenant_*_members', $before->index());
        self::assertSame($before->hash(), $after->hash());

        // And the default still splits them, which is the bug being fixed.
        $stock = Formatter::create();
        self::assertNotSame(
            $stock->describe($request, 'tenant_0178_members_4f171971a955af948fae1c7a964c49b8')->hash(),
            $stock->describe($request, 'tenant_0178_members_0000000000000000000000000000ffff')->hash(),
        );
    }

    /**
     * A rule is not trusted to behave: it runs in a logging path, where a
     * TypeError out of someone's closure would cost the log line rather than
     * the digest. Anything that is not a scalar reads as an erased name.
     */
    public function testACustomRuleThatReturnsNonsenseCostsOnlyTheName(): void
    {
        $erased = IndexNormalizer::custom(static fn(string $index) => null);

        self::assertSame('', $erased->normalize('logs-2026.08.13'));
        self::assertSame('', $erased->normalize('a,b'));

        // Nor to come back tidy: a name padded by a sloppy closure would
        // otherwise reach the collapsing rules — and the digest — with its
        // whitespace on.
        $padded = IndexNormalizer::custom(static fn(string $index): string => '  ' . $index . ' ');
        self::assertSame('logs-*', $padded->normalize('logs-2026.08.13'));
        self::assertSame('', IndexNormalizer::custom(static fn(string $index): string => '   ')
            ->normalize('logs-2026.08.13'));

        // A rule may legitimately erase one name out of a list.
        $onlyLogs = IndexNormalizer::custom(
            static fn(string $index): string => strpos($index, 'logs') === 0 ? $index : '',
        );

        self::assertSame('logs-*', $onlyLogs->normalize('logs-2026.08.13,audit-2026.08.13'));
    }

    /**
     * A callable has no array form, so `custom` is not a mode a configuration
     * file can name — the same line {@see Options::withRedactor()} sits on.
     */
    public function testCustomIsNotAModeAConfigurationCanName(): void
    {
        self::assertSame(['date-patterns', 'identity'], IndexNormalizer::MODES);

        $this->expectException(InvalidOptionException::class);
        IndexNormalizer::fromMode('custom');
    }

    /**
     * Pinned because it is surprising and it is *why* the hook is needed rather
     * than a third shipped mode: the date rule matches eight digits with no
     * separators — `20260813` is a real index name — so a mapping hash with a
     * long digit run is collapsed *in part*, and a name that merely looks
     * collapsed is worse than one that does not.
     */
    public function testTheShippedRuleMangesAHashThatBeginsWithDigits(): void
    {
        $shipped = IndexNormalizer::datePatterns();

        self::assertSame(
            'tenant_*_members_4f171971a955af948fae1c7a964c49b8',
            $shipped->normalize('tenant_0178_members_4f171971a955af948fae1c7a964c49b8'),
            'A hash of mostly letters survives whole.',
        );
        self::assertSame(
            'tenant_*_members_*9999aaaa',
            $shipped->normalize('tenant_0179_members_9999999999999999999999999999aaaa'),
            'One of mostly digits does not — so the fingerprint depends on the hash.',
        );
    }

    public function testNormalizationCanBeDisabled(): void
    {
        $formatter = Formatter::create(
            Options::create()->withIndexNormalizer(IndexNormalizer::identity()),
        );
        $request = ['query' => ['term' => ['service' => 'api']]];

        self::assertNotSame(
            $formatter->describe($request, 'logs-2026.08.13')->hash(),
            $formatter->describe($request, 'logs-2026.08.14')->hash(),
        );
    }
}
