# What counts as public

The hash is one contract; the classes are the other. Every class in `src/` is
marked either `@api` or `@internal`, and `ApiBoundaryTest` fails the suite if
one is marked neither, marked both, or if a public method hands back an
internal type — because a type you can reach from a public signature is public
whatever its annotation claims.

**The public surface is fourteen classes:**

|               |                                                                       |
|---------------|-----------------------------------------------------------------------|
| entry point   | `Formatter`                                                           |
| results       | `Digest`, `LazyDigest`, `Explain\Explanation`, `Explain\Rule`         |
| configuration | `Options`, `Normalization`, `IndexNormalizer`                         |
| extension     | `Extension\ClauseRenderer`, `Extension\RenderedClause`                |
| failures      | `Exception\InvalidQueryException`, `Exception\InvalidOptionException` |
| Monolog       | `Monolog\DigestProcessor`, `Monolog\SafeDigest`                       |

Everything else — the parser, the tree, the renderers, the canonicaliser, the
hasher, the CLI command — is `@internal`. Not out of secrecy: those are exactly
the classes that change whenever a query type is promoted. Freezing them would
mean every improvement to the rendering is a major release, and the library
would stop improving. Depend on them and an ordinary patch may move under you.

This matters because it is the half of a `1.0.0` that cannot be walked back:
widening a public surface later is free, narrowing one is not.
