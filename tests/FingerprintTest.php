<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    public function testValuesAreErasedButShapeIsKept(): void
    {
        $formatter = Formatter::create();

        $first = $formatter->describe(['query' => ['bool' => ['filter' => [
            ['term' => ['service' => 'api']],
            ['range' => ['@timestamp' => ['gte' => 'now-15m']]],
        ]]]]);

        $second = $formatter->describe(['query' => ['bool' => ['filter' => [
            ['term' => ['service' => 'worker']],
            ['range' => ['@timestamp' => ['gte' => 'now-7d']]],
        ]]]]);

        self::assertSame($first->hash(), $second->hash());
        self::assertSame('q=(@timestamp >= ? and service:?)', $first->signature());
        self::assertNotSame($first->text(), $second->text());
    }

    public function testCardinalityIsKeptAtValueLevelAndErasedAtStructuralLevel(): void
    {
        $two = ['query' => ['terms' => ['status' => [500, 502]]]];
        $three = ['query' => ['terms' => ['status' => [500, 502, 503]]]];

        $values = Formatter::create();
        self::assertNotSame(
            $values->describe($two)->hash(),
            $values->describe($three)->hash(),
            'At the "values" level the number of terms is part of the shape.',
        );

        $structural = Formatter::create(
            Options::create()->withNormalization(Normalization::structural()),
        );
        self::assertSame(
            $structural->describe($two)->hash(),
            $structural->describe($three)->hash(),
        );
    }

    public function testPaginationOnlyDiffersAtValueLevel(): void
    {
        $first = ['query' => ['match_all' => []], 'size' => 20, 'from' => 0];
        $second = ['query' => ['match_all' => []], 'size' => 20, 'from' => 40];

        $values = Formatter::create();
        self::assertNotSame($values->describe($first)->hash(), $values->describe($second)->hash());

        $structural = Formatter::create(
            Options::create()->withNormalization(Normalization::structural()),
        );
        self::assertSame($structural->describe($first)->hash(), $structural->describe($second)->hash());
    }

    public function testSizeZeroSurvivesStructuralNormalization(): void
    {
        // "aggregations only" is a different kind of query from "give me hits".
        $structural = Formatter::create(
            Options::create()->withNormalization(Normalization::structural()),
        );

        $aggsOnly = $structural->describe(['query' => ['match_all' => []], 'size' => 0]);
        $withHits = $structural->describe(['query' => ['match_all' => []], 'size' => 50]);

        self::assertStringContainsString('size=0', $aggsOnly->signature());
        self::assertStringContainsString('size=?', $withHits->signature());
        self::assertNotSame($aggsOnly->hash(), $withHits->hash());
    }

    public function testTruncationNeverAffectsTheHash(): void
    {
        $request = ['query' => ['terms' => ['status' => range(1, 40)]]];

        $capped = Formatter::create(Options::create()->withMaxValues(3));
        $uncapped = Formatter::create(Options::create()->withMaxValues(null));

        self::assertSame($capped->describe($request)->hash(), $uncapped->describe($request)->hash());
        self::assertStringContainsString('+37', $capped->describe($request)->text());
    }

    public function testHashCarriesItsAlgorithmVersion(): void
    {
        $digest = Formatter::create()->describe(['query' => ['match_all' => []]]);

        self::assertMatchesRegularExpression('/^q4:[0-9a-f]{12}$/', $digest->hash());
    }

    public function testHashLengthIsConfigurable(): void
    {
        $formatter = Formatter::create(Options::create()->withHashLength(8)->withHashVersion('q9'));
        $digest = $formatter->describe(['query' => ['match_all' => []]]);

        self::assertMatchesRegularExpression('/^q9:[0-9a-f]{8}$/', $digest->hash());
    }

    public function testRedactorKeepsSensitiveValuesOutOfTheReadableLine(): void
    {
        $formatter = Formatter::create(Options::create()->withRedactor(
            static fn(string $field, $value) => $field === 'email' ? '<redacted>' : $value,
        ));

        $digest = $formatter->describe(['query' => ['bool' => ['filter' => [
            ['term' => ['email' => 'denis@example.org']],
            ['term' => ['env' => 'prod']],
        ]]]]);

        self::assertStringNotContainsString('denis@example.org', $digest->text());
        self::assertStringContainsString('<redacted>', $digest->text());
        self::assertStringContainsString('env:prod', $digest->text());
    }
}
