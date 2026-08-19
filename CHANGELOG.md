# Changelog

Every released version, and what it did to your fingerprints.

**This file is the source.** A release's notes on GitHub are extracted from the
section below it — `make release-notes VERSION=v0.6.0` — so what ships is what
was reviewed in the pull request, rather than prose written after the tag.

**Every section carries a `Fingerprints:` line**, and it is checked rather than
trusted. `tools/changelog.php check` compares the hashes pinned in
`tests/fixtures/*/expected.json` between two tags and fails if the line
disagrees with what actually happened — a release cannot claim your dashboards
survived when they did not, or forget to mention that they did not.

The prefix has moved twice, and both times only the prefix: a signature that did
not change kept its twelve hex characters, so `q2:abc…` and `q3:abc…` describe
the same query. See [Hash stability](https://mrdlef.github.io/php-os-query-digest/explanation/hash-stability/).

| prefix | from | why |
|---|---|---|
| `q1:` | v0.1.0 | the first published rules |
| `q2:` | v0.2.0 | three query types promoted out of `type(?)` |
| `q3:` | v0.6.0 | eight more promoted |
| `q3x:` | — | not a release: any digest minted with a registered `ClauseRenderer` carries the `x`, because the rules are then no longer this library's alone |

## v0.8.0 — unreleased

_contributing and security_

**Fingerprints:** `q3:` unchanged.

[`SECURITY.md`](SECURITY.md) — how to report a vulnerability (privately, through
GitHub), which versions are supported, and a threat model grounded in what the
library actually does rather than in boilerplate: no file access, no network, no
process execution at runtime, and `php` plus `ext-json` for dependencies. It is
blunt about the one real risk, which is that at the default normalisation the
rendered *line* keeps literal values — so a `term` on an email puts that email
wherever the line goes. The signature and the hash never do.

[`CONTRIBUTING.md`](CONTRIBUTING.md) — the commands, and then the half that
matters: the seven rules that are not guessable from the code. Never regenerate
fixtures without reading the diff, promotions are batched because the prefix is
global, a new class is `@internal` until someone decides otherwise, PHP 7.4 is
the floor and it constrains the tooling, the mutation score is a ratchet, the
changelog is the source, and new query types must be classified.

## v0.7.0 — 2026-08-18

_the pre-1.0 hardening_

**Fingerprints:** `q3:` unchanged.

Nothing here moves a hash. It is the work of deciding what a `1.0.0` would be
promising, before promising it.

### What is public, and what is not

Every class in `src/` is now marked `@api` or `@internal`, and `ApiBoundaryTest`
fails the suite if one is marked neither, marked both, or if a public method
hands back an internal type — a type reachable from a public signature is public
whatever its annotation claims.

**Fourteen classes are the public surface.** The parser, the tree, the
renderers, the canonicaliser and the hasher are not, and that is the point:
those are exactly the classes that change whenever a query type is promoted.
Frozen, every improvement to the rendering would be a major release.

**Breaking:** `IndexNormalizer` moves from `Support\` to the root namespace,
beside `Normalization`, the sibling concept it is configured with.

```php
use MrDlef\OsQueryDigest\Support\IndexNormalizer;   // before
use MrDlef\OsQueryDigest\IndexNormalizer;           // after
```

The `elasticsearch` keyword and mention are gone from `composer.json`. The DSL
overlaps enough that the library is useful against Elasticsearch; that is not
the same as promising it when no ES-specific type was ever classified or
certified.

### Teaching it a query type it does not know

`Options::withClauseRenderer()` takes an `Extension\ClauseRenderer` for a type
the library leaves opaque — the Learning-to-Rank plugin's `sltr`, or a query
type private to your cluster.

```
before  q=(sltr(?))
after   q=(_score:sltr(model=ltr_model_v3))
```

Three properties make it safe: a renderer cannot reach a natively modelled type
(the hook sits in the parser's default branch, so `term` has already returned);
the hash version is marked `q3x:` as soon as one is registered, because the
rules are then no longer this library's alone; and `explain()` reports
`extension_rendered`.

### Would the tests notice?

Mutation testing runs in CI — `make mutation` locally. Nothing in `src/` is
uncovered and the covered score is 79%, guarded so it cannot quietly fall.

It found three real gaps on its first run: `ABSORB_MATCH_NONE` was recorded from
two branches and tested on one, `UNWRAP`'s guard was unpinned, and `Hasher`
carried a second copy of the defaults that live in `Options` — unreachable, and
free to drift from the values actually used.

### What it costs

`make bench` measures the request path against the committed fixtures. The
pitch — that you can afford a digest on every search — was an argument until
now: **~30 µs per request on PHP 8.5, ~37 µs on 7.4**, against a search that
takes milliseconds. `lazy()` costs 0.2 µs, some hundred times less, so a debug
record your handler drops really does parse nothing.

No timing gate in CI: wall-clock on a shared runner is noise, and a threshold
tight enough to catch a regression would fail on a busy afternoon.

### The playground answers to nobody

It used to `import()` its PHP-in-WebAssembly runtime from a public CDN, which
cost two things. Every visitor was disclosed to a third party the page never
named, and a dynamic `import()` takes no `integrity` attribute — so nothing
checked that what arrived was what had been published.

**The runtime is now served from this site.** `tools/fetch-runtime.php` downloads
it at build time and verifies all nine files against the SHA-256 hashes in
`playground/runtime.lock.json`; a substituted artefact fails the deploy rather
than reaching a browser. That verification is the point of the arrangement, and
it was not available before: the usual workaround for an unverifiable dynamic
import — fetching the module and importing a hash-checked blob URL — cannot work
here, because this runtime resolves its wasm with `new URL(…, import.meta.url)`
and a blob URL breaks that.

The runtime is **not committed**. 12.5 MB does not belong in the history of a
library whose own package is measured in kilobytes, and the repository's largest
file is 146 KB. It is fetched by `make playground-runtime`, gitignored, and
downloaded in the Pages workflow beside MkDocs, for the same reason MkDocs lives
only there.

What it costs a visitor: **about 3.1 MB instead of 2.8 MB**, once — GitHub Pages
gzips wasm where the CDN served brotli. What it buys: the page's claim is now
literally true. Nothing it loads comes from anywhere but the site serving it, and
`PlaygroundTest` fails if that stops being so.

That guard is worth a note. The first version matched the *shape* of a loader
call, looking for `import('https://…')` — and passed the very line it existed to
forbid, because the URL reached `import()` through a constant. It matches on
hosts now: two are allowed, both anchors, and a link the reader may click is not
a request the page makes.

Also fixed while verifying this in a real browser:
`tools/playground-browser-check.mjs` had pinned `q2:5b2210eb5318` as the hash the
CLI produces. The prefix moved to `q3:` in v0.6.0 and nothing noticed, because
that script is deliberately not in CI. It asks the CLI now, which is what its
comment always claimed it did.

### Use cases, with every query executed

Four [Use cases](https://mrdlef.github.io/php-os-query-digest/use-cases/) pages,
placed before the guides because a reader who has just installed this wants to
know what it answers, not what its options are.

The home page already claimed the log index could tell you "which kind of query
got slow this afternoon, which shape runs a thousand times an hour, which one
appeared the day the incident started". Nothing behind it showed how. These pages
do, and the answers are less obvious than the claim:

- **Ranking by p95 finds the wrong query.** It surfaces the report that takes a
  second and a half and took a second and a half yesterday. Ranking each shape
  against *its own history* finds the one that went from 50 ms to 1074 ms.
- **The query you run most is not the query that costs you most.** The workhorse
  ran 7200 times for 57.6 s; a dashboard aggregation ran 360 times for 118.6 s.
- **A deploy marker and a latency bump are not the same event.** In the scenario
  the slowdown lands at 14:00 and the release at 15:00, so the obvious story —
  the release broke search — is wrong and hard to disprove without the hash.
- **The hash is not a cache key.** Three tenants share one fingerprint, because
  erasing literals is the whole point. Cache on it and tenant 42 is served
  tenant 41's invoices.

**Every aggregation on those pages is executed by `UseCaseTest` against
OpenSearch 2.19.6 and 3.8.0**, including the one shown as a counter-example,
which has to keep being wrong. The queries are *extracted from the markdown* by a
`<!-- verified: -->` marker rather than copied into the test, so there is one copy
and it is the one a reader sees. The test also fails if a page prints a hash this
library no longer produces, and if a marked block has no test behind it.

That caught two things worth admitting: a page that quoted an invented hash, and
a page that showed `status:?` inside the `q` field while explaining that `q` keeps
its values.

`make integration` was broken against the 3.x node on any machine with less than
about 100 GB free, and had been. 3.x adds a headroom to the flood-stage watermark,
so the block trips under ~100 GB regardless of the percentage, and the node
answers index creation with a bare 403 `index_create_block_exception` that
mentions no disk. `os2` already disabled the threshold; `os3` now does too.

### A documentation site

**<https://mrdlef.github.io/php-os-query-digest/>** — built with MkDocs
Material, published from a release tag like the playground and for the same
reason: a page describing an API nobody can install yet is worse than a page a
few days out of date.

The README had grown to 36 KB across 23 sections, one of which was 39% of the
file on its own. It is now 6 KB: what the library does, the before-and-after,
how to install it, and where to read more. Everything else moved to pages, plus
new material that was missing — a five-minute getting started, and a reference
for the fourteen public classes written from reflection rather than from memory.

**The playground moves to
[/playground/](https://mrdlef.github.io/php-os-query-digest/playground/).** The
root now serves the documentation, which is what people arrive looking for. The
`composer.json` homepage is unchanged; it points at the same URL, which now
answers with docs.

The two share one identity rather than looking like a site and an app that
happen to be neighbours — and that identity is now generated rather than written
twice. Bone is the field, pitch black is the ink, and a lobster marks anything
you can act on: six values, emitted into both stylesheets from
`tools/build-palette.php`, because they were written twice and the second copy
drifted inside a day.

The lobster needed two of those six. It reads 2.87:1 on bone and 3.88:1 on the
playground's raised pane, so it carries no text itself — light mode takes a
deeper one, dark mode a lifted one, and the hue they are drawn from stays in the
palette as the thing they agree on. Nothing is `#fff`: Material defaults three
variables to white or an alpha of it, and all three name bone.

Every pairing is measured, not checked once. `PaletteTest` parses the shipped
stylesheets rather than the tool's own values — which would prove only that the
tool agrees with itself — resolves each `var()` chain, and reports all 34
documented pairs. The 30 text pairs clear AA with the tightest at 4.73:1 and
fourteen of them at AAA; the four form borders clear the 3:1 that identifies a
control. A colour nudged to taste turns CI red instead of shipping 3.9:1.

**The site fetches no fonts.** It linked a stylesheet on `fonts.googleapis.com`
and preconnected to `fonts.gstatic.com`; it now references neither, naming the
system stacks the playground already used. The playground's corners follow
Material's three radii — 2px for controls, 4px for panes, a pill for the chips
that were already one. Its links to the source and to Packagist carry inline
marks, still fetching nothing from anywhere.

MkDocs lives only in the Pages workflow — not in `composer.json`, not in the
package. `make docs` serves the site locally through the same pinned image CI
uses, so a local build and the published one cannot disagree.

### This file

`CHANGELOG.md` is new, and it is the source: release notes are extracted from
it, so they are reviewed in the pull request that ships the change rather than
written after the tag. `tools/changelog.php check` holds each entry to the
hashes pinned in `tests/fixtures`.

## v0.6.0 — 2026-08-18

_eight query types promoted, and the hash moves to q3_

**Fingerprints:** `q2:` → `q3:` — eight query types promoted. Every signature that did not change kept its twelve hex characters.

Coverage goes from **38 native / 21 opaque** to **46 / 13** of the 59 query types in the OpenSearch specification. The fingerprint prefix moves **`q2:` → `q3:`**.

### Breaking: your fingerprints change

Every hash minted by this version carries the `q3:` prefix. Dashboards grouping on `q2:` values will not match new digests — that is what the prefix is for, and it is why all eight promotions ship together rather than one per release: the prefix is global, so promoting a single rare type would invalidate exactly as much as promoting eight.

**A signature that did not change keeps its twelve hex characters.** All 16 pre-existing fixtures moved their prefix and nothing else:

```
v0.5.0  q2:fe168406e702
v0.6.0  q3:fe168406e702
```

So `q2:abc…` and `q3:abc…` describe the same shape, and a prefix bump reads as a rename rather than a wall of unrelated values.

### What was promoted

| type | before | after |
|---|---|---|
| `hybrid` | `hybrid(?)` | `(embedding:knn(k=20) or title:"waterproof hiking boots")` |
| `wrapper` | `wrapper(?)` | the decoded query, in full |
| `combined_fields` | `combined_fields(?)` | `title\|body:"connection timeout"` |
| `common` | `common(?)` | `msg:timeout` |
| `percolate` | `percolate(?)` | `alerts:percolate()` |
| `rank_feature` | `rank_feature(?)` | `popularity:rank_feature()` |
| `distance_feature` | `distance_feature(?)` | `created_at:distance_feature(pivot=7d)` |
| `intervals` | `intervals(?)` | `msg:intervals()` |

**`hybrid` is the one that matters on a modern cluster.** The flagship OpenSearch pattern — a lexical clause and a vector clause combined under a normalisation pipeline — used to collapse into a single word that said nothing about a query whose entire point is what it combines. It returns the union of its `queries` exactly as `dis_max` does; the two differ only in how scores are blended, and this library already declines to distinguish scoring:

```
q=(embedding:knn(k=20) or title:"waterproof hiking boots")   q3:a8a542c4af15   (hybrid)
q=(embedding:knn(k=20) or title:"waterproof hiking boots")   q3:a8a542c4af15   (dis_max)
```

**`wrapper` recovers rather than summarises.** A query passed through base64 as an opaque blob is decoded and parsed, so it fingerprints identically to the same query sent unwrapped:

```
{"term":{"env":"prod"}}                              q3:1cc724ddd8ef
{"wrapper":{"query":"eyJ0ZXJtIjp7ImVudiI6InByb2QifX0="}}   q3:1cc724ddd8ef
```

**`rank_feature` and `distance_feature` stay leaves** rather than unwrapping the way `function_score` does. They read as boosting and sit where a boost would, but a document without the field does not match — so they genuinely restrict the result set. The scoring curve (saturation, log, sigmoid, and a distance `origin`) is dropped: it reorders, it does not exclude.

`intervals` keeps the field only — modelling `all_of`/`any_of`/`max_gaps`/`ordered` would be a parser inside the parser for the rarest type that has a field at all. `percolate` keeps the field, which says which set of saved queries is being replayed; its indexed-document variant gets the same warning a terms lookup does. `combined_fields` and `common` reuse the paths `multi_match` and `fuzzy` already take.

### What stays opaque, and why that is now settled

The 13 remaining types are a position, not a backlog:

- the **`span_*` family** (9, including `field_masking_span`) — nobody debugs a span query from a log line, and promoting one span without the rest would read worse than promoting none;
- **`type`** — removed with mapping types; no live cluster accepts it;
- **`sltr`** — a Learning-to-Rank plugin absent from the official image, and pure rescoring;
- **`template`** — lives behind `/_search/template`, not in a query clause;
- **`agentic`** — hands the whole result set to a model deciding outside the DSL.

They are still signalled as `type(?)`, never dropped, and still contribute to the fingerprint.

### Certification and compatibility

All eight promoted types were already certified against live clusters, with two documented exceptions: `combined_fields` is accepted by 3.8.0 and answers `unknown query` on 2.19.6, and `hybrid` cannot be probed without a registered search pipeline — its reason is recorded in `resources/probes.json`.

Runtime requirements are unchanged: **`php` and `ext-json`, nothing else.** Tested on PHP 7.4 through 8.5.

### Upgrading

```bash
composer require mr-dlef/os-query-digest:^0.6
```

If you store fingerprints, expect `q3:` on everything minted after the upgrade. Historical `q2:` values remain correct for the rules that produced them — and where the shape is unchanged, the twelve hex characters let you line the two up.

## v0.5.0 — 2026-08-17

_a CLI and a browser playground_

**Fingerprints:** `q2:` unchanged.

Two ways to use the library without writing a line of PHP: a command you can
pipe a slow log through, and a page that runs it in your browser.

**No hash moves.** Every fingerprint `v0.4.0` produced, `v0.5.0` produces. This
is additive throughout.

### A CLI

```console
$ echo '{"query":{"term":{"service":"api"}},"size":50}' \
    | vendor/bin/os-query-digest --index logs-2026.08.13
idx:  logs-*
text: logs-* | q=(service:api) | size=50
sig:  logs-* | q=(service:?) | size=50
hash: q2:5b2210eb5318
```

`--explain` appends the rules table, `--json` emits the digest object, `--hash`
emits nothing but the fingerprint.

The reason it exists is `--ndjson` — one query per input line, one line of
output each, which is the shape `sort | uniq -c | sort -rn` expects:

```console
$ os-query-digest --ndjson --hash < slow.ndjson | sort | uniq -c | sort -rn
      3 q2:3109618415cb
      1 q2:a3e42b3a6c70
```

Those three are not three slow queries to read: they are one shape, hit on two
different days, with two different `service` values. That is the question no
amount of reading individual slow queries answers.

A malformed line is reported on stderr and skipped — a slow log is untrusted
input, and stopping at the first mangled record would make the tool useless
exactly where it is needed. Exit codes: `0` ok, `1` an input could not be
parsed, `2` a bad invocation.

### A playground

**<https://mrdlef.github.io/php-os-query-digest/>** runs this library on your
query, in your browser, with no server involved: PHP itself compiled to
WebAssembly. Your query never leaves the page — there is nowhere to send it.

It opens on a precomputed example and downloads **nothing**; the moment you
change the query or an option it fetches a PHP 8.3 and runs the real library.
Measured in Chromium: 2.77 MB transferred, ~300 ms to a working interpreter,
0.5 ms per query after that.

Pin a query as a reference, then edit it: the page tells you whether the
fingerprint moved and **which normalisation rule made the difference** — the
question you actually have when two queries you thought were different share a
hash. Every state is a permalink, so a bug report can be a link.

What it shows is guarded by the offline suite: the library is shipped to the
browser as one file, and `PlaygroundTest` executes that file with real PHP
against the golden fixtures. "The browser runs the same library as `composer
require` does" is checked by CI without a browser or a byte of wasm.

### `Options::fromArray()`

Every front end that is not PHP configures through a string map — a CLI flag, a
YAML block, a query string:

```php
Formatter::create(Options::fromArray([
    'normalization' => 'structural',
    'maxValues'     => 5,
    'aggNames'      => true,
]));
```

Unknown keys and wrong types throw `InvalidOptionException` rather than being
ignored: an option that silently does nothing is the bug you find months later,
in a dashboard that was never grouped the way the config claimed. Types are
taken as JSON gives them — `"5"` is rejected, because a front end that guesses
at `"5"` also accepts `"five"`.

`Normalization::fromLevel()` and `IndexNormalizer::fromMode()` are its
counterparts, and `Options::KEYS`, `Normalization::LEVELS` and
`IndexNormalizer::MODES` are public so a help text or a `<select>` never
hard-codes a vocabulary that then drifts.

### Also

- The README leads with the problem and a real before/after, using a fixture as
  its showcase — so every number on the front page is pinned by the test suite.
- Workflows moved off Node 20 actions.
- Tested on PHP 7.4 → 8.5, PHPStan `level: max`, still no runtime dependencies.

## v0.4.0 — 2026-08-16

_Monolog processor_

**Fingerprints:** `q2:` unchanged.

If your application already logs its OpenSearch request bodies, you no longer
have to touch every call site. Push one processor and the raw request is
replaced by its digest wherever it appears.

```php
use MrDlef\OsQueryDigest\Monolog\DigestProcessor;

$logger->pushProcessor(new DigestProcessor());

$logger->info('opensearch.search', [
    'query' => $request,               // → {"idx": …, "q": …, "sig": …, "hash": …}
    'index' => 'logs-2026.08.16',
    'took'  => $response['took'],      // untouched, like the rest of the context
]);
```

The keys it reads are configurable — `new DigestProcessor($formatter,
'search_body', 'target')` — and anything that is not a search request is left
exactly as it was found. A processor that guessed would corrupt your log lines.

### Both Monolog versions, one class

- Monolog 2 hands a processor an array; Monolog 3 hands a `LogRecord`.
  Implementing `ProcessorInterface` would pin the class to one of them, so it
  stays a **plain callable** — which both versions accept wherever a processor
  is expected.
- Monolog 3 makes `context` **readonly**, so it cannot be assigned. The update
  goes through `with(context: …)`. Named arguments are PHP 8 syntax and this
  file has to *parse* on 7.4, so the same call is written as an unpack of a
  string-keyed array, which PHP 8.1 turns into named arguments — and 8.1 is
  Monolog 3's own floor.
- `instanceof` against a class that does not exist is false rather than an
  error, and does not autoload, so the Monolog 3 branch never runs under 2.

This matters because a PHP 7.4 user can only have Monolog 2, and that is exactly
who the 7.4 support exists for. Verified on both sides: 7.4 → Monolog 2.11, 8.0
→ Monolog 2, 8.1+ → Monolog 3, with the same 107 tests.

### Lazy, and safe

The digest stays lazy, so a record buffered by a `FingersCrossedHandler` that
never triggers costs nothing.

That laziness moves any parse failure into Monolog's formatting, where an
exception would cost the whole record. So a request the library cannot read
yields `{"error": "…"}` in place of the digest. **You lose the digest, never the
log line.**

It deliberately does not fall back to the raw request: that would restore the
wall of nested braces this library exists to keep out, at the size that made it
unloggable in the first place. The error message says what went wrong, which is
the part you can act on.

### Dependency

Monolog is a **suggested** dependency, never a required one. The library itself
still has no runtime dependencies beyond `ext-json`.

```json
"suggest": {
    "monolog/monolog": "^2.0 || ^3.0"
}
```

### No hash moves

Nothing under `src/Fingerprint`, `src/Render`, `src/Parser`, `src/Normalizer` or
`src/Tree` changed, and no fixture moved. The `q2:` prefix stays and every
fingerprint published under it remains valid.

**Full changelog**: https://github.com/mrDlef/php-os-query-digest/compare/v0.3.0...v0.4.0

## v0.3.0 — 2026-08-16

_certified against real OpenSearch clusters_

**Fingerprints:** `q2:` unchanged.

Which OpenSearch versions this library supports is now **measured, not
inferred**.

Until now the answer came from `resources/opensearch-spec.json`, which could
only ever record a *floor*: the OpenSearch API specification carries no
per-type version marker, so `opensearch_version_floor: 3.2.0` meant "this
snapshot knows about features up to 3.2" — never "the library supports 3.2".
The README said so, and left it there. This release closes that gap with live
clusters.

### Certified against OpenSearch 2.19.6 and 3.8.0

| | |
|---|---|
| 53 types | probed against live clusters |
| 52 | accepted by 2.19.6 |
| 53 | accepted by 3.8.0 |
| 6 | cannot be probed, each with a written reason |

`make certify` boots throwaway 2.x and 3.x nodes, sends every query type the
library claims to handle, and records what came back in
`resources/versions.json`.

**The one finding the specification could not have given you:**
`combined_fields` is listed as a query type, 3.8.0 accepts it, and 2.19.6
answers `unknown query [combined_fields]`.

The six unprobed types are not a gap left quiet. `resources/probes.json` says
why each one cannot have a probe: `neural` needs a deployed ML model id, `sltr`
needs a plugin absent from the official image, `agentic` needs a configured
agent, `hybrid` needs a search pipeline, `template` lives behind a different
endpoint, and `type` was removed with mapping types. Certifying those would test
someone's cluster configuration, not this library.

### A probe that fails for the wrong reason stops the run

`make certify` records a type as unsupported only on "unknown query". Any other
failure aborts instead of being written down — filing our own malformed DSL as
"this version does not support X" would poison the very file the tool exists to
produce.

That guard earned its place during development: the first run reported
`match_all` and `match_none` as rejected by 2.19.6, and the cause was PHP
re-encoding a decoded `{}` as `[]`, not OpenSearch. A laxer tool would have
committed two invented version differences.

### Three layers, one job each

- **`tools/certify.php`** writes the matrix. Needs clusters.
- **`CertificationTest`** guards it offline: every type is either probed or
  explained, and every natively-rendered type is re-checked against its *real*
  probe body rather than the minimal one `SpecCoverageTest` uses. A type that is
  "native" but falls through to `type(?)` on a real-world body is native in name
  only.
- **`tests/Integration`** replays the matrix against a live node, in its own
  suite, skipped without `OPENSEARCH_URL`.

The default suite stays offline and Docker-free. `defaultTestSuite` now makes
that explicit: a bare `phpunit` no longer touches the network.

A scheduled workflow replays the matrix weekly, matching by major version so an
upstream patch release does not turn the job red for nothing.

### No hash moves

`src/` is untouched since v0.2.0. The `q2:` prefix stays, and every fingerprint
published under it remains valid — this release is tooling, tests and resources.

```bash
composer require mr-dlef/os-query-digest
```

**Full changelog**: https://github.com/mrDlef/php-os-query-digest/compare/v0.2.0...v0.3.0

## v0.2.0 — 2026-08-16

_shape queries, and the hash prefix moves to q2_

**Fingerprints:** `q1:` → `q2:` — three query types promoted. Every signature that did not change kept its twelve hex characters.

The shape queries are rendered natively, and the hash prefix moves to `q2:`.

### ⚠️ Breaking: `q1:` → `q2:`

Promoting a query type out of `type(?)` moves the fingerprint of every query
that used it, and **the prefix is global** — `Hasher` applies it to all hashes,
not only to the ones that changed. Anything you have stored as `q1:` was minted
by an older set of rules and does not compare with a `q2:` value.

One detail that makes the migration cheaper than it sounds: a signature that did
not change keeps the same twelve hex characters. Only the prefix moved. So
`q1:b7cc218cda09` and `q2:b7cc218cda09` describe the same query shape — a
dashboard can be repointed by matching on the hex if you need continuity across
the boundary.

The three types shipped together deliberately: one prefix bump rather than three.

### What's new

**`geo_polygon`** joins `geo_bounding_box` — an area given inline, with no
scalar worth logging, so the field and the kind of area are the whole clause:

```
q=(location:geo_polygon())
```

**`geo_shape`** and **`xy_shape`** keep two parts of the clause *in the
signature*, which no other operator does:

```
text: q=(drop_zone:geo_shape(polygon,within))
sig:  q=(drop_zone:geo_shape(polygon,within))
```

The relation does not parameterise the query, it *is* the query: `within` and
`disjoint` over the same polygon return opposite result sets, so erasing it
would be the geo equivalent of erasing a `not`. The geometry kind reads the same
way. Both come from a closed vocabulary — four relations, nine geometry kinds —
so keeping them cannot inflate the number of distinct fingerprints. The
coordinates are values and are erased, so two searches over neighbouring
polygons share a fingerprint.

`intersects` is what OpenSearch applies when the query says nothing, so an
absent relation renders as `intersects` — writing it and omitting it produce the
same hash.

An `indexed_shape` points at a geometry stored in another document: the same
blind spot as a terms lookup, and it gets the same treatment — rendered as
`indexed`, listed in the notes, and reported by `explain()` under the
`indexed_shape` rule.

### Coverage

**38 of the 59 query types** are now rendered natively, up from 35. The
remaining 21 — `percolate`, `intervals`, `rank_feature`, the `span_*` family,
plugin queries — render as `type(?)`: signalled, never dropped, and still part
of the fingerprint. The span family (9 of the 21) stays there on purpose; nobody
debugs a span query from a log line.

### Also

The dev tool configs (`.php-cs-fixer.dist.php`, `rector.php`) no longer ship in
the dist archive. It now contains `LICENSE`, `LICENSE.GPL`, `README.md`,
`composer.json`, `src/` and `resources/` — nothing else.

### Verified

`make check` (php-cs-fixer, Rector, PHPStan at level max with strict rules,
PHPUnit) plus the suite on PHP 7.4 through 8.5 in CI. 89 tests, 245 assertions.

**Full changelog**: https://github.com/mrDlef/php-os-query-digest/compare/v0.1.0...v0.2.0

## v0.1.0 — 2026-08-16

_first release_

**Fingerprints:** `q1:` — the first published prefix.

First release.

`os-query-digest` turns an OpenSearch / Elasticsearch DSL request into one
readable line, and into a stable fingerprint that survives a change of
parameters:

```
logs-* | q=(@timestamp >= now-15m and service:api) | size=0
q1:b7cc218cda09
```

The line is meant to be logged. The `q=(…)` segment is DQL, so it pastes
straight into the Dashboards search bar. The hash is meant to be grouped by:
two runs of the same query with different values share it, and two genuinely
different queries do not. Log it alongside `took` and you can ask your own log
index which *kinds* of query are slow — using OpenSearch to analyse OpenSearch.

```bash
composer require mr-dlef/os-query-digest
```

### What's in it

- **35 of the 59 query types** in the OpenSearch API specification are rendered
  natively — term-level, full text, compound, joining, vector (`knn`, `neural`),
  geo, `script`, `script_score`, `parent_id`. The other 24 render as `type(?)`:
  signalled, never dropped, and still part of the fingerprint.
- **All 65 aggregation types** are rendered; 12 get a shape tuned for reading
  (`terms(host,10)`, `date_histogram(@ts,1h)`, `p95(latency)`).
- **`explain()`** names the normalisation rules that produced a fingerprint, so
  "why do these two queries share a hash?" has an answer that is not a guess. It
  costs no second pass — the rules are recorded during the normal parse.
- **`post_filter`** renders as its own `post=(…)` segment rather than being
  folded into `q=(…)`: it runs after the aggregations, so merging the two would
  describe a query nobody sent.
- **Three normalisation levels** — `none`, `values` (the default) and
  `structural` — plus display caps on clauses and values, and an optional
  redactor for value-level PII.
- **No runtime dependencies**, PHP 7.4 through 8.5, LGPL-3.0-or-later.

### Things it gets right that are easy to get wrong

- `must_not: [A, B]` is `(NOT A) AND (NOT B)`, never `NOT (A AND B)`.
- A `should` group beside a `must`/`filter` with no `minimum_should_match` only
  boosts — rendering it as a filter would make the line lie.
- `sort: ["_score"]` defaults to *descending*, unlike every other field.
- Rolling indices collapse (`logs-2026.08.13` → `logs-*`), so a daily index does
  not mint a new fingerprint every midnight.
- The hash is computed on the *uncapped* signature: display limits never
  influence identity.
- `function_score` and `script_score` are unwrapped because they only rescore —
  but a `script_score.min_score` *excludes* documents, so it is called out in
  the notes and changes the fingerprint.

### Versioning

0.x on purpose. The library treats any change to a produced hash as a breaking
change, and 24 query types still render opaquely — giving one of them a native
rendering moves the hashes of the queries that use it. Tagging 1.0.0 now would
force 2.0.0 on the very next improvement.

From this release onward the hash contract is honoured: a normalisation change
bumps the `q1:` prefix so the break is visible in the data rather than silently
invalidating a dashboard.

### Verified against

The OpenSearch API specification snapshot committed in
`resources/opensearch-spec.json`, with `resources/coverage.json` recording the
stance on every type. `SpecCoverageTest` fails if the two ever disagree — when
OpenSearch adds a query type, the suite says so instead of letting it appear as
`something(?)` in production.

CI runs the suite on PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5.
