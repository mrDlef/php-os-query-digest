# Getting started

Five minutes, from nothing to a log line you can read.

## Install

```bash
composer require mr-dlef/os-query-digest
```

Requires PHP 7.4 or newer and `ext-json`. That is the whole dependency list.

### Or without installing PHP at all

The next section points the tool at a slow log, and a slow log usually lives on
a machine with no PHP toolchain on it — nor any reason to acquire one. The CLI
is published as a single file and as an image, both built from the same source
by [the release workflow](https://github.com/mrDlef/php-os-query-digest/releases):

=== "Docker"

    ```bash
    cat *_index_search_slowlog.log \
      | docker run -i --rm ghcr.io/mrdlef/os-query-digest slowlog
    ```

    It reads standard input, so nothing has to be mounted. To pass a path
    instead, mount the directory and keep your own identity — the image runs as
    `nobody`, which cannot read a root-owned log:

    ```bash
    docker run --rm --user "$(id -u):$(id -g)" -v /var/log/opensearch:/logs:ro \
      ghcr.io/mrdlef/os-query-digest slowlog '/logs/*_index_search_slowlog.log'
    ```

=== "One file"

    ```bash
    curl -sSLO https://github.com/mrDlef/php-os-query-digest/releases/latest/download/os-query-digest.phar
    curl -sSL  https://github.com/mrDlef/php-os-query-digest/releases/latest/download/os-query-digest.phar.sha256 \
      | sha256sum -c -
    chmod +x os-query-digest.phar

    ./os-query-digest.phar slowlog /var/log/opensearch/*_index_search_slowlog.log
    ```

    A phar needs PHP on the machine — 7.4 or newer, `ext-json`, no Composer and
    no `vendor/`. `--version` names the build it came from, which a copied file
    otherwise cannot tell you.

Both carry the same rules as the library, so their fingerprints are the
library's: CI compares the image's output with a checkout's rather than trusting
that they agree.

## First, on the log you already have

Before touching your code: the binary that ships with the package reads the slow
log your cluster is already writing, and ranks what is in it by what it costs.

<!-- verified: getting-started-slowlog -->
```bash
$ vendor/bin/os-query-digest slowlog /var/log/opensearch/*_index_search_slowlog.log
60 lines, 59 records, 3 shapes, 13,515 ms total

  count  total ms*  mean    p95    max  shape
     41      6,807   166    246    258  q4:fe168406e702
                                        logs-* | q=(@timestamp >= ? and @timestamp < ? and not status:? and service:?) | size=50 sort=@timestamp:desc
      6      5,978   996  1,325  1,325  q4:6b6fb17c6640
                                        orders-* | q=(sku:(? or ? or ?)) | aggs=date_histogram(created,day)
```

Two minutes, no code change, nothing deployed — and if that table is not
interesting on your own traffic, you have your answer before writing a line.
[The whole sub-command](guides/cli.md#from-the-log-your-cluster-already-writes)
is one page.

## Your first digest

Take a request you already send to OpenSearch — the array you hand to
`opensearch-php`, or the JSON string from a slow log.

<!-- verified: getting-started-digest -->
```php
use MrDlef\OsQueryDigest\Formatter;

$request = [
    'query' => ['bool' => ['filter' => [
        ['term'  => ['service' => 'api']],
        ['range' => ['@timestamp' => ['gte' => 'now-15m']]],
    ]]],
    'size' => 50,
];

$digest = Formatter::create()->describe($request, 'logs-2026.08.13');

echo $digest->text();       // logs-* | q=(@timestamp >= now-15m and service:api) | size=50
echo $digest->signature();  // logs-* | q=(@timestamp >= ? and service:?) | size=50
echo $digest->hash();       // q4:…
```

Three things happened without being asked for:

- **the index became `logs-*`** — a daily index would otherwise mint a new
  fingerprint every midnight;
- **`bool.filter` became `and`** — it differs from `must` in scoring, not in
  which documents match;
- **the values were erased in the signature**, so every search of this shape
  shares one fingerprint however different the search terms.

## Put it in your logs

Log the digest instead of the request body:

```php
$logger->info('opensearch.search', [
    'q'    => $digest->text(),
    'hash' => $digest->hash(),
    'took' => $response['took'],
]);
```

If you log at debug level and filter most of it out, use `lazy()` instead — it
parses nothing until something reads it, which costs about 0.2 µs for a record
your handler drops:

```php
$logger->debug('opensearch.search', [
    'q' => Formatter::create()->lazy($request, $index),
]);
```

Already using Monolog? [One processor does every call site](guides/logging.md#with-monolog).

## Now ask the question the hash exists for

With the fingerprint in your log index, this becomes a `terms` aggregation:

> *which kind of query got slow this afternoon?*

```
GET logs-*/_search
{
  "query": { "range": { "@timestamp": { "gte": "now-4h" } } },
  "aggs": {
    "by_shape": {
      "terms": { "field": "hash", "order": { "slowest": "desc" } },
      "aggs": { "slowest": { "avg": { "field": "took" } } }
    }
  }
}
```

Each bucket is one *shape* of query, not one query. Sort by `took` and the top
bucket is the shape worth looking at — and `q` on any document in it is a line
you can paste straight into Dashboards.

## Try it without installing anything

It runs this same library, compiled to WebAssembly, in your browser. Paste a
real request from your slow log: nothing leaves the page.

[Open the playground :material-arrow-right-bold:](playground.md){ .md-button .md-button--primary }

[How it manages that](explanation/playground.md) is worth a read on its own.

## Where to go next

- **[Log your queries](guides/logging.md)** — the Monolog processor, and how to
  read the rendered line.
- **[Options](guides/options.md)** — normalisation levels, redaction, display
  limits.
- **[How the fingerprint works](explanation/how-it-works.md)** — why two
  differently-written queries land on the same hash, and what is deliberately
  thrown away.
- **[Hash stability](explanation/hash-stability.md)** — read this before you
  store a fingerprint anywhere permanent.
