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
        self::assertSame('logs-*', $first['idx'] ?? null, 'So must the index it was logged against.');
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

    /**
     * The value forms an option can take. `--sort p95` walks the argument list
     * forward, and a step in the wrong direction reads the flag's own name back
     * as its value.
     */
    public function testAnOptionTakesItsValueAsASeparateArgument(): void
    {
        [$status, $out] = $this->invoke(['--sort', 'count'], self::FLAT . "\n" . self::OTHER . "\n");

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('count*', $out, 'The starred column says what the ranking used.');
    }

    /** `--` ends the options, and `-` is stdin rather than a file called `-`. */
    public function testTheSeparatorAndStdinAreNotMistakenForOptions(): void
    {
        [$status, $out] = $this->invoke(['--', '-'], self::FLAT . "\n");

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('1 record, 1 shape', $out);
    }

    public function testAFileIsReadAndClosed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'report');
        self::assertIsString($file);
        file_put_contents($file, self::FLAT . "\n" . self::OTHER . "\n");

        [$status, $out] = $this->invoke([$file]);
        unlink($file);

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('2 lines, 2 records, 2 shapes', $out);
    }

    public function testAnUnreadableFileIsAUsageError(): void
    {
        [$status, , $err] = $this->invoke([__DIR__ . '/no-such-file.log']);

        self::assertSame(Command::USAGE, $status);
        self::assertStringContainsString('cannot read', $err);
    }

    /**
     * A duration is whatever the collector's JSON encoder made of it. Ignoring
     * `"took": "12"` would rank a whole file at zero without saying why, and
     * taking `"took": "soon"` would rank it at nonsense.
     */
    public function testADurationIsReadFromANumberOrANumericString(): void
    {
        $records = [
            '{"dsl_hash":"q5:aaaa0000aaaa","dsl_sig":"q=(a:?)","took":"12"}',
            '{"dsl_hash":"q5:bbbb0000bbbb","dsl_sig":"q=(b:?)","took":8.5}',
            '{"dsl_hash":"q5:cccc0000cccc","dsl_sig":"q=(c:?)","took":"soon"}',
            '{"dsl_hash":"q5:dddd0000dddd","dsl_sig":"q=(d:?)","took":true}',
        ];

        [$status, $out] = $this->invoke(['--json'], implode("\n", $records) . "\n");
        self::assertSame(Command::OK, $status);

        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);

        $totals = [];
        $measured = [];
        foreach ($decoded as $shape) {
            self::assertIsArray($shape);
            $hash = $shape['hash'] ?? null;
            self::assertIsString($hash);
            $totals[$hash] = $shape['total_ms'] ?? null;
            $measured[$hash] = $shape['measured'] ?? null;
        }

        // JSON has one number type, so a whole total comes back as an int.
        self::assertEquals(12.0, $totals['q5:aaaa0000aaaa'] ?? null);
        self::assertSame(8.5, $totals['q5:bbbb0000bbbb'] ?? null);

        // Counted, and timed by nothing: `measured` is what says so.
        self::assertSame(1, $measured['q5:aaaa0000aaaa'] ?? null);
        self::assertSame(0, $measured['q5:cccc0000cccc'] ?? null, 'A duration that is not a number is no duration.');
        self::assertSame(0, $measured['q5:dddd0000dddd'] ?? null, 'A boolean is not a duration.');
    }

    /** An empty key is a missing field, not a field whose value is `""`. */
    public function testAnEmptyValueIsTreatedAsAbsent(): void
    {
        $record = '{"dsl_hash":"q5:eeee0000eeee","dsl_sig":"","dsl_q":"q=(a:prod)","dsl_kind":""}';

        [, $out] = $this->invoke(['--json'], $record . "\n");

        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[0]);
        // No signature, so the readable line stands in for it rather than an
        // empty column — and an empty kind is no kind at all.
        self::assertSame('q=(a:prod)', $decoded[0]['sig'] ?? null);
        self::assertSame('unknown', $decoded[0]['kind'] ?? null);
    }

    /**
     * With neither, the hash is the only thing left that identifies the shape,
     * and an empty column would say less than repeating it.
     */
    public function testARecordWithNeitherSignatureNorLineFallsBackToItsHash(): void
    {
        [, $out] = $this->invoke(['--json'], '{"dsl_hash":"q5:ffff0000ffff"}' . "\n");

        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[0]);
        self::assertSame('q5:ffff0000ffff', $decoded[0]['sig'] ?? null);
    }

    /** The default text key is the digest's own name for it, not the flag's. */
    public function testTheReadableLineIsReadFromThePrefixedDigestField(): void
    {
        [, $out] = $this->invoke(['--json'], self::FLAT . "\n");

        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[0]);
        $slowest = $decoded[0]['slowest'] ?? null;
        self::assertIsArray($slowest);
        self::assertSame('logs-* | q=(service:api)', $slowest['text'] ?? null);
    }

    /** A literal key wins over the same name read as a path. */
    public function testAKeyIsTriedLiterallyBeforeItIsWalked(): void
    {
        $record = '{"dsl.hash":"q5:1111ffff1111","dsl.sig":"q=(z:?)"}';

        [$status, $out] = $this->invoke(['--key-prefix=dsl.'], $record . "\n");

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('q5:1111ffff1111', $out);
    }

    public function testTopKeepsExactlyWhatWasAskedFor(): void
    {
        [, $one] = $this->invoke(['--top=1'], self::FLAT . "\n" . self::OTHER . "\n");

        self::assertStringContainsString('q5:aaaabbbbcccc', $one);
        self::assertStringNotContainsString('q5:ddddeeeeffff', $one);
        self::assertStringContainsString('1 more shape', $one);
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
