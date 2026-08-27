# Capture at the transport

The Monolog processor needs an application that already logs its search bodies.
This needs one that talks to the cluster over HTTP — which is all of them.

Wrap the client your OpenSearch library already uses, and every `_search` and
`_msearch` it sends is digested on the way past, joined to what it cost, and
handed to an observer. **No call site changes.** ([Which one to
use](#which-one-to-use) says what "the client" means for yours, and which
libraries have no seam to wrap.)

```php
use MrDlef\OsQueryDigest\Http\DigestingClient;
use MrDlef\OsQueryDigest\Http\LoggingObserver;

$client = new DigestingClient($client, new LoggingObserver($logger));
```

That is the whole integration. Every search now produces one log record:

<!-- verified: transport-record -->
```json
{
  "message": "opensearch.search",
  "os": {
    "idx": "logs-*",
    "q": "logs-* | q=(not status:200 and service:api) | size=5",
    "sig": "logs-* | q=(not status:? and service:?) | size=5",
    "hash": "q5:b87b162b2b4e"
  },
  "took": 6,
  "elapsed_ms": 9,
  "status": 200
}
```

`os` and `took` are exactly what the [dashboard pack](dashboards.md) maps, so
importing the template and the four panels is the only other thing to do.

## Which one to use

There are two, and what picks one is not the name of your OpenSearch library. It
is what that library puts its requests through.

| | attaches to | sees |
|---|---|---|
| `Http\DigestingClient` | a PSR-18 client, wherever the library takes one | everything sent through `sendRequest()` |
| `Http\Guzzle\DigestMiddleware` | a Guzzle handler stack | that, plus every asynchronous and pooled request |

A Guzzle client *is* a PSR-18 client, so the decorator works on one — for the
requests it sends synchronously. The middleware sits a layer lower and sees the
rest. So the question to ask about your client is: **is there a PSR-18 client or
a Guzzle handler stack anywhere inside it?**

| your client | its transport | attach with |
|---|---|---|
| `elasticsearch-php` 8 | `elastic/transport`, PSR-18 | either |
| `opensearch-php` ≥ 2.4 built through `GuzzleClientFactory`, `SymfonyClientFactory` or `TransportFactory` | PSR-18 | either |
| your own Guzzle client, HTTPlug, anything PSR-18 | PSR-18 | either |
| `opensearch-php` built through the deprecated `ClientBuilder` | `ezimuel/ringphp` | **neither** — see below |
| `opensearch-php` ≤ 2.3 | `ezimuel/ringphp` | **neither** — see below |
| `elasticsearch-php` 7.x | `ezimuel/ringphp` | **neither** — see below |

"Either" is literal in the first three rows, with one condition on the
middleware: it needs the PSR-18 client to be Guzzle-backed *and* the handler
stack to be yours to push onto.

### On a Guzzle stack you own

```php
use GuzzleHttp\{Client, HandlerStack};
use MrDlef\OsQueryDigest\Http\Guzzle\DigestMiddleware;

$stack = HandlerStack::create();
$stack->push(new DigestMiddleware(new LoggingObserver($logger)));

$client = new Client(['handler' => $stack]);
```

Where you push it in the stack is a choice. `push()` puts a middleware nearer
the handler than everything pushed before it, so one pushed **after** `retry`
runs once per *attempt* — which is how many searches the cluster actually ran,
and usually what you want. Push it before `retry` to count one per call.

### On `opensearch-php`

The modern client takes the PSR-18 client you give it, so the decorator goes
where you build it:

```php
use GuzzleHttp\Psr7\HttpFactory;
use MrDlef\OsQueryDigest\Http\DigestingClient;
use OpenSearch\{Client, EndpointFactory, RequestFactory, TransportFactory};
use OpenSearch\Serializers\SmartSerializer;

$psr18 = new DigestingClient($yourGuzzleClient, new LoggingObserver($logger));

$serializer = new SmartSerializer();
$factory = new HttpFactory();

$client = new Client(
    (new TransportFactory())
        ->setHttpClient($psr18)
        ->setRequestFactory(new RequestFactory($factory, $factory, $factory, $serializer))
        ->create(),
    new EndpointFactory($serializer),
    [],
);
```

If you would rather let the client build its own Guzzle, its factory takes the
middleware instead — which is the shorter way in:

```php
$client = (new OpenSearch\GuzzleClientFactory())->create([
    'base_uri' => 'http://localhost:9200',
    'middleware' => [new DigestMiddleware(new LoggingObserver($logger))],
]);
```

Both were run against a live 2.x node on `opensearch-php` 2.6.0 and produce the
same fingerprint for the same search, `_msearch` split per line included.

### A ringphp client is covered by neither

`ezimuel/ringphp` predates PSR-7, and that — not asynchrony — is why the two
integrations cannot reach it. A ring handler is a
`callable(array $request): array|FutureArrayInterface`: no `sendRequest()` to
decorate, no handler stack to push onto. The only seam is
`ClientBuilder::setHandler()`, and what it hands you is an array:

```php
['http_method' => 'POST', 'uri' => '/logs-*/_search', 'body' => '{…}', 'headers' => [/* … */]]
```

which is nearly the shape the capture wants, but not a shape it takes.
[Issue #42](https://github.com/mrDlef/php-os-query-digest/issues/42) tracks
closing that gap.

Until it does, an application on a ringphp client has two ways in that need no
transport integration at all: log the request body and read it with the
[Monolog processor](logging.md), or point the [command line](cli.md) at the
search slow log the cluster already writes.

On `opensearch-php` those two rows are on their way out — `ClientBuilder` is
deprecated since 2.4.0 and gone in 3.0.0. The third is not: `elasticsearch-php`
7.x is still a common way to reach an OpenSearch cluster.

### Dependencies

Neither integration is a required dependency. `psr/http-client` and
`guzzlehttp/guzzle` are *suggested*; the library itself still needs nothing but
`php` and `ext-json`.

## Behind a proxy

`/logs-*/_search` and `/opensearch/_search` are the same URL to anything reading
one. The index is in the signature, so a path prefix mistaken for an index name
is a wrong fingerprint rather than a cosmetic slip — tell it about the prefix:

```php
new DigestingClient($client, $observer, null, '/opensearch');
```

## Doing something other than logging

`LoggingObserver` is one implementation of a one-method interface. Implement it
to count, sample, or send somewhere else:

```php
use MrDlef\OsQueryDigest\Http\{ObservedSearch, SearchObserver};

final class SlowOnly implements SearchObserver
{
    public function observe(ObservedSearch $search): void
    {
        if (($search->tookMillis() ?? 0) < 500) {
            return;                          // nothing was parsed
        }

        $this->statsd->increment('search.slow.' . $search->digest()->digest()->hash());
    }
}
```

The digest is still lazy at that point, so a search the observer drops has not
cost a parse. What else is on it:

| | |
|---|---|
| `digest()` | the `LazyDigest` — `->digest()` for the `Digest` itself |
| `tookMillis()` | what the cluster says it spent, or null |
| `elapsedMillis()` | wall clock around the call: the cluster, plus the network |
| `statusCode()` | null if the request never got a response |
| `position()` | which line of an `_msearch`; null if it was not one |

## What it will not do

**It cannot break the call.** Reading the request is wrapped, so is reading the
response, so is the observer's own work. A body that cannot be put back exactly
as it was found is not read at all — an unseekable stream is one the client has
not sent yet, and consuming it would send the request without its query. The
failure mode is a missing digest, never a missing search.

**A request that is not a search passes straight through**, untouched and
unmeasured. Only `_search` and `_msearch` are read: `_search/scroll` sends a
scroll id, `_search/template` an id and its params, `_search/point_in_time`
nothing at all, and digesting those would mint fingerprints for requests that
have no shape.

**A failed request is still counted**, with a null status — the shape that times
out is exactly the shape worth finding, and dropping it would hide the worst
queries from the report. The exception reaches your error handling unchanged.

**A batch reports no `took` per line.** An `_msearch` response opens with the
`took` of the whole batch and carries one per line further in, past the hits of
the line before it. Attributing the batch's number to each line would put the
same figure several times into a `took` aggregation, so each line reports null
and `elapsed_ms` covers the batch. `position()` says which line it was.

**`took` comes off the front of the response.** A search response opens with it
on every certified version, so a fixed peek at the first bytes finds it without
decoding a body that may hold a megabyte of hits — and leaves the response
exactly as the caller will find it. That is an observation about OpenSearch's
serialiser rather than a documented rule, so an integration test asserts it
against real 2.19.6 and 3.8.0 nodes; a version that stopped doing it would fail
that test rather than quietly report null.
