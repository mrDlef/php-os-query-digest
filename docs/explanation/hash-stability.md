# Hash stability

**The hash is a public contract.** If a normalisation rule changes, every hash
changes — and any dashboard built on it silently breaks. So:

- the hash carries its algorithm version: `q4:8f3ac1d2b901`. A rule change bumps
  it — `q4:` → `q5:` — making the break visible in your data instead of
  invisible. The prefix has moved three times: v0.2.0 promoted three query types
  out of `type(?)`, v0.6.0 promoted eight more, and v0.10.0 taught the parser the
  older spelling of a range, so anything published as `q1:`, `q2:` or `q4:` was
  minted by an older set of rules. Promotions are deliberately batched into one
  release for that reason — the prefix is global, so promoting a single rare type
  would invalidate every dashboard on its own.
- a signature that did not change keeps its twelve hex characters. `q4:abc…`
  and `q4:abc…` describe the same shape, which makes a prefix bump readable
  rather than a wall of new values.
- every fixture pins its exact hash in `tests/fixtures/*/expected.json`. A rule
  change cannot land without a reviewable diff.
- a change to the produced hashes is a **major** release.
- [`CHANGELOG.md`](https://github.com/mrDlef/php-os-query-digest/blob/main/CHANGELOG.md) says, for every released version, whether your
  fingerprints moved — and that claim is checked, not trusted. `make
  release-check VERSION=v0.7.0` compares the entry against the hashes pinned in
  `tests/fixtures`, so a release cannot promise your dashboards survived when
  they did not, or move every hash without saying so.

`CHANGELOG.md` is the source the GitHub release notes are extracted from, not a
copy of them: the notes are reviewed in the pull request that ships the change
rather than written after the tag.

```bash
make release-check VERSION=v0.7.0             # may this be tagged?
php tools/changelog.php section v0.7.0 > notes.md
gh release create v0.7.0 --verify-tag --latest --notes-file notes.md
```

**Write the notes to a file and check it before publishing**, rather than
piping. v0.7.0 shipped with every blank line stripped out — headings glued to
paragraphs — because the extraction went through a pipeline that reflowed it,
and nothing looked at the result until after it was public. The extraction
itself was fine. Afterwards, compare what was published against the source:

```bash
gh release view v0.7.0 --json body --jq .body | diff - notes.md
```

There is no generator, and there will not be one. v0.6.0's notes run to four and
a half thousand characters over a single commit — which types were promoted, why
the thirteen left are a settled position, that both prefix bumps kept every hex
character. None of that is in anybody's `git log`. What a tool can check is
whether the entry is *true*, and that is what the tool checks.

Twelve hex characters (48 bits) keep collisions negligible at any realistic
number of distinct query shapes; `withHashLength()` adjusts it.
