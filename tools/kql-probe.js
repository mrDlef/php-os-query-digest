/*
 * Parses DQL with OpenSearch Dashboards' own parser and reports the fields the
 * resulting query actually targets.
 *
 * Runs inside the Dashboards image, because that is the only place the parser
 * exists: `@osd/es-query` is not published to npm, and Dashboards converts DQL
 * to DSL in the browser, so there is no endpoint to call.
 *
 *   docker run --rm \
 *     -v "$PWD/tools/kql-probe.js:/kql-probe.js:ro" -v "$IN:/cases.json:ro" \
 *     --entrypoint /usr/share/opensearch-dashboards/node/bin/node \
 *     opensearchproject/opensearch-dashboards:2.19.0 /kql-probe.js /cases.json
 *
 * Reads `[{ id, kql }]`, writes `[{ id, fields, error }]` on stdout.
 */

'use strict';

const fs = require('fs');

const KUERY = '/usr/share/opensearch-dashboards/src/plugins/data/common/opensearch_query/kuery';
const { fromKueryExpression, toOpenSearchQuery } = require(KUERY);

// The leaf clauses that name a field, and where the name sits in each.
const KEYED = ['match', 'match_phrase', 'match_phrase_prefix', 'term', 'terms', 'range', 'prefix', 'wildcard', 'regexp'];

function fieldsOf(node, found) {
  if (Array.isArray(node)) {
    node.forEach((child) => fieldsOf(child, found));
    return found;
  }
  if (node === null || typeof node !== 'object') {
    return found;
  }

  for (const [key, value] of Object.entries(node)) {
    if (key === 'exists' && value && typeof value.field === 'string') {
      found.add(value.field);
      continue;
    }
    if (KEYED.includes(key) && value && typeof value === 'object' && !Array.isArray(value)) {
      Object.keys(value).forEach((field) => found.add(field));
      continue;
    }
    fieldsOf(value, found);
  }

  return found;
}

const cases = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const results = cases.map((probe) => {
  try {
    const dsl = toOpenSearchQuery(fromKueryExpression(probe.kql));

    return { id: probe.id, fields: [...fieldsOf(dsl, new Set())].sort(), error: null };
  } catch (e) {
    return { id: probe.id, fields: [], error: String(e && e.message ? e.message : e) };
  }
});

process.stdout.write(JSON.stringify(results));
