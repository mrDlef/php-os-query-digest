# What it costs

The pitch is that you can afford this on every search. That was an argument
until it was measured.

```bash
make bench                    # PHP 8.3 in Docker
make bench PHP_VERSION=7.4
```

Measured on the committed fixtures — the same requests the suite treats as
representative, from a single term to a 200-value `terms` clause:

|                                     | PHP 8.5    | PHP 7.4    |
|-------------------------------------|------------|------------|
| `describe()`, mean over 17 fixtures | **~30 µs** | **~37 µs** |
| `json_encode()` of the same body    | ~0.7 µs    | ~1.5 µs    |
| `lazy()`                            | **0.2 µs** | 0.17 µs    |

**Next to the search itself, it disappears.** A query that takes 20 ms round-trip
pays about 0.15% to be readable in your logs afterwards. Yes, it is some 40×
the cost of `json_encode()` — but `json_encode()` gives you the wall of nested
braces this library exists to keep out, and both are rounding errors against the
network.

**`lazy()` is ~100× cheaper than `describe()`**, which is the number that
matters for a debug record your handler drops: building the wrapper parses
nothing, so the work never happens.

Cost grows slightly faster than the clause count — 4.1 µs per clause at ten,
7.2 µs at two hundred and fifty — which is the canonicaliser's sort, not an
accident. It is watched because a quadratic in there would be invisible on a
fixture and painful on a real dashboard query.

There is deliberately **no timing gate in CI**. Wall-clock on a shared runner is
noise, and a threshold tight enough to catch a real regression would fail on a
busy afternoon. This reports; CI guards behaviour.
