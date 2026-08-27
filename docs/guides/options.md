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
