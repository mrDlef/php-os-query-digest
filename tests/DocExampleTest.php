<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Cli\Command;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\IndexNormalizer;
use MrDlef\OsQueryDigest\Kind;
use MrDlef\OsQueryDigest\Normalization;
use MrDlef\OsQueryDigest\Options;
use PHPUnit\Framework\TestCase;

/**
 * Recomputes every fingerprint the hand-written pages print.
 *
 * The Use cases pages are executed against a live node, the playground presets
 * are generated and the fixtures are pinned — so the examples nothing read were
 * exactly these. A block copied from one guide into another and edited by hand
 * shipped with a wrong hash *and* a wrong clause order, and only a manual
 * recomputation before the tag caught it.
 *
 * **The pages are the source.** Each block is found by the
 * `<!-- verified: name -->` marker above it, the convention
 * {@see \MrDlef\OsQueryDigest\Tests\Integration\UseCaseTest} already uses, and
 * compared with what this library actually prints. Nothing here asserts a hash
 * written out in this file: a fingerprint that moves must move the pages, not a
 * copy of them.
 *
 * @internal
 */
final class DocExampleTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /**
     * Every hand-written page that prints a fingerprint. `docs/use-cases` is
     * excluded because UseCaseTest runs it against a cluster, and CHANGELOG.md
     * because ChangelogTest owns it — a released section there is history and
     * must not be rewritten when the rules change.
     *
     * @var array<int,string>
     */
    private const PAGES = [
        'README.md',
        'docs/index.md',
        'docs/getting-started.md',
        'docs/guides/cli.md',
        'docs/guides/dashboards.md',
        'docs/guides/extending.md',
        'docs/guides/logging.md',
        'docs/guides/options.md',
        'docs/guides/transport.md',
        'docs/explanation/how-it-works.md',
        'docs/explanation/hash-stability.md',
        'docs/reference/coverage.md',
        'docs/reference/kinds.md',
    ];

    /**
     * Requests whose block prints a digest without printing the request.
     *
     * As JSON, which is what a request in a log file is, and what `describe()`
     * takes either way.
     *
     * @var array<string,array{0:string,1:string}> marker => [request, index]
     */
    private const SOURCES = [
        'logging-record' => [
            '{"query":{"bool":{"filter":['
                . '{"range":{"@timestamp":{"gte":"now-15m"}}},'
                . '{"term":{"service":"api"}}'
                . ']}},"size":0}',
            'logs-2026.08.16',
        ],
        'logging-line' => [
            '{"query":{"bool":{"filter":['
                . '{"term":{"service":"api"}},'
                . '{"terms":{"status":[500,502]}}'
                . ']}},"post_filter":{"term":{"host":"web-1"}},'
                . '"aggs":{"by_host":{"terms":{"field":"host","size":10},'
                . '"aggs":{"rt":{"percentiles":{"field":"rt","percents":[95]}}}}},'
                . '"size":0,"sort":[{"@timestamp":"desc"}],"highlight":{"fields":{"message":{}}}}',
            'logs-2026.08.16',
        ],
        'transport-record' => [
            '{"query":{"bool":{'
                . '"filter":[{"term":{"service":"api"}}],'
                . '"must_not":[{"term":{"status":200}}]'
                . '}},"size":5}',
            'logs-2026.08.21',
        ],
        // The one block whose rules table is the point, so the request is built
        // to fire six of them: a boost that is dropped, a nested AND flattened
        // into its parent, must and filter merged, siblings reordered, a
        // boost-only should group moved to the notes, and a daily index
        // collapsed to a pattern.
        'explain-output' => [
            '{"query":{"bool":{'
                . '"filter":[{"term":{"service":"api"}}],'
                . '"must":[{"bool":{"filter":['
                . '{"match":{"msg":{"query":"timeout","boost":2.0}}},'
                . '{"term":{"env":"prod"}}'
                . ']}}],'
                . '"should":[{"term":{"tier":"gold"}}]'
                . '}},"size":0}',
            'logs-2026.08.13',
        ],
        // The array the page writes in PHP. Unlike the JSON blocks above, this
        // one cannot be read out of the page and run, so this is a second copy
        // of it: the check is that the page's claimed output matches this
        // request, not that the page's request matches this one. Edit both.
        'getting-started-digest' => [
            '{"query":{"bool":{"filter":['
                . '{"term":{"service":"api"}},'
                . '{"range":{"@timestamp":{"gte":"now-15m"}}}'
                . ']}},"size":50}',
            'logs-2026.08.13',
        ],
    ];

    /**
     * The two runs of one catalogue search the "which level" section compares:
     * page 1 with two categories, page 3 with eight. The section's whole claim
     * is a claim about *four* digests — two apart under the default, one under
     * `structural()` — so none of them is transcribed.
     *
     * @var array<string,string> label => request
     */
    private const LEVELS = [
        'page 1, two categories' => '{"query":{"bool":{"filter":['
            . '{"term":{"shop":"fr"}},'
            . '{"terms":{"category":["boots","coats"]}}'
            . ']}},"size":20,"sort":[{"price":"asc"}]}',
        'page 3, eight categories' => '{"query":{"bool":{"filter":['
            . '{"term":{"shop":"fr"}},'
            . '{"terms":{"category":["boots","coats","hats","gloves","scarves","socks","belts","bags"]}}'
            . ']}},"size":20,"from":40,"sort":[{"price":"asc"}]}',
    ];

    private const LEVELS_INDEX = 'catalog-2026.08';

    /**
     * The one fingerprint in the docs that is not meant to be real: the page
     * about hash stability shows the *shape* of a hash. Anything else the pages
     * print has to be recomputed here.
     *
     * @var array<string,string> hash => why it is not checked
     */
    private const ILLUSTRATIVE = [
        'q5:8f3ac1d2b901' => 'docs/explanation/hash-stability.md — the shape of a hash, not one of ours',
    ];

    /**
     * The demo slow log the two slowlog blocks are a run of.
     *
     * The durations are not decoration. `p95` is nearest rank, so the 41-record
     * shape needs its 39th smallest value to be the 246 the pages print, its
     * largest to be 258, and the whole set to sum to 6,807 — and the three
     * shapes together to sum to the 13,515 ms of the summary line. Changing one
     * number here changes a published table.
     *
     * @var array<string,array<int,int>>
     */
    private const DURATIONS = [
        // 41 records: [132] + [142..178] + [246, 251, 258] = 6,807 ms.
        'logs' => [246, 251, 258, 132],
        // 6 records, 5,978 ms, and p95 lands on the max at this count.
        'orders' => [1325, 1180, 1002, 890, 780, 801],
        // 12 records, 730 ms.
        'catalog' => [86, 78, 72, 68, 64, 61, 58, 55, 52, 48, 44, 44],
    ];

    /** @var array<string,string> shape => the source line of a slow log record */
    private const BODIES = [
        'logs' => '{"query":{"bool":{"filter":['
            . '{"term":{"service":"api"}},'
            . '{"range":{"@timestamp":{"gte":"now-15m","lt":"now"}}}'
            . '],"must_not":[{"term":{"status":200}}]}},"size":50,"sort":[{"@timestamp":"desc"}]}',
        'orders' => '{"query":{"terms":{"sku":["SKU-1","SKU-2","SKU-3"]}},'
            . '"aggs":{"per_day":{"date_histogram":{"field":"created","calendar_interval":"day"}}}}',
        'catalog' => '{"query":{"match":{"title":"waterproof boots"}},"size":10}',
    ];

    /** @var array<string,string> shape => the index its records name */
    private const INDICES = [
        'logs' => 'logs-2026.08.20',
        'orders' => 'orders-2026.08',
        'catalog' => 'catalog-2026.08',
    ];

    /**
     * The file the `--ndjson` block reads. Three lines of one shape — two days,
     * two `service` values, which is the point the page makes about them — and
     * one line of another.
     *
     * @var array<int,string>
     */
    private const NDJSON = [
        '{"index":"logs-2026.08.13","body":{"query":{"term":{"service":"api"}},"size":50}}',
        '{"index":"logs-2026.08.13","body":{"query":{"term":{"service":"checkout"}},"size":50}}',
        '{"index":"logs-2026.08.14","body":{"query":{"term":{"service":"api"}},"size":50}}',
        '{"index":"logs-2026.08.14","body":{"query":{"match":{"message":"connection reset"}},"size":50}}',
    ];

    /**
     * The pages that print a request and then its digest.
     *
     * @var array<string,string> page => marker
     */
    private const LANDING = [
        'README.md' => 'readme-digest',
        'docs/index.md' => 'index-digest',
    ];

    /**
     * The pages that print a serialised log record.
     *
     * @var array<string,string> page => marker
     */
    private const RECORDS = [
        'docs/guides/logging.md' => 'logging-record',
        'docs/guides/transport.md' => 'transport-record',
    ];

    /** Every marker this class checks. Kept in step with the pages by a test. */
    private const EXERCISED = [
        'readme-digest',
        'index-digest',
        'getting-started-slowlog',
        'getting-started-digest',
        'cli-describe',
        'cli-ndjson',
        'cli-slowlog',
        'cli-slowlog-json',
        'logging-record',
        'logging-record-value-free',
        'options-index-shipped',
        'options-index-custom',
        'options-index-partial',
        'logging-line',
        'transport-record',
        'explain-output',
        'how-it-works-levels',
        'kinds-table',
    ];

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

    /**
     * The two landing pages print the request *and* its digest, so neither has
     * to be copied here: the second block is checked against a run of the
     * first. They order the same two filter clauses differently on purpose —
     * the canonicaliser is what makes them share a fingerprint.
     */
    public function testTheLandingPagesDigestTheRequestTheyPrint(): void
    {
        foreach (self::LANDING as $page => $marker) {
            [$request, $printed] = self::twoBlocks($page, $marker);

            $digest = Formatter::create()->describe($request, 'logs-2026.08.13');

            self::assertSame(
                [
                    'text  ' . $digest->text(),
                    'sig   ' . $digest->signature(),
                    'hash  ' . $digest->hash(),
                ],
                self::lines($printed),
                $page . ' prints a digest that is not the one this library produces for the request above it.',
            );
        }
    }

    /**
     * The JSON log records the two integration guides print. These are the
     * blocks that shipped wrong: one was copied from the other and its values
     * edited by hand, which the canonicaliser's reordering made a lie.
     */
    public function testTheGuidesPrintTheRecordThisLibraryWouldLog(): void
    {
        foreach (self::RECORDS as $page => $marker) {
            [$request, $index] = self::SOURCES[$marker];
            $digest = Formatter::create()->describe($request, $index);

            $printed = self::decode(self::oneBlock($page, $marker));

            // A record is the digest, or carries it under an "os" key.
            $actual = is_array($printed['os'] ?? null) ? $printed['os'] : $printed;

            self::assertSame(
                $digest->toArray(),
                $actual,
                $page . ' prints a log record whose digest this library does not produce.',
            );
        }
    }

    /**
     * The same request under `withText(false)`, printed on the same page. Its
     * whole claim is that a field is *absent*, which is the kind of claim a
     * hand-written block gets wrong by adding one back.
     */
    public function testTheValueFreeRecordIsWhatTheOptionProduces(): void
    {
        [$request, $index] = self::SOURCES['logging-record'];

        $digest = Formatter::create(Options::create()->withText(false))->describe($request, $index);
        $printed = self::decode(self::oneBlock('docs/guides/logging.md', 'logging-record-value-free'));

        self::assertSame(
            $digest->toArray(),
            $printed,
            'The value-free record in the logging guide is not the one this option produces.',
        );

        // The page's other claim about it, one line below the block.
        self::assertSame(
            Formatter::create()->describe($request, $index)->hash(),
            $digest->hash(),
            'The page says the hash survives the switch.',
        );
    }

    /**
     * The `index → collapsed` arrows in the options guide. Three of the four
     * claims there are counter-intuitive — a tenant prefix collapsing for free,
     * the custom rule composing with the shipped one, and a mapping hash being
     * mangled in part — which is exactly the kind a hand-written page gets
     * subtly wrong.
     */
    public function testTheIndexArrowsInTheOptionsGuideAreRealCollapses(): void
    {
        // A second copy of the page's own rule, like `getting-started-digest`:
        // the check is that the page's claimed output matches this rule, not
        // that its PHP matches this one. Edit both.
        $stripMappingHash = static fn(string $index): string => (string) preg_replace(
            '/_[0-9a-f]{32}$/',
            '',
            $index,
        );

        $normalizers = [
            'options-index-shipped' => IndexNormalizer::datePatterns(),
            'options-index-custom' => IndexNormalizer::custom($stripMappingHash),
            'options-index-partial' => IndexNormalizer::datePatterns(),
        ];

        $expected = [];
        $actual = [];

        foreach ($normalizers as $marker => $normalizer) {
            foreach (self::lines(self::oneBlock('docs/guides/options.md', $marker)) as $line) {
                $sides = explode('→', $line);
                self::assertCount(2, $sides, $marker . ': "' . $line . '" is not an arrow.');

                $index = trim($sides[0]);
                $expected[$marker . ': ' . $index] = trim($sides[1]);
                $actual[$marker . ': ' . $index] = $normalizer->normalize($index);
            }
        }

        self::assertNotSame([], $actual, 'The options guide prints no index arrows at all.');
        self::assertSame($expected, $actual, 'The options guide claims a collapse this library does not do.');
    }

    /**
     * The annotated line in the logging guide, which is the only place the
     * segment order is documented. Its second line is the annotation, aligned
     * by hand; the first is a digest and is checked.
     */
    public function testTheAnnotatedLineIsARealDigest(): void
    {
        [$request, $index] = self::SOURCES['logging-line'];

        $lines = self::lines(self::oneBlock('docs/guides/logging.md', 'logging-line'));

        self::assertSame(
            Formatter::create()->describe($request, $index)->text(),
            $lines[0],
            'The line the logging guide annotates segment by segment is not one this library renders.',
        );
    }

    /**
     * The `describe()` example on the Getting started page. The request there is
     * PHP rather than JSON, so it cannot be run out of the page; what is checked
     * is that the two `//` comments claiming its output are that output.
     */
    public function testTheGettingStartedExampleClaimsWhatItProduces(): void
    {
        [$request, $index] = self::SOURCES['getting-started-digest'];
        $digest = Formatter::create()->describe($request, $index);

        $block = self::oneBlock('docs/getting-started.md', 'getting-started-digest');

        foreach ([$digest->text(), $digest->signature()] as $claim) {
            self::assertStringContainsString(
                '// ' . $claim,
                $block,
                'The Getting started page claims an output this request does not produce.',
            );
        }
    }

    /**
     * The CLI transcript that carries its own input: the request is in the
     * `echo`, so the block is run rather than trusted.
     */
    public function testTheCliTranscriptIsARealRun(): void
    {
        $block = self::lines(self::oneBlock('docs/guides/cli.md', 'cli-describe'));

        [$command, $expected] = self::splitTranscript($block);

        if (preg_match("/echo '([^']*)'/", $command, $echoed) !== 1) {
            self::fail('The transcript pipes no request into the command: ' . $command);
        }

        [$status, $out, $err] = $this->invoke(self::argumentsOf($command), $echoed[1]);

        self::assertSame(Command::OK, $status, $err);
        self::assertSame('', $err);
        self::assertSame(
            $expected,
            self::lines($out),
            'The command line guide prints output the command does not produce.',
        );
    }

    /**
     * The ranked table, byte for byte against a run of a real file.
     *
     * The Getting started page prints the same table with its last shape
     * elided, so what is checked there is that the rows it does print are the
     * ones the run begins with.
     */
    public function testTheSlowlogTableIsARealRun(): void
    {
        $log = $this->file(self::slowlog());

        [$status, $out, $err] = $this->invoke(['slowlog', $log]);
        self::assertSame(Command::OK, $status, $err);
        self::assertSame('', $err, 'The demo log should hold nothing the reader reports.');

        $produced = self::lines($out);

        $full = self::lines(self::oneBlock('docs/guides/cli.md', 'cli-slowlog'));
        self::assertSame(
            $produced,
            array_slice($full, 1),
            'The command line guide prints a slowlog table that is not a run of this file.',
        );

        $elided = self::lines(self::oneBlock('docs/getting-started.md', 'getting-started-slowlog'));
        $rows = array_slice($elided, 1);
        self::assertSame(
            array_slice($produced, 0, count($rows)),
            $rows,
            'Getting started prints the first rows of that table; they no longer match it.',
        );
    }

    /**
     * The `--json` block, which the page pipes through `jq`. `jq` renders 246.0
     * as 246, so the four values are compared as decoded numbers rather than as
     * text — the claim is the values, and jq's formatting is not ours to pin.
     */
    public function testTheSlowlogJsonBlockHoldsWhatTheReportHolds(): void
    {
        $log = $this->file(self::slowlog());

        [$status, $out, $err] = $this->invoke(['slowlog', '--json', '--top=1', $log]);
        self::assertSame(Command::OK, $status, $err);

        $report = self::decode($out);
        $first = $report[0] ?? null;
        self::assertIsArray($first);

        [, $block] = self::splitTranscript(self::lines(self::oneBlock('docs/guides/cli.md', 'cli-slowlog-json')));
        $printed = self::decode(implode("\n", $block));

        self::assertNotSame([], $printed, 'The block should be a JSON object.');

        foreach ($printed as $key => $value) {
            self::assertEquals(
                $first[$key] ?? null,
                $value,
                'The command line guide prints a ' . $key . ' the report does not hold.',
            );
        }
    }

    /**
     * The `--ndjson` pipeline. `sort | uniq -c | sort -rn` is counted here
     * rather than reproduced: what the block claims is which fingerprints the
     * file holds and how many of each, not coreutils' column widths.
     */
    public function testTheNdjsonPipelineCountsWhatItDigests(): void
    {
        [$status, $out, $err] = $this->invoke(['--ndjson', '--hash'], implode("\n", self::NDJSON));

        self::assertSame(Command::OK, $status, $err);
        self::assertSame('', $err);

        $counted = array_count_values(self::lines($out));
        arsort($counted);

        $expected = [];
        foreach ($counted as $hash => $count) {
            $expected[] = $count . ' ' . $hash;
        }

        $printed = [];
        foreach (self::lines(self::oneBlock('docs/guides/cli.md', 'cli-ndjson')) as $line) {
            if (strpos($line, '$ ') === 0) {
                continue;
            }

            if (preg_match('/^\s*(\d+) (\S+)$/', $line, $counted) !== 1) {
                self::fail('Not a line of `uniq -c` output: ' . $line);
            }

            $printed[] = $counted[1] . ' ' . $counted[2];
        }

        self::assertSame(
            $expected,
            $printed,
            'The command line guide counts fingerprints the file does not produce.',
        );
    }

    /**
     * The explain output. The page elides the long rule descriptions with an
     * ellipsis to fit its width, so a description is checked as the prefix it
     * claims to be; everything else — the digest, the notes, which rules fired
     * and their labels — is compared as printed.
     */
    public function testTheExplainBlockIsWhatExplainPrints(): void
    {
        [$request, $index] = self::SOURCES['explain-output'];

        $produced = self::lines((string) Formatter::create()->explain($request, $index));
        $printed = self::lines(self::oneBlock('docs/explanation/how-it-works.md', 'explain-output'));

        self::assertSame(
            count($produced),
            count($printed),
            'The explain block has gained or lost a line — a rule started or stopped firing.',
        );

        foreach ($printed as $i => $line) {
            $real = $produced[$i];

            // Byte length, not one character: the ellipsis is three bytes and
            // this library targets PHP 7.4, where mbstring is not a given.
            $ellipsis = strlen('…');

            if (substr($line, -$ellipsis) !== '…') {
                self::assertSame($real, $line, 'Line ' . ($i + 1) . ' of the explain block.');

                continue;
            }

            $kept = substr($line, 0, -$ellipsis);
            self::assertSame(
                $kept,
                substr($real, 0, strlen($kept)),
                'An elided rule description no longer begins the one this library prints.',
            );
        }
    }

    /**
     * The level comparison on the same page. It is the one block whose point is
     * that two fingerprints are *equal*, so the collapse is asserted as well as
     * transcribed: a `structural()` that stopped erasing pagination would still
     * print three plausible lines.
     */
    public function testTheLevelBlockComparesFingerprintsTheseLevelsProduce(): void
    {
        $values = Formatter::create();
        $structural = Formatter::create(
            Options::create()->withNormalization(Normalization::structural()),
        );

        $expected = [];
        foreach (self::LEVELS as $label => $request) {
            $digest = $values->describe($request, self::LEVELS_INDEX);

            $expected[] = 'values()      ' . $label;
            $expected[] = '  sig   ' . $digest->signature();
            $expected[] = '  hash  ' . $digest->hash();
        }

        $collapsed = [];
        foreach (self::LEVELS as $request) {
            $collapsed[] = $structural->describe($request, self::LEVELS_INDEX);
        }

        self::assertSame(
            $collapsed[0]->signature(),
            $collapsed[1]->signature(),
            'The page says structural() collapses the pair; it no longer does.',
        );

        $expected[] = 'structural()  either page, either basket';
        $expected[] = '  sig   ' . $collapsed[0]->signature();
        $expected[] = '  hash  ' . $collapsed[0]->hash();

        self::assertSame(
            $expected,
            self::lines(self::oneBlock('docs/explanation/how-it-works.md', 'how-it-works-levels')),
            'The normalisation levels section compares fingerprints these levels do not produce.',
        );
    }

    /**
     * The kinds page, which carries its own requests: each line is a kind and
     * the request that is one, so the page is the source and this only runs it.
     * A taxonomy documented in prose drifts from the code the first time a rule
     * is tightened — this fails instead.
     */
    public function testTheKindsPageClassifiesTheRequestsItPrints(): void
    {
        $formatter = Formatter::create();
        $printed = [];

        foreach (self::lines(self::oneBlock('docs/reference/kinds.md', 'kinds-table')) as $line) {
            if (preg_match('/^([a-z]+)\s+(\{.*\})$/', $line, $found) !== 1) {
                self::fail('Not a `kind  request` line: ' . $line);
            }

            $printed[] = $found[1];

            self::assertSame(
                $found[1],
                $formatter->describe($found[2], 'catalog')->kind()->name(),
                'The kinds page calls this a ' . $found[1] . ': ' . $found[2],
            );
        }

        // And it shows every one of them: a kind the library can mint and the
        // reference does not name is a kind nobody can act on.
        $expected = Kind::KINDS;
        sort($expected);
        sort($printed);

        self::assertSame($expected, $printed, 'The kinds page does not name every kind.');
    }

    /**
     * A marked block nobody checks claims to be verified and is not, and a
     * check whose marker was renamed away silently stops running.
     */
    public function testEveryMarkedBlockIsExercised(): void
    {
        $marked = [];
        foreach (self::PAGES as $page) {
            preg_match_all('/<!-- verified: ([a-z0-9-]+) -->/', self::read($page), $found);
            $marked = array_merge($marked, $found[1]);
        }

        $exercised = self::EXERCISED;

        sort($marked);
        sort($exercised);

        self::assertSame($exercised, $marked, 'A marked block is not being checked, or vice versa.');
    }

    /**
     * The backstop. Recomputing the marked blocks says nothing about a hash
     * dropped into a sentence, and a rule change would leave one quoting a
     * fingerprint nothing produces.
     */
    public function testNoFingerprintInTheDocsIsUnaccountedFor(): void
    {
        $produced = $this->everyFingerprintTheseExamplesProduce();

        $found = false;
        foreach (self::PAGES as $page) {
            preg_match_all('/\bq5x?:[0-9a-f]{12}\b/', self::read($page), $matches);

            foreach (array_unique($matches[0]) as $hash) {
                $found = true;

                if (isset(self::ILLUSTRATIVE[$hash])) {
                    continue;
                }

                self::assertContains(
                    $hash,
                    $produced,
                    $hash . ' is printed in ' . $page . ' and nothing here produces it. If fingerprints '
                    . 'moved, the pages need regenerating; if the example is new, check it here.',
                );
            }
        }

        self::assertTrue($found, 'The pages should print fingerprints at all.');
    }

    /**
     * Every fingerprint the examples above mint, which is the set the pages are
     * allowed to print.
     *
     * @return array<int,string>
     */
    private function everyFingerprintTheseExamplesProduce(): array
    {
        $formatter = Formatter::create();
        $hashes = [];

        foreach (self::SOURCES as [$request, $index]) {
            $hashes[] = $formatter->describe($request, $index)->hash();
        }

        // The one section that prints a fingerprint no default formatter mints.
        $structural = Formatter::create(
            Options::create()->withNormalization(Normalization::structural()),
        );
        foreach (self::LEVELS as $request) {
            $hashes[] = $formatter->describe($request, self::LEVELS_INDEX)->hash();
            $hashes[] = $structural->describe($request, self::LEVELS_INDEX)->hash();
        }

        foreach (self::LANDING as $page => $marker) {
            [$request] = self::twoBlocks($page, $marker);
            $hashes[] = $formatter->describe($request, 'logs-2026.08.13')->hash();
        }

        [, $out] = $this->invoke(['slowlog', $this->file(self::slowlog())]);
        [, $ndjson] = $this->invoke(['--ndjson', '--hash'], implode("\n", self::NDJSON));

        preg_match_all('/\bq5x?:[0-9a-f]{12}\b/', $out . "\n" . $ndjson, $found);

        return array_unique(array_merge($hashes, $found[0]));
    }

    /**
     * The demo slow log, 60 lines: 59 search records over three shapes, plus one
     * line that is not a record at all — a slow log holds those, and skipping
     * them in silence is what the page says happens.
     */
    private static function slowlog(): string
    {
        $lines = [];

        foreach (self::durations('logs') as $i => $millis) {
            $lines[] = self::record(self::timestamp($i), self::INDICES['logs'], $i % 5, $millis, self::BODIES['logs']);
        }

        foreach (['orders', 'catalog'] as $shape) {
            foreach (self::durations($shape) as $i => $millis) {
                $lines[] = self::record(
                    // Inside the window the 41-record shape spans, so the
                    // report's first and last are that shape's own.
                    sprintf('2026-08-20T14:01:%02d,%03d', $i % 60, 100 + $i),
                    self::INDICES[$shape],
                    $i % 3,
                    $millis,
                    self::BODIES[$shape],
                );
            }
        }

        array_splice($lines, 30, 0, [
            '[2026-08-20T14:01:00,000][INFO ][o.o.m.j.JvmGcMonitorService] [node-1] '
            . '[gc][young][41][7] duration [310ms], collections [1]/[1s], total [310ms]/[2.4s]',
        ]);

        return implode("\n", $lines);
    }

    /**
     * The 41 durations of the biggest shape, spelled out rather than listed: 38
     * of them only have to stay under the p95 and sum to what is left.
     *
     * @return array<int,int>
     */
    private static function durations(string $shape): array
    {
        if ($shape !== 'logs') {
            return self::DURATIONS[$shape];
        }

        return array_merge(self::DURATIONS['logs'], range(142, 178));
    }

    /**
     * The window the `--json` block prints as `first` and `last`. The records in
     * between are spread across it; only the two ends are quoted anywhere.
     */
    private static function timestamp(int $i): string
    {
        if ($i === 0) {
            return '2026-08-20T14:00:03,970';
        }

        if ($i === 40) {
            return '2026-08-20T14:02:03,355';
        }

        // Strictly inside those two, or the report's window is not the one the
        // block prints. Seconds from 14:00:00, spread over the two minutes.
        $second = 6 + intdiv($i * 112, 40);

        return sprintf('2026-08-20T14:%02d:%02d,%03d', intdiv($second, 60), $second % 60, ($i * 137) % 1000);
    }

    private static function record(string $timestamp, string $index, int $shard, int $millis, string $source): string
    {
        return sprintf(
            '[%s][WARN ][i.s.s.query              ] [node-1] [%s][%d] took[%dms], took_millis[%d], '
            . 'total_hits[7 hits], stats[], search_type[QUERY_THEN_FETCH], total_shards[5], source[%s], id[]',
            $timestamp,
            $index,
            $shard,
            $millis,
            $millis,
            $source,
        );
    }

    /**
     * The fenced block after a marker.
     */
    private static function oneBlock(string $page, string $marker): string
    {
        return self::blocks($page, $marker, 1)[0];
    }

    /**
     * The two after it, for the pages that print a request and then its digest.
     *
     * @return array{0:string,1:string}
     */
    private static function twoBlocks(string $page, string $marker): array
    {
        $blocks = self::blocks($page, $marker, 2);

        return [$blocks[0], $blocks[1]];
    }

    /**
     * @return array<int,string>
     */
    private static function blocks(string $page, string $marker, int $count): array
    {
        $markdown = self::read($page);
        $at = strpos($markdown, '<!-- verified: ' . $marker . ' -->');

        self::assertNotFalse($at, 'No <!-- verified: ' . $marker . ' --> marker in ' . $page . '.');

        $blocks = [];
        $current = null;

        foreach (self::lines(substr($markdown, $at)) as $line) {
            if (strpos($line, '```') !== 0) {
                if ($current !== null) {
                    $current[] = $line;
                }

                continue;
            }

            if ($current === null) {
                $current = [];

                continue;
            }

            $blocks[] = implode("\n", $current);
            $current = null;

            if (count($blocks) === $count) {
                return $blocks;
            }
        }

        self::fail('Fewer than ' . $count . ' blocks follow ' . $marker . ' in ' . $page . '.');
    }

    /**
     * A transcript's command — continued across lines with a trailing
     * backslash — and the output under it.
     *
     * @param array<int,string> $lines
     *
     * @return array{0:string,1:array<int,string>}
     */
    private static function splitTranscript(array $lines): array
    {
        self::assertNotSame([], $lines);
        self::assertSame('$ ', substr($lines[0], 0, 2), 'A transcript starts with its command.');

        $command = substr($lines[0], 2);
        $consumed = 1;

        while (substr(rtrim($command), -1) === '\\') {
            $command = rtrim(rtrim($command), '\\') . ' ' . trim($lines[$consumed]);
            $consumed++;
        }

        return [$command, array_slice($lines, $consumed)];
    }

    /**
     * The arguments of the `os-query-digest` call in a shell command, whatever
     * it is piped from.
     *
     * @return array<int,string>
     */
    private static function argumentsOf(string $command): array
    {
        $at = strpos($command, 'os-query-digest');
        self::assertNotFalse($at, 'No os-query-digest call in: ' . $command);

        $tail = substr($command, $at + strlen('os-query-digest'));
        $arguments = preg_split('/\s+/', trim($tail), -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($arguments);

        return $arguments;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'Not JSON: ' . $json);

        return $decoded;
    }

    /**
     * @return array<int,string>
     */
    private static function lines(string $text): array
    {
        return explode("\n", trim($text, "\n"));
    }

    private static function read(string $page): string
    {
        $path = self::ROOT . '/' . $page;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docslowlog');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $contents . "\n"));

        $this->temporary[] = $path;

        return $path;
    }

    /**
     * @param array<int,string> $arguments
     *
     * @return array{0:int,1:string,2:string}
     */
    private function invoke(array $arguments, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);

        fwrite($in, $stdin);
        rewind($in);

        $status = (new Command($in, $out, $err))->run(array_merge(['os-query-digest'], $arguments));

        rewind($out);
        rewind($err);

        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
