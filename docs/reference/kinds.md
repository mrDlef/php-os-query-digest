# Kinds

A fingerprint says which searches are the *same*. It does not say what they are
**for**, and a top-N of two hundred hashes is unreadable for exactly that
reason. Grouped by kind, the same list is a sentence: where the load goes, which
autocompletes are slow, how much traffic never asks for a document at all.

Every digest carries one, on `Digest::kind()` and in the `kind` field of
`toArray()`. It is read off the parsed, canonicalised request — never off the
raw body — so it holds no literal and survives
[`withText(false)`](../guides/options.md#withtextfalse-and-what-it-does-not-promise)
the way the hash does.

<!-- verified: kinds-table -->
```
suggest    {"query":{"match_phrase_prefix":{"name":"ada lov"}},"size":8}
aggregate  {"query":{"term":{"service":"api"}},"aggs":{"hosts":{"terms":{"field":"host"}}},"size":0}
scan       {"query":{"term":{"status":"shipped"}},"search_after":[1755561600000],"size":1000}
lookup     {"query":{"ids":{"values":["a1","b2"]}}}
browse     {"query":{"match":{"title":"waterproof boots"}},"size":20}
unknown    {"query":{"span_near":{"clauses":[{"span_term":{"msg":"refused"}}],"slop":2}},"size":10}
```

## What each one is

| kind | the request | read from |
|---|---|---|
| `suggest` | someone is still typing | a `suggest` section, a `match_phrase_prefix` or `match_bool_prefix`, or a `prefix` that is the whole question |
| `aggregate` | no documents come back | `size: 0`, or aggregations with no `size` and `_source: false` |
| `scan` | a walk over a result set | `search_after`, `pit`, `slice`, or a `scroll` beside `body` |
| `lookup` | documents named rather than described | an `_id`, or one exact clause asking for no more than a page |
| `browse` | a page of what matches | what a readable request falls back to |
| `unknown` | documents come back and the query cannot be read | a query that is entirely opaque — a plugin type, a `script`, a clause a [`ClauseRenderer`](../guides/extending.md) described |

They are decided in that order, first match wins, and `unknown` sits where it
does on purpose: it is a verdict, not a fallback. `browse` is the fallback.

## The two rules that make it right

Both are easy to get wrong from the raw body, and both are settled by reading
the parsed model instead:

- **Only `query` and `post_filter` select.** A faceted browse legitimately
  carries `filter` aggregations with `match` clauses inside them. Classified
  over the whole body, every faceted page reports as a text search — the
  aggregations say what is *counted*, never what is asked. `post_filter` is in
  because it narrows the hits, which is also why the model keeps it in a slot of
  its own.
- **`size` absent is not `size: 0`.** A request with aggregations and no `size`
  still comes back with ten documents, so it is not buckets-only. The intent is
  only legible by also reading `_source`: turned off, the caller is saying it
  will not look at them. A *filtered* `_source` — a list of two fields — is
  still asking for documents.

## Where it stops

The kind is read from the shape, and the shape does not say everything:

- **A `term` on a business key cannot be told from a filter.** `sku` and
  `service` are the same clause to a parser, so `lookup` needs the request to
  name an `_id`, or to be a single exact clause asking for no more than the
  cluster's default page. A single `term` with `size: 5000` is reading in bulk,
  and reads as `browse`.
- **A big page is not a `scan`.** Without a cursor there is no walk, only a
  large page — the alternative would be a threshold this library invented.
- **An autocomplete written as a `match` on an edge-ngram field is invisible.**
  It is an ordinary `match` in the DSL, and nothing in the request says the
  mapping analyses it that way.

None of this moves a fingerprint. The two completion ops the classification
needed — `match_phrase_prefix` and `match_bool_prefix` — are modelled apart from
the ops they refine and **render exactly as them**, so promoting them left every
hash where it was.
