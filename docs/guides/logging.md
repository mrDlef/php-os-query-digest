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
  "kind": "aggregate",
  "q": "logs-* | q=(@timestamp >= now-15m and service:api) | size=0",
  "sig": "logs-* | q=(@timestamp >= ? and service:?) | size=0",
  "hash": "q5:b7cc218cda09"
}
```

`kind` is what the request is *for* — this one asks for buckets and no
documents. It is read off the shape, holds no value, and is the field to group
by when the question is what your application searches for rather than which
search is slow. The six of them are in [Kinds](../reference/kinds.md).

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

## When the values may not leave the building

`q` is search input. Names, addresses, e-mail addresses — whatever a user typed
into the box. The moment those records leave for a hosted log collector or a
third-party SIEM, that is the difference between "we may ship these logs" and
"we may not", and it is decided per *field*.

`sig` and `hash` are already value-free, and everything this library is *for*
reads them: which shape got slow, which one the deploy added, which one to group
a dashboard by. `q` is the convenience of pasting into Dashboards. So it can be
turned off:

```php
$formatter = Formatter::create(Options::create()->withText(false));
```

The record is then three fields:

<!-- verified: logging-record-value-free -->
```json
{
  "idx": "logs-*",
  "kind": "aggregate",
  "sig": "logs-* | q=(@timestamp >= ? and service:?) | size=0",
  "hash": "q5:b7cc218cda09"
}
```

The same hash as with the line on — what a shape is *called* does not depend on
what is emitted beside it, so a dashboard built before the switch keeps matching
after it.

Three things worth knowing:

- **The line is never rendered, not rendered and dropped.** So a blanket
  redactor — `withRedactor(fn ($field, $value) => '?')` — is not the same trade:
  it renders the same line twice to throw one away, and a *per-field* redactor is
  one forgotten field away from a leak. Here there is no literal anywhere in the
  digest, and `text()` returns the signature so that nothing reading the wrong
  accessor can find one either.
- **`q` is omitted, not emptied.** A `q` that duplicated `sig` would still have
  to be inspected before those logs could ship. It is also the longest of the
  four fields, so this is the cheapest log-volume win the library offers.
- **It does not, by itself, mean no literal is emitted.** Under
  `Normalization::none()` the signature *is* the readable line. The default —
  `Normalization::values()` — is what makes `sig` value-free, and the two
  together are what a regulated deployment wants.

The shipped [dashboard pack](dashboards.md) needs no change: its panels group and
aggregate on `os.hash` and read `os.sig`. `os.q` appears only in the index
pattern's field list, so it simply has no values.

## Ranking what you log, without a log index

Shipping digests to an index and building panels on them is one way to read
them. The other is to add them up where they are made, which needs no index and
no dashboard:

```php
use MrDlef\OsQueryDigest\Analysis\Report;

$report = new Report();

// wherever a search comes back
$report->record($digest, (float) $response['took']);

// at the end of the request, the job, the test run
foreach ($report->top(5) as $shape) {
    printf("%-16s  %5d ×  %8.1f ms  %s\n",
        $shape->hash(), $shape->count(), $shape->total(), $shape->signature());
}
```

`Report` is the grouping and the ranking the `slowlog` command runs on a
cluster's slow log — same class, fed from the other end. It holds one object per
*shape*, so a long-running worker accumulating a million searches over forty
shapes holds forty of them.

It is the natural place to answer "what does this page actually search for":
group the top by [`kind()`](../reference/kinds.md) and a request that fires
eleven searches turns into two autocompletes, one lookup and eight browses.

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
