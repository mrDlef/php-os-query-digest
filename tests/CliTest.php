<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use Composer\Autoload\ClassLoader;
use MrDlef\OsQueryDigest\Cli\Command;
use PHPUnit\Framework\TestCase;

/**
 * The CLI, driven in-process through memory streams. Only one test spawns a
 * real process — the shim that finds the autoloader, which is the one part
 * in-process tests cannot cover.
 */
final class CliTest extends TestCase
{
    private const BODY = '{"query":{"bool":{"filter":['
        . '{"term":{"service":"api"}},'
        . '{"range":{"@timestamp":{"gte":"now-15m","lt":"now"}}}'
        . '],"must_not":[{"term":{"status":200}}]}},"size":50,"sort":[{"@timestamp":"desc"}]}';

    /** The hash fixture 01 pins for the same body. */
    private const HASH = 'q2:fe168406e702';

    public function testTheDefaultBlockNamesTheIndexTextSignatureAndHash(): void
    {
        [$status, $out, $err] = $this->invoke(['--index=logs-2026.08.13'], self::BODY);

        self::assertSame(Command::OK, $status, $err);
        self::assertStringContainsString('idx:  logs-*', $out);
        self::assertStringContainsString('text: logs-* | q=(@timestamp >= now-15m', $out);
        self::assertStringContainsString('sig:  logs-* | q=(@timestamp >= ? and', $out);
        self::assertStringContainsString('hash: ' . self::HASH, $out);
        self::assertSame('', $err);
    }

    public function testHashPrintsNothingButTheHash(): void
    {
        [$status, $out] = $this->invoke(['--hash', '-i', 'logs-2026.08.13'], self::BODY);

        self::assertSame(Command::OK, $status);
        self::assertSame(self::HASH . "\n", $out);
    }

    public function testTheIndexCanBeGivenAsASeparateArgument(): void
    {
        [, $withEquals] = $this->invoke(['--hash', '--index=logs-2026.08.13'], self::BODY);
        [, $separate] = $this->invoke(['--hash', '--index', 'logs-2026.08.13'], self::BODY);
        [, $short] = $this->invoke(['--hash', '-i', 'logs-2026.08.13'], self::BODY);

        self::assertSame($withEquals, $separate);
        self::assertSame($withEquals, $short);
    }

    public function testJsonEmitsTheDigestObject(): void
    {
        [$status, $out] = $this->invoke(['--json', '-i', 'logs-2026.08.13'], self::BODY);

        self::assertSame(Command::OK, $status);
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertSame('logs-*', $decoded['idx'] ?? null);
        self::assertSame(self::HASH, $decoded['hash'] ?? null);
        self::assertArrayNotHasKey('rules', $decoded);
    }

    public function testExplainListsTheRulesThatFired(): void
    {
        [$status, $out] = $this->invoke(['--explain', '-i', 'logs-2026.08.13'], self::BODY);

        self::assertSame(Command::OK, $status);
        // The same four lines as without --explain, plus the table.
        self::assertStringContainsString('hash: ' . self::HASH, $out);
        self::assertStringContainsString('rules applied:', $out);
        self::assertStringContainsString('index_pattern [logs-2026.08.13 -> logs-*]', $out);
    }

