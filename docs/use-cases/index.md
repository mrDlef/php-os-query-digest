# Use cases

Everything on these pages comes from one habit: **log the hash next to `took`**.

```php
$logger->info('opensearch.search', [
    'os'   => $formatter->lazy($request, $index),
    'took' => $response['took'],
]);
```

That is it. [Log your queries](../guides/logging.md) covers the mechanics, and a
Monolog processor does it without touching your call sites. What follows is what
the log index can then answer — questions your dashboards cannot, because
Dashboards can show you that search is slow and cannot tell you *which kind* of
search.

## The one thing to get right: the mapping

The digest gives you four fields. Two of them need a mapping you would not get
by default, and getting it wrong is the difference between an aggregation and a
pile of word fragments. The library ships that mapping as an index template —
this is the file, not a copy of it:

```json title="resources/dashboards/index-template.json"
--8<-- "resources/dashboards/index-template.json"
```

```bash
curl -XPUT localhost:9200/_index_template/os-query-digest \
     -H 'Content-Type: application/json' \
     --data-binary @resources/dashboards/index-template.json
```

`release` is there for [What a deploy changed](what-a-deploy-changed.md) and is
your own field, not the digest's — drop it if you do not log one. Everything
else on these pages needs the four `os.*` fields and `took`.

`os.hash` and `os.sig` are **`keyword`**, not `text`. A `text` field is analysed,
so a `terms` aggregation on it returns `logs`, `timestamp`, `service` — the words
in your signature rather than the signature. `os.q` is the one field that *should*
be `text`: it holds real values, and searching it is the point.

`ignore_above` on the signature is a safety valve, not a limit you should hit. A
signature longer than a kilobyte would stop being indexed rather than fail the
document, which is the right way round for a log record.

!!! warning "The `q` field keeps your literal values"
    `os.q` is the line with real values in it, so a `term` on an email address
    puts that address in your log index. The signature and the hash never do.
    If that matters, log `sig` and `hash` and drop `q` — every page here works
    without it. See [the threat model](https://github.com/mrDlef/php-os-query-digest/blob/main/SECURITY.md).

## The scenario on these pages

Every query and every number below was run against real nodes — **OpenSearch
2.19.6 and 3.8.0** — not inferred from a specification. `UseCaseTest` extracts
each aggregation from the page you are reading and executes it, so an example
here cannot drift from what a cluster actually does. The index holds six hours of
one afternoon, and four query shapes:

| shape | what it is | how it behaves |
|---|---|---|
| `q5:fe168406e702` | the error-rate filter | 1200/hour, 8 ms, all afternoon |
| `q5:63a1ca5c80b9` | a dashboard's aggregation | 60/hour, and it changes at 14:00 |
| `q5:e9794c1be608` | a report over `orders` | 5/hour, and always slow |
| `q5:4dde138a2ad7` | a vector search | does not exist before 15:00 |

Two things happened that afternoon, an hour apart, and telling them apart is
the whole exercise: **something got slow at 14:00, and a deploy shipped at
15:00.** They are not the same event, and the obvious query blames the wrong one.

<div class="grid cards" markdown>

- :material-speedometer-slow: **[Which shape got slow](what-got-slow.md)**

    And which one is actually worth fixing, which is not the same question.

- :material-rocket-launch-outline: **[What a deploy changed](what-a-deploy-changed.md)**

    Query shapes that did not exist before the release.

- :material-hand-back-left: **[What the hash is not for](what-the-hash-is-not.md)**

    Three things people try. One of them silently corrupts data.

- :material-view-dashboard-outline: **[The dashboard pack](../guides/dashboards.md)**

    The same four questions, imported once instead of pasted.

</div>
