<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Digest;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\LazyDigest;
use MrDlef\OsQueryDigest\Options;
use MrDlef\OsQueryDigest\RecordLayout;
use PHPUnit\Framework\TestCase;

/**
 * A digest goes into a log record as one object or as sibling keys, and the
 * choice belongs to whoever collects the logs: some platforms cannot group or
 * chart on a node below the top level.
 *
 * The two spellings are one contract, so what is asserted here is mostly that
 * they agree — and that the flat one keeps the property the nested one was
 * built for, which is that nothing is parsed until something reads it.
 */
final class RecordLayoutTest extends TestCase
{
    private const SEARCH = ['query' => ['term' => ['service' => 'api']], 'size' => 20];

    /** Two notes, so the join between them is what the assertion sees. */
    private const ANNOTATED = [
        'query' => ['term' => ['service' => 'api']],
        'highlight' => ['fields' => ['message' => []]],
        'collapse' => ['field' => 'host'],
    ];

    public function testNestedPutsTheDigestUnderOneKey(): void
    {
        $context = RecordLayout::nested()->apply(['took' => 12], self::digest());

        self::assertSame(['took', 'dsl'], array_keys($context));

        $decoded = self::decode($context);
        self::assertIsArray($decoded['dsl']);
        self::assertSame(['idx', 'kind', 'q', 'sig', 'hash'], array_keys($decoded['dsl']));
    }

    public function testFlatPutsOneKeyPerField(): void
    {
        $context = RecordLayout::flat()->apply(['took' => 12], self::digest());

        self::assertSame(
            ['took', 'dsl_idx', 'dsl_kind', 'dsl_q', 'dsl_sig', 'dsl_hash', 'dsl_notes', 'dsl_error'],
            array_keys($context),
        );

        $decoded = self::decode($context);
        foreach ($decoded as $value) {
            self::assertIsNotArray($value, 'A flat record must hold no node of its own.');
        }
    }

    /**
     * The claim the whole feature rests on: `dsl.hash` and `dsl_hash` are the
     * same value under two spellings, not two contracts.
     */
    public function testBothSpellingsCarryTheSameValues(): void
    {
        $nested = self::decode(RecordLayout::nested()->apply([], self::digest()));
        $flat = self::decode(RecordLayout::flat()->apply([], self::digest()));

        self::assertIsArray($nested['dsl']);
        foreach ($nested['dsl'] as $field => $value) {
            self::assertSame($value, $flat['dsl_' . $field], 'The two layouts disagree about ' . $field . '.');
        }
    }

    public function testTheKeyAndThePrefixAreTheCallersToChoose(): void
    {
        $nested = RecordLayout::nested('search')->apply([], self::digest());
        $flat = RecordLayout::flat('q-')->apply([], self::digest());

        self::assertArrayHasKey('search', $nested);
        self::assertArrayHasKey('q-hash', $flat);
    }

    /**
     * A flat record puts a value under seven keys, and seven eager values would
     * have been seven times the reason the digest is lazy. One parse, and only
     * once something reads the record.
     */
    public function testAFlatRecordIsStillLazyAndParsesOnce(): void
    {
        $calls = new class {
            public int $n = 0;
        };
        $lazy = new LazyDigest(static function () use ($calls): Digest {
            $calls->n++;

            return Formatter::create()->describe(self::SEARCH);
        });

        $context = RecordLayout::flat()->apply([], $lazy);
        self::assertSame(0, $calls->n, 'Laying the fields out parsed the request.');

        json_encode($context);
        self::assertSame(1, $calls->n, 'Seven keys must not mean seven parses.');
    }

    /**
     * Same trade as the nested layout: losing the digest is acceptable, losing
     * the record is not — and a request that cannot be read must not be read
     * seven times before the record gives up on it.
     */
    public function testAnUnreadableRequestFillsTheErrorFieldOnceAndNothingElse(): void
    {
        $calls = new class {
            public int $n = 0;
        };
        $lazy = new LazyDigest(static function () use ($calls): void {
            $calls->n++;

            throw new \RuntimeException('nope');
        });

        $decoded = self::decode(RecordLayout::flat()->apply([], $lazy));

        self::assertSame(1, $calls->n, 'A broken request must be attempted once, not once per field.');
        self::assertIsString($decoded['dsl_error']);
        self::assertStringContainsString('could not read this request', $decoded['dsl_error']);
        foreach (['dsl_idx', 'dsl_kind', 'dsl_q', 'dsl_sig', 'dsl_hash', 'dsl_notes'] as $key) {
            self::assertNull($decoded[$key], $key . ' should be empty on a request that could not be read.');
        }
    }

    /**
     * The one field a flat layout cannot carry as it stands. Joined rather than
     * dropped: a collector that cannot query a nested object cannot query an
     * array either, and the notes are worth more as a string than as nothing.
     */
    public function testNotesAreJoinedIntoOneString(): void
    {
        $withNotes = Formatter::create()->lazy(self::ANNOTATED);

        $nested = self::decode(RecordLayout::nested()->apply([], $withNotes));
        $flat = self::decode(RecordLayout::flat()->apply([], $withNotes));

        self::assertIsArray($nested['dsl']);
        $notes = $nested['dsl']['notes'];
        self::assertIsArray($notes, 'This request is meant to produce notes.');
        self::assertGreaterThan(1, count($notes), 'One note would not exercise the join.');

        $strings = [];
        foreach ($notes as $note) {
            self::assertIsString($note);
            $strings[] = $note;
        }

        self::assertSame(implode('; ', $strings), $flat['dsl_notes']);
    }

    public function testTheTextFieldIsEmptyWhenTheDigestEmitsNone(): void
    {
        $lazy = Formatter::create(Options::create()->withText(false))->lazy(self::SEARCH);

        $decoded = self::decode(RecordLayout::flat()->apply([], $lazy));

        self::assertNull($decoded['dsl_q']);
        self::assertIsString($decoded['dsl_sig']);
    }

    /**
     * A flat layout names its keys before anything is parsed, so the list is
     * written down — and a field added to the digest without a line in it would
     * be silently dropped from every flat record.
     */
    public function testTheFlatKeysCoverEveryFieldADigestCanEmit(): void
    {
        $formatter = Formatter::create();
        $emitted = [];
        foreach ([
            self::SEARCH,
            self::ANNOTATED,
        ] as $request) {
            $emitted = array_merge($emitted, array_keys($formatter->describe($request)->toArray()));
        }

        self::assertSame(
            [],
            array_diff(array_unique($emitted), RecordLayout::FIELDS),
            'A digest emits a field no flat record would carry.',
        );
    }

    private static function digest(): LazyDigest
    {
        return Formatter::create()->lazy(self::SEARCH);
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private static function decode(array $context): array
    {
        $encoded = json_encode($context);
        self::assertIsString($encoded);

        $decoded = json_decode($encoded, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