    public function testExplainAsJsonCarriesTheRules(): void
    {
        [$status, $out] = $this->invoke(['--json', '--explain', '-i', 'logs-2026.08.13'], self::BODY);

        self::assertSame(Command::OK, $status);
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['rules'] ?? null);
        self::assertNotSame([], $decoded['rules']);
    }

    public function testNormalizationChangesTheFingerprint(): void
    {
        [, $default] = $this->invoke(['--hash'], self::BODY);
        [, $structural] = $this->invoke(['--hash', '-n', 'structural'], self::BODY);
        [, $none] = $this->invoke(['--hash', '--normalization=none'], self::BODY);

        self::assertNotSame($default, $structural);
        self::assertNotSame($default, $none);
    }

    public function testRawIndexKeepsTheDatedName(): void
    {
        [, $out] = $this->invoke(['--raw-index', '-i', 'logs-2026.08.13'], self::BODY);

        self::assertStringContainsString('idx:  logs-2026.08.13', $out);
    }

    public function testACapCanBeLifted(): void
    {
        $body = '{"query":{"terms":{"status":[500,502,503,504,505,506,507]}}}';

        [, $capped] = $this->invoke([], $body);
        [, $lifted] = $this->invoke(['--max-values=none'], $body);

        self::assertStringContainsString('+', $capped, 'The default cap should summarise the tail.');
        self::assertStringContainsString('507', $lifted);
    }

    public function testMaxLengthTruncatesTheLineButNotTheHash(): void
    {
        [, $full] = $this->invoke(['--json'], self::BODY);
        [, $short] = $this->invoke(['--json', '--max-length=40'], self::BODY);

        $fullDecoded = json_decode($full, true);
        $shortDecoded = json_decode($short, true);
        self::assertIsArray($fullDecoded);
        self::assertIsArray($shortDecoded);

        self::assertNotSame($fullDecoded['q'] ?? null, $shortDecoded['q'] ?? null);
        self::assertSame($fullDecoded['hash'] ?? null, $shortDecoded['hash'] ?? null);
    }

    public function testAFileIsReadWhenGivenAsAnArgument(): void
    {
        $file = __DIR__ . '/fixtures/01-error-rate-filter/input.json';

        // The fixture wraps the body in a `request` key, so this only proves the
        // file was read: the digest itself is asserted from stdin elsewhere.
        [$status, $out] = $this->invoke(['--hash', $file]);

        self::assertSame(Command::OK, $status);
        self::assertStringStartsWith('q2:', $out);
    }

    public function testNdjsonEmitsOneLinePerQuery(): void
    {
        $input = implode("\n", [
            '{"index":"logs-2026.08.13","body":{"query":{"term":{"service":"api"}},"size":10}}',
            '',
            '{"index":"logs-2026.08.14","body":{"query":{"term":{"service":"worker"}},"size":10}}',
            '{"index":"logs-2026.08.14","body":{"query":{"match":{"message":"timeout"}},"size":5}}',
        ]);

        [$status, $out, $err] = $this->invoke(['--ndjson'], $input);

        self::assertSame(Command::OK, $status, $err);
        $lines = explode("\n", trim($out));
        self::assertCount(3, $lines, 'The blank line must not produce output.');

        // The whole point: two different days and two different service values
        // are one shape.
        $hashes = [];
        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            $hashes[] = $parts[0];
            self::assertCount(2, $parts, 'Each line is `hash TAB text`.');
        }
        self::assertSame($hashes[0], $hashes[1]);
        self::assertNotSame($hashes[0], $hashes[2]);
    }

    public function testNdjsonSkipsABadLineAndStillFails(): void
    {
        $input = implode("\n", [
            '{"query":{"term":{"service":"api"}}}',
            '{not json',
            '{"query":{"term":{"service":"api"}}}',
        ]);

        [$status, $out, $err] = $this->invoke(['--ndjson', '--hash'], $input);

        self::assertSame(Command::INVALID_INPUT, $status);
        self::assertCount(2, explode("\n", trim($out)), 'The good lines must still be digested.');
        self::assertStringContainsString('line 2', $err);
    }

    public function testNdjsonJsonEmitsOneObjectPerLine(): void
    {
        $input = implode("\n", [
            '{"query":{"term":{"service":"api"}}}',
            '{"query":{"term":{"service":"worker"}}}',
        ]);

        [$status, $out] = $this->invoke(['--ndjson', '--json'], $input);

        self::assertSame(Command::OK, $status);
        foreach (explode("\n", trim($out)) as $line) {
            self::assertIsArray(json_decode($line, true), 'Each line must decode on its own.');
            self::assertStringNotContainsString("\n", $line);
        }
    }

    public function testAnUnparseableQueryIsAnInputFailure(): void
    {
        [$status, $out, $err] = $this->invoke([], '{oops');

        self::assertSame(Command::INVALID_INPUT, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('could not be decoded as JSON', $err);
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function badInvocations(): array
    {
        return [
            'unknown option' => ['--nope', 'unknown option --nope'],
            'value on a flag' => ['--explain=yes', 'takes no value'],
            'unknown level' => ['--normalization=loose', 'none, values, structural'],
            'non-numeric cap' => ['--max-values=lots', 'a number'],
            'two output formats' => ['--json', '--json and --hash'],
        ];
    }

    public function testBadInvocationsExplainThemselvesAndExitTwo(): void
    {
        // One test over the table rather than a data provider: static providers
        // and doc-comment metadata differ across the supported PHPUnit range.
        foreach (self::badInvocations() as $case => $expectation) {
            $argv = $case === 'two output formats' ? ['--json', '--hash'] : [$expectation[0]];

            [$status, $out, $err] = $this->invoke($argv, self::BODY);

            self::assertSame(Command::USAGE, $status, $case);
            self::assertSame('', $out, $case);
            self::assertStringContainsString($expectation[1], $err, $case);
            self::assertStringContainsString('--help', $err, $case);
        }
    }

    public function testAValuedOptionAtTheEndOfArgvIsRejected(): void
    {
        [$status, , $err] = $this->invoke(['--index'], self::BODY);

        self::assertSame(Command::USAGE, $status);
        self::assertStringContainsString('--index needs a value', $err);
    }

    public function testExplainNeedsJsonInNdjsonMode(): void
    {
        [$status, , $err] = $this->invoke(['--ndjson', '--explain'], self::BODY);

        self::assertSame(Command::USAGE, $status);
        self::assertStringContainsString('--explain needs --json', $err);
    }

    public function testMoreThanOneFileIsRejected(): void
    {
        [$status, , $err] = $this->invoke(['one.json', 'two.json']);

        self::assertSame(Command::USAGE, $status);
        self::assertStringContainsString('at most one file', $err);
    }

    public function testAnUnreadableFileIsRejected(): void
    {
        [$status, , $err] = $this->invoke([__DIR__ . '/does-not-exist.json']);

        self::assertSame(Command::USAGE, $status);
        self::assertStringContainsString('cannot read', $err);
    }

    public function testNoInputIsRejected(): void
    {
        [$status, , $err] = $this->invoke([], '');

        self::assertSame(Command::USAGE, $status);
        self::assertStringContainsString('no input', $err);
    }

    public function testHelpGoesToStdoutAndSucceeds(): void
    {
        [$status, $out, $err] = $this->invoke(['--help']);

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('Usage:', $out);
        self::assertStringContainsString('--ndjson', $out);
        self::assertSame('', $err);
    }

    public function testVersionReportsTheFingerprintVersion(): void
    {
        [$status, $out] = $this->invoke(['--version']);

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('fingerprint version q2', $out);
    }

    public function testADoubleDashEndsTheOptions(): void
    {
        // A file whose name starts with a dash is the only reason this exists.
        [$status, , $err] = $this->invoke(['--', '-weird.json']);

        self::assertSame(Command::USAGE, $status);
        self::assertStringContainsString('cannot read -weird.json', $err);
    }

    /**
     * The shim: it has to find the autoloader on its own, which is exactly what
     * the in-process tests above cannot prove.
     */
    public function testTheInstalledBinaryRuns(): void
    {
        $reflection = new \ReflectionClass(ClassLoader::class);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $vendor = dirname($file, 2);

        $binary = dirname(__DIR__) . '/bin/os-query-digest';
        self::assertFileExists($binary);

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $binary, '--hash', '--index=logs-2026.08.13'],
            $descriptors,
            $pipes,
            null,
            ['COMPOSER_VENDOR_DIR' => $vendor],
        );
        self::assertIsResource($process);

        fwrite($pipes[0], self::BODY);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(Command::OK, proc_close($process), $err);
        self::assertSame(self::HASH . "\n", $out);
    }

    /**
     * @param array<int,string> $argv the options only; the program name is added
     *
     * @return array{0:int,1:string,2:string} status, stdout, stderr
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

        $status = (new Command($in, $out, $err))->run(array_merge(['os-query-digest'], $argv));

        rewind($out);
        rewind($err);

        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
