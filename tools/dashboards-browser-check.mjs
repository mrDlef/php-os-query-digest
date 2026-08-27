/*
 * Open the imported dashboard in a real browser and assert it draws.
 *
 *     make dashboards-check
 *     node tools/dashboards-browser-check.mjs http://localhost:5602 [--shot path.png]
 *
 * The import API answers a narrower question — that the file is a set of saved
 * objects this version understands. It stores a Vega specification as an opaque
 * string, so a panel written for the other major imports just as cleanly and
 * then refuses to render. Everything below was a real defect in the pack at some
 * point, and none of it was visible without a browser:
 *
 *   - a panel with no `version` makes the whole dashboard app throw
 *   - a search source with no `indexRefName` fails every classic panel
 *   - Dashboards 3.x needs the index pattern's field list; 2.x fetches it
 *   - `%context%` and a body query are mutually exclusive
 *   - `%dashboard_context-*%` clauses are bare strings, not objects
 *   - `%timefilter%` with a positive shift moves the window *forward*
 *   - a nested aggregation value has to be computed, not addressed
 *
 * Needs node, playwright and a Chromium, like the playground check:
 *
 *     npm install -g playwright && npx playwright install chromium
 *
 * Not in CI: it needs two 1.3 GB images and a browser. `DashboardPackTest`
 * guards what can be guarded offline, and `UseCaseTest` runs the aggregations
 * against real clusters; this is the half that needs eyes, so it also writes the
 * screenshot the documentation shows.
 */
import { createRequire } from 'node:module';

const { chromium } = createRequire(import.meta.url)('playwright');

const BASE = process.argv[2] ?? 'http://localhost:5602';
const SHOT = process.argv.includes('--shot') ? process.argv[process.argv.indexOf('--shot') + 1] : null;

// The hour the scenario `tools/demo-index.php` indexes gets slow, which is the
// window where every panel has something to say.
const FROM = '2026-08-19T14:00:00.000Z';
const TO = '2026-08-19T15:00:00.000Z';

const PANELS = ['Where the time goes', 'p95 by shape over time', 'What regressed', 'Shapes the release added'];
const SHAPES = ['q5:63a1ca5c80b9', 'q5:fe168406e702', 'q5:e9794c1be608', 'q5:4dde138a2ad7'];

const failures = [];
const browser = await chromium.launch();

try {
    const page = await browser.newPage({ viewport: { width: 1680, height: 1050 } });
    const thrown = [];
    page.on('pageerror', (error) => thrown.push(error.message));

    const url = `${BASE}/app/dashboards#/view/os-query-digest-query-shapes`
        + `?_g=(time:(from:'${FROM}',to:'${TO}'))`;

    await page.goto(url, { waitUntil: 'load', timeout: 120000 });
    await page.waitForSelector('[data-test-subj="embeddablePanel"]', { timeout: 90000 });
    // Vega fetches its own data after the panel exists.
    await page.waitForTimeout(15000);

    const seen = await page.evaluate(() => [...document.querySelectorAll('[data-test-subj="embeddablePanel"]')]
        .map((panel) => ({
            title: (panel.querySelector('[data-test-subj="dashboardPanelTitle"]')?.textContent ?? '').trim(),
            text: panel.innerText,
            messages: [...panel.querySelectorAll('.vgaVis__messages, .euiCallOut--danger, [data-test-subj="visualization-error"]')]
                .map((message) => message.textContent.trim().slice(0, 200)),
            canvases: [...panel.querySelectorAll('canvas')].map((canvas) => canvas.width * canvas.height),
        })));

    if (seen.length !== PANELS.length) {
        failures.push(`${seen.length} panels on the dashboard, expected ${PANELS.length}`);
    }

    for (const [position, expected] of PANELS.entries()) {
        const panel = seen[position];
        if (!panel) {
            failures.push(`no panel where "${expected}" should be`);
            continue;
        }

        if (!panel.title.includes(expected)) {
            failures.push(`panel ${position + 1} is "${panel.title}", expected "${expected}"`);
        }

        for (const message of panel.messages) {
            failures.push(`"${expected}" reports: ${message}`);
        }
    }

    // The data has to have reached the panels, not merely the panels the page.
    const table = seen[0]?.text ?? '';
    for (const shape of SHAPES) {
        if (!table.includes(shape)) {
            failures.push(`"${PANELS[0]}" does not list ${shape}`);
        }
    }

    for (const position of [2, 3]) {
        const drawn = (seen[position]?.canvases ?? []).some((pixels) => pixels > 0);
        if (!drawn) {
            failures.push(`"${PANELS[position]}" drew no canvas`);
        }
    }

    for (const error of thrown) {
        failures.push(`the page threw: ${error}`);
    }

    if (SHOT) {
        await page.screenshot({ path: SHOT });
        console.log(`  screenshot  ${SHOT}`);
    }
} finally {
    await browser.close();
}

if (failures.length > 0) {
    console.error(`\n${BASE} — ${failures.length} problem(s):`);
    for (const failure of failures) {
        console.error(`  - ${failure}`);
    }
    process.exit(1);
}

console.log(`  ${BASE}  four panels, no messages, data in all of them.`);
