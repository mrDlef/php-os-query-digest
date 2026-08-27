# os-query-digest

**Human-readable, loggable digests and stable fingerprints for OpenSearch DSL
queries.**

A search request in a log file is a wall of nested braces nobody reads. This
turns it into one line you can paste into Dashboards, plus a stable fingerprint
you can `terms` aggregate on.

**Before.**

<!-- verified: index-digest -->
```json
{
  "query": {
    "bool": {
      "filter": [
        { "range": { "@timestamp": { "gte": "now-15m", "lt": "now" } } },
        { "term": { "service": "api" } }
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
hash  q5:fe168406e702
```

|             | what it is                             | what it is for                          |
|-------------|----------------------------------------|-----------------------------------------|
| `text`      | the query in DQL, with real values     | paste it into the Dashboards search bar |
| `signature` | the same line with literals erased     | read the *shape* at a glance            |
| `hash`      | versioned fingerprint of the signature | `terms` aggregate on it                 |

[Try it on your own query :material-arrow-right-bold:](playground.md){ .md-button .md-button--primary }
[Getting started](getting-started.md){ .md-button }

The playground runs this library in your browser — PHP compiled to WebAssembly,
no server, nothing leaves the page.

**The third one is the point.** Log the hash next to `took`, and your log index
answers questions your dashboards cannot: which *kind* of query got slow this
afternoon, which shape runs a thousand times an hour, which one appeared the day
the incident started.

---

## Start here

<div class="grid cards" markdown>

- :material-rocket-launch: **[Getting started](getting-started.md)**

    From `composer require` to a readable log line, in five minutes.

- :material-flask: **[Try it on your query](playground.md)**

    Runs in your browser. No install, nothing leaves the page.

- :material-book-open-variant: **[How the fingerprint works](explanation/how-it-works.md)**

    Why two differently-written queries land on the same hash.

- :material-api: **[Public API](reference/api.md)**

    The twenty classes you may depend on.

</div>

## Why you can afford it on every search

- **Two runtime dependencies**: `php` and `ext-json`. Nothing else is installed,
  so nothing else can break.
- **~30 µs per request**, against a search that takes milliseconds. `lazy()`
  costs 0.2 µs, so a debug record your handler drops parses nothing at all.
- **PHP 7.4 through 8.5**, tested against every one of them.
- **Certified against real clusters** — OpenSearch 2.19.6 and 3.8.0, not
  inferred from a specification.

## Install

```bash
composer require mr-dlef/os-query-digest
```

```php
use MrDlef\OsQueryDigest\Formatter;

$digest = Formatter::create()->describe($request, 'logs-2026.08.13');

$logger->info('opensearch.search', [
    'q'    => $digest->text(),
    'hash' => $digest->hash(),
    'took' => $response['took'],
]);
```

That is the whole of it. [Getting started](getting-started.md) takes it from
there.
