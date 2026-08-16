<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Options;
use MrDlef\OsQueryDigest\Support\IndexNormalizer;
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
