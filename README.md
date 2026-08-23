# os-query-digest

[![CI](https://github.com/mrDlef/php-os-query-digest/actions/workflows/ci.yml/badge.svg)](https://github.com/mrDlef/php-os-query-digest/actions/workflows/ci.yml)
![PHP 7.4 – 8.5](https://img.shields.io/badge/PHP-7.4%20%E2%80%93%208.5-777bb3)
![no runtime dependencies](https://img.shields.io/badge/runtime%20dependencies-none-2e7d32)
![LGPL-3.0-or-later](https://img.shields.io/badge/licence-LGPL--3.0--or--later-555)

**Human-readable, loggable digests and stable fingerprints for OpenSearch DSL
queries.**

A DSL query in a log line is a wall of nested braces: nobody greps it, nobody
groups by it, and it costs a fortune in log volume. So the question you actually
have during an incident — *which **kind** of query is hurting us?* — has no
answer anywhere in your stack.

This gives it one!

### 📖 [Documentation](https://mrdlef.github.io/php-os-query-digest/) · ▶ [Try it on your own query](https://mrdlef.github.io/php-os-query-digest/playground/)

---


**Before.** One search request, as it lands in your logs — and there are
thousands of these a day:

<!-- verified: readme-digest -->
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

### Download the CLI

It digests a query you paste, a file of them a line at a time, or the slow log
your cluster is already writing —
[every flag is in the guide](https://mrdlef.github.io/php-os-query-digest/guides/cli/):

```bash
curl -sSLO https://github.com/mrDlef/php-os-query-digest/releases/latest/download/os-query-digest.phar
chmod +x os-query-digest.phar
./os-query-digest.phar slowlog /var/log/opensearch/*_index_search_slowlog.log
```

That last one ranks the shapes in the file by what they cost you: no code to
change, nothing to deploy, no index to create. Needs PHP 7.4 → 8.5 on the
machine, and every release carries a `.sha256` beside the file.

### Or run it in Docker

```bash
cat *_index_search_slowlog.log \
  | docker run -i --rm ghcr.io/mrdlef/os-query-digest slowlog
```

It reads standard input, so nothing has to be mounted.
[Mounting a log instead](https://mrdlef.github.io/php-os-query-digest/getting-started/#or-without-installing-php-at-all)
is one flag. Both artefacts are built from the same source as the package and
mint the same fingerprints — CI digests one query each way and compares the two.

### Then put it in your application

```bash
composer require mr-dlef/os-query-digest
```

Three ways in, and they mix:

- **[The Monolog processor](https://mrdlef.github.io/php-os-query-digest/guides/logging/#with-monolog)**
  replaces the request body with its digest wherever your application already
  logs one. One processor, no call sites.
- **[A PSR-18 decorator or a Guzzle middleware](https://mrdlef.github.io/php-os-query-digest/guides/transport/)**
  digests every search on its way out of the HTTP client, already joined to what
  it cost.
- **The formatter**, when you want the digest in your own hands:

```php
use MrDlef\OsQueryDigest\Formatter;

$digest = Formatter::create()->describe($request, 'logs-2026.08.13');

$logger->info('opensearch.search', [
    'q'    => $digest->text(),
    'hash' => $digest->hash(),
    'took' => $response['took'],
]);
```

`ext-json`, and no runtime dependencies: Monolog, PSR-18 and Guzzle are
suggested, never required.

## What you get

- **Logs you can read, and fewer bytes of them.** A 40-line body becomes one
  capped line — and it is DQL, so you select it, paste it into the Dashboards
  search bar, and you are looking at the same query.
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

**[mrdlef.github.io/php-os-query-digest](https://mrdlef.github.io/php-os-query-digest/)** —
a guide for each way in, the reference, and the reasoning behind the
normalisation rules.

- **[Getting started](https://mrdlef.github.io/php-os-query-digest/getting-started/)**
  goes from nothing to a log line you can read.
- **[Hash stability](https://mrdlef.github.io/php-os-query-digest/explanation/hash-stability/)**
  comes before storing a fingerprint anywhere permanent: the prefix moves when
  the rules do, so a stored hash is never silently reinterpreted.

The site tracks the latest release, not `main`.

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
