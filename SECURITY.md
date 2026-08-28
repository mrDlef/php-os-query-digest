# Security

## Reporting a vulnerability

**[Open a private advisory](https://github.com/mrDlef/php-os-query-digest/security/advisories/new)**
through GitHub. It creates a private thread with the maintainer; nothing is
public until there is something to say.

Please do not open a public issue for a suspected vulnerability.

Expect a first reply within a week. If a fix is warranted it ships as a patch
release with a published advisory, credited to you unless you would rather not
be.

## Supported versions

| version            | supported |
|--------------------|-----------|
| latest `0.x` minor | yes       |
| everything older   | no        |

This library is pre-1.0 and moves quickly — six releases in the first three
days. Backporting to an older minor would mean maintaining a branch nobody is
running. Upgrade paths are documented in [the changelog](CHANGELOG.md), and no
release so far has needed more than a `composer update`, except the two that
moved the fingerprint prefix.

## What this library does, and what it therefore cannot do

Most of the answer to "is this risky?" is in how little it touches.

**It reads a data structure and returns strings.** At runtime it performs no
file access, no network calls, and no process execution — there is not a single
`file_get_contents`, `curl_*`, `exec` or `include` in the parsing, rendering, or
hashing path. The one exception is the CLI, which reads the file you name on the
command line, because that is what you asked it to do.

**Its runtime dependencies are `php` and `ext-json`.** Nothing else is
installed, so nothing else can be the problem. Monolog is a `suggest`, used only
if you wire the processor up yourself.

**It never talks to OpenSearch.** It describes a request; it does not send one.
No credentials, no cluster URL, no client library.

## Where the risk actually is

**The input can be attacker-influenced.** A search body often contains what a
user typed into a search box, and in some applications the *structure* is built
from user input too. So treat the request array as untrusted, and know what the
library does with it:

- **Depth.** Parsing is recursive. Handed a pre-decoded array, it has been
  tested to 20 000 levels of nested `bool` clauses without failing — that costs
  about 50 ms. Handed a JSON *string*, PHP's own `json_decode` refuses anything
  past 512 levels (`Maximum stack depth exceeded`) and the library reports it as
  an `InvalidQueryException` long before recursion is a concern. If you decode
  the body yourself and pass an array, the 512-level ceiling is yours to keep.
- **Size.** Cost is roughly linear in clause count — about 4 µs per clause at
  ten, 7 µs at two hundred and fifty. There is no quadratic. `make bench`
  measures it. A body large enough to matter would have been expensive to
  transport before it reached this library.
- **Failure mode.** A body the parser cannot make sense of renders as
  `type(?)` rather than throwing. `describe()` throws only on input that is
  neither an array nor decodable JSON.

**Values reach your logs.** This is the one that has bitten people with other
tools, so it is worth being blunt: at the default `values` normalisation the
rendered *line* keeps literal values, because that is what makes it pasteable
into Dashboards. If your queries carry personal data — an email in a `term`, a
token in a `match` — that data lands wherever the line lands.

Two things address it, both documented in the README:

- `withRedactor()` lets you rewrite or drop a value by field name before it is
  rendered;
- the **signature**, the **hash** and the **kind** never contain values at all,
  so logging only those is safe by construction.

**A custom `ClauseRenderer` runs your code on that input.** If you register one,
what it returns is rendered. It receives the raw clause body; treat it with the
same care as any other handler of untrusted input.

## Out of scope

The maintainer tooling under `tools/` — the certification harness, the
benchmark, the playground builder — is not shipped in the Composer package
(`git archive` contains none of it) and is only ever run by someone with a
checkout, Docker, and a network. Findings there are bugs, not vulnerabilities.
