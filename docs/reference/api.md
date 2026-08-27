# Public API

Nineteen classes. Everything else in `src/` is `@internal` and may move in a
patch release — see [what counts as public](../explanation/public-api.md) for
why the line is drawn there.

`ApiBoundaryTest` fails the build if this list and the annotations ever
disagree, so what follows cannot quietly drift from the code.

---

## Entry point

### `Formatter`

```php
static create(?Options $options = null): Formatter
options(): Options
describe(array|string $request, ?string $index = null): Digest
explain(array|string $request, ?string $index = null): Explanation
lazy(array|string $request, ?string $index = null): LazyDigest
```

`$request` is a search body, an `['index' => …, 'body' => …]` envelope as
produced by `opensearch-php`, or the JSON string of either. `$index` overrides
whatever the envelope carried.

`describe()` does the work immediately. `lazy()` defers all of it until
something reads the result — use it when the record may be filtered out.
`explain()` returns the digest plus every normalisation rule that fired.

!!! warning "Throws"
    `InvalidQueryException` when `$request` is neither an array nor decodable
    JSON. A body it cannot *understand* does not throw — it renders as
    `type(?)`.

---

## Results

### `Digest`

```php
index(): string
text(): string
signature(): string
hash(): string
notes(): array
toArray(): array
```

Implements `JsonSerializable` and `__toString()` (which returns `text()`).
`toArray()` gives the compact `{idx, q, sig, hash, notes}` object — without `q`
under `Options::withText(false)`, where `text()` returns the signature because
there is no literal line to return.

### `LazyDigest`

```php
digest(): Digest
```

Implements `JsonSerializable` and `__toString()`. Nothing is parsed until one of
those is called; both memoise, so reading it twice costs once.

### `Explain\Explanation`

```php
digest(): Digest
rules(): Rule[]
has(string $rule): bool
ruleIds(): string[]
toArray(): array
```

`has()` takes a `Rule::` constant. Casting to string gives the human-readable
table.

### `Explain\Rule`

```php
id(): string
count(): int
details(): string[]
description(): string
```

One rule that actually fired, with how many times and on what. A rule that had
nothing to do is never reported. The 22 `Rule::` constants name every rule —
`MUST_FILTER_MERGED`, `BOOST_DROPPED`, `TERMS_LOOKUP`, `EXTENSION_RENDERED` and
the rest.

---

## Configuration

### `Options`

Immutable; every `with*()` returns a copy.

```php
static create(): Options
static fromArray(array $spec): Options

withNormalization(Normalization $normalization): Options
withMaxClauses(?int $maxClauses): Options
withMaxValues(?int $maxValues): Options
withMaxLength(?int $maxLength): Options
withIndexNormalizer(IndexNormalizer $indexNormalizer): Options
withRedactor(?callable $redactor): Options
withAggNames(bool $includeAggNames): Options
withText(bool $emitText): Options
withHashLength(int $hashLength): Options
withHashVersion(string $hashVersion): Options
withClauseRenderer(string $type, ClauseRenderer $renderer): Options
```

Each has a matching getter. `fromArray()` accepts the nine keys in
`Options::KEYS` and throws `InvalidOptionException` on an unknown key or a wrong
type — the redactor and clause renderers have no array form, being callables and
objects.

The redactor is called as `fn(string $field, mixed $value): mixed` before a
value is rendered.

