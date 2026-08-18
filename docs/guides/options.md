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
]));
```

Unknown keys and wrong types throw `InvalidOptionException` instead of being
ignored: an option that silently does nothing is the bug you find months later,
in a dashboard that was never grouped the way the config claimed. Types are
taken as JSON gives them — `"5"` is rejected, because a front end that guesses
at `"5"` also accepts `"five"`. `redactor` has no array form; a callable cannot
be expressed there.
