# Command line

The package ships a binary, so a query pasted out of a slow log does not need a
scratch PHP file:

```bash
$ echo '{"query":{"term":{"service":"api"}},"size":50}' \
    | vendor/bin/os-query-digest --index logs-2026.08.13
idx:  logs-*
text: logs-* | q=(service:api) | size=50
sig:  logs-* | q=(service:?) | size=50
hash: q3:5b2210eb5318
```

`--explain` appends the rules table, `--json` emits the digest object, `--hash`
emits nothing but the fingerprint. Every `Options` key has a flag of the same
name, so `--normalization=structural --max-values=none` is the CLI spelling of
the array above.

The reason it exists is `--ndjson`: one query per input line, one line of output
each. Point it at a log of search bodies and "which *kind* of query is slow" is
a `uniq -c` away:

```bash
$ os-query-digest --ndjson --hash < slow.ndjson | sort | uniq -c | sort -rn
      3 q3:2e2169e22798
      1 q3:33a434d95576
```

Those three are not three slow queries to read: they are one shape, hit on two
different days, with two different `service` values.

A malformed line is reported on stderr and skipped, so one mangled record does
not cost you the rest of the file. Exit codes: `0` ok, `1` an input could not be
parsed, `2` a bad invocation. `--help` lists every flag.
