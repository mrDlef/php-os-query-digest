# Which shape got slow

Search latency went up this afternoon. Your dashboards show the p95 climbing and
cannot tell you which of the four hundred queries your application sends is
responsible. The log index can.

## The obvious query, and why it lies

Rank the shapes by p95 and take the worst:

<!-- verified: naive -->
```json
{
  "size": 0,
  "aggs": {
    "shapes": {
      "terms": { "field": "os.hash", "size": 3, "order": { "slow.95": "desc" } },
      "aggs": {
        "slow": { "percentiles": { "field": "took", "percents": [95] } },
        "shape": { "terms": { "field": "os.sig", "size": 1 } }
      }
    }
  }
}
```

```
q5:e9794c1be608   p95=1484ms   n=30    orders | q=(country:? and status:(? or ? or ? or ? or ? or +3))
q5:63a1ca5c80b9   p95=1019ms   n=360   logs-* | q=(@timestamp >= ? and env:?) | aggs=terms(host,10)>…
q5:4dde138a2ad7   p95=  30ms   n=90    products-v3 | q=(image_embedding:knn(k=?) and in_stock:? …)
```

The top answer is wrong. `q5:e9794c1be608` is a report over `orders` that takes
a second and a half — and took a second and a half yesterday, and will tomorrow.
It is the slowest shape you run, and it is not what changed. Ranking by p95
finds what is slow, and "slow" is a property, not an event.

Note the `"order": { "slow.95": "desc" }`: ordering a `terms` aggregation on a
percentile needs the metric named, not just the aggregation. `{ "slow": "desc" }`
fails with `Invalid aggregation order path`, which is a clearer error than most.

## Rank by change instead

Split each shape's own history into two windows and compare it to itself:

<!-- verified: what-regressed -->
```json
{
  "size": 0,
  "aggs": {
    "shapes": {
      "terms": { "field": "os.hash", "size": 50 },
      "aggs": {
        "before": {
          "filter": { "range": { "@timestamp": { "lt": "2026-08-19T14:00:00Z" } } },
          "aggs": { "p95": { "percentiles": { "field": "took", "percents": [95] } } }
        },
        "after": {
          "filter": { "range": { "@timestamp": { "gte": "2026-08-19T14:00:00Z" } } },
          "aggs": { "p95": { "percentiles": { "field": "took", "percents": [95] } } }
        },
        "established": {
          "bucket_selector": {
            "buckets_path": { "b": "before>_count" },
            "script": "params.b > 0"
          }
        },
        "slowdown": {
          "bucket_script": {
            "buckets_path": { "b": "before>p95[95.0]", "a": "after>p95[95.0]" },
            "script": "params.b == 0 ? 0 : params.a / params.b"
          }
        },
        "worst": {
          "bucket_sort": { "sort": [ { "slowdown": { "order": "desc" } } ], "size": 3 }
        },
        "shape": { "terms": { "field": "os.sig", "size": 1 } }
      }
    }
  }
}
```

```
q5:63a1ca5c80b9   x21.5    50 →  1074ms   n=360
    logs-* | q=(@timestamp >= ? and env:?) | aggs=terms(host,10)>{p95(latency_ms), sum(error_count)} | size=0

q5:fe168406e702   x1.0      9 →     9ms   n=7200
    logs-* | q=(@timestamp >= ? and @timestamp < ? and not status:? and service:?) | size=50 sort=@timestamp:desc

q5:e9794c1be608   x1.0   1484 →  1484ms   n=30
    orders | q=(country:? and status:(? or ? or ? or ? or ? or +3))
```

**Twenty-one times slower**, and the signature says what it is without your
having to go and find the code: a dashboard aggregating `p95(latency_ms)` and
`sum(error_count)` per host. The always-slow report is still there at ×1.0,
which is exactly where it belongs — unchanged.

### The trap in that query

`established` is not decoration. Drop it and the request fails:

```
"caused_by": {
  "type": "null_pointer_exception",
  "reason": "Cannot invoke \"java.lang.Double.doubleValue()\" because \"bucketValue\" is null"
}
```

A shape with no documents in the *before* window has no `before>p95`, so
`bucket_script` produces no value, and `bucket_sort` dereferences it. The
aggregation breaks on precisely the bucket you would most want to see — a query
shape that is brand new. `bucket_selector` drops those buckets before the sort
reaches them, and they are the subject of
[their own page](what-a-deploy-changed.md) anyway. `"gap_policy":
"insert_zeros"` on the `bucket_script` also stops the crash, but it keeps new
shapes in the list at ×0, sorted last, where they read as "fine".

## Which one is worth fixing

"What regressed" and "what costs you" are different questions, and the second has
a more surprising answer. Rank by the total time each shape spent:

<!-- verified: worth-fixing -->
```json
{
  "size": 0,
  "aggs": {
    "shapes": {
      "terms": { "field": "os.hash", "size": 5, "order": { "total_ms": "desc" } },
      "aggs": {
        "total_ms": { "sum": { "field": "took" } },
        "median": { "percentiles": { "field": "took", "percents": [50] } },
        "shape": { "terms": { "field": "os.sig", "size": 1 } }
      }
    }
  }
}
```

```
q5:63a1ca5c80b9   n=360     median=  47ms   total=118.6s
q5:fe168406e702   n=7200    median=   8ms   total= 57.6s
q5:e9794c1be608   n=30      median=1316ms   total= 39.5s
q5:4dde138a2ad7   n=90      median=  25ms   total=  2.3s
```

**The query you run most is not the query that costs you most.** The workhorse
ran 7200 times and spent 57.6 seconds. The dashboard aggregation ran 360 times —
twenty times less — and spent 118.6 seconds, twice as much. Meanwhile the report
everyone complains about, the one that takes a second and a half, accounts for
39.5 seconds all afternoon: it is the slowest query you have and close to the
cheapest thing on this list to ignore.

Sorting by `sum(took)` is what turns "which query is slow" into "where does the
cluster's afternoon actually go".

## Then read it, or run it

You have a signature, which is usually enough to recognise the query. When it is
not, `os.q` is the same line with the real values still in it — paste the `q=(…)`
segment into the Dashboards search bar and you are looking at the query itself.

```
logs-* | q=(@timestamp >= now-15m and env:prod) | aggs=terms(host,10)>{p95(latency_ms), sum(error_count)} | size=0
                ────────────────────────────────
                paste this part into Dashboards
```

And if you want to know why the signature says what it says — why a value was
erased, why a clause is missing — `explain()` answers per clause. See
[How the fingerprint works](../explanation/how-it-works.md).
