<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Cli\Command;
use PHPUnit\Framework\TestCase;

/**
 * `os-query-digest slowlog`, driven in-process over memory streams.
 *
 * The interesting inputs are not the well-formed ones: a slow log is a log
 * file, so it holds startup notices, a record log rotation cut in half, and
 * bodies from whatever plugin the cluster runs. What this pins is that none of
 * those cost you the rest of the file, and that the two the tool must not
 * confuse — noise, and a record it could not read — are told apart.
 *
 * @internal
 */
final class SlowlogTest extends TestCase
{
    private const BODY = '{"query":{"bool":{"filter":['
        . '{"term":{"service":"api"}},'
        . '{"range":{"@timestamp":{"gte":"now-15m","lt":"now"}}}'
        . '],"must_not":[{"term":{"status":200}}]}},"size":50,"sort":[{"@timestamp":"desc"}]}';

    /** The hash fixture 01 pins for that body — the same one the CLI prints. */
    private const HASH = 'q4:fe168406e702';

    private const OTHER = '{"query":{"term":{"service":"api"}},"size":50}';

    /** @var array<int,string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->temporary = [];
    }

    public function testItRanksTheShapesInAPlainSlowLog(): void
    {
        $log = implode("\n", [
            self::plain(self::BODY, 'logs-2026.08.20', 145),
            self::plain(self::OTHER, 'logs-2026.08.20', 300),
            self::plain(self::BODY, 'logs-2026.08.21', 1200),
        ]);

        [$status, $out, $err] = $this->invoke([], $log);

        self::assertSame(Command::OK, $status, $err);
        self::assertSame('', $err);
        self::assertStringContainsString('3 lines, 3 records, 2 shapes, 1,645 ms total', $out);

        // Ranked by total time: two records at 1,345 ms outrank one at 300.
        self::assertLessThan(
            strpos($out, 'q4:5b2210eb5318'),
            (int) strpos($out, self::HASH),
            'The default ranking is by total time, not by the slowest record.',
        );

        // The group is a shape, so the table prints the shape.
        self::assertStringContainsString('q=(@timestamp >= ? and', $out);
        self::assertStringNotContainsString('now-15m', $out);
    }

    public function testItReadsBothJsonAppenders(): void
    {
        $log = implode("\n", [
            // The common shape: bare keys, every value written as text.
            (string) json_encode([
                'type' => 'index_search_slowlog',
                'timestamp' => '2026-08-20T10:00:01,123+02:00',
                'message' => '[logs-2026.08.20][2]',
                'took_millis' => '145',
                'source' => self::BODY,
            ]),
            // A layout that namespaces its keys — read, though only OpenSearch
            // is a certified product here.
            (string) json_encode([
                '@timestamp' => '2026-08-20T08:00:02.001Z',
                'elasticsearch.slowlog.message' => '[logs-2026.08.20][0]',
                'elasticsearch.slowlog.took_millis' => 210,
                'elasticsearch.slowlog.source' => self::BODY,
            ]),
            // Some layouts embed the body instead of the string of it.
            (string) json_encode([
                'timestamp' => '2026-08-20T10:00:03,900+02:00',
                'message' => '[logs-2026.08.20][1]',
                'took_millis' => 95,
                'source' => json_decode(self::BODY, true),
            ]),
        ]);

        [$status, $out, $err] = $this->invoke([], $log);

        self::assertSame(Command::OK, $status, $err);
        self::assertStringContainsString('3 records, 1 shape, 450 ms total', $out);
        self::assertStringContainsString(self::HASH, $out);
    }

    public function testABracketInsideTheQueryDoesNotEndTheSource(): void
    {
        $body = '{"query":{"terms":{"sku":["a[1]","b]c","d"]}}}';

        [$status, $out, $err] = $this->invoke([], self::plain($body, 'orders-2026.08', 12));

        self::assertSame(Command::OK, $status, $err);
        self::assertStringContainsString('sku:(? or ? or ?)', $out);
    }

    public function testNoiseIsSkippedInSilenceButAnUnreadableRecordIsNot(): void
    {
        $log = implode("\n", [
            '[2026-08-20T10:00:00,000][INFO ][o.o.n.Node] [node-1] starting ...',
            self::plain(self::BODY, 'logs-2026.08.20', 145),
            // What rotation does to a line: the source never closes.
            '[2026-08-20T10:00:05,000][WARN ][i.s.s.query] [n1] [logs-x][2] '
                . 'took[1ms], took_millis[1], source[{"query":{"term":{"a":',
        ]);

        [$status, $out, $err] = $this->invoke([], $log);

        self::assertSame(Command::INVALID_INPUT, $status);
        self::assertStringContainsString('line 3', $err);
        self::assertStringContainsString('unterminated', $err);
        self::assertStringContainsString('3 lines, 1 record, 1 shape', $out);
        self::assertStringContainsString('(1 unreadable, reported above)', $out);
    }

    public function testARecordWhoseBodyWillNotParseIsReportedWithItsLine(): void
    {
        $log = implode("\n", [
            self::plain(self::BODY, 'logs-2026.08.20', 145),
            self::plain('{"query":{"term":', 'logs-2026.08.20', 9),
        ]);

        [$status, $out, $err] = $this->invoke([], $log);

        self::assertSame(Command::INVALID_INPUT, $status);
        self::assertStringContainsString('line 2', $err);
        self::assertStringContainsString(self::HASH, $out, 'The good record still counts.');
    }

    public function testAFileWithNoRecordsSaysWhatItExpectedAndWhereToGoInstead(): void
    {
        [$status, $out, $err] = $this->invoke([], "hello\nworld\n");

        self::assertSame(Command::INVALID_INPUT, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('no search record in 2 lines', $err);
        self::assertStringContainsString('--ndjson', $err);
    }

    public function testTheRankingCanBeOrderedOnAnyColumn(): void
    {
        // Two records adding up to more than the single slow one, so the three
        // orders disagree: by total the pair wins, by max the single record
        // does, by count the pair again.
        $log = implode("\n", [
            self::plain(self::BODY, 'logs-2026.08.20', 500),
            self::plain(self::BODY, 'logs-2026.08.20', 500),
            self::plain(self::OTHER, 'logs-2026.08.20', 900),
        ]);

        [, $byTotal] = $this->invoke([], $log);
        [, $byMax] = $this->invoke(['--sort=max'], $log);
        [, $byCount] = $this->invoke(['-s', 'count'], $log);

        self::assertLessThan(strpos($byTotal, 'q4:5b2210eb5318'), (int) strpos($byTotal, self::HASH));
        self::assertLessThan(strpos($byMax, self::HASH), (int) strpos($byMax, 'q4:5b2210eb5318'));
        self::assertLessThan(strpos($byCount, 'q4:5b2210eb5318'), (int) strpos($byCount, self::HASH));

        // The table says which column it was ordered by.
        self::assertStringContainsString('max*', $byMax);
    }

    public function testTopLimitsTheTableAndSaysWhatItLeftOut(): void
    {
        $log = implode("\n", [
            self::plain(self::BODY, 'logs-2026.08.20', 900),
            self::plain(self::OTHER, 'logs-2026.08.20', 100),
        ]);

        [$status, $limited] = $this->invoke(['--top', '1'], $log);
        [, $all] = $this->invoke(['--top=none'], $log);

        self::assertSame(Command::OK, $status);
        self::assertStringNotContainsString('q4:5b2210eb5318', $limited);
        self::assertStringContainsString('1 more shape (--top none for all)', $limited);
        self::assertStringContainsString('q4:5b2210eb5318', $all);
        self::assertStringNotContainsString('--top none for all', $all);
    }

    public function testJsonCarriesTheSlowestSampleAndTheSpanItCovers(): void
    {
        $log = implode("\n", [
            self::plain(self::BODY, 'logs-2026.08.20', 100, '2026-08-20T10:00:01,000'),
            self::plain(
                '{"query":{"bool":{"filter":[{"term":{"service":"batch"}},'
                    . '{"range":{"@timestamp":{"gte":"now-7d","lt":"now"}}}],'
                    . '"must_not":[{"term":{"status":500}}]}},"size":50,"sort":[{"@timestamp":"desc"}]}',
                'logs-2026.08.20',
                900,
                '2026-08-20T10:00:09,000',
            ),
        ]);

        [$status, $out] = $this->invoke(['--json'], $log);

        self::assertSame(Command::OK, $status);
        $first = self::first($out);

        self::assertSame(self::HASH, $first['hash'] ?? null);
        self::assertSame(2, $first['count'] ?? null);
        self::assertSame(1000, $first['total_ms'] ?? null);
        self::assertSame(900, $first['max_ms'] ?? null);
        self::assertSame('2026-08-20T10:00:01,000', $first['first'] ?? null);
        self::assertSame('2026-08-20T10:00:09,000', $first['last'] ?? null);

        $slowest = $first['slowest'] ?? null;
        self::assertIsArray($slowest);
        self::assertSame(900, $slowest['took_ms'] ?? null);
        // The one place a sample's own values belong, labelled as a sample.
        $text = $slowest['text'] ?? null;
        self::assertIsString($text);
        self::assertStringContainsString('service:batch', $text);
    }

    public function testEveryStatisticInTheRankingIsThatOfTheWholeGroup(): void
    {
        // Twenty records, one to twenty milliseconds, so no two statistics
        // share a value: mean 10.5, nearest-rank p95 19, max 20, total 210.
        $lines = [];
        for ($millis = 1; $millis <= 20; $millis++) {
            $lines[] = self::plain(self::BODY, 'logs-2026.08.20', $millis);
        }

        [$status, $out] = $this->invoke(['--json'], implode("\n", $lines));

        self::assertSame(Command::OK, $status);
        $shape = self::first($out);

        self::assertSame(20, $shape['count'] ?? null);
        self::assertSame(20, $shape['measured'] ?? null);
        self::assertSame(210, $shape['total_ms'] ?? null);
        self::assertSame(10.5, $shape['mean_ms'] ?? null);
        self::assertSame(19, $shape['p95_ms'] ?? null);
        self::assertSame(20, $shape['max_ms'] ?? null);
    }

    public function testTheSampleKeptIsTheSlowestOneWhereverItIsInTheFile(): void
    {
        $slow = '{"query":{"term":{"service":"batch"}},"size":50}';

        // The slowest first, so a tracker that keeps whichever it saw last —
        // or simply the first — reads as working on an ordered file.
        $log = implode("\n", [
            self::plain($slow, 'logs-2026.08.20', 900),
            self::plain(self::OTHER, 'logs-2026.08.20', 10),
            self::plain(self::OTHER, 'logs-2026.08.20', 20),
        ]);

        [$status, $out] = $this->invoke(['--json'], $log);

        self::assertSame(Command::OK, $status);
        $slowest = self::first($out)['slowest'] ?? null;
        self::assertIsArray($slowest);
        $text = $slowest['text'] ?? null;
        self::assertIsString($text);

        self::assertSame(900, $slowest['took_ms'] ?? null);
        self::assertStringContainsString('service:batch', $text);
    }

    public function testTheSpanIsTheEarliestAndLatestRecord(): void
    {
        // Out of order on purpose: a slow log merged from two nodes is.
        $log = implode("\n", [
            self::plain(self::BODY, 'logs-2026.08.20', 10, '2026-08-20T10:00:05,000'),
            self::plain(self::BODY, 'logs-2026.08.20', 10, '2026-08-20T09:00:00,000'),
            self::plain(self::BODY, 'logs-2026.08.20', 10, '2026-08-20T11:30:00,000'),
        ]);

        [, $out] = $this->invoke(['--json'], $log);

        $shape = self::first($out);

        self::assertSame('2026-08-20T09:00:00,000', $shape['first'] ?? null);
        self::assertSame('2026-08-20T11:30:00,000', $shape['last'] ?? null);
    }

    public function testTheIndexComesFromWhicheverFieldTheAppenderFilled(): void
    {
        $records = [
            // Nothing but the `[index][shard]` message every appender opens with.
            ['message' => '[orders-2026.08][1]', 'source' => self::OTHER],
            // A layout that names the index outright.
            ['elasticsearch.index.name' => 'people-2026.08', 'elasticsearch.slowlog.source' => self::OTHER],
            // Neither: the digest has no index to normalise.
            ['source' => self::OTHER],
        ];

        $log = [];
        foreach ($records as $record) {
            $log[] = (string) json_encode($record);
        }

        [$status, $out] = $this->invoke(['--json', '--raw-index'], implode("\n", $log));

        self::assertSame(Command::OK, $status);
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);

        $indices = [];
        foreach ($decoded as $shape) {
            self::assertIsArray($shape);
            $indices[] = $shape['idx'] ?? null;
        }
        sort($indices);

        // The one with no index at all renders as none, rather than guessing.
        self::assertSame(['', 'orders-2026.08', 'people-2026.08'], $indices);
    }

    public function testAnEscapedQuoteInTheQueryDoesNotEndTheSource(): void
    {
        // A `]` inside a string, inside an escaped quote: the scan has to know
        // where strings begin and end, not merely count brackets.
        $body = '{"query":{"term":{"note":"a \\"quoted] thing\\""}}}';

        [$status, $out, $err] = $this->invoke([], self::plain($body, 'notes-2026.08', 12));

        self::assertSame(Command::OK, $status, $err);
        self::assertStringContainsString('1 record, 1 shape', $out);
        self::assertStringContainsString('note:?', $out);
    }

    public function testADurationIsReadWhateverTypeTheAppenderWroteIt(): void
    {
        $log = implode("\n", [
            (string) json_encode(['message' => '[logs-x][0]', 'took_millis' => '10', 'source' => self::OTHER]),
            (string) json_encode(['message' => '[logs-x][0]', 'took_millis' => 20, 'source' => self::OTHER]),
            (string) json_encode(['message' => '[logs-x][0]', 'took_millis' => 30.5, 'source' => self::OTHER]),
            // Not a number: the record still counts, its duration does not.
            (string) json_encode(['message' => '[logs-x][0]', 'took_millis' => 'n/a', 'source' => self::OTHER]),
        ]);

        [$status, $out] = $this->invoke(['--json'], $log);

        self::assertSame(Command::OK, $status);
        $shape = self::first($out);

        self::assertSame(4, $shape['count'] ?? null);
        self::assertSame(3, $shape['measured'] ?? null);
        self::assertSame(60.5, $shape['total_ms'] ?? null);
    }

    public function testARecordWithoutADurationStillCountsAsOne(): void
    {
        $line = '[2026-08-20T10:00:01,123][WARN ][i.s.s.query] [node-1] [logs-2026.08.20][2] '
            . 'source[' . self::BODY . '], id[]';

        [$status, $out, $err] = $this->invoke([], $line);

        self::assertSame(Command::OK, $status, $err);
        self::assertStringContainsString('1 record, 1 shape, 0 ms total', $out);
        self::assertMatchesRegularExpression('/\s1\s+-\s+-\s+-\s+-\s+q4:/', $out);
    }

    public function testTheFingerprintFlagsReachTheDigest(): void
    {
        $log = self::plain(self::BODY, 'logs-2026.08.20', 145);

        [, $normalised] = $this->invoke([], $log);
        [, $raw] = $this->invoke(['--raw-index'], $log);
        [, $structural] = $this->invoke(['-n', 'structural'], $log);

        self::assertStringContainsString('logs-* |', $normalised);
        self::assertStringContainsString('logs-2026.08.20 |', $raw);
        self::assertStringNotContainsString(self::HASH, $structural);
    }

    public function testEachFileIsNamedWhenMoreThanOneIsRead(): void
    {
        $good = $this->file(self::plain(self::BODY, 'logs-2026.08.20', 145));
        $bad = $this->file(self::plain('{"query":{"term":', 'logs-2026.08.20', 9));

        [$status, $out, $err] = $this->invoke([$good, $bad]);

        self::assertSame(Command::INVALID_INPUT, $status);
        self::assertStringContainsString($bad . ' line 1', $err);
        self::assertStringContainsString('2 lines, 2 records', $out);
    }

    public function testTheSignatureSitsUnderTheShapeItBelongsTo(): void
    {
        // Two records whose mean is 100.5, so rounding is visible, and a table
        // whose second line has to line up with the first to be readable at
        // all — the alignment is the product here, not decoration.
        $log = implode("\n", [
            self::plain(self::BODY, 'logs-2026.08.20', 100),
            self::plain(self::BODY, 'logs-2026.08.20', 101),
        ]);

        [$status, $out, $err] = $this->invoke([], $log);

        self::assertSame(Command::OK, $status, $err);
        self::assertStringNotContainsString('unreadable', $out);

        $lines = explode("\n", rtrim($out));
        $row = $lines[count($lines) - 2];
        $signature = $lines[count($lines) - 1];

        $column = strpos($row, 'q4:');
        self::assertIsInt($column);
        self::assertSame($column, strlen($signature) - strlen(ltrim($signature)));
        self::assertStringContainsString('101', $row, 'The mean is rounded, not truncated.');
    }

    public function testOneFileIsNotWorthNamingInAnErrorAndStdinCanBeAskedForByName(): void
    {
        $file = $this->file(self::plain('{"query":{"term":', 'logs-2026.08.20', 9));

        [$status, , $err] = $this->invoke([$file]);

        self::assertSame(Command::INVALID_INPUT, $status);
        self::assertStringContainsString('line 1', $err);
        self::assertStringNotContainsString($file, $err, 'One file needs no label.');

        [$status, $out] = $this->invoke(['-'], self::plain(self::BODY, 'logs-2026.08.20', 5));

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString(self::HASH, $out);
    }

    public function testMeanRanksTheShapeThatIsSlowEveryTimeAboveTheOneThatIsSlowOnce(): void
    {
        $log = implode("\n", [
            self::plain(self::BODY, 'logs-2026.08.20', 300),
            self::plain(self::OTHER, 'logs-2026.08.20', 500),
            self::plain(self::OTHER, 'logs-2026.08.20', 1),
            self::plain(self::OTHER, 'logs-2026.08.20', 1),
        ]);

        [$status, $out] = $this->invoke(['--sort=mean'], $log);

        self::assertSame(Command::OK, $status);
        self::assertLessThan(strpos($out, 'q4:5b2210eb5318'), (int) strpos($out, self::HASH));
        self::assertStringContainsString('mean*', $out);
    }

    public function testOnePhaseIsCountedAndAnUnnamedPhaseIsNeverDropped(): void
    {
        $log = implode("\n", [
            (string) json_encode(['component' => 'i.s.s.query', 'message' => '[logs-x][0]',
                'took_millis' => 10, 'source' => self::OTHER]),
            (string) json_encode(['component' => 'i.s.s.fetch', 'message' => '[logs-x][0]',
                'took_millis' => 90, 'source' => self::OTHER]),
            // A layout that names no logger: unknown is not the same as other.
            (string) json_encode(['message' => '[logs-x][0]', 'took_millis' => 5, 'source' => self::OTHER]),
        ]);

        [$status, $query] = $this->invoke([], $log);
        [, $fetch] = $this->invoke(['--phase', 'fetch'], $log);
        [, $both] = $this->invoke(['-p', 'both'], $log);

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('2 records', $query);
        self::assertStringContainsString('1 record from another phase, see --phase', $query);
        self::assertStringContainsString('2 records', $fetch);
        self::assertStringContainsString('3 records', $both);
        self::assertStringNotContainsString('another phase', $both);
    }

    public function testBadInvocationsExplainThemselvesAndExitTwo(): void
    {
        $cases = [
            'unknown sort' => [['--sort=nope'], 'total, count, p95, max, mean'],
            'unknown top' => [['--top=lots'], 'a number, or `none`'],
            'unknown phase' => [['--phase=nope'], 'query, fetch, both'],
            'half a number' => [['--top=1x'], 'a number, or `none`'],
            'value on a flag' => [['--json=yes'], 'takes no value'],
            'unknown option' => [['--nope'], 'unknown option --nope'],
            'missing value' => [['--sort'], '--sort needs a value'],
            'unreadable file' => [['/nope.log'], 'cannot read /nope.log'],
            'bad cap' => [['--max-values=lots'], 'a number'],
        ];

        foreach ($cases as $case => $expectation) {
            [$status, $out, $err] = $this->invoke($expectation[0], self::plain(self::BODY, 'logs-x', 1));

            self::assertSame(Command::USAGE, $status, $case);
            self::assertSame('', $out, $case);
            self::assertStringContainsString($expectation[1], $err, $case);
            self::assertStringContainsString('os-query-digest slowlog --help', $err, $case);
        }
    }

    public function testHelpGoesToStdoutAndSucceeds(): void
    {
        [$status, $out, $err] = $this->invoke(['--help']);

        self::assertSame(Command::OK, $status);
        self::assertStringContainsString('index.search.slowlog', $out);
        self::assertStringContainsString('--sort=KEY', $out);
        self::assertSame('', $err);
    }

    /**
     * The sub-command is dispatched on the first argument, which a file of that
     * name would otherwise lose — so the escape hatch is part of the contract.
     */
    public function testADoubleDashMakesSlowlogAFileNameAgain(): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);

        $status = (new Command($in, $out, $err))->run(['os-query-digest', '--', 'slowlog']);
        rewind($err);

        self::assertSame(Command::USAGE, $status);
        self::assertStringContainsString('cannot read slowlog', (string) stream_get_contents($err));
    }

    /**
     * The top row of a `--json` ranking, decoded.
     *
     * @return array<mixed>
     */
    private static function first(string $json): array
    {
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        $first = $decoded[0] ?? null;
        self::assertIsArray($first);

        return $first;
    }

    /** A plain-appender line, the shape log4j writes it. */
    private static function plain(
        string $source,
        string $index,
        int $millis,
        string $timestamp = '2026-08-20T10:00:01,123'
    ): string {
        return sprintf(
            '[%s][WARN ][i.s.s.query              ] [node-1] [%s][2] took[%sms], took_millis[%d], '
            . 'total_hits[7 hits], stats[], search_type[QUERY_THEN_FETCH], total_shards[5], source[%s], id[]',
            $timestamp,
            $index,
            $millis,
            $millis,
            $source,
        );
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'slowlog');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $contents . "\n"));

        $this->temporary[] = $path;

        return $path;
    }

    /**
     * @param array<int,string> $args everything after `slowlog`
     *
     * @return array{0:int,1:string,2:string} status, stdout, stderr
     */
    private function invoke(array $args, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);

        fwrite($in, $stdin);
        rewind($in);

        $argv = array_merge(['os-query-digest', 'slowlog'], $args);
        $status = (new Command($in, $out, $err))->run($argv);

        rewind($out);
        rewind($err);

        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
