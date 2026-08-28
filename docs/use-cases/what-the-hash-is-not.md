# What the hash is not for

The fingerprint is a good answer to "which queries are the same shape". People
reach for it to answer three other questions, and one of those quietly returns
the wrong data to the wrong user.

## Not a cache key

The signature erases literal values. That is what makes it useful for grouping,
and it makes it disqualifying as a cache key:

```php
$digest = Formatter::create()->describe([
    'query' => ['bool' => ['filter' => [
        ['term'  => ['tenant_id' => $tenant]],
        ['range' => ['@timestamp' => ['gte' => 'now-1d']]],
    ]]],
    'size' => 20,
], 'invoices');
```

```
tenant 41     q5:b9b2fe44f67a    invoices | q=(@timestamp >= now-1d and tenant_id:41) | size=20
tenant 42     q5:b9b2fe44f67a    invoices | q=(@timestamp >= now-1d and tenant_id:42) | size=20
tenant 9999   q5:b9b2fe44f67a    invoices | q=(@timestamp >= now-1d and tenant_id:9999) | size=20
```

**One hash, three tenants.** Cache on it and tenant 42 is served tenant 41's
invoices. This is not a subtle failure mode you would catch in review — it is a
cache that works perfectly in every single-tenant test you write and leaks across
customers the first time two of them are active at once.

There is no option that fixes this. `Normalization::NONE` keeps values in the
rendered *text*, not in the hash; the hash is a fingerprint of the signature by
definition. If you want a cache key, hash the request body — `sha1(json_encode($request))`
is two functions and answers the question you are actually asking.

## Not an anonymised query

The hash is safe to put anywhere. `os.q` is not, and they arrive together:

```json
{
  "idx":  "logs-*",
  "kind": "browse",
  "q":    "logs-* | q=(email:alice@example.com and status:shipped) | size=20",
  "sig":  "logs-* | q=(email:? and status:?) | size=20",
  "hash": "q5:614ecdff8fdf"
}
```

At the default normalisation the rendered line keeps literal values, because
being able to paste it into Dashboards is the point of having it. So a `term` on
an email address puts that address wherever the line goes — and log shipping
tends to go further than people remember.

`kind`, `sig` and `hash` never carry values. If your log index leaves your perimeter, log
those two and drop `q`; every query on
[these pages](index.md) works without it. The
[threat model](https://github.com/mrDlef/php-os-query-digest/blob/main/SECURITY.md)
is blunt about this being the one real risk in the library.

## Not a cost estimate

Two requests with the same hash can differ by orders of magnitude in `took`. The
shape is identical; the work is not:

```
q5:5b2210eb5318   logs-* | q=(service:?) | size=50    ← service:api        1.2M matches
q5:5b2210eb5318   logs-* | q=(service:?) | size=50    ← service:cron-jobs      3 matches
```

Same signature, same hash, wildly different queries to execute. This is why
[the regression page](what-got-slow.md) compares a shape against **its own
history** rather than against other shapes, and why the percentile matters more
than the mean. A p95 within one shape is a real signal. A hash's p95 compared to
another hash's p95 tells you about two workloads that happen to be named
similarly.

It also means a shape whose latency moved is not necessarily a shape whose *query*
changed. The values it is being called with may have changed instead — a filter
that used to match three documents now matching a million is invisible in the
signature, on purpose. The signature tells you what was asked; it does not tell
you how much data answered.

## What it is for

For completeness, the three questions it does answer:

- **Which shapes exist**, and which appeared or vanished between two releases —
  [what a deploy changed](what-a-deploy-changed.md).
- **How one shape behaves over time**, compared to itself —
  [which shape got slow](what-got-slow.md).
- **Where the time goes**, by summing `took` per shape rather than counting hits.

And one property worth knowing, which is the reverse of the cache-key problem:
two queries written differently but asking the same thing land on the same hash.

```
filter: [ status:open, team:core ]   →   q5:62a3e27e69de
filter: [ team:core, status:open ]   →   q5:62a3e27e69de
```

Clause order does not survive normalisation, so a refactor that reorders a `bool`
produces no new shape. [How the fingerprint works](../explanation/how-it-works.md)
covers what else is erased and why.
