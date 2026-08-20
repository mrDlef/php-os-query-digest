# How the playground works

The [playground](../playground.md) runs this library on your query, in
your browser, with no server involved: PHP itself is compiled to WebAssembly.
Your query is never sent anywhere, and neither is anything else — the page loads
nothing from a third party.

It is published from a release tag, never from `main` — the page prints
fingerprints, and one that no installable version produces would be worse than
no page at all.

Locally:

```bash
make docs              # the site, playground included, on :8000
make playground-check  # drives the built page in a real Chromium
```

Two engines render the same thing. The sixteen examples are digested at build
time by `tools/build-playground.php`, so the page is useful and instant and
downloads nothing; the moment you change the query or an option, it fetches a
PHP 8.3 (about 3.1 MB, once) and runs the real library — boot measured at ~300 ms,
then well under a millisecond per query. Nothing is ever *approximated* by the
precomputed path: it is the same library, run earlier.

## The runtime is served from here, not a CDN

The wasm build of PHP comes from this site, beside the page. It is not committed
— 12.5 MB does not belong in the history of a library whose own package is
measured in kilobytes — so `tools/fetch-runtime.php` downloads it at build time
and checks every file against the SHA-256 hashes pinned in
`playground/runtime.lock.json`. A substituted artefact fails the deploy instead
of reaching a browser.

That check is the reason for the arrangement. A dynamic `import()` takes no
`integrity` attribute, so a runtime pulled from a CDN cannot be verified at all;
the usual workaround of importing a hash-checked blob URL does not apply here,
because this runtime resolves its wasm with `new URL(…, import.meta.url)` and a
blob URL breaks that. Fetching the files ourselves is what makes them checkable.

Only PHP 8.3 is fetched: the runtime names every build it can load but imports
them dynamically, and the page pins one version.

## A page of this site, which costs three things

The playground is `docs/playground.md`, and its markup is a template,
`overrides/playground.html`. It is not an application parked beside the
documentation any more, and the three things that cost are all consequences of
`navigation.instant` — the feature that swaps a page without reloading it.

**Its stylesheet and its module belong to the site, not to the page.** Instant
navigation replaces the parts of the document Material knows about; it does not
re-run the `<head>`, and it does not re-run the scripts at the end of the body.
A `<link>` or a `<script>` that only this page declared is therefore missing for
every reader who arrives by a link rather than a reload — the page renders
unstyled, or renders and does nothing, and the build is perfectly happy. So both
are declared in `mkdocs.yml`: the stylesheet is scoped to `.playground` and the
module is nine lines that import the real one when a document contains the
playground.

**The module boots on a signal, not on being loaded.** A module URL is evaluated
once per document, so the second arrival evaluates nothing. It subscribes to
Material's `document$`, which emits on load and on every instant navigation, and
its boot is idempotent — the flag lives on the root element, so a document
that has just been replaced boots again while the one it replaced cannot boot
twice. The PHP interpreter, once started, outlives the page: navigating away and
back does not cost 3.1 MB twice.

**Every asset it fetches is resolved against `import.meta.url`.** The document's
base changes under it on every client-side navigation; the module's own URL does
not.

Two smaller ones, for anyone editing it. Every id in the markup is prefixed
`pg-`, because the page shares a document with headings whose anchors are slugs —
`body`, `text`, `notes` and `status` are all slugs waiting to happen. And the
page hides the navigation sidebar and the table of contents, which is what gives
two panes the same width they had when the playground stood alone. Hiding the
table of contents has a second effect worth knowing: `navigation.tracking`
rewrites the URL fragment to the heading in view as you scroll, which would eat
the permalinks this page writes — with no anchors to track, it leaves them alone.

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
`make playground-check`, which drives the built site in a real Chromium — the
built site, because that is where the page is assembled.

That check rewrites one thing on its way to the browser: `sitemap.xml`. Material
decides which links to intercept from it, and its URLs are absolute, built from
`site_url` — so on any origin but the published one, every link is an ordinary
page load and instant navigation never happens. Served from `127.0.0.1`, the
failure mode the check exists to catch could not occur.
