# Options

```php
use MrDlef\OsQueryDigest\{Formatter, Normalization, Options};
use MrDlef\OsQueryDigest\IndexNormalizer;

$formatter = Formatter::create(
    Options::create()
        ->withNormalization(Normalization::structural())
        ->withMaxValues(5)          // status:(500 or 502 or 503 or +8)
        ->withMaxClauses(12)        // a and b and … +4 more
        ->withMaxLength(512)        // hard cap on the line, never on the hash
        ->withIndexNormalizer(IndexNormalizer::datePatterns())
        ->withRedactor(fn ($field, $value) => $field === 'email' ? '<redacted>' : $value)
        ->withAggNames(false)
        ->withText(true)            // false: emit idx/sig/hash only
        ->withHashLength(12)
);
```

The same options as a plain array — for a YAML file, a framework config block,
or a CLI flag:

```php
$formatter = Formatter::create(Options::fromArray([
    'normalization' => 'structural',
    'maxValues'     => 5,
    'aggNames'      => true,
    'text'          => false,
]));
```

Unknown keys and wrong types throw `InvalidOptionException` instead of being
ignored: an option that silently does nothing is the bug you find months later,
in a dashboard that was never grouped the way the config claimed. Types are
taken as JSON gives them — `"5"` is rejected, because a front end that guesses
at `"5"` also accepts `"five"`. `redactor` has no array form; a callable cannot
be expressed there.

## The level, and the question you are asking

`values()`, the default, erases literals and keeps the rest — including
pagination and how many values a `terms` clause holds. That is what you want
when the question is latency, and the wrong grouping when it is volume:
`structural()` is the setting that puts page 1 and page 3 of one search on one
row. [Which level answers which
question](../explanation/how-it-works.md#which-level-answers-which-question)
compares the two on the same search.

## `withText(false)`, and what it does not promise

It removes the readable line from the digest — never rendered, so no accessor on
the object can hand out a value, and `toArray()` emits `idx` / `sig` / `hash`.
[When the values may not leave the
building](logging.md#when-the-values-may-not-leave-the-building) is the case it
exists for.

It is not, on its own, a guarantee that no literal is emitted.
`Normalization::none()` makes the signature *equal* the readable line, values
included, so the pair that emits none is `withText(false)` with any normalization
above `none` — which is the default, and the only combination worth calling
value-free.

## An index name only you can read

`datePatterns()` collapses what any cluster does — dates, and standalone numeric
segments, which covers rolling indices and, pleasantly, multi-tenant numeric
prefixes:

<!-- verified: options-index-shipped -->
```
tenant_0178_members  →  tenant_*_members
logs-2026.08.13  →  logs-*
```

What it cannot collapse is a suffix whose meaning is yours: a **content-versioned**
index, where the physical name carries a hash of the mapping and the alias moves
over it on reindex. Left alone, every mapping change mints a fresh fingerprint
for every query shape, and every dashboard built on the hash resets on the next
deploy — the thing the normalizer exists to prevent.

```php
use MrDlef\OsQueryDigest\IndexNormalizer;

Options::create()->withIndexNormalizer(IndexNormalizer::custom(
    fn (string $index): string => preg_replace('/_[0-9a-f]{32}$/', '', $index),
));
```

<!-- verified: options-index-custom -->
```
tenant_0178_members_4f171971a955af948fae1c7a964c49b8  →  tenant_*_members
tenant_0179_members_0000000000000000000000000000ffff  →  tenant_*_members
```

**Your rule runs first, then the shipped one.** The hook is for stripping what
this library cannot know is meaningless; dates and numeric segments are then
collapsed exactly as always. So the example lands on `tenant_*_members`, not on
`tenant_0178_members` — you do not reimplement what already works.

Three things it is worth knowing:

- **It is called once per name.** A request against `a,b` gets two calls, and the
  deduplicating and sorting of a comma-separated list stays where it is. A rule
  may return `''` to drop one name from the list.
- **It is not trusted to return a string.** Anything non-scalar reads as an
  erased name rather than throwing: this runs in a logging path, where a
  `TypeError` out of a closure would cost the log line and not just the digest.
- **A rule changes fingerprints** — that is the point — so roll it out the way you
  would a prefix bump, not quietly on a Friday.

There is no shipped mode for this, deliberately. A rule that collapsed long hex
runs generally would have to decide what a hash *is* — how long, which alphabet —
and would move the fingerprint of every index name with hex anywhere in it. So
the shipped rules stop where the cluster's own conventions stop: the tenant
number is a number and collapses, and the suffix is left alone whatever it
happens to be made of.

<!-- verified: options-index-partial -->
```
tenant_0178_members_4f171971a955af948fae1c7a964c49b8  →  tenant_*_members_4f171971a955af948fae1c7a964c49b8
tenant_0179_members_9999999999999999999999999999aaaa  →  tenant_*_members_9999999999999999999999999999aaaa
```

Only you know where your suffix begins, which is why this is a callable rather
than a third mode.
