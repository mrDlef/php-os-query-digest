<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use MrDlef\OsQueryDigest\Formatter;
use PHPUnit\Framework\TestCase;

/**
 * Holds CHANGELOG.md to the code it describes.
 *
 * The prose in that file is not derivable from the commits and never will be —
 * which is why it is written by hand and why it is the source the release notes
 * are extracted from. What *is* mechanical is whether it stays true, and this
 * is the offline half of that: the shape of the file, and the one claim in it
 * that the library itself can answer — which prefix the code produces today.
 *
 * The other half needs git history and lives in `tools/changelog.php check`,
 * because this suite reads committed files and must stay that way.
 */
final class ChangelogTest extends TestCase
{
    /** The line every section carries. Mirrored in tools/changelog.php. */
    private const FINGERPRINT_LINE = '/^\*\*Fingerprints:\*\* `(q[0-9a-z]+):`(?:\s*(?:→|->)\s*`(q[0-9a-z]+):`)?/mu';

    public function testEverySectionDeclaresWhatItDidToFingerprints(): void
    {
        $missing = [];

        foreach (self::sections() as $version => $body) {
            if (preg_match_all(self::FINGERPRINT_LINE, $body, $matches) !== 1) {
                $missing[] = $version;
            }
        }

        self::assertSame(
            [],
            $missing,
            "Every release says what it did to your fingerprints, in exactly one line:\n"
            . "  **Fingerprints:** `q4:` unchanged.\n"
            . "  **Fingerprints:** `q4:` → `q5:` — why.\n"
            . 'Without one: ' . implode(', ', $missing),
        );
    }

    /**
     * The claim the library can check on its own. Everything else in the file
     * is about the past; this is about the build in front of you.
     */
    public function testTheNewestEntryDeclaresThePrefixTheCodeProduces(): void
    {
        $sections = self::sections();
        $newest = (string) array_key_first($sections);

        self::assertNotSame('', $newest, 'CHANGELOG.md has no version sections.');

        self::assertSame(
            self::prefixProducedByTheCode(),
            self::resultingPrefix($sections[$newest]),
            $newest . ' declares a prefix the code does not produce.',
        );
    }

    /**
     * Newest first. A section filed in the wrong place would make the check
     * above read the wrong entry, so the order is part of the contract rather
     * than a convention.
     */
    public function testSectionsAreNewestFirst(): void
    {
        $versions = array_keys(self::sections());
        $sorted = $versions;
        usort($sorted, static fn(string $a, string $b): int => version_compare($b, $a));

        self::assertSame($sorted, $versions, 'CHANGELOG.md sections are out of order.');
    }

    /**
     * The summary table at the top exists so somebody holding a stored `q2:`
     * hash can find out, in one glance, which release minted it. It is a second
     * copy of what the sections say, so it can disagree with them.
     */
    public function testThePrefixTableAgreesWithTheSections(): void
    {
        $sections = self::sections();
        $changelog = self::changelog();

        // | `q2:` | v0.2.0 | three query types promoted… |
        preg_match_all('/^\|\s*`(q[0-9a-z]+):`\s*\|\s*(v[0-9][^\s|]*)\s*\|/mu', $changelog, $rows, PREG_SET_ORDER);

        self::assertNotSame([], $rows, 'The prefix table is missing or unreadable.');

        foreach ($rows as $row) {
            [, $prefix, $version] = $row;

            self::assertArrayHasKey(
                $version,
                $sections,
                sprintf('The prefix table names %s, which has no section.', $version),
            );

            self::assertSame(
                $prefix,
                self::resultingPrefix($sections[$version]),
                sprintf('The table says %s introduced `%s:`, its section does not.', $version, $prefix),
            );
        }
    }

    /**
     * The extraction the release procedure depends on. If this drifts, a tag
     * ships the wrong notes — or none.
     */
    public function testTheToolExtractsASectionTheSuiteCanSee(): void
    {
        $version = (string) array_key_first(self::sections());

        $tool = escapeshellarg(dirname(__DIR__) . '/tools/changelog.php');
        $output = shell_exec(sprintf('php %s section %s 2>&1', $tool, escapeshellarg($version)));

        self::assertIsString($output);
        self::assertStringContainsString('**Fingerprints:**', $output);
    }

    /**
     * What a section leaves you on: the prefix it moved to, or the one it kept.
     */
    private static function resultingPrefix(string $body): string
    {
        self::assertSame(1, preg_match(self::FINGERPRINT_LINE, $body, $match));

        $from = $match[1] ?? '';
        $to = $match[2] ?? '';
        self::assertNotSame('', $from, 'Unreadable Fingerprints: line.');

        return $to === '' ? $from : $to;
    }

    private static function prefixProducedByTheCode(): string
    {
        $hash = Formatter::create()->describe(['query' => ['term' => ['env' => 'prod']]])->hash();

        return explode(':', $hash)[0];
    }

    /**
     * @return array<string,string> body, keyed by version, in file order
     */
    private static function sections(): array
    {
        $parts = preg_split(
            '/^## (v[0-9][^\s—-]*)[^\n]*$/m',
            self::changelog(),
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        self::assertIsArray($parts);

        $sections = [];
        for ($i = 1; isset($parts[$i], $parts[$i + 1]); $i += 2) {
            $sections[$parts[$i]] = trim($parts[$i + 1]);
        }

        return $sections;
    }

    private static function changelog(): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
        self::assertIsString($contents, 'CHANGELOG.md is unreadable.');

        return $contents;
    }
}
