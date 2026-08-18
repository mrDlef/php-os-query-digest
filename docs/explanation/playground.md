# How the playground works

**[mrdlef.github.io/php-os-query-digest/playground/](https://mrdlef.github.io/php-os-query-digest/playground/)**
runs this library on your query, in your browser, with no server involved: PHP
itself is compiled to WebAssembly. Your query is never sent anywhere.

It is published from a release tag, never from `main` — the page prints
fingerprints, and one that no installable version produces would be worse than
no page at all.

Locally:

```bash
make playground        # regenerates the data, serves it on :8080
```

Two engines render the same thing. The sixteen examples are digested at build
time by `tools/build-playground.php`, so the page is useful and instant and
downloads nothing; the moment you change the query or an option, it fetches a
PHP 8.3 (about 2.8 MB, once) and runs the real library — boot measured at ~300 ms,
then well under a millisecond per query. Nothing is ever *approximated* by the
precomputed path: it is the same library, run earlier.

What makes it worth more than a formatter: pin a query as a reference, then edit
it. The page tells you whether the fingerprint moved and which normalisation
rule made the difference — which is the question you actually have when two
queries you thought were different share a hash. Every state is a permalink, so
a bug report can be a link.

The page ships two generated files, both committed so both are reviewable:
`playground/data/library.php.txt` (the library as one file, for a runtime with
no autoloader) and `playground/data/presets.json`. `tests/PlaygroundTest.php`
executes that bundle with real PHP against the golden files, so "the browser
runs the same library as `composer require` does" is guarded by CI without
needing a browser or wasm. The page itself is checked by
`make playground-check`, which drives it in a real Chromium.
