# How the fingerprint works

## Normalisation levels

| level                                 | erases                    | `terms: [500, 502]` becomes |
|---------------------------------------|---------------------------|-----------------------------|
| `Normalization::none()`               | nothing                   | `status:(500 or 502)`       |
| `Normalization::values()` *(default)* | literals                  | `status:(? or ?)`           |
| `Normalization::structural()`         | + cardinality, pagination | `status:(?)`                |

`size=0` survives every level: "aggregations only" is a different kind of query
from "give me hits".

## Which level answers which question

The default erases literals and stops there, which is the right trade for
**latency**: page 3 of a search really is more work than page 1, and a `terms`
filter holding eight values is not the one holding two. Kept apart, the shape
that got slow is the one you can point at.

It is the wrong trade for **volume**. Ask instead how much of the traffic one
search accounts for, and those same distinctions fan it out into a row per page
and a row per basket size — so a top-N ranks pagination rather than searches.
`structural()` erases cardinality and pagination, and the fan collapses back to
a single row.

One catalogue search — a `term` on `shop`, a `terms` on `category`, sorted by
price, twenty per page — run at page 1 with two categories and at page 3 with
eight:

<!-- verified: how-it-works-levels -->
```
values()      page 1, two categories
  sig   catalog-* | q=(category:(? or ?) and shop:?) | size=20 sort=price:asc
  hash  q5:8fd2dd38ce3a
values()      page 3, eight categories
  sig   catalog-* | q=(category:(? or ? or ? or ? or ? or +3) and shop:?) | size=20 from=40 sort=price:asc
  hash  q5:3af67be7bbcb
structural()  either page, either basket
  sig   catalog-* | q=(category:(?) and shop:?) | size=? sort=price:asc
  hash  q5:b64ab1f3a179
```

Ranking a slow log by what it costs is the first question, so the CLI and the
shipped dashboards are built on the default. Counting what an application
searches for is the second one, and it wants `structural()`. Both at once is two
formatters and two fields on the same log line — the cost is a second parse of
the same body, not a second integration.

## Canonicalisation

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

## Explaining a fingerprint

Sooner or later two queries you thought were different share a hash, and the
library is only worth trusting if it can say *why*. `explain()` returns the same
digest plus every rule that fired:

```php
$explanation = $formatter->explain($request, 'logs-2026.08.13');

echo $explanation;
```

<!-- verified: explain-output -->
```
text: logs-* | q=(env:prod and msg:timeout and service:api) | size=0 | should=1
sig:  logs-* | q=(env:? and msg:~? and service:?) | size=0 | should=1
hash: q5:a5d822c18ab3
kind: aggregate
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

## Things it gets right that are easy to get wrong

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
