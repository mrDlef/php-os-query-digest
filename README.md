# os-query-digest

[![CI](https://github.com/mrDlef/php-os-query-digest/actions/workflows/ci.yml/badge.svg)](https://github.com/mrDlef/php-os-query-digest/actions/workflows/ci.yml)

Turn an OpenSearch / Elasticsearch DSL query into something you can actually put
in a log line — and into a **stable fingerprint** you can group by.

```php
$formatter = MrDlef\OsQueryDigest\Formatter::create();
$digest = $formatter->describe($request, 'logs-2026.08.13');

$digest->text();      // logs-* | q=(@timestamp >= now-15m and service:api) | size=0
$digest->signature(); // logs-* | q=(@timestamp >= ? and service:?) | size=0
$digest->hash();      // q1:b7cc218cda09
$digest->index();     // logs-*
```

Requires PHP 7.4+, no runtime dependencies.

## Why

A DSL query in a JSON log is a wall of nested braces: unreadable in a terminal,
useless for grouping, and it blows up your log volume. This library gives you
three things instead:

| | what it is | what it is for |
|---|---|---|
| `text` | the query in DQL, with real values | paste it into the Dashboards search bar |
| `signature` | the same line with literals erased | read the *shape* at a glance |
| `hash` | versioned fingerprint of the signature | `terms` aggregate on it |

That last one is the payoff. Log `hash` alongside `took` and you can ask your own
log index which *kinds* of query are slow, which appeared this week, and which
one caused the incident — using OpenSearch to analyse OpenSearch.

## Install

```bash
composer require mr-dlef/os-query-digest
```

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
  "hash": "q1:b7cc218cda09"
}
```

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
use MrDlef\OsQueryDigest\Support\IndexNormalizer;

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
hash: q1:a5d822c18ab3
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

### Coverage

Checked against the official
[OpenSearch API specification](https://github.com/opensearch-project/opensearch-api-specification)
rather than from memory. `resources/opensearch-spec.json` is a committed
snapshot of the type names it declares; `resources/coverage.json` records our
stance on each one, and `SpecCoverageTest` fails if the two ever disagree.

**24 of the 59 query types** are rendered natively: `bool`, `term`, `terms`,
`terms_set`, `match`, `match_bool_prefix`, `match_phrase`,
`match_phrase_prefix`, `multi_match`, `prefix`, `wildcard`, `regexp`, `fuzzy`,
`exists`, `range`, `ids`, `match_all`, `nested`, `query_string`,
`simple_query_string`, `constant_score`, `dis_max`, `function_score` and
`boosting` (filtering part).

The other 35 — `script`, `knn`, `neural`, `geo_*`, `has_child`, the `span_*`
family, plugin queries — render as `type(?)`. They are signalled, never dropped,
and still contribute to the fingerprint.

All **65 aggregation types** are rendered; 12 of them get a shape tuned for
readability (`terms(host,10)`, `date_histogram(@ts,1h)`, `p95(latency)`), the
rest render generically as `type(field)`.

Top-level sections that are not modelled (`highlight`, `collapse`, `rescore`, …)
are listed in the notes.

### Which OpenSearch version

The snapshot in `resources/opensearch-spec.json` records the spec commit it came
from, the date, and an `opensearch_version_floor` — the highest release named by
an `x-version-added` marker in the fetched schemas.

That floor is a *floor*, not a certificate: the spec does not tag every type
with the version that introduced it, so it means "this snapshot knows about
features up to that release", not "every feature of that release is handled
natively". The per-type truth is `resources/coverage.json`.

To check whether OpenSearch has moved:

```bash
make spec                    # refresh from the spec, then run the coverage test
make spec SPEC_REF=e027edc   # or pin a commit for a reproducible snapshot
```

If OpenSearch has added a query type, the test fails until it is classified in
`coverage.json`.

The spec is published as YAML and PHP has no core YAML parser, so
`tools/refresh-spec.php` uses `symfony/yaml` — a **require-dev** dependency, so
it is never installed by anything that depends on this library. The snapshot
itself is committed as JSON: the test suite reads it with `json_decode()`,
offline, with no dependency at all.

## Hash stability

**The hash is a public contract.** If a normalisation rule changes, every hash
changes — and any dashboard built on it silently breaks. So:

- the hash carries its algorithm version: `q1:8f3ac1d2b901`. A rule change bumps
  it to `q2:`, making the break visible in your data instead of invisible.
- every fixture pins its exact hash in `tests/fixtures/*/expected.json`. A rule
  change cannot land without a reviewable diff.
- a change to the produced hashes is a **major** release.

Twelve hex characters (48 bits) keep collisions negligible at any realistic
number of distinct query shapes; `withHashLength()` adjusts it.

## Development

Tests run in Docker across the whole supported matrix:

```bash
make test                   # PHP 8.3
make test PHP_VERSION=7.4   # one version
make test-all               # 7.4 → 8.5
make stan                   # PHPStan level 8
make fixtures               # regenerate golden files — review the diff!
```

## License

LGPL-3.0-or-later — see [LICENSE](LICENSE).

The LGPL builds on the GPL, so both texts ship: [LICENSE](LICENSE) holds the
Lesser GPL and [LICENSE.GPL](LICENSE.GPL) the GPL it refers to.

In practice: you can use this library in a closed-source application without
that application becoming subject to the licence. If you *modify the library
itself* and distribute it, those changes stay under the LGPL.
