# Contributing

Thanks for looking. This file has two halves: how to run things, and the half
that matters — the handful of rules that are not guessable from the code and
will fail your build if you meet them by surprise.

## Getting set up

```bash
composer install
vendor/bin/phpunit          # 271 tests, offline, under a second
```

The full gate, which is what CI runs:

```bash
make check                  # tests + phpstan + cs + rector
make test PHP_VERSION=7.4   # one version in Docker
make test-all               # 7.4 through 8.5
```

The unit suite is **offline and deterministic**, deliberately. It reads
committed files and never talks to a cluster or the network. Keep it that way:
anything needing a real node belongs in `make certify` or `make integration`.

Other things you may need:

```bash
make mutation               # would the tests notice? ~35s
make bench                  # what a digest costs
make docs                   # serve the documentation site on :8000
make playground             # the same server: the playground is a page of it
make palette                # write the palette into both stylesheets
make dashboards             # rebuild the importable dashboard pack
make dashboards-up          # a Dashboards of each major, to look by hand
make dashboards-check       # import it into both, open it, assert it draws
make playground-runtime     # fetch the wasm PHP the playground runs
make playground-check       # drive the built page in a real Chromium
```

The playground is a page of the documentation site — `docs/playground.md`, whose
markup is `overrides/playground.html`. Two things about it are load-bearing and
easy to undo by accident. Its stylesheet and its module are declared for the
whole site in `mkdocs.yml`, not by the page, because instant navigation swaps
neither the `<head>` nor the scripts at the end of the body: an asset the page
declared alone is absent for every reader who arrives by a link rather than a
reload. And every id in that markup is prefixed `pg-`, because the page shares a
document with headings whose anchors are slugs. `PlaygroundTest` guards both.

The playground serves its own PHP-in-WebAssembly runtime rather than importing
one from a CDN. It is gitignored — 12.5 MB does not belong in this history — so
`tools/fetch-runtime.php` downloads it and verifies every file against
`playground/runtime.lock.json`. `make docs` does that for you.

The colours of the documentation and the playground come from
`tools/build-palette.php` and are generated into both stylesheets. Edit the tool,
not the CSS: `PaletteTest` fails if either file has drifted, and fails again if
any pair drops under the contrast ratio it is documented at.

**Never write a fingerprint into a page by hand.** Every digest example in the
docs is recomputed by `DocExampleTest`, which finds it by the
`<!-- verified: name -->` marker above its block — the same marker `UseCaseTest`
uses on the Use cases pages. A new example needs the marker and a case in that
test; a hash dropped into a sentence needs to be one the test already mints, or
it fails there. That guard exists because a block copied between two guides and
edited by hand shipped with a wrong hash *and* a wrong clause order: the
canonicaliser reorders clauses, so editing a rendered line is not editing a
query.

### The Docker matrix

Tests run in Docker across the whole supported matrix:

```bash
make test                   # PHP 8.3
make test PHP_VERSION=7.4   # one version
make test-all               # 7.4 → 8.5
make fixtures               # regenerate golden files — review the diff!
```

Quality gates, on the dev PHP:

```bash
make check                  # everything below, in one go
make stan                   # PHPStan, level max + strict rules
make cs                     # apply the coding standard
make rector                 # apply the Rector rules
make hooks                  # install the pre-push hook
```

`make hooks` points `core.hooksPath` at `tools/hooks/`, so the hook is versioned
with the code rather than living in an untracked `.git/hooks` every clone has to
recreate. It runs the same four checks CI runs — coding standard, Rector,
PHPStan, tests — on the dev PHP only: the Docker matrix takes minutes, and a
pre-push hook people wait on is a pre-push hook people bypass. `git push
--no-verify` skips it.

**PHPStan runs at `level: max` with `phpstan/phpstan-strict-rules` and
`treatPhpDocTypesAsCertain: true`** — the strictest configuration the tool
offers. That is not free on a library whose whole job is reading untrusted
decoded JSON: it forces every `mixed` to be narrowed before use. The parsing
layer is typed `array<mixed>` rather than `array<string,mixed>` for exactly that
reason — a JSON object whose key is `"0"` decodes to an *integer* key, and
claiming otherwise would be a lie the analyser cannot catch.

**Rector is pinned to `PhpVersion::PHP_74`**, the lowest supported release, so
the type-declaration set never emits syntax the matrix cannot install. Its
config avoids named arguments for the same reason: Rector installs happily on
7.4, where `php74: true` would be a parse error.

### Would the tests notice?

Line coverage answers "was this executed?". For a library whose product is a
stable hash, the question worth asking is "would anything fail if this stopped
doing its job?" — a normalisation rule quietly ceasing to fire is exactly the
bug no percentage would show.

```bash
make mutation      # ~35s, plus the image build the first time
```

Infection rewrites the source one small change at a time and re-runs the suite
against each. **Nothing in `src/` is uncovered**, and the covered mutation score
sits at 80%, guarded in CI so it cannot quietly fall.

It has already earned its keep. Three findings on the first run:

- `ABSORB_MATCH_NONE` is recorded from two branches — an AND that meets
  `match_none` matches nothing, an OR merely drops it — and only the AND one
  was tested. Deleting the OR branch's report left every test green.
