# What a deploy changed

A release went out at 15:00. The question nobody can usually answer is what it
changed about the *queries* — not the code that was reviewed, but the shapes that
actually reached the cluster. A new query shape in production is a change to your
search workload, and it does not appear in any diff.

## Shapes that did not exist before the release

Take every shape, count it on each side of the deploy, and keep the ones with
nothing on the left:

<!-- verified: new-shapes -->
```json
{
  "size": 0,
  "aggs": {
    "shapes": {
      "terms": { "field": "os.hash", "size": 100 },
      "aggs": {
        "before": { "filter": { "range": { "@timestamp": { "lt": "2026-08-19T15:00:00Z" } } } },
        "after":  { "filter": { "range": { "@timestamp": { "gte": "2026-08-19T15:00:00Z" } } } },
        "only_after": {
          "bucket_selector": {
            "buckets_path": { "b": "before>_count", "a": "after>_count" },
            "script": "params.b == 0 && params.a > 0"
          }
        },
        "first_seen": { "min": { "field": "@timestamp" } },
        "shape": { "terms": { "field": "os.sig", "size": 1 } }
      }
    }
  }
}
```

```
q4:4dde138a2ad7   n=90   first seen 2026-08-19T15:00:00.000Z
    products-v3 | q=(image_embedding:knn(k=?) and in_stock:? or text_embedding:neural(query=?,k=?)) | size=20
```

One new shape, and you can read it without opening the codebase: the release
started sending a vector search against `products-v3` — a `knn` on an image
embedding combined with a `neural` clause on a text embedding. Ninety times an
hour, against an index the previous version never touched.

Flip the script to `params.a == 0 && params.b > 0` and you get the opposite list:
shapes that **stopped**. On this afternoon that list is empty — nothing went away
— but it is worth reading on a real release, because a query that silently
disappeared is either a feature you removed or a feature that broke.

## The point of the 15:00 boundary

The afternoon on these pages has two events in it, an hour apart:

```
14:00  q4:63a1ca5c80b9 goes from 50ms to 1074ms
15:00  the deploy — q4:4dde138a2ad7 appears
```

**The deploy did not cause the slowdown.** It landed an hour after the latency
moved, and the shape that regressed already existed before it. Without the hash
you have one latency graph with two bumps and a deploy marker somewhere near
them, and the obvious story — *the release broke search* — is wrong, expensive,
and very hard to disprove.

This is the argument for logging the hash rather than the reason. Correlation
against a deploy marker tells you two things happened. Grouping by shape tells
you which things.

## Comparing releases, not clocks

A wall-clock boundary works when you know when the deploy landed. If you would
rather not care, log your release alongside the digest:

```php
$logger->info('opensearch.search', [
    'os'      => $formatter->lazy($request, $index),
    'took'    => $response['took'],
    'release' => getenv('APP_RELEASE'),   // 'v2.31.0'
]);
```

Then the comparison is a `terms` on `release` rather than a date range, and it
survives a rollback, an overlapping canary, and a deploy nobody wrote down:

<!-- verified: one-release-only -->
```json
{
  "size": 0,
  "aggs": {
    "shapes": {
      "terms": { "field": "os.hash", "size": 200 },
      "aggs": {
        "releases": { "terms": { "field": "release", "size": 10 } },
        "only_one_release": {
          "bucket_selector": {
            "buckets_path": { "n": "releases._bucket_count" },
            "script": "params.n == 1"
          }
        }
      }
    }
  }
}
```

```
1 shape seen under exactly one release
    q4:4dde138a2ad7   v2.31.0   n=90
```

```
1 shape seen under exactly one release
    q4:4dde138a2ad7   v2.31.0   n=90
```

Every shape that has only ever been seen under a single release: new arrivals and
departures in one list, with the release that owns each one named. `_bucket_count`
counts sub-buckets rather than documents, which is what makes "seen under exactly
one release" expressible without knowing the release names in advance. `_bucket_count`
counts the sub-buckets rather than the documents, which is what makes "seen under
exactly one release" expressible without knowing the release names.

!!! note "Two shapes can become one"
    A refactor that rewrites a query without changing what it asks for produces
    **no** new hash — that is the whole point of the signature, and
    [How the fingerprint works](../explanation/how-it-works.md) covers what it
    erases. So an empty result here is a real answer: the release changed no
    query shape. It is not the same as "the release changed no query code".

## When the prefix moves instead

If a list of "new" shapes suddenly contains *everything*, check the prefix before
you check your code. A library upgrade that changes a normalisation rule bumps
`q4:` → `q5:`, and every hash in the after-window is new by construction.

The prefix exists to make that visible rather than silent, and a signature that
did not change keeps its twelve hex characters — so `q4:abc…` and `q5:abc…` are
the same shape under different rules. Comparing across an upgrade means comparing
the hex, not the hash. [Hash stability](../explanation/hash-stability.md) is the
contract, and `CHANGELOG.md` says for every release whether your fingerprints
moved.
