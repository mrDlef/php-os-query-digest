<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Two things can go wrong with a generated palette: a stylesheet drifts because
 * someone edited the CSS instead of tools/build-palette.php, or the palette stays
 * consistent and becomes unreadable — which is worse, since it then fails on both
 * pages at once.
 *
 * Every pair is asserted at the ratio it is documented at, so a colour nudged to
 * taste turns CI red. The values are parsed out of the *shipped* stylesheets:
 * asserting against the tool's own constant would prove only that it agrees with
 * itself.
 */
final class PaletteTest extends TestCase
{
    private const DOCS = __DIR__ . '/../docs/stylesheets/extra.css';
    private const PLAYGROUND = __DIR__ . '/../playground/playground.css';

    /** Normal text, and anything under 18.66px bold. */
    private const TEXT = 4.5;

    /** Borders that identify a control — WCAG 1.4.11. */
    private const UI = 3.0;

    public function testBothStylesheetsCarryWhatTheToolWouldWriteNow(): void
    {
        $tool = dirname(__DIR__) . '/tools/build-palette.php';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $tool, '--check'], $descriptors, $pipes);
        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), 'build-palette.php --check failed:' . "\n" . $out . $err);
    }

    /**
     * One test rather than a data provider: PHPUnit's annotation and attribute
     * disagree across the versions this suite runs on, and one nudged colour
     * usually breaks several pairs — reporting all of them beats one at a time.
     */
    public function testEveryPairClearsTheRatioItIsDocumentedAt(): void
    {
        // [foreground token, background token, minimum ratio]
        $docs = [
            'body text' => ['--md-default-fg-color', '--md-default-bg-color', self::TEXT],
            'muted text' => ['--md-default-fg-color--light', '--md-default-bg-color', self::TEXT],
            'links' => ['--md-typeset-a-color', '--md-default-bg-color', self::TEXT],
            'the header' => ['--md-primary-bg-color', '--md-primary-fg-color', self::TEXT],
            'the search placeholder' => ['--md-primary-bg-color--light', '--md-primary-fg-color', self::TEXT],
            'a code block' => ['--md-code-fg-color', '--md-code-bg-color', self::TEXT],
            'the banner and the footer' => ['--md-footer-fg-color', '--md-footer-bg-color', self::TEXT],
        ];

        $playground = [
            'body text on the page' => ['--fg', '--bg', self::TEXT],
            'body text on a pane' => ['--fg', '--pane', self::TEXT],
            'muted text on the page' => ['--muted', '--bg', self::TEXT],
            'muted text on a pane' => ['--muted', '--pane', self::TEXT],
            'the fingerprint' => ['--accent', '--pane', self::TEXT],
            'links' => ['--accent', '--bg', self::TEXT],
            'the engine badge' => ['--accent', '--accent-bg', self::TEXT],
            'an error' => ['--error', '--error-bg', self::TEXT],
            'a field border on the page' => ['--line-strong', '--bg', self::UI],
            'a field border on a pane' => ['--line-strong', '--pane', self::UI],
        ];

        $failures = [];
        $checked = 0;

        foreach ([self::DOCS => $docs, self::PLAYGROUND => $playground] as $file => $pairs) {
            foreach (['light', 'dark'] as $scheme) {
                $tokens = self::tokens($file, $scheme);

                foreach ($pairs as $what => [$foreground, $background, $minimum]) {
                    $fg = self::resolve($tokens, $foreground);
                    $bg = self::resolve($tokens, $background);

                    $where = basename($file) . ', ' . $scheme . ': ' . $what;

                    if ($fg === null || $bg === null) {
                        $failures[] = $where . ' — ' . ($fg === null ? $foreground : $background)
                            . ' resolves to no colour';
                        continue;
                    }

                    ++$checked;
                    $ratio = self::contrast($fg, $bg);

                    if (round($ratio, 2) < $minimum) {
                        $failures[] = sprintf(
                            '%s — %.2f:1, under %.1f:1 (%s on %s)',
                            $where,
                            $ratio,
                            $minimum,
                            $fg,
                            $bg,
                        );
                    }
                }
            }
        }

        self::assertSame([], $failures, "Contrast has regressed:\n  - " . implode("\n  - ", $failures));
        self::assertSame(34, $checked, 'Every pair should have been measured');
    }

    /**
     * Every custom property applying in one scheme, later declarations winning.
     * Both files put their dark values after the light ones, so "light" is the
     * file up to where the dark block starts and "dark" is the whole file.
     *
     * @return array<string,string>
     */
    private static function tokens(string $file, string $scheme): array
    {
        $css = (string) file_get_contents($file);

        $dark = self::darkStartsAt($css);
        if ($scheme === 'light') {
            $css = substr($css, 0, $dark);
        }

        preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;}]+)/i', $css, $matches, PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[strtolower($match[1])] = trim($match[2]);
        }

        return $tokens;
    }

    private static function darkStartsAt(string $css): int
    {
        foreach (['@media (prefers-color-scheme: dark)', '[data-md-color-scheme="slate"]'] as $marker) {
            $at = strpos($css, $marker);
            if ($at !== false) {
                return $at;
            }
        }

        self::fail('No dark-scheme block found; the parser assumes one exists');
    }

    /**
     * A hex colour, following var() references. Material's own defaults are not
     * in these files, so a token resolving to nothing is reported, not guessed.
     *
     * @param array<string,string> $tokens
     */
    private static function resolve(array $tokens, string $name, int $depth = 0): ?string
    {
        if ($depth > 8) {
            return null;
        }

        $value = $tokens[strtolower($name)] ?? null;
        if ($value === null) {
            return null;
        }

        if (preg_match('/^#[0-9a-f]{6}$/i', $value) === 1) {
            return strtolower($value);
        }

        if (preg_match('/var\(\s*(--[a-z0-9-]+)/i', $value, $match) === 1) {
            return self::resolve($tokens, $match[1], $depth + 1);
        }

        return null;
    }

    private static function contrast(string $a, string $b): float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);

        $lighter = max($first, $second);
        $darker = min($first, $second);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /** WCAG 2.1 relative luminance. */
    private static function luminance(string $hex): float
    {
        $channels = [];

        foreach ([1, 3, 5] as $offset) {
            $value = (int) hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