- `UNWRAP` is only a rewrite when the connector had something to unwrap; a bool
  that arrives with a single clause was already reported by the parser. That
  guard was unpinned.
- `Hasher` carried its own default prefix and length beside the ones in
  `Options`. Nothing constructed it that way, so the copy was free to drift
  from the values actually used. It is gone.

Most of what still survives is string concatenation in CLI help and error text
— reordering a message nobody asserts on. The threshold is a ratchet: raise it
when real escapes are killed, never lower it to make a build pass.

Infection keeps its own manifest in `tools/infection/`. It needs PHP 8.3 while
this library supports 7.4, so putting it in the root `require-dev` would either
break `composer update` on 7.4 or drag in a release from 2021 that nobody runs.

## The rules that bite

### 1. The hash is a public contract

People build dashboards that group by fingerprint. If a normalisation rule
changes, every hash changes and those dashboards silently stop matching.

`tests/fixtures/*/expected.json` pins the exact hash of every fixture, so a
change shows up as a reviewable diff rather than a surprise.

**Never regenerate fixtures without reading the diff.** `UPDATE_FIXTURES=1` and
`make fixtures` will happily rewrite every expectation to match whatever the
code now does, which turns a broken test into a green one and a contract
violation into a commit nobody noticed.

When hashes do move, the prefix moves with them — `q3:` → `q4:` — so the break
is visible in the data instead of invisible.

### 2. Promotions must be batched

Thirteen query types render as `type(?)`. Giving one of them a native rendering
changes the fingerprints of every query that uses it.

**The prefix is global.** Promoting one rare type invalidates every dashboard
just as thoroughly as promoting eight. So promotions are collected and shipped
together, and that cost is paid once. If you want to promote a type, say so in
an issue first — it may be waiting for company.

Some of the thirteen are opaque *on purpose* and are not looking for a
volunteer: the `span_*` family (nobody debugs a span query from a log line),
`type` (removed with mapping types), `sltr` and `template` (rescoring, or
another endpoint), `agentic` (a model decides outside the DSL). If you run a
plugin the library cannot read, you do not need a fork — register a
`ClauseRenderer`.

### 3. A new class is `@internal` until someone decides otherwise

Every class in `src/` carries `@api` or `@internal`, and `ApiBoundaryTest` fails
if one carries neither, both, or if a public method returns an internal type.

Nineteen classes are public. The parser, the tree and the renderers are not —
they change on every promotion, and freezing them would make each rendering
improvement a major release.

Adding a class? Mark it `@internal` unless you mean to promise it forever.
Widening the public surface is a deliberate line in that test's list, not a side
effect.

### 4. PHP 7.4 is the floor, and it constrains the tooling

Everything in the root `require-dev` must install on 7.4, because CI resolves
dependencies on every version from 7.4 to 8.5.

A tool that needs something newer gets **its own manifest**, the way Infection
does in `tools/infection/`. Adding it to the root would either break
`composer update` on 7.4 or drag in an ancient release nobody runs.

The library code itself is 7.4-compatible: no union types, no enums, no
constructor promotion, no `match`. Rector is configured to `PHP_74` and will
tell you.

### 5. The mutation score is a ratchet

`make mutation` must stay at or above the threshold in `infection.json5`.

Raise it when you kill real escapes. **Never lower it to make a build pass** —
that converts a signal into decoration. If a mutant is genuinely equivalent, say
so in the pull request rather than moving the number.

### 6. The changelog is the source, and it is checked

Release notes are extracted from `CHANGELOG.md`, not written at tag time. Add
your entry to the unreleased section in the same pull request as the change, so
it gets reviewed with it.

Every section carries a `Fingerprints:` line, and it is verified against the
pinned hashes:

```bash
make release-check VERSION=v0.8.0
```

It fails if you claim a prefix move that did not happen, or move hashes and stay
quiet about it.

### 7. New query types must be classified

`resources/opensearch-spec.json` is a committed snapshot of the OpenSearch API
specification. `make spec` refreshes it, and `SpecCoverageTest` then fails until
every new type is recorded in `resources/coverage.json` as `native` or `opaque`.

`make certify` then fails until it is either probed against a live cluster or
given a written reason why it cannot be.

## Sending a change

- **Conventional commits.** `feat:`, `fix:`, `chore:`, `docs:`, `test:`,
  `refactor:`. A `!` marks a breaking change — `feat!:`, `refactor!:`.
- **Explain the why in the message.** The diff already shows the what. This
  repository's history leans long on purpose: the reasoning is the part that is
  expensive to reconstruct in six months.
- **Open a pull request.** CI runs on `push: main` and on pull requests — a
  feature branch gets no CI until the pull request exists, so do not read a
  quiet branch as a green one.
- The pre-push hook runs the local gate. It lives in `tools/hooks`; `make hooks`
  installs it.

## Reporting a bug

An OpenSearch request body that renders wrongly is the most useful bug report
there is, because it becomes a fixture. Include the body, what you got, and what
you expected.

For anything security-related, see [SECURITY.md](SECURITY.md) — please do not
open a public issue.
