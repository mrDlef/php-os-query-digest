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

### 📖 [Documentation](https://mrdlef.github.io/php-os-query-digest/) · ▶ [Try it on your own query](https://mrdlef.github.io/php-os-query-digest/playground/)

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
hash  q4:fe168406e702
```

```php
$digest = MrDlef\OsQueryDigest\Formatter::create()
    ->describe($request, 'logs-2026.08.13');

$digest->text();       // the line above — select it, paste it into Dashboards
$digest->signature();  // the same query with its literals erased: the shape
$digest->hash();       // q4:fe168406e702 — stable, versioned, groupable
```

|             | what it is                             | what it is for                          |
|-------------|----------------------------------------|-----------------------------------------|
| `text`      | the query in DQL, with real values     | paste it into the Dashboards search bar |
| `signature` | the same line with literals erased     | read the *shape* at a glance            |
| `hash`      | versioned fingerprint of the signature | `terms` aggregate on it                 |

**The third one is the point.** Log the hash next to `took`, and your log index
answers questions your dashboards cannot: which *kind* of query got slow this
week, which one showed up with Friday's deploy, which one was hammering the
cluster during the incident. Not which thousand queries were slow — which
**shape** was. OpenSearch, analysing OpenSearch.

That query and that fingerprint are not illustrations: they are
`tests/fixtures/01-error-rate-filter`, and the golden file pins the exact hash.

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

PHP 7.4 → 8.5, `ext-json`, nothing else. Ships a CLI, a Monolog processor, and a
browser playground.

## What you get

- **You can try it before integrating.** `os-query-digest slowlog` reads the
  slow log your cluster already writes and ranks the query *shapes* in it by
  what they cost — no code change, nothing to deploy, no index to create.
- **Logs you can read.** One line replaces the body — and it is DQL, so you
  select it, paste it into the Dashboards search bar, and you are looking at the
  same query.
- **Your log volume drops.** A 40-line body becomes one line, capped.
- **Slow queries become countable.** `terms` on the hash and the top of the list
  is the shape to fix.
- **The dashboard is written already.** An index template and four panels ship
  in the package — import them and you are looking at which shape costs you,
  which one regressed, and which one the last release added.
- **It cannot break your logging.** Nothing is required at runtime, the digest
  is lazy, and a request it cannot parse yields an error field rather than an
  exception. You lose the digest, never the log line.
- **It tells you why.** When two queries you thought were different share a
  hash, `explain()` names the rule that merged them.
- **It is verified, not asserted.** Certified against real OpenSearch 2.19.6 and
  3.8.0 nodes, checked against the official API specification, PHPStan at
  `level: max`, mutation-tested, benchmarked, and run on PHP 7.4 → 8.5.

## Documentation

**[mrdlef.github.io/php-os-query-digest](https://mrdlef.github.io/php-os-query-digest/)**

| | |
|---|---|
| [Getting started](https://mrdlef.github.io/php-os-query-digest/getting-started/) | install to a readable log line, in five minutes |
| [Log your queries](https://mrdlef.github.io/php-os-query-digest/guides/logging/) | the Monolog processor, and how to read the line |
| [Options](https://mrdlef.github.io/php-os-query-digest/guides/options/) | normalisation levels, redaction, display limits |
| [Command line](https://mrdlef.github.io/php-os-query-digest/guides/cli/) | `slowlog`, `--ndjson`, `--explain`, `--hash` |
| [The dashboard pack](https://mrdlef.github.io/php-os-query-digest/guides/dashboards/) | the index template and four panels, imported once |
| [How the fingerprint works](https://mrdlef.github.io/php-os-query-digest/explanation/how-it-works/) | why two different-looking queries share a hash |
| [Hash stability](https://mrdlef.github.io/php-os-query-digest/explanation/hash-stability/) | read this before storing a fingerprint |
| [Public API](https://mrdlef.github.io/php-os-query-digest/reference/api/) | the fourteen classes you may depend on |

The site is published from a release tag, never from `main`: a page describing
an API nobody can install yet is worse than a page a few days out of date.

## Contributing and security

[`CONTRIBUTING.md`](CONTRIBUTING.md) — the commands, and the rules that are not
guessable from the code.

[`SECURITY.md`](SECURITY.md) — report a vulnerability privately, not in an
issue.

## License

LGPL-3.0-or-later — see [LICENSE](LICENSE).

The LGPL builds on the GPL, so both texts ship: [LICENSE](LICENSE) holds the
Lesser GPL and [LICENSE.GPL](LICENSE.GPL) the GPL it refers to.

In practice: you can use this library in a closed-source application without
that application becoming subject to the licence. If you *modify the library
itself* and distribute it, those changes stay under the LGPL.
