# os-query-digest

[![CI](https://github.com/mrDlef/php-os-query-digest/actions/workflows/ci.yml/badge.svg)](https://github.com/mrDlef/php-os-query-digest/actions/workflows/ci.yml)
![PHP 7.4 – 8.5](https://img.shields.io/badge/PHP-7.4%20%E2%80%93%208.5-777bb3)
![no runtime dependencies](https://img.shields.io/badge/runtime%20dependencies-none-2e7d32)
![LGPL-3.0-or-later](https://img.shields.io/badge/licence-LGPL--3.0--or--later-555)

### Read your OpenSearch queries. Group them. Find the slow ones.

A DSL query in a log line is a wall of nested braces: nobody greps it, nobody
groups by it, and it costs a fortune in log volume. So the question you actually
have during an incident — *which **kind** of query is hurting us?* — has no
answer anywhere in your stack.

This library gives that question three answers: **one line you can log**, **one
shape you can read**, and **one hash you can `terms`-aggregate on**.

### ▶ [Try it on your own query](https://mrdlef.github.io/php-os-query-digest/) — no install, nothing leaves your browser

---

**Before.** One search request, as it lands in your logs — and there are
thousands of these a day:

```json
{
  "query": {
    "bool": {
      "filter": [
        { "term": { "service": "api" } },
        { "range": { "@timestamp": { "gte": "now-15m", "lt": "now" } } }
      ],
      "must_not": [ { "term": { "status": 200 } } ]
    }
  },
  "size": 50,
  "sort": [ { "@timestamp": "desc" } ],
  "_source": [ "@timestamp", "message", "status" ]
}
```

**After.** The same request, three ways:

```
text  logs-* | q=(@timestamp >= now-15m and @timestamp < now and not status:200 and service:api) | size=50 sort=@timestamp:desc
sig   logs-* | q=(@timestamp >= ? and @timestamp < ? and not status:? and service:?) | size=50 sort=@timestamp:desc
hash  q3:fe168406e702
```

```php
$digest = MrDlef\OsQueryDigest\Formatter::create()
    ->describe($request, 'logs-2026.08.13');

$digest->text();       // the line above — select it, paste it into Dashboards
$digest->signature();  // the same query with its literals erased: the shape
$digest->hash();       // q3:fe168406e702 — stable, versioned, groupable
```

| | what it is | what it is for |
|---|---|---|
| `text` | the query in DQL, with real values | paste it into the Dashboards search bar |
| `signature` | the same line with literals erased | read the *shape* at a glance |
| `hash` | versioned fingerprint of the signature | `terms` aggregate on it |

**The third one is the point.** Log the hash next to `took`, and your log index
answers questions your dashboards cannot: which *kind* of query got slow this
week, which one showed up with Friday's deploy, which one was hammering the
cluster during the incident. Not which thousand queries were slow — which
**shape** was. OpenSearch, analysing OpenSearch.

That query and that fingerprint are not illustrations: they are
`tests/fixtures/01-error-rate-filter`, and the golden file pins the exact hash.
Every example in this README is a test.

## What you get

- **Logs you can read.** One line replaces the body — and it is DQL, so you
  select it, paste it into the Dashboards search bar, and you are looking at the
  same query.
- **Your log volume drops.** A 40-line body becomes one line, capped.
- **Slow queries become countable.** `terms` on the hash and the top of the list
  is the shape to fix.
- **It cannot break your logging.** Nothing is required at runtime, the digest
  is lazy, and a request it cannot parse yields an error field rather than an
  exception. You lose the digest, never the log line.
- **It tells you why.** When two queries you thought were different share a
  hash, `explain()` names the rule that merged them — see
  [Explaining a fingerprint](#explaining-a-fingerprint).
- **It is verified, not asserted.** Certified against real OpenSearch 2.19.6 and
  3.8.0 nodes, checked against the official API specification, PHPStan at
  `level: max`, tested on PHP 7.4 → 8.5.

## Install

```bash
composer require mr-dlef/os-query-digest
```

PHP 7.4 → 8.5. No runtime dependencies. Ships a CLI, a Monolog processor, and a
browser playground.

## Usage

`describe()` takes a search body, an `['index' => …, 'body' => …]` envelope as
produced by `opensearch-php`, or the JSON string of either.

```php
use MrDlef\OsQueryDigest\Formatter;

$formatter = Formatter::create();

$logger->info('opensearch.search', [
    'q'    => $formatter->lazy($request, $index),   // nothing is parsed…
    'took' => $response['took'],
]);
```

`lazy()` returns a `JsonSerializable` that only parses when something reads it —
so a debug-level log filtered out by your handler costs nothing.

The digest serialises to a compact object:

```json
{
  "idx": "logs-*",
  "q": "logs-* | q=(@timestamp >= now-15m and service:api) | size=0",
  "sig": "logs-* | q=(@timestamp >= ? and service:?) | size=0",
  "hash": "q3:b7cc218cda09"
}
```

### With Monolog

If your application already logs its request bodies, you do not have to touch
every call site. Push one processor and the raw request is replaced by its
digest wherever it appears:

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
exactly as it was found, because a processor that guessed would corrupt your log
lines.

It stays lazy: the parse happens when a handler serialises the record, so one
buffered by a `FingersCrossedHandler` that never triggers costs nothing. And
because the parse then happens inside Monolog's formatting, a request the
library cannot read yields `{"error": "…"}` in place of the digest rather than
an exception. **You lose the digest, never the log line.**

Monolog is a *suggested* dependency, never a required one — the library itself
still has none. Both major versions work: `^2.0` on PHP 7.4, `^3.0` from 8.1.

### Reading the line

```
logs-* | q=(service:api and status:(500 or 502)) | post=(host:web-1) | aggs=terms(host,10)>p95(rt) | size=0 sort=@timestamp:desc | +highlight
└ index  └ DQL query                              └ post_filter       └ aggregation pipeline        └ options                    └ notes
```

The `q=(…)` segment is DQL: select it and paste it into OpenSearch Dashboards.
Aggregations use `>` to read as "then, per bucket".

`post=(…)` is the `post_filter`, kept apart from `q=(…)` on purpose: it runs
*after* the aggregations, so it narrows the hits while the buckets keep counting
the whole result set. That is the faceted-search pattern, and folding the two
together would describe a query nobody sent.

The last segment lists what was acknowledged but not rendered inline — a
boost-only `should` group, an unsupported top-level section. Nothing is ever
dropped silently.

### Options

```php
use MrDlef\OsQueryDigest\{Formatter, Normalization, Options};
use MrDlef\OsQueryDigest\IndexNormalizer;

$formatter = Formatter::create(
    Options::create()
        ->withNormalization(Normalization::structural())
        ->withMaxValues(5)          // status:(500 or 502 or 503 or +8)
        ->withMaxClauses(12)        // a and b and … +4 more
        ->withMaxLength(512)        // hard cap on the line, never on the hash
        ->withIndexNormalizer(IndexNormalizer::datePatterns())
        ->withRedactor(fn ($field, $value) => $field === 'email' ? '<redacted>' : $value)
        ->withAggNames(false)
        ->withHashLength(12)
);
```

The same options as a plain array — for a YAML file, a framework config block,
or a CLI flag:

```php
$formatter = Formatter::create(Options::fromArray([
    'normalization' => 'structural',
    'maxValues'     => 5,
    'aggNames'      => true,
]));
```

Unknown keys and wrong types throw `InvalidOptionException` instead of being
ignored: an option that silently does nothing is the bug you find months later,
in a dashboard that was never grouped the way the config claimed. Types are
taken as JSON gives them — `"5"` is rejected, because a front end that guesses
at `"5"` also accepts `"five"`. `redactor` has no array form; a callable cannot
be expressed there.

## CLI

The package ships a binary, so a query pasted out of a slow log does not need a
scratch PHP file:

```bash
$ echo '{"query":{"term":{"service":"api"}},"size":50}' \
    | vendor/bin/os-query-digest --index logs-2026.08.13
idx:  logs-*
text: logs-* | q=(service:api) | size=50
sig:  logs-* | q=(service:?) | size=50
hash: q3:5b2210eb5318
```

`--explain` appends the rules table, `--json` emits the digest object, `--hash`
emits nothing but the fingerprint. Every `Options` key has a flag of the same
name, so `--normalization=structural --max-values=none` is the CLI spelling of
the array above.

The reason it exists is `--ndjson`: one query per input line, one line of output
each. Point it at a log of search bodies and "which *kind* of query is slow" is
a `uniq -c` away:

```bash
$ os-query-digest --ndjson --hash < slow.ndjson | sort | uniq -c | sort -rn
      3 q3:2e2169e22798
      1 q3:33a434d95576
```

Those three are not three slow queries to read: they are one shape, hit on two
different days, with two different `service` values.

A malformed line is reported on stderr and skipped, so one mangled record does
not cost you the rest of the file. Exit codes: `0` ok, `1` an input could not be
parsed, `2` a bad invocation. `--help` lists every flag.

## Playground

**[mrdlef.github.io/php-os-query-digest](https://mrdlef.github.io/php-os-query-digest/)**
runs this library on your query, in your browser, with no server involved: PHP
itself is compiled to WebAssembly. Your query is never sent anywhere.

It is published from a release tag, never from `main` — the page prints
fingerprints, and one that no installable version produces would be worse than
no page at all.

Locally:

```bash
make playground        # regenerates the data, serves it on :8080
```

Two engines render the same thing. The sixteen examples are digested at build
time by `tools/build-playground.php`, so the page is useful and instant and
downloads nothing; the moment you change the query or an option, it fetches a
PHP 8.3 (about 2.8 MB, once) and runs the real library — boot measured at ~300 ms,
then well under a millisecond per query. Nothing is ever *approximated* by the
precomputed path: it is the same library, run earlier.

What makes it worth more than a formatter: pin a query as a reference, then edit
it. The page tells you whether the fingerprint moved and which normalisation
rule made the difference — which is the question you actually have when two
queries you thought were different share a hash. Every state is a permalink, so
a bug report can be a link.

The page ships two generated files, both committed so both are reviewable:
`playground/data/library.php.txt` (the library as one file, for a runtime with
no autoloader) and `playground/data/presets.json`. `tests/PlaygroundTest.php`
executes that bundle with real PHP against the golden files, so "the browser
runs the same library as `composer require` does" is guarded by CI without
needing a browser or wasm. The page itself is checked by
`make playground-check`, which drives it in a real Chromium.

## How the fingerprint works

### Normalisation levels

| level | erases | `terms: [500, 502]` becomes |
|---|---|---|
| `Normalization::none()` | nothing | `status:(500 or 502)` |
| `Normalization::values()` *(default)* | literals | `status:(? or ?)` |
| `Normalization::structural()` | + cardinality, pagination | `status:(?)` |

`size=0` survives every level: "aggregations only" is a different kind of query
from "give me hits".

### Canonicalisation

Before rendering, the query is rewritten so that equivalent queries written
differently converge. Every rule preserves the result set:

- `bool.must` and `bool.filter` both collapse to AND — they differ only in scoring
- nested connectors flatten: `AND(a, AND(b, c))` → `AND(a, b, c)`
- single-child connectors unwrap: `bool.filter: [a]` → `a`
- identical siblings de-duplicate
- commutative siblings are ordered by a stable key
- `match_all` disappears inside a multi-clause AND

- `match_none` absorbs: `AND(a, none)` → `none`, `OR(a, none)` → `a`

Rules that would only preserve *intent* — merging `.keyword` into its parent
field, dropping boosts, treating `term` and `match` as one — are deliberately
**not** applied. Over-normalising makes genuinely different queries collide,
which destroys the diagnostic value of the hash.

### Explaining a fingerprint

Sooner or later two queries you thought were different share a hash, and the
library is only worth trusting if it can say *why*. `explain()` returns the same
digest plus every rule that fired:

```php
$explanation = $formatter->explain($request, 'logs-2026.08.13');

echo $explanation;
```

```
text: logs-* | q=(env:prod and msg:timeout and service:api) | size=0 | should=1
sig:  logs-* | q=(env:? and msg:~? and service:?) | size=0 | should=1
hash: q3:a5d822c18ab3
notes: should=1

rules applied:
  boost_dropped [msg]                        A boost was ignored: it reorders results, it does not change them.
  flatten [and]                              Nested connectors of the same kind were flattened.
  index_pattern [logs-2026.08.13 -> logs-*]  The index was collapsed to a rolling pattern, so a daily index …
  must_filter_merged                         bool.must and bool.filter both became AND: they differ in scoring, …
  reorder [and]                              Commutative siblings were reordered by a stable key, so the order …
  should_boost_only [1 clause]               A should group beside a must/filter, with no minimum_should_match, …
```

A rule is listed only when it actually changed the query, so an empty list means
the query was already canonical. Diff two explanations and the rule that merged
them is named.

It is also queryable, which is what makes it usable in a test:

```php
use MrDlef\OsQueryDigest\Explain\Rule;

$explanation->has(Rule::MUST_FILTER_MERGED);  // true
$explanation->ruleIds();                      // ['boost_dropped', 'flatten', …]
$explanation->digest();                       // identical to describe()
json_encode($explanation);                    // the digest object plus a "rules" array
```

`explain()` needs no second pass: the rules are recorded during the normal
parse, so it returns the very digest `describe()` would have produced.

### Things it gets right that are easy to get wrong

- **`must_not: [A, B]`** is `(NOT A) AND (NOT B)`, never `NOT (A AND B)`.
- **A `should` group beside a `must`/`filter`**, with no `minimum_should_match`,
  does not restrict anything — it only boosts. It is moved to the notes
  (`should=2`) instead of being rendered as a filter, which would make the line
  lie. Set `minimum_should_match` and it renders inline again.
- **`sort: ["_score"]`** defaults to *descending*, unlike every other field.
- **Rolling indices** collapse: `logs-2026.08.13` → `logs-*`, so a daily index
  does not mint a new fingerprint every midnight.
- **The hash is computed on the uncapped signature.** Display limits never
  influence identity — otherwise a 200-value and a 300-value `terms` clause
  would collide by accident of truncation.
- **`query_string` payloads are erased** in the signature. They are values, and
  leaving them in would mint a new fingerprint for every user search term.
- **A rescoring wrapper is unwrapped, but its threshold is not.** `function_score`
  and `script_score` only reorder what their inner query already matched, so the
  wrapper goes and the query stays. A `script_score.min_score` is the exception:
  it *excludes* documents, on a score this library never computes, so it is
  called out as `script_score:min_score` and changes the fingerprint.

### Coverage

Checked against the official
[OpenSearch API specification](https://github.com/opensearch-project/opensearch-api-specification)
rather than from memory. `resources/opensearch-spec.json` is a committed
snapshot of the type names it declares; `resources/coverage.json` records our
stance on each one, and `SpecCoverageTest` fails if the two ever disagree.

**46 of the 59 query types** are rendered natively:

| | |
|---|---|
| term-level | `term`, `terms`, `terms_set`, `prefix`, `wildcard`, `regexp`, `fuzzy`, `exists`, `range`, `ids` |
| full text | `match`, `match_bool_prefix`, `match_phrase`, `match_phrase_prefix`, `multi_match`, `combined_fields`, `common`, `query_string`, `simple_query_string`, `more_like_this`, `intervals` |
| compound | `bool`, `constant_score`, `dis_max`, `hybrid`, `function_score`, `script_score`, `boosting` (filtering part), `wrapper` |
| joining | `nested`, `has_child`, `has_parent`, `parent_id` |
| vector | `knn`, `neural` |
| geo | `geo_distance`, `geo_bounding_box`, `geo_polygon`, `geo_shape`, `xy_shape` |
| scoring | `rank_feature`, `distance_feature` |
| other | `match_all`, `match_none`, `script`, `percolate` |

Vector and geo clauses keep what a reader needs and drop what they cannot use: a
`knn` renders as `image_embedding:knn(k=20)`, not as a thousand floats, so two
searches of the same kind share a fingerprint however different their vectors.
Same for a `geo_distance` — the radius survives, the centre does not. A shape
query keeps the two parts that decide which documents match,
`zone:geo_shape(polygon,within)`, and drops the coordinates: `within` and
`disjoint` over the same polygon return opposite result sets, so collapsing them
would be the geo equivalent of erasing a `not`.

Two of them recover more than they summarise. A `hybrid` — the OpenSearch
pattern of running a lexical and a vector clause together under a normalisation
pipeline — renders as the union it matches, so
`q=(embedding:knn(k=20) or title|description:"hiking boots")` says what the
search actually combined instead of hiding it behind one word. And a `wrapper`
is base64-decoded and parsed, so a query passed through as an opaque blob comes
back as the query it always was, hashing exactly like the same query sent
unwrapped.

The other 13 render as `type(?)`. They are signalled, never dropped, and still
contribute to the fingerprint — and none of them is a gap waiting to be filled.
The `span_*` family is 9 of the 13 and stays there on purpose: nobody debugs a
span query from a log line. The remaining four cannot be read even in principle
— `type` was removed with mapping types, `sltr` and `template` only rescore or
live behind another endpoint, and an `agentic` query hands the whole result set
to a model that decides outside the DSL.

### Teaching it a type it does not know

"In principle" means *by this library*. If you run the Learning-to-Rank plugin,
`sltr` is not unreadable to **you** — and a private plugin's query type was
never going to be in anyone's specification. Register a renderer for it:

```php
use MrDlef\OsQueryDigest\Extension\{ClauseRenderer, RenderedClause};

final class SltrRenderer implements ClauseRenderer
{
    public function render(array $body): ?RenderedClause
    {
        $model = $body['model'] ?? null;
        if (!is_string($model)) {
            return null;    // not a shape I know — leave it opaque
        }

        return RenderedClause::on('_score', 'sltr')->withParam('model', $model);
    }
}

$formatter = Formatter::create(
    Options::create()->withClauseRenderer('sltr', new SltrRenderer()),
);
```

```
before   q=(sltr(?))
after    q=(_score:sltr(model=ltr_model_v3))
signature q=(_score:sltr(model=?))
```

A `RenderedClause` carries a field, a label and keyed parameters — the same
three things the vector and geo clauses reduce to, rendered the same way. The
knobs are part of the shape, their values are not, so two searches through the
same model share a fingerprint. Returning `null` is the honest answer for a body
you do not recognise: `type(?)` is true, and a guess would be a fingerprint built
on a misreading.

Three properties make this safe to hand out:

- **A renderer cannot reach a type the library models.** The hook sits in the
  parser's default branch, so every native type has already returned before it
  runs. That is structural, not a list that could drift — registering one for
  `term` does nothing at all.
- **The hash version is marked.** Register anything and fingerprints become
  `q3x:` instead of `q3:`. Your rules are no longer this library's alone, and
  that is exactly what a prefix exists to make visible. The twelve hex
  characters are untouched, so `q3:abc…` and `q3x:abc…` are still recognisably
  the same query.
- **`explain()` names it.** A digest that depended on someone's plugin code
  reports `extension_rendered`, rather than looking like one this library
  produced on its own.

Extensions receive the raw clause body and return a value object — never a node
of the internal tree. An extension point built on that tree would freeze it, and
the tree is exactly what changes every time a query type is promoted.

All **65 aggregation types** are rendered; 12 of them get a shape tuned for
readability (`terms(host,10)`, `date_histogram(@ts,1h)`, `p95(latency)`), the
rest render generically as `type(field)`.

Top-level sections that are not modelled (`highlight`, `collapse`, `rescore`, …)
are listed in the notes.

### Which OpenSearch version

**Certified against OpenSearch 2.19.6 and 3.8.0.** Not inferred from the
specification — every query type is sent to a real node of each, and
`resources/versions.json` records what came back:

| | |
|---|---|
| 53 types | probed against live clusters |
| 52 | accepted by 2.19.6 |
| 53 | accepted by 3.8.0 |
| 6 | cannot be probed, each with a written reason |

`combined_fields` is the one difference the specification could not have told
you: it is listed as a query type, 3.x accepts it, and 2.19.6 answers `unknown
query [combined_fields]`.

The six unprobed types are not a gap left quiet — `resources/probes.json` says
why each one cannot have a probe. `neural` needs a deployed model id, `sltr`
needs a plugin absent from the official image, `agentic` needs a configured
agent, `hybrid` needs a search pipeline, `template` lives behind a different
endpoint, and `type` was removed with mapping types. Certifying those would test
someone's cluster configuration, not this library.

```bash
make certify       # boot 2.x and 3.x, re-probe, rewrite resources/versions.json
make integration   # replay the committed matrix against live nodes
```

`make certify` refuses to record a probe that fails for any reason other than
"unknown query": our own malformed DSL must never be filed as a version
difference. A scheduled workflow replays the matrix weekly, so a version that
changes its mind about a query type surfaces on its own instead of during
someone's release.

The spec snapshot is still there and still useful for a different question —
*has OpenSearch grown a type we have never heard of?*

```bash
make spec                    # refresh from the spec, then run the coverage test
make spec SPEC_REF=e027edc   # or pin a commit for a reproducible snapshot
```

If OpenSearch has added a query type, the test fails until it is classified in
`coverage.json` — and `make certify` then fails until it is probed or explained.

The spec is published as YAML and PHP has no core YAML parser, so
`tools/refresh-spec.php` uses `symfony/yaml` — a **require-dev** dependency, so
it is never installed by anything that depends on this library. The snapshot
itself is committed as JSON: the test suite reads it with `json_decode()`,
offline, with no dependency at all.

## Hash stability

**The hash is a public contract.** If a normalisation rule changes, every hash
changes — and any dashboard built on it silently breaks. So:

- the hash carries its algorithm version: `q3:8f3ac1d2b901`. A rule change bumps
  it — `q3:` → `q4:` — making the break visible in your data instead of
  invisible. The prefix has moved twice: v0.2.0 promoted three query types out
  of `type(?)`, and v0.6.0 promoted eight more, so anything published as `q1:`
  or `q2:` was minted by an older set of rules. Promotions are deliberately
  batched into one release for that reason — the prefix is global, so promoting
  a single rare type would invalidate every dashboard on its own.
- a signature that did not change keeps its twelve hex characters. `q2:abc…`
  and `q3:abc…` describe the same shape, which makes a prefix bump readable
  rather than a wall of new values.
- every fixture pins its exact hash in `tests/fixtures/*/expected.json`. A rule
  change cannot land without a reviewable diff.
- a change to the produced hashes is a **major** release.
- [`CHANGELOG.md`](CHANGELOG.md) says, for every released version, whether your
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

## What counts as public

The hash is one contract; the classes are the other. Every class in `src/` is
marked either `@api` or `@internal`, and `ApiBoundaryTest` fails the suite if
one is marked neither, marked both, or if a public method hands back an
internal type — because a type you can reach from a public signature is public
whatever its annotation claims.

**The public surface is fourteen classes:**

| | |
|---|---|
| entry point | `Formatter` |
| results | `Digest`, `LazyDigest`, `Explain\Explanation`, `Explain\Rule` |
| configuration | `Options`, `Normalization`, `IndexNormalizer` |
| extension | `Extension\ClauseRenderer`, `Extension\RenderedClause` |
| failures | `Exception\InvalidQueryException`, `Exception\InvalidOptionException` |
| Monolog | `Monolog\DigestProcessor`, `Monolog\SafeDigest` |

Everything else — the parser, the tree, the renderers, the canonicaliser, the
hasher, the CLI command — is `@internal`. Not out of secrecy: those are exactly
the classes that change whenever a query type is promoted. Freezing them would
mean every improvement to the rendering is a major release, and the library
would stop improving. Depend on them and an ordinary patch may move under you.

This matters because it is the half of a `1.0.0` that cannot be walked back:
widening a public surface later is free, narrowing one is not.

## Would the tests notice?

Line coverage answers "was this executed?". For a library whose product is a
stable hash, the question worth asking is "would anything fail if this stopped
doing its job?" — a normalisation rule quietly ceasing to fire is exactly the
bug no percentage would show.

```bash
make mutation      # ~35s, plus the image build the first time
```

Infection rewrites the source one small change at a time and re-runs the suite
against each. **Nothing in `src/` is uncovered**, and the covered mutation score
sits at 79%, guarded in CI so it cannot quietly fall.

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

## What it costs

The pitch is that you can afford this on every search. That was an argument
until it was measured.

```bash
make bench                    # PHP 8.3 in Docker
make bench PHP_VERSION=7.4
```

Measured on the committed fixtures — the same requests the suite treats as
representative, from a single term to a 200-value `terms` clause:

| | PHP 8.5 | PHP 7.4 |
|---|---|---|
| `describe()`, mean over 17 fixtures | **~30 µs** | **~37 µs** |
| `json_encode()` of the same body | ~0.7 µs | ~1.5 µs |
| `lazy()` | **0.2 µs** | 0.17 µs |

**Next to the search itself, it disappears.** A query that takes 20 ms round-trip
pays about 0.15% to be readable in your logs afterwards. Yes, it is some 40×
the cost of `json_encode()` — but `json_encode()` gives you the wall of nested
braces this library exists to keep out, and both are rounding errors against the
network.

**`lazy()` is ~100× cheaper than `describe()`**, which is the number that
matters for a debug record your handler drops: building the wrapper parses
nothing, so the work never happens.

Cost grows slightly faster than the clause count — 4.1 µs per clause at ten,
7.2 µs at two hundred and fifty — which is the canonicaliser's sort, not an
accident. It is watched because a quadratic in there would be invisible on a
fixture and painful on a real dashboard query.

There is deliberately **no timing gate in CI**. Wall-clock on a shared runner is
noise, and a threshold tight enough to catch a real regression would fail on a
busy afternoon. This reports; CI guards behaviour.

## Development

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

## Contributing and security

[`CONTRIBUTING.md`](CONTRIBUTING.md) has the commands, and the seven rules that
are not guessable from the code — the hash contract, why promotions are batched,
why a new class is `@internal` until someone decides otherwise.

Found a vulnerability? [`SECURITY.md`](SECURITY.md) — report it privately, not
in an issue. It also sets out what the library does and does not touch, which is
most of the answer: no file access, no network, no process execution, and two
runtime dependencies.

## License

LGPL-3.0-or-later — see [LICENSE](LICENSE).

The LGPL builds on the GPL, so both texts ship: [LICENSE](LICENSE) holds the
Lesser GPL and [LICENSE.GPL](LICENSE.GPL) the GPL it refers to.

In practice: you can use this library in a closed-source application without
that application becoming subject to the licence. If you *modify the library
itself* and distribute it, those changes stay under the LGPL.
