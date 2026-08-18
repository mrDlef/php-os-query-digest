# Contributing

Thanks for looking. This file has two halves: how to run things, and the half
that matters — the handful of rules that are not guessable from the code and
will fail your build if you meet them by surprise.

## Getting set up

```bash
composer install
vendor/bin/phpunit          # 184 tests, offline, under a second
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
make playground             # serve the browser playground on :8080
```

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

Fourteen classes are public. The parser, the tree and the renderers are not —
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
