<?php

declare(strict_types=1);

/**
 * Write the palette into the two stylesheets that need it, from one source:
 *
 *   docs/stylesheets/extra.css   the documentation site
 *   playground/playground.css    the browser playground
 *
 *     make palette
 *
 * Maintainer tool. It is not shipped in the Composer package.
 *
 * Why generate six hex values instead of writing them twice: because they were
 * written twice, and the second copy drifted within a day. The documentation
 * and the playground are one product on one domain — a reader moves between
 * them without a page load's worth of warning — so a lobster that is #c46d5e on
 * one and #9c4536 on the other is not a small bug, it is the seam showing.
 *
 * Only the palette block is generated. What each side *does* with these values
 * is not shared and should not be: Material wants --md-* variables it defines
 * the meaning of, the playground wants its own --bg and --accent, and pretending
 * those are the same list would force one of them to lie. The generated region
 * is fenced by the two markers below and nothing outside it is touched.
 *
 * The contrast ratios in PaletteTest are the other half of this. A palette that
 * is consistent and unreadable is worse than two that disagree, because it fails
 * everywhere at once.
 */
const OPEN = '/* >>> generated palette — run `make palette`, do not edit by hand */';
const CLOSE = '/* <<< generated palette */';

/**
 * The palette. Six values, and each one earns its place or says why it cannot
 * be fewer.
 *
 * @var array<string,array{0:string,1:string}> name => [hex, why]
 */
const PALETTE = [
    'pitch-black' => ['#231c07', 'the ink, and the dark field'],
    'bone' => ['#ece2d0', 'the light field, and the light ink — never #fff'],
    'lobster-pink' => ['#c46d5e', 'the hue the other two are drawn from; carries no text itself'],
    'lobster-deep' => ['#9c4536', 'light mode: 4.93:1 on bone, both as the header and as a link'],
    'lobster-light' => ['#cd8777', 'dark mode: 4.99:1 on the raised pane, the tighter dark surface'],
    'dark-blue' => ['#272a35', 'the raised pane, dark mode — the playground and the docs code blocks'],
];

$arguments = $_SERVER['argv'] ?? [];
$checking = is_array($arguments) && in_array('--check', $arguments, true);

$targets = [
    'docs/stylesheets/extra.css' => '  ',
    'playground/playground.css' => '    ',
];

$stale = [];

foreach ($targets as $path => $indent) {
    $file = __DIR__ . '/../' . $path;
    $current = is_file($file) ? file_get_contents($file) : false;

    if ($current === false) {
        fwrite(STDERR, 'Missing ' . $path . "\n");
        exit(1);
    }

    $wanted = replace($current, block($indent), $path);

    if ($checking) {
        if ($current !== $wanted) {
            $stale[] = $path;
        }
        continue;
    }

    if ($current === $wanted) {
        echo '  ', str_pad($path, 28), "unchanged\n";
        continue;
    }

    if (file_put_contents($file, $wanted) === false) {
        fwrite(STDERR, 'Could not write ' . $path . "\n");
        exit(1);
    }

    echo '  ', str_pad($path, 28), "written\n";
}

if (!$checking) {
    exit(0);
}

if ($stale !== []) {
    fwrite(STDERR, "The palette is out of date in:\n");
    foreach ($stale as $path) {
        fwrite(STDERR, '  - ' . $path . "\n");
    }
    fwrite(STDERR, "Run: make palette\n");
    exit(1);
}

echo "The palette is in sync.\n";
exit(0);

/**
 * The generated region, indented to match the file it lands in. Both markers
 * are emitted, so a file that has them once keeps having them exactly once.
 */
function block(string $indent): string
{
    $width = 0;
    foreach (array_keys(PALETTE) as $name) {
        $width = max($width, strlen($name) + 2);
    }

    $lines = [$indent . OPEN];

    foreach (PALETTE as $name => [$hex, $why]) {
        $declaration = str_pad('--' . $name . ':', $width + 1) . ' ' . $hex . ';';
        $lines[] = $indent . str_pad($declaration, 34) . '/* ' . $why . ' */';
    }

    $lines[] = $indent . CLOSE;

    return implode("\n", $lines);
}

/**
 * Swap the fenced region for a freshly generated one. A file without the fence
 * is an error rather than something to guess at: appending a palette to the
 * wrong place in a stylesheet would produce a file that still parses, which is
 * the worst kind of wrong.
 */
function replace(string $contents, string $block, string $path): string
{
    $start = strpos($contents, OPEN);
    $end = strpos($contents, CLOSE);

    if ($start === false || $end === false || $end < $start) {
        fwrite(STDERR, 'No generated palette fence in ' . $path . "\n");
        fwrite(STDERR, 'Expected a line containing: ' . OPEN . "\n");
        exit(1);
    }

    // Back up over the marker's own indentation so it is replaced too, rather
    // than left behind in front of the new block.
    $lineStart = strrpos(substr($contents, 0, $start), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;

    return substr($contents, 0, $lineStart)
        . $block
        . substr($contents, $end + strlen(CLOSE));
}
