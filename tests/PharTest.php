<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Cli\Command;
use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * Builds the distributable phar and runs it.
 *
 * In the default suite on purpose, so the whole PHP matrix builds it: the file
 * is one anyone can download, and it has to run on the oldest interpreter this
 * library claims — which is not the one a release is cut from.
 *
 * What can only break here is the stub. In a checkout every class comes from
 * Composer's autoloader; in the phar it comes from nine lines written by
 * `tools/build-phar.php`, and a namespace it maps wrongly is a class-not-found
 * on someone else's machine, from a file the test suite never opened.
 *
 * @internal
 */
final class PharTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** Stamped in, so `--version` is checkable rather than whatever git says. */
    private const BUILD = 'v9.9.9-test';

    private const BODY = '{"query":{"bool":{"filter":['
        . '{"term":{"service":"api"}},'
        . '{"range":{"@timestamp":{"gte":"now-15m","lt":"now"}}}'
        . '],"must_not":[{"term":{"status":200}}]}},"size":50,"sort":[{"@timestamp":"desc"}]}';

    private string $phar = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/os-query-digest-phar-' . getmypid();
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0700, true));
        }

        $this->phar = $directory . '/os-query-digest.phar';

        // phar.readonly is on by default and cannot be changed at runtime, so
        // the build runs in a child with it off — which is also how the
        // Makefile and the image build it.
        [$status, $out, $err] = $this->spawn([
            PHP_BINARY,
            '-d',
            'phar.readonly=0',
            self::ROOT . '/tools/build-phar.php',
            $this->phar,
        ], '', ['OS_QUERY_DIGEST_VERSION' => self::BUILD]);

        self::assertSame(0, $status, 'The phar did not build: ' . $err);
        self::assertStringContainsString($this->phar, $out);
        self::assertFileExists($this->phar);
    }

    protected function tearDown(): void
    {
        if ($this->phar !== '' && is_file($this->phar)) {
            unlink($this->phar);
            rmdir(dirname($this->phar));
        }
    }

    /**
     * The whole point of the artefact: one file, no autoloader, no vendor
     * directory, and the same fingerprint the library produces in process.
     */
    public function testItDigestsAQueryWithNothingInstalled(): void
    {
        [$status, $out, $err] = $this->runPhar(['--hash', '--index=logs-2026.08.13'], self::BODY);

        self::assertSame(Command::OK, $status, $err);
        self::assertSame('', $err);
        self::assertSame(
            Formatter::create()->describe(self::BODY, 'logs-2026.08.13')->hash() . "\n",
            $out,
            'The phar mints a different fingerprint from the library it is built out of.',
        );
    }

    /**
     * The sub-command and the explanation, because they are the parts of the
     * library the stub has to reach through namespaces the first digest never
     * touches.
     */
    public function testItReachesEveryCornerOfTheLibrary(): void
    {
        [$status, $out, $err] = $this->runPhar(['--explain', '--json', '--index=logs-2026.08.13'], self::BODY);

        self::assertSame(Command::OK, $status, $err);
        self::assertStringContainsString('"rules"', $out, 'Explain\\ was not reachable from the stub.');

        $record = '[2026-08-20T10:00:01,123][WARN ][i.s.s.query] [node-1] [logs-2026.08.20][2] '
            . 'took[145ms], took_millis[145], total_hits[7 hits], stats[], '
            . 'search_type[QUERY_THEN_FETCH], total_shards[5], source[' . self::BODY . '], id[]';

        [$status, $out, $err] = $this->runPhar(['slowlog'], $record);

        self::assertSame(Command::OK, $status, $err);
        self::assertStringContainsString('1 shape', $out, 'Cli\\Slowlog was not reachable from the stub.');
    }

    /** A file people download and run, so it says which one it is. */
    public function testItNamesItsOwnBuild(): void
    {
        [$status, $out, $err] = $this->runPhar(['--version']);

        self::assertSame(Command::OK, $status, $err);
        self::assertStringContainsString('(build ' . self::BUILD . ')', $out);
    }

    /**
     * Executable, and opening with the shebang rather than with anything the
     * shim printed on its way past. A `require` echoes everything before the
     * first `<?php`, which is how a stray line lands in front of the output.
     */
    public function testItIsAnExecutableThatPrintsNothingOfItsOwn(): void
    {
        self::assertTrue(is_executable($this->phar), 'The phar should be executable.');

        $contents = (string) file_get_contents($this->phar);
        self::assertSame('#!/usr/bin/env php', strtok($contents, "\n"));

        [, $out] = $this->runPhar(['--hash', '--index=logs-2026.08.13'], self::BODY);
        self::assertStringStartsWith('q4', $out, 'Something is printed before the digest.');
    }

    /**
     * The archive carries the library and the shim, and nothing else. A
     * hand-written manifest would sooner or later forget a new directory under
     * `src/`; this is the check that it is found instead of listed.
     */
    public function testItCarriesEverySourceFileAndNothingElse(): void
    {
        $source = realpath(self::ROOT . '/src');
        self::assertIsString($source);

        $expected = ['bin/os-query-digest'];

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($tree as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);

            if ($file->getExtension() === 'php') {
                $expected[] = 'src' . substr($file->getPathname(), strlen($source));
            }
        }

        // Recursively: a Phar iterates its top level only, which would compare
        // two directory names against fifty-five files and pass for the wrong
        // reason if the assertion were any looser.
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \Phar($this->phar)) as $file) {
            self::assertInstanceOf(\PharFileInfo::class, $file);
            $found[] = str_replace('phar://' . $this->phar . '/', '', $file->getPathname());
        }

        sort($expected);
        sort($found);

        self::assertSame($expected, $found, 'The phar and src/ have drifted apart.');
    }

    /**
     * @param array<int,string> $arguments
     *
     * @return array{0:int,1:string,2:string}
     */
    private function runPhar(array $arguments, string $stdin = ''): array
    {
        return $this->spawn(array_merge([PHP_BINARY, $this->phar], $arguments), $stdin);
    }

    /**
     * @param list<string>         $command
     * @param array<string,string> $environment
     *
     * @return array{0:int,1:string,2:string}
     */
    private function spawn(array $command, string $stdin, array $environment = []): array
    {
        $process = proc_open(
            $command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            $environment === [] ? null : $environment,
        );
        self::assertIsResource($process);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
