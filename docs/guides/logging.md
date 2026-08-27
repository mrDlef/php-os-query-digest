# Log your queries

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

<!-- verified: logging-record -->
```json
{
  "idx": "logs-*",
  "q": "logs-* | q=(@timestamp >= now-15m and service:api) | size=0",
  "sig": "logs-* | q=(@timestamp >= ? and service:?) | size=0",
  "hash": "q5:b7cc218cda09"
}
```

## With Monolog

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

## If it does not log its bodies

This one still asks something of the application: that the request already
reaches a log call. If it does not, there is a way in that asks nothing at all —
wrap the HTTP client instead, and no call site changes. See
[Capture at the transport](transport.md).

## Reading the line

<!-- verified: logging-line -->
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
