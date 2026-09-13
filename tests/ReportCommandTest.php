<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Cli\Command;
use PHPUnit\Framework\TestCase;

/**
 * `report` ranks digests it did not mint.
 *
 * That is the whole difference from the other two sub-commands, and it is what
 * most of this asserts: the stored fingerprint is the one records are grouped
 * by, whatever key it was written under, and nothing on the line is re-parsed.
 */
final class ReportCommandTest extends TestCase
{
    private const FLAT = '{"dsl_idx":"logs-*","dsl_kind":"browse","dsl_q":"logs-* | q=(service:api)",'
        . '"dsl_sig":"logs-* | q=(service:?)","dsl_hash":"q5:aaaabbbbcccc","took":12}';

    private const FLAT_SLOWER = '{"dsl_idx":"logs-*","dsl_kind":"browse","dsl_q":"logs-* | q=(service:worker)",'
        . '"dsl_sig":"logs-* | q=(service:?)","dsl_hash":"q5:aaaabbbbcccc","took":30}';

    private const OTHER = '{"dsl_idx":"logs-*","dsl_kind":"aggregate","dsl_sig":"logs-* | q=(host:?)",'
        . '"dsl_hash":"q5:ddddeeeeffff","took":5}';

    public function testItGroupsByTheFingerprintTheRecordsAlreadyCarry(): void
    {
        [$status, $out] = $this->invoke([], self::FLAT . "\n" . self::FLAT_SLOWER . "\n" . self::OTHER . "\n");

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('3 lines, 3 records, 2 shapes, 47 ms total', $out);
        self::assertStringContainsString('q5:aaaabbbbcccc', $out);
        self::assertStringContainsString('logs-* | q=(service:?)', $out);
    }

    /**
     * A log file holds more than searches. Refusing the file over the lines
     * that are not searches would make the tool useless exactly where it is
     * pointed.
     */
    public function testLinesThatCarryNoFingerprintAreSkippedInSilence(): void
    {
        $stdin = "not json at all\n" . self::FLAT . "\n{\"message\":\"unrelated\"}\n";

        [$status, $out, $err] = $this->invoke([], $stdin);

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('3 lines, 1 record, 1 shape', $out);
        self::assertSame('', $err);
    }

    /** Whatever the collector wrote in front of the JSON is not ours to model. */
    public function testAPrefixBeforeTheJsonIsIgnored(): void
    {
        [, $out] = $this->invoke([], 'host app[123]: ' . self::FLAT . "\n");

        self::assertStringContainsString('1 record, 1 shape', $out);
    }

    /**
     * The nested spelling this library writes by default, read by pointing the
     * prefix at the object rather than by teaching the reader a second shape.
     */
    public function testADottedPrefixReadsTheNestedRecord(): void
    {
        $nested = '{"dsl":{"idx":"logs-*","kind":"browse","sig":"logs-* | q=(service:?)",'
            . '"hash":"q5:111122223333"},"took":9}';

        [$status, $out] = $this->invoke(['--key-prefix=dsl.'], $nested . "\n");

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('q5:111122223333', $out);
        self::assertStringContainsString('9', $out);
    }

    /** An application that flattened the digest itself named its own keys. */
    public function testEveryKeyCanBeNamedOneAtATime(): void
    {
        $record = '{"q_hash":"q5:999988887777","q_sig":"q=(env:?)","q":"q=(env:prod)","duration_ms":42}';

        [$status, $out] = $this->invoke(
            ['--key-prefix=q_', '--text-key=q', '--took-key=duration_ms'],
            $record . "\n",
        );

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('q5:999988887777', $out);
        self::assertStringContainsString('42', $out);
    }

    /**
     * Ranking by total time on a file with no durations makes every total zero,
     * which reads as a broken report rather than a missing field.
     */
    public function testAFileWithoutDurationsSaysSoRatherThanRankingEverythingAtZero(): void
    {
        [$status, $out] = $this->invoke([], '{"dsl_hash":"q5:aaaa1111bbbb","dsl_sig":"q=(a:?)"}' . "\n");

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('no durations', $out);
        self::assertStringContainsString('--took-key', $out);
    }

    public function testAFileWithNoDigestSaysWhatItExpectedAndWhereElseToLook(): void
    {
        [$status, , $err] = $this->invoke([], "nothing here\n");

        self::assertSame(Command::INVALID_INPUT, $status);
        self::assertStringContainsString('dsl_hash', $err);
        self::assertStringContainsString('--ndjson', $err);
        self::assertStringContainsString('slowlog', $err);
    }

    public function testTheJsonOutputCarriesTheRanking(): void
    {
        [$status, $out] = $this->invoke(['--json'], self::FLAT . "\n" . self::OTHER . "\n");

        self::assertSame(Command::OK, $status);

        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);

        $first = $decoded[0];
        self::assertIsArray($first);
        self::assertSame('q5:aaaabbbbcccc', $first['hash'] ?? null);
        self::assertSame('browse', $first['kind'] ?? null, 'The logged kind must survive into the report.');
    }

    /**
     * A kind minted by a release this one does not know must not stop a report
     * about the shapes beside it.
     */
    public function testAnUnknownKindIsReadAsUnknown(): void
    {
        $record = '{"dsl_hash":"q5:cccc2222dddd","dsl_sig":"q=(a:?)","dsl_kind":"telepathy"}';

        [, $out] = $this->invoke(['--json'], $record . "\n");

        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[0]);
        self::assertSame('unknown', $decoded[0]['kind'] ?? null);
    }

    public function testSortAndTopAreValidated(): void
    {
        [$sorted] = $this->invoke(['--sort=nope'], self::FLAT . "\n");
        [$topped] = $this->invoke(['--top=0'], self::FLAT . "\n");

        self::assertSame(Command::USAGE, $sorted);
        self::assertSame(Command::USAGE, $topped);
    }

    public function testHelpNamesTheDefaultPrefix(): void
    {
        [$status, $out] = $this->invoke(['--help']);

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('--key-prefix', $out);
        self::assertStringContainsString('dsl_', $out);
    }

    /**
     * @param array<int,string> $argv
     *
     * @return array{0:int,1:string,2:string}
     */
    private function invoke(array $argv, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);

        fwrite($in, $stdin);
        rewind($in);

        $status = (new Command($in, $out, $err))->run(array_merge(['os-query-digest', 'report'], $argv));

        rewind($out);
        rewind($err);

        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
