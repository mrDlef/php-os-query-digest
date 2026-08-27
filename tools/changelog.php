<?php

declare(strict_types=1);

/**
 * Read CHANGELOG.md, and hold it to what actually happened.
 *
 * Maintainer tool. Not shipped in the Composer package; the offline suite runs
 * the parts of this that need no git (see ChangelogTest), and this one covers
 * the parts that do.
 *
 *     php tools/changelog.php section v0.6.0     the notes for one release
 *     php tools/changelog.php check v0.7.0       may that version be tagged?
 *
 * Why this rather than a changelog generator: the prose is not derivable from
 * the commits. Which types were promoted, why the remaining opaque ones are a
 * settled position, whether a prefix bump kept every hex character — none of
 * that is in a `git log`.
 *
 * What *is* mechanical is whether the entry tells the truth. Every section
 * carries a `Fingerprints:` line, and `check` compares it against the hashes
 * pinned in tests/fixtures, so a release cannot claim dashboards survived when
 * they did not.
 */
const CHANGELOG = __DIR__ . '/../CHANGELOG.md';

/** The line every section must carry, and the two shapes it may take. */
const FINGERPRINT_LINE = '/^\*\*Fingerprints:\*\* `(q[0-9a-z]+):`(?:\s*(?:→|->)\s*`(q[0-9a-z]+):`)?/mu';

$arguments = $_SERVER['argv'] ?? [];
$arguments = is_array($arguments) ? array_values(array_filter($arguments, 'is_string')) : [];

exit(main(array_slice($arguments, 1)));

/**
 * @param array<int,string> $args
 */
function main(array $args): int
{
    $command = $args[0] ?? '';
    $version = $args[1] ?? '';

    if ($command === '' || $version === '') {
        fwrite(STDERR, "usage: changelog.php <section|check> <version>\n");

        return 2;
    }

    $sections = sections(readChangelog());

    if (!isset($sections[$version])) {
        fwrite(STDERR, sprintf(
            "CHANGELOG.md has no section for %s.\n"
            . "A release without an entry is a release nobody reviewed — add one before tagging.\n"
            . "Known versions: %s\n",
            $version,
            $sections === [] ? '(none)' : implode(', ', array_keys($sections)),
        ));

        return 1;
    }

    if ($command === 'section') {
        echo rtrim($sections[$version]), "\n";

        return 0;
    }

    if ($command === 'check') {
        return check($version, $sections[$version]);
    }

    fwrite(STDERR, sprintf("Unknown command: %s\n", $command));

    return 2;
}

/**
 * Does the entry agree with what the fixtures say happened?
 */
function check(string $version, string $body): int
{
    $declared = fingerprints($body);
    if ($declared === null) {
        fwrite(STDERR, sprintf(
            "%s has no usable `Fingerprints:` line.\n"
            . "Write one of:\n"
            . "  **Fingerprints:** `q5:` unchanged.\n"
            . "  **Fingerprints:** `q5:` → `q6:` — why.\n",
            $version,
        ));

        return 1;
    }

    $previous = previousTag();
    if ($previous === null) {
        echo "No earlier tag to compare against; nothing to check.\n";

        return 0;
    }

    $before = fixtureHashesAt($previous);
    $after = fixtureHashesNow();
    $shared = array_intersect_key($before, $after);

    if ($shared === []) {
        fwrite(STDERR, "No fixture is present in both revisions — cannot check this claim.\n");

        return 1;
    }

    $moved = [];
    $reshaped = [];
    foreach ($shared as $file => $hash) {
        if ($hash === $after[$file]) {
            continue;
        }
        $moved[] = $file;
        if (hex($hash) !== hex($after[$file])) {
            $reshaped[] = $file;
        }
    }

    $actualFrom = onlyPrefix($shared);
    $actualTo = onlyPrefix(array_intersect_key($after, $shared));

    return report($version, $previous, $declared, $moved, $reshaped, $actualFrom, $actualTo);
}

/**
 * @param array{0:string,1:?string} $declared
 * @param array<int,string>         $moved
 * @param array<int,string>         $reshaped
 */
