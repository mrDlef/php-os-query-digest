# OpenSearch versions

**Certified against OpenSearch 2.19.6 and 3.8.0.** Not inferred from the
specification — every query type is sent to a real node of each, and
`resources/versions.json` records what came back:

|          |                                              |
|----------|----------------------------------------------|
| 53 types | probed against live clusters                 |
| 52       | accepted by 2.19.6                           |
| 53       | accepted by 3.8.0                            |
| 6        | cannot be probed, each with a written reason |

`combined_fields` is the one difference the specification could not have told
you: it is listed as a query type, 3.x accepts it, and 2.19.6 answers `unknown
query [combined_fields]`.

The six unprobed types are not a gap left quiet — `resources/probes.json` says
why each one cannot have a probe. `neural` needs a deployed model id, `sltr`
needs a plugin absent from the official image, `agentic` needs a configured
agent, `hybrid` needs a search pipeline, `template` lives behind a different
endpoint, and `type` was removed with mapping types. Certifying those would test
someone's cluster configuration, not this library.

```bash
make certify       # boot 2.x and 3.x, re-probe, rewrite resources/versions.json
make integration   # replay the committed matrix against live nodes
```

`make certify` refuses to record a probe that fails for any reason other than
"unknown query": our own malformed DSL must never be filed as a version
difference. A scheduled workflow replays the matrix weekly, so a version that
changes its mind about a query type surfaces on its own instead of during
someone's release.

The spec snapshot is still there and still useful for a different question —
*has OpenSearch grown a type we have never heard of?*

```bash
make spec                    # refresh from the spec, then run the coverage test
make spec SPEC_REF=e027edc   # or pin a commit for a reproducible snapshot
```

If OpenSearch has added a query type, the test fails until it is classified in
`coverage.json` — and `make certify` then fails until it is probed or explained.

The spec is published as YAML and PHP has no core YAML parser, so
`tools/refresh-spec.php` uses `symfony/yaml` — a **require-dev** dependency, so
it is never installed by anything that depends on this library. The snapshot
itself is committed as JSON: the test suite reads it with `json_decode()`,
offline, with no dependency at all.