`withText(false)` drops the readable line: it is never rendered, `toArray()`
emits `idx` / `sig` / `hash`, and `text()` returns the signature. See
[when the values may not leave the building](../guides/logging.md#when-the-values-may-not-leave-the-building).

See [Options](../guides/options.md) for what each one does to the output.

### `Normalization`

```php
static none(): Normalization
static values(): Normalization        // the default
static structural(): Normalization
static fromLevel(string $level): Normalization

level(): string
erasesValues(): bool
erasesCardinality(): bool
erasesPagination(): bool
```

Constants: `NONE`, `VALUES`, `STRUCTURAL`.

### `IndexNormalizer`

```php
static datePatterns(): IndexNormalizer   // the default
static identity(): IndexNormalizer
static fromMode(string $mode): IndexNormalizer

normalize(string $index): string
```

Constants: `DATE_PATTERNS`, `IDENTITY`. `datePatterns()` collapses
`logs-2026.08.13` to `logs-*`, so a daily index does not mint a new fingerprint
every midnight.

---

## Extension

### `Extension\ClauseRenderer`

```php
render(array $body): ?RenderedClause
```

Implement it to teach the library a query type it leaves opaque, and register it
with `Options::withClauseRenderer()`. Return `null` for a body you do not
recognise — the clause then stays `type(?)`, which is true, where a guess would
be a fingerprint built on a misreading.

A renderer is only ever consulted for a type the library does not model
natively. See [teaching it a query type](../guides/extending.md).

### `Extension\RenderedClause`

```php
static on(string $field, string $label): RenderedClause
static fieldless(string $label): RenderedClause
withParam(string $name, scalar|null $value): RenderedClause

field(): string
label(): string
params(): array
```

Immutable. Parameter names survive into the signature, their values do not — the
same rule every other clause follows. A numeric parameter name throws
`InvalidOptionException`.

---

## Failures

### `Exception\InvalidQueryException`

Thrown by `describe()`, `explain()` and `lazy()` when the input is neither an
array nor decodable JSON. Extends `InvalidArgumentException`.

### `Exception\InvalidOptionException`

Thrown by `Options::fromArray()` on an unknown key or a wrong type, by
`Normalization::fromLevel()` and `IndexNormalizer::fromMode()` on an unknown
name, and by `RenderedClause::withParam()` on a numeric name.

---

## Transport

See [Capture at the transport](../guides/transport.md) for which of the two to
use and why.

### `Http\DigestingClient`

```php
__construct(ClientInterface $inner, SearchObserver $observer, ?Formatter $formatter = null, string $basePath = '')
sendRequest(RequestInterface $request): ResponseInterface
```

A PSR-18 client that digests every `_search` and `_msearch` passing through it.
Anything else passes straight through. `$basePath` is the path prefix the cluster
is mounted under, if it is behind a proxy — without it that prefix is read as the
index name.

### `Http\Guzzle\DigestMiddleware`

```php
__construct(SearchObserver $observer, ?Formatter $formatter = null, string $basePath = '')
__invoke(callable $handler): callable
```

The same capture as a Guzzle middleware — `$stack->push(new
DigestMiddleware($observer))`. Needed for the requests a PSR-18 decorator cannot
see: asynchronous and pooled ones, which is most of what `opensearch-php` sends.

### `Http\SearchObserver`

```php
observe(ObservedSearch $search): void
```

What to do with a search once it has been seen. It may throw: both integrations
catch everything an observer does, because a digest is never worth a failed
request.

### `Http\ObservedSearch`

```php
digest(): LazyDigest
tookMillis(): ?int
elapsedMillis(): float
statusCode(): ?int
position(): ?int
```

One search seen going out, and what came back. The digest is still lazy, so an
observer that drops this search has not paid to parse it. `tookMillis()` is null
for every line of an `_msearch`, whose response reports one `took` for the whole
batch, and when the response body could not be read without disturbing it.
`statusCode()` is null when the request never got a response.

### `Http\LoggingObserver`

```php
__construct(LoggerInterface $logger, string $level = LogLevel::INFO, string $message = 'opensearch.search')
```

Writes one PSR-3 record per search, with `os` and `took` in the shape the
[dashboard pack](../guides/dashboards.md) maps.

---

## Monolog

### `Monolog\DigestProcessor`

```php
__construct(?Formatter $formatter = null, string $requestKey = 'query', string $indexKey = 'index')
__invoke(array|LogRecord $record)
```

One class for both Monolog 2 and 3. Push it once and every log record carrying a
search body under `$requestKey` gets it replaced by the digest.

### `Monolog\SafeDigest`

The object that actually lands in your log context — a `LazyDigest` that cannot
take the record down with it. If parsing fails while Monolog is formatting, it
serialises the error rather than throwing, because losing a digest is acceptable
and losing the log line is not.
