# Teach it a query type it does not know

"In principle" means *by this library*. If you run the Learning-to-Rank plugin,
`sltr` is not unreadable to **you** — and a private plugin's query type was
never going to be in anyone's specification. Register a renderer for it:

```php
use MrDlef\OsQueryDigest\Extension\{ClauseRenderer, RenderedClause};

final class SltrRenderer implements ClauseRenderer
{
    public function render(array $body): ?RenderedClause
    {
        $model = $body['model'] ?? null;
        if (!is_string($model)) {
            return null;    // not a shape I know — leave it opaque
        }

        return RenderedClause::on('_score', 'sltr')->withParam('model', $model);
    }
}

$formatter = Formatter::create(
    Options::create()->withClauseRenderer('sltr', new SltrRenderer()),
);
```

```
before   q=(sltr(?))
after    q=(_score:sltr(model=ltr_model_v3))
signature q=(_score:sltr(model=?))
```

A `RenderedClause` carries a field, a label and keyed parameters — the same
three things the vector and geo clauses reduce to, rendered the same way. The
knobs are part of the shape, their values are not, so two searches through the
same model share a fingerprint. Returning `null` is the honest answer for a body
you do not recognise: `type(?)` is true, and a guess would be a fingerprint built
on a misreading.

Three properties make this safe to hand out:

- **A renderer cannot reach a type the library models.** The hook sits in the
  parser's default branch, so every native type has already returned before it
  runs. That is structural, not a list that could drift — registering one for
  `term` does nothing at all.
- **The hash version is marked.** Register anything and fingerprints become
  `q5x:` instead of `q5:`. Your rules are no longer this library's alone, and
  that is exactly what a prefix exists to make visible. The twelve hex
  characters are untouched, so `q5:abc…` and `q5x:abc…` are still recognisably
  the same query.
- **`explain()` names it.** A digest that depended on someone's plugin code
  reports `extension_rendered`, rather than looking like one this library
  produced on its own.

Extensions receive the raw clause body and return a value object — never a node
of the internal tree. An extension point built on that tree would freeze it, and
the tree is exactly what changes every time a query type is promoted.

All **65 aggregation types** are rendered; 12 of them get a shape tuned for
readability (`terms(host,10)`, `date_histogram(@ts,1h)`, `p95(latency)`), the
rest render generically as `type(field)`.

Top-level sections that are not modelled (`highlight`, `collapse`, `rescore`, …)
are listed in the notes.