function report(
    string $version,
    string $previous,
    array $declared,
    array $moved,
    array $reshaped,
    ?string $actualFrom,
    ?string $actualTo
): int {
    [$from, $to] = $declared;
    $claimsMove = $to !== null;
    $errors = [];

    if ($moved === [] && $claimsMove) {
        $errors[] = sprintf(
            'The entry claims `%s:` → `%s:`, but no fixture hash moved since %s. '
            . 'Nobody has to rebuild a dashboard for this release.',
            $from,
            $to,
            $previous,
        );
    }

    if ($moved !== [] && !$claimsMove) {
        $errors[] = sprintf(
            '%d of the pinned fixture hashes moved since %s, and the entry says `%s:` unchanged. '
            . 'Every stored fingerprint of those shapes stops matching — say so.',
            count($moved),
            $previous,
            $from,
        );
    }

    if ($actualFrom !== null && $from !== $actualFrom) {
        $errors[] = sprintf(
            'The entry starts from `%s:`, but %s produced `%s:`.',
            $from,
            $previous,
            $actualFrom,
        );
    }

    if ($claimsMove && $actualTo !== null && $to !== $actualTo) {
        $errors[] = sprintf(
            'The entry moves to `%s:`, but the code produces `%s:`.',
            $to,
            $actualTo,
        );
    }

    if ($errors !== []) {
        fwrite(STDERR, sprintf("%s: the changelog and the fixtures disagree.\n\n", $version));
        foreach ($errors as $error) {
            fwrite(STDERR, '  - ' . $error . "\n");
        }
        fwrite(STDERR, "\n");

        return 1;
    }

    if ($moved === []) {
        printf("%s: no fixture hash moved since %s. `%s:` stands.\n", $version, $previous, $from);

        return 0;
    }

    printf(
        "%s: %d fixture %s moved since %s, `%s:` → `%s:`.\n",
        $version,
        count($moved),
        count($moved) === 1 ? 'hash' : 'hashes',
        $previous,
        $from,
        (string) $to,
    );

    // The property worth stating out loud: a prefix bump that leaves every hex
    // character alone means the shapes are unchanged and the two prefixes
    // describe the same queries. One that does not is a deeper break.
    if ($reshaped === []) {
        printf("        every one kept its twelve hex characters — the shapes are unchanged.\n");

        return 0;
    }

    printf(
        "        %d of them changed shape, not just prefix:\n          %s\n",
        count($reshaped),
        implode("\n          ", $reshaped),
    );
    printf("        that is a stronger break than a prefix bump. Make sure the entry says why.\n");

    return 0;
}

/**
 * @return array{0:string,1:?string}|null the declared prefix, and the one it
 *                                        moves to when it moves
 */
function fingerprints(string $body): ?array
{
    if (preg_match_all(FINGERPRINT_LINE, $body, $matches, PREG_SET_ORDER) !== 1) {
        return null;
    }

    $match = $matches[0];

    return [$match[1], ($match[2] ?? '') === '' ? null : $match[2]];
}

/**
 * Split the file on its version headings.
 *
 * @return array<string,string> body, keyed by version
 */
function sections(string $markdown): array
{
    $parts = preg_split('/^## (v[0-9][^\s—-]*)[^\n]*$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return [];
    }

    $sections = [];
    // The first element is the preamble; then version, body, version, body…
    for ($i = 1; $i + 1 < count($parts) + 1; $i += 2) {
        if (!isset($parts[$i], $parts[$i + 1])) {
            break;
        }
        $sections[$parts[$i]] = trim($parts[$i + 1]);
    }

    return $sections;
}

/**
 * @param array<string,string> $hashes
 *
 * @return string|null the one prefix they all share, or null if they disagree
 */
function onlyPrefix(array $hashes): ?string
{
    $prefixes = [];
    foreach ($hashes as $hash) {
        $prefixes[explode(':', $hash)[0]] = true;
    }

    return count($prefixes) === 1 ? array_key_first($prefixes) : null;
}

function hex(string $hash): string
{
    $parts = explode(':', $hash, 2);

    return $parts[1] ?? '';
}

/**
 * @return array<string,string>
 */
function fixtureHashesNow(): array
{
    $hashes = [];
    $files = glob(__DIR__ . '/../tests/fixtures/*/expected.json');

    foreach ($files === false ? [] : $files as $file) {
        $hash = hashIn((string) file_get_contents($file));
        if ($hash !== null) {
            $hashes['tests/fixtures/' . basename(dirname($file)) . '/expected.json'] = $hash;
        }
    }

    return $hashes;
}

/**
 * @return array<string,string>
 */
function fixtureHashesAt(string $tag): array
{
    $hashes = [];
    foreach (explode("\n", git(['ls-tree', '-r', '--name-only', $tag])) as $file) {
        if (preg_match('#^tests/fixtures/.+/expected\.json$#', $file) !== 1) {
            continue;
        }
        $hash = hashIn(git(['show', $tag . ':' . $file]));
        if ($hash !== null) {
            $hashes[$file] = $hash;
        }
    }

    return $hashes;
}

function hashIn(string $json): ?string
{
    $decoded = json_decode($json, true);

    return is_array($decoded) && isset($decoded['hash']) && is_string($decoded['hash'])
        ? $decoded['hash']
        : null;
}

function previousTag(): ?string
{
    $tag = trim(git(['describe', '--tags', '--abbrev=0'], true));

    return $tag === '' ? null : $tag;
}

/**
 * @param array<int,string> $arguments
 */
function git(array $arguments, bool $tolerateFailure = false): string
{
    $command = 'git ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>/dev/null';
    $output = shell_exec($command);

    if (!is_string($output) && !$tolerateFailure) {
        fwrite(STDERR, sprintf("Command failed: %s\n", $command));
        exit(1);
    }

    return is_string($output) ? $output : '';
}

function readChangelog(): string
{
    $contents = file_get_contents(CHANGELOG);
    if ($contents === false) {
        fwrite(STDERR, "Cannot read CHANGELOG.md\n");
        exit(1);
    }

    return $contents;
}
