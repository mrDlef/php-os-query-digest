# Coverage

Two questions, and this page answers both: which queries the library can read,
and which clients it can read them out of.

## Query types

Checked against the official
[OpenSearch API specification](https://github.com/opensearch-project/opensearch-api-specification)
rather than from memory. `resources/opensearch-spec.json` is a committed
snapshot of the type names it declares; `resources/coverage.json` records our
stance on each one, and `SpecCoverageTest` fails if the two ever disagree.

**46 of the 59 query types** are rendered natively:

|            |                                                                                                                                                                                       |
|------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| term-level | `term`, `terms`, `terms_set`, `prefix`, `wildcard`, `regexp`, `fuzzy`, `exists`, `range`, `ids`                                                                                       |
| full text  | `match`, `match_bool_prefix`, `match_phrase`, `match_phrase_prefix`, `multi_match`, `combined_fields`, `common`, `query_string`, `simple_query_string`, `more_like_this`, `intervals` |
| compound   | `bool`, `constant_score`, `dis_max`, `hybrid`, `function_score`, `script_score`, `boosting` (filtering part), `wrapper`                                                               |
| joining    | `nested`, `has_child`, `has_parent`, `parent_id`                                                                                                                                      |
| vector     | `knn`, `neural`                                                                                                                                                                       |
| geo        | `geo_distance`, `geo_bounding_box`, `geo_polygon`, `geo_shape`, `xy_shape`                                                                                                            |
| scoring    | `rank_feature`, `distance_feature`                                                                                                                                                    |
| other      | `match_all`, `match_none`, `script`, `percolate`                                                                                                                                      |

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

## Clients captured at the transport

The [transport integrations](../guides/transport.md) attach to a PSR-18 client or
to a Guzzle handler stack. Whether your OpenSearch library has either is a
property of its transport, not of its name:

| client | transport | captured |
|---|---|---|
| `elasticsearch-php` 8 | `elastic/transport`, PSR-18 | yes |
| `opensearch-php` ≥ 2.4 through `GuzzleClientFactory`, `SymfonyClientFactory` or `TransportFactory` | PSR-18 | yes |
| any Guzzle, HTTPlug or PSR-18 client of your own | PSR-18 | yes |
| `opensearch-php` through the deprecated `ClientBuilder` | `ezimuel/ringphp` | no |
| `opensearch-php` ≤ 2.3 | `ezimuel/ringphp` | no |
| `elasticsearch-php` 7.x | `ezimuel/ringphp` | no |

A ringphp handler is a `callable(array): array|FutureArrayInterface` — it
predates PSR-7, so there is no request object to intercept.
[Issue #42](https://github.com/mrDlef/php-os-query-digest/issues/42) tracks it.

**Not captured is not uncovered.** The transport is one of three ways in, and the
other two do not care what your client is: the
[Monolog processor](../guides/logging.md) digests a body your application already
logs, and the [command line](../guides/cli.md) reads the search slow log the
cluster writes on its own.
