# Command line

The package ships a binary, so a query pasted out of a slow log does not need a
scratch PHP file:

<!-- verified: cli-describe -->
```bash
$ echo '{"query":{"term":{"service":"api"}},"size":50}' \
    | vendor/bin/os-query-digest --index logs-2026.08.13
idx:  logs-*
text: logs-* | q=(service:api) | size=50
sig:  logs-* | q=(service:?) | size=50
hash: q5:5b2210eb5318
kind: browse
```

`--explain` appends the rules table, `--json` emits the digest object, `--hash`
emits nothing but the fingerprint. Every `Options` key has a flag of the same
name, so `--normalization=structural --max-values=none` is the CLI spelling of
the array above.

The reason it exists is `--ndjson`: one query per input line, one line of output
each. Point it at a log of search bodies and "which *kind* of query is slow" is
a `uniq -c` away:

<!-- verified: cli-ndjson -->
```bash
$ os-query-digest --ndjson --hash < slow.ndjson | sort | uniq -c | sort -rn
      3 q5:5b2210eb5318
      1 q5:f70c7bc21a0f
```

Those three are not three slow queries to read: they are one shape, hit on two
different days, with two different `service` values.

Counting is also where `--normalization=structural` earns its keep: under the
default, page 1 and page 3 of one search are two fingerprints, and a `uniq -c`
ranks pagination. [Which level answers which
question](../explanation/how-it-works.md#which-level-answers-which-question)
is the comparison.

A malformed line is reported on stderr and skipped, so one mangled record does
not cost you the rest of the file. Exit codes: `0` ok, `1` an input could not be
parsed, `2` a bad invocation. `--help` lists every flag.

## Without the package

The binary does not need the library installed beside it: every release ships
the CLI as one `.phar` and as an image, and
[without installing PHP at all](../getting-started.md#or-without-installing-php-at-all)
has both commands. A slow log is rarely on a machine anyone would install a PHP
toolchain on.

Only two things differ from `vendor/bin/os-query-digest`:

- **`--version` names the build.** An installed copy is identified by the
  `composer.lock` that installed it; a file someone copied onto a host is
  identified by nothing, so the phar carries the release it was built from and
  says so.
- **The image reads standard input** unless you mount something. It runs as
  `nobody`, which cannot read a root-owned log — piping avoids the question
  entirely.

The fingerprints are the same: CI digests one query with the image and with the
checkout it was built from, and compares the two.

## From the log your cluster already writes

`--ndjson` still asks you for a file of query bodies, which you probably do not
have yet. Your cluster does: `index.search.slowlog` is on in most of them, and
`os-query-digest slowlog` reads it as it is.

<!-- verified: cli-slowlog -->
```bash
$ vendor/bin/os-query-digest slowlog /var/log/opensearch/*_index_search_slowlog.log
60 lines, 59 records, 3 shapes, 13,515 ms total

  count  total ms*  mean    p95    max  shape
     41      6,807   166    246    258  q5:fe168406e702
                                        logs-* | q=(@timestamp >= ? and @timestamp < ? and not status:? and service:?) | size=50 sort=@timestamp:desc
      6      5,978   996  1,325  1,325  q5:6b6fb17c6640
                                        orders-* | q=(sku:(? or ? or ?)) | aggs=date_histogram(created,day)
     12        730    61     86     86  q5:810928290c12
                                        catalog-* | q=(title:~?) | size=10
```

**No application change, nothing to deploy, no index to create** — and the
question the whole library exists for is answered on the file you already have.

**Ranked by total time, not by the slowest record.** A query that took 1.3
seconds once is a bad afternoon; a query that takes 166 ms forty-one times is
the afternoon, and a slow log lists the second one forty-one times without ever
adding them up. `--sort` takes `count`, `mean`, `p95` or `max` when you want the
other reading — `--sort=p95` puts that `date_histogram` on top, which is the
answer to a different and equally real question.

The second line of each row is the **signature**, not one record's values: under
a count of forty-one, a single sample's `service` and timestamps would read as
the group's. `--json` carries both, and labels the sample as one — plus the
timestamps the group spans, which is how you tell a shape that has always been
there from one that arrived with this morning's deploy.

<!-- verified: cli-slowlog-json -->
```bash
$ os-query-digest slowlog --json --top=1 slowlog.log | jq '.[0] | {count, p95_ms, first, last}'
{
  "count": 41,
  "p95_ms": 246,
  "first": "2026-08-20T14:00:03,970",
  "last": "2026-08-20T14:02:03,355"
}
```

Both appenders are read, the plain one and the JSON one beside it. Files are
read a line at a time, so a rotated log of any size is fine, and every
fingerprint flag above applies here too: group the report under the same rules
your application logs under, or the two are about different things.

Records whose JSON keys are namespaced — `…slowlog.source` rather than `source`
— are read as well. That is tolerance for a layout, not a claim: OpenSearch is
what this library certifies, and it is the only thing it promises.

**Lines that hold no search record are skipped in silence.** A slow log also
holds allocation notices and stack traces, and a tool that refused the file over
them would be useless exactly where it is pointed. One line is *not* treated as
noise: a record whose `source[` never closes, which is what log rotation does to
a line. That is reported, because staying quiet about it would understate the
shape it belonged to.

If nothing in the input is a slow log record, that is an error rather than an
empty table — pointing this at the wrong file should not look like a healthy
cluster.

### What a slow log record actually holds

Three things about the file itself, each of which changes how the report reads.
They are not deductions: the reader is tested against records captured from
OpenSearch 2.19.6 and 3.8.0, committed under `tests/slowlog/`.

**A search is logged once per phase.** The query phase and the fetch phase each
write a record, and both carry the same body — so counting both doubles every
number. One phase is read at a time and `query`, the one you tune, is the
default; `--phase=fetch` or `--phase=both` when you want the others. The summary
line says how many records the other phase held, so the choice is never silent.

**The body is the query the shard ran, not the one your client sent.** It has
been rewritten by then: `boost` and `adjust_pure_negative` appear, a `term`
becomes `{"value": …, "boost": 1.0}`, a range that matches nothing on that shard
collapses to `match_none`, and a range every document on it satisfies keeps the
field while losing its bounds — so `@timestamp >= now-15m` reaches the log as
`@timestamp:*`, an unbounded range, which is genuinely all the record says. The
older `from`/`to` spelling every rewritten range uses is read as of v0.10.0;
before that the whole clause came out as `range(?)`.

**So a time window is not part of the shape here.** `now-15m` and `now-7d` over
the same field reach the log identically and share one hash — the bounds were
resolved away before the record was written, and no reading of it brings them
back. Every other kind of value survives; this one does not, and it is worth
knowing before you conclude that two dashboards are running the same search.

!!! warning "Slow log fingerprints are not your application's fingerprints"
    The same request digests differently from the two sides, because the shard
    rewrote it. The hash still tells you *which shape*, and it does that on both
    sides — but group slow log records against slow log records, and application
    digests against application digests. The two sets do not join.

**Records are per shard.** A search over five shards writes five of them, so
`count` is shards touched rather than requests served. That is the right number
for *what is this shape costing the cluster* and the wrong one for *how often was
it called*.

One more, in the same spirit of not deducing: **OpenSearch 3 escapes the body
twice in the JSON layout**, from the same configuration file 2.19.6 escapes it
once with. The extra layer comes off through the JSON decoder rather than by
stripping backslashes, so a query holding an escaped quote survives it.
