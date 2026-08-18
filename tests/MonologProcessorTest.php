<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Monolog\DigestProcessor;
use MrDlef\OsQueryDigest\Monolog\SafeDigest;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * The Monolog integration, driven through a real `Logger` rather than by
 * hand-building records.
 *
 * That is deliberate: Monolog 2 passes processors an array and Monolog 3 passes
 * a `LogRecord`, and the whole point of the processor is that it copes with
 * both. Going through `Logger` means these tests exercise whichever version the
 * PHP under test resolved — Monolog 2 on 7.4 and 8.0, Monolog 3 from 8.1 — with
 * no branching here. `LogRecord` implements `ArrayAccess`, so reading a record
 * back looks the same either way.
 */
final class MonologProcessorTest extends TestCase
{
    private TestHandler $handler;

    private Logger $logger;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->logger = new Logger('test', [$this->handler]);
    }

    public function testTheRequestIsReplacedByItsDigest(): void
    {
        $this->logger->pushProcessor(new DigestProcessor());
        $this->logger->info('opensearch.search', [
            'query' => ['query' => ['term' => ['env' => 'prod']], 'size' => 10],
            'index' => 'logs-2026.08.16',
            'took' => 12,
        ]);

        $digest = $this->digested();

        self::assertSame('logs-*', $digest['idx']);
        self::assertSame('logs-* | q=(env:prod) | size=10', $digest['q']);
        self::assertSame('logs-* | q=(env:?) | size=10', $digest['sig']);
        self::assertMatchesRegularExpression('/^q3:[0-9a-f]{12}$/', self::text($digest, 'hash'));
    }

    /**
     * A processor that reorganised the context would break every other field
     * someone already logs beside the query.
     */
    public function testTheRestOfTheContextIsLeftAlone(): void
    {
        $this->logger->pushProcessor(new DigestProcessor());
        $this->logger->info('opensearch.search', [
            'query' => ['query' => ['match_all' => []]],
            'index' => 'logs-2026.08.16',
            'took' => 12,
            'user' => 'alice',
        ]);

        $context = $this->context();

        self::assertSame(12, $context['took']);
        self::assertSame('alice', $context['user']);
        self::assertSame('logs-2026.08.16', $context['index']);
    }

    public function testTheEnvelopeFormCarriesItsOwnIndex(): void
    {
        $this->logger->pushProcessor(new DigestProcessor());
        $this->logger->info('opensearch.search', [
            'query' => [
                'index' => 'orders-2026.08',
                'body' => ['query' => ['term' => ['status' => 'paid']]],
            ],
        ]);

        self::assertSame('orders-*', $this->digested()['idx']);
    }

    public function testJsonIsAcceptedJustLikeAnArray(): void
    {
        $this->logger->pushProcessor(new DigestProcessor());
        $this->logger->info('opensearch.search', [
            'query' => '{"query":{"term":{"env":"prod"}}}',
        ]);

        self::assertSame('q=(env:prod)', $this->digested()['q']);
    }

    /**
     * Nothing else in a context looks like a search request, and a processor
     * that guessed would corrupt someone's log line.
     */
    public function testAContextWithoutARequestIsUntouched(): void
    {
        $this->logger->pushProcessor(new DigestProcessor());
        $this->logger->info('cache.miss', ['key' => 'user:42', 'ttl' => 300]);

        self::assertSame(['key' => 'user:42', 'ttl' => 300], $this->context());
    }

    public function testANonRequestValueUnderTheKeyIsLeftAsItIs(): void
    {
        $this->logger->pushProcessor(new DigestProcessor());
        $this->logger->info('opensearch.search', ['query' => 42]);

        self::assertSame(['query' => 42], $this->context());
    }

    public function testTheKeysAreConfigurable(): void
    {
        $this->logger->pushProcessor(new DigestProcessor(null, 'search_body', 'target'));
        $this->logger->info('opensearch.search', [
            'search_body' => ['query' => ['term' => ['env' => 'prod']]],
            'target' => 'logs-2026.08.16',
        ]);

        $context = $this->context();

        self::assertInstanceOf(SafeDigest::class, $context['search_body']);
        self::assertSame('logs-*', $this->digested('search_body')['idx']);
    }

    public function testItHonoursTheFormatterItWasGiven(): void
    {
        $processor = new DigestProcessor(
            Formatter::create(Options::create()->withHashVersion('app1')),
        );

        $this->logger->pushProcessor($processor);
        $this->logger->info('opensearch.search', ['query' => ['query' => ['match_all' => []]]]);

        self::assertStringStartsWith('app1:', self::text($this->digested(), 'hash'));
    }

    /**
     * The parse happens when the handler serialises, which is *after* the
     * processor has returned — so a failure there would surface while Monolog is
     * formatting and take the record with it.
     */
    public function testAnUnreadableRequestCostsTheDigestAndNotTheLogLine(): void
    {
        $this->logger->pushProcessor(new DigestProcessor());
        $this->logger->info('opensearch.search', ['query' => 'not json at all']);

        $digest = $this->digested();

        self::assertArrayHasKey('error', $digest);
        self::assertStringContainsString('could not read this request', self::text($digest, 'error'));
        self::assertCount(1, $this->handler->getRecords(), 'The record itself must survive.');
    }

    /**
     * Nothing is parsed inside the processor: a record a handler buffers and
     * later discards — a FingersCrossed that never triggers — has to cost
     * nothing, which is the reason the digest is lazy in the first place.
     *
     * Driven through a NullHandler rather than the TestHandler: that one
     * formats every record as it stores it, so it would parse the digest during
     * the log call and hide the very property under test.
     */
    public function testTheProcessorItselfNeverParses(): void
    {
        $spy = self::spy();

        $logger = new Logger('lazy', [new NullHandler()]);
        $logger->pushProcessor(new DigestProcessor(self::formatterWatchedBy($spy)));
        $logger->info('opensearch.search', ['query' => ['query' => ['term' => ['env' => 'prod']]]]);

        self::assertFalse($spy['parsed'], 'The processor parsed the request eagerly.');
    }

    public function testSerialisingIsWhatParses(): void
    {
        $spy = self::spy();
        $processor = new DigestProcessor(self::formatterWatchedBy($spy));

        $record = $processor(['context' => ['query' => ['query' => ['term' => ['env' => 'prod']]]]]);
        self::assertIsArray($record);
        $context = $record['context'];
        self::assertIsArray($context);

        self::assertFalse($spy['parsed']);

        json_encode($context['query']);

        self::assertTrue($spy['parsed'], 'Serialising the digest should have parsed the request.');
    }

    /**
     * A holder rather than a by-reference boolean: static analysis cannot follow
     * a `use (&$flag)` across a closure, and would call the later assertion
     * unreachable.
     *
     * @return \ArrayObject<string,bool>
     */
    private static function spy(): \ArrayObject
    {
        return new \ArrayObject(['parsed' => false]);
    }

    /**
     * A formatter whose redactor trips the spy. The redactor is the deepest
     * point of the parse, so nothing reaches it without the request having been
     * read.
     *
     * @param \ArrayObject<string,bool> $spy
     */
    private static function formatterWatchedBy(\ArrayObject $spy): Formatter
    {
        return Formatter::create(
            Options::create()->withRedactor(static function (string $field, $value) use ($spy) {
                $spy['parsed'] = true;

                return $value;
            }),
        );
    }

    public function testTheDigestAlsoReadsAsAPlainLine(): void
    {
        $this->logger->pushProcessor(new DigestProcessor());
        $this->logger->info('opensearch.search', ['query' => ['query' => ['term' => ['env' => 'prod']]]]);

        $context = $this->context();
        self::assertInstanceOf(SafeDigest::class, $context['query']);
        self::assertSame('q=(env:prod)', (string) $context['query']);
    }

    /**
     * @param array<string,mixed> $digest
     */
    private static function text(array $digest, string $field): string
    {
        $value = $digest[$field] ?? null;
        self::assertIsString($value, $field . ' should have been a string.');

        return $value;
    }

    /**
     * @return array<string,mixed>
     */
    private function context(): array
    {
        $records = $this->handler->getRecords();
        self::assertCount(1, $records);

        // LogRecord implements ArrayAccess, so this reads a Monolog 3 record and
        // a Monolog 2 array the same way.
        $context = $records[0]['context'];
        self::assertIsArray($context);

        $out = [];
        foreach ($context as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function digested(string $key = 'query'): array
    {
        $value = $this->context()[$key] ?? null;
        self::assertInstanceOf(SafeDigest::class, $value);

        $decoded = json_decode((string) json_encode($value), true);
        self::assertIsArray($decoded);

        $out = [];
        foreach ($decoded as $name => $item) {
            $out[(string) $name] = $item;
        }

        return $out;
    }
}
