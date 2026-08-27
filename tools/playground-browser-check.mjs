/*
 * Drive the playground in a real browser and assert what it renders.
 *
 *     make playground-check
 *
 * It serves the *built site*, because the playground is a page of it now: the
 * markup comes from overrides/playground.html through MkDocs, so a check against
 * the source directory would test something nobody visits. `make playground-check`
 * builds the site first.
 *
 * Needs node, playwright and a Chromium:
 *
 *     npm install -g playwright && npx playwright install chromium
 *
 * or point PLAYWRIGHT_EXECUTABLE at a Chromium you already have.
 *
 * Deliberately not part of CI. The guard that must be automatic is
 * tests/PlaygroundTest.php, which proves the shipped bundle *is* the library
 * using nothing but PHP. This script proves the other half — that the page
 * wires it up correctly — and that half needs 2.8 MB of wasm and a browser, so
 * it stays a thing you run when you touch the page.
 *
 * What it asserts, in order:
 *   - the page opens on a precomputed preset and downloads no wasm at all
 *   - all sixteen presets render the digest committed for them
 *   - editing the query boots PHP and produces the hash the CLI produces
 *   - changing an option changes the fingerprint
 *   - pinning, then editing, reports whether the fingerprint moved
 *   - a permalink restores the query and runs it
 *   - an unparseable query surfaces the library's own message
 */
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// require(), not import: ESM resolution ignores NODE_PATH, which is the only
// way to reach a globally installed playwright without adding a package.json
// to a PHP repository.
const { chromium } = createRequire(import.meta.url)('playwright');

const ROOT = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const PORT = process.env.PLAYGROUND_PORT ?? '8901';
const BASE = `http://127.0.0.1:${PORT}`;
// The query typed into the page below, and the CLI's answer for it. Asked
// rather than pinned: a literal here rotted through the v0.6.0 prefix bump and
// nothing noticed, because this script is not in CI. Now the claim it makes —
// the browser agrees with the CLI — is the thing it actually tests.
const TYPED = { query: { term: { service: 'api' } }, size: 50 };
const TYPED_INDEX = 'logs-2026.08.13';
const CLI_HASH = execFileSync(
    'php',
    [path.join(ROOT, 'bin/os-query-digest'), '--hash', `--index=${TYPED_INDEX}`],
    { input: JSON.stringify(TYPED), encoding: 'utf8' },
).trim();

const data = JSON.parse(fs.readFileSync(path.join(ROOT, 'playground/data/presets.json'), 'utf8'));
const failures = [];

const check = (name, actual, expected) => {
    if (actual === expected) {
        console.log('  ok   ' + name);

        return;
    }
    console.log(`  FAIL ${name}\n         expected ${expected}\n         actual   ${actual}`);
    failures.push(name);
};

const SITE = path.join(ROOT, 'site');
if (!fs.existsSync(path.join(SITE, 'playground/index.html'))) {
    console.error('No built site. Run: make docs-build');
    process.exit(2);
}

const server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', SITE], { stdio: 'ignore' });
const stop = () => { try { server.kill(); } catch { /* already gone */ } };
process.on('exit', stop);

await new Promise((resolve) => setTimeout(resolve, 700));

const browser = await chromium.launch(
    process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {},
);
const context = await browser.newContext();

/*
 * Material decides what to intercept from sitemap.xml, whose URLs are absolute
 * and come from site_url — so on any origin but the published one, every link is
 * an ordinary page load and navigation.instant is inert. Served from here, the
 * one thing this check exists to exercise would silently not happen.
 *
 * So the sitemap is rewritten to this origin on the way to the page. Nothing
 * else is touched, and if a future Material asks for sitemap.xml.gz instead, the
 * marker assertion below goes red rather than quietly passing.
 */
const sitemap = fs.readFileSync(path.join(ROOT, 'site/sitemap.xml'), 'utf8');
const published = sitemap.match(/<loc>(.*?)<\/loc>/)[1];
await context.route('**/sitemap.xml', (route) => route.fulfill({
    contentType: 'application/xml',
    body: sitemap.replaceAll(published, BASE + '/'),
}));
const page = await context.newPage();

let wasmBytes = 0;
page.on('response', async (response) => {
    if (!response.url().endsWith('.wasm')) {
        return;
    }
    try {
        wasmBytes += (await response.request().sizes()).responseBodySize ?? 0;
    } catch { /* a response that never finished has no sizes */ }
});
page.on('pageerror', (error) => {
    console.log('  page error: ' + String(error.message).slice(0, 200));
    failures.push('page error');
});

const settle = () => page.waitForTimeout(2500);
const untilPhp = () => page.waitForFunction(
    'document.getElementById("pg-engine").textContent.startsWith("php ")',
    null,
    { timeout: 120000 },
);

await page.goto(BASE + '/playground/', { waitUntil: 'load' });
await page.waitForFunction('document.getElementById("pg-hash").textContent !== ""');

console.log('\n== opens precomputed, nothing downloaded');
check('first preset hash', await page.textContent('#pg-hash'), data.presets[0].digest.hash);
check('engine', (await page.textContent('#pg-engine')).trim(), 'precomputed');
check('wasm bytes', wasmBytes, 0);

console.log('\n== every preset renders its committed digest');
for (const [index, preset] of data.presets.entries()) {
    await page.click(`#pg-presets button[data-index="${index}"]`);
    await page.waitForFunction(
        (expected) => document.getElementById('pg-hash').textContent === expected,
        preset.digest.hash,
        { timeout: 5000 },
    ).catch(() => { /* the assertion below reports it */ });
    check(preset.id, await page.textContent('#pg-hash'), preset.digest.hash);
}
check('still no wasm', wasmBytes, 0);

console.log('\n== editing boots PHP and agrees with the CLI');
await page.click('#pg-presets button[data-index="0"]');
await page.fill('#pg-body', JSON.stringify(TYPED, null, 2));
await page.fill('#pg-index', TYPED_INDEX);
await untilPhp();
check('hash', await page.textContent('#pg-hash'), CLI_HASH);
console.log('  ' + (await page.textContent('#pg-engine')).trim()
    + ' · ' + (await page.textContent('#pg-engine-detail')).trim()
    + ' · wasm ' + (wasmBytes / 1048576).toFixed(2) + ' MB');

console.log('\n== an option moves the fingerprint');
await page.click('#pg-options-box summary');
await page.click('#pg-levels input[value="structural"]');
await settle();
const structural = await page.textContent('#pg-hash');
check('structural differs from values', structural !== CLI_HASH, true);

console.log('\n== omitting the values line takes the row with it');
await page.click('#pg-levels input[value="values"]');
await settle();
const withValues = await page.textContent('#pg-hash');
await page.click('#pg-omitText');
await settle();
// The record loses the field, so the row goes rather than showing a blank one.
check('text row hidden', await page.isVisible('#pg-text'), false);
check('its label too', await page.isVisible('#pg-text-label'), false);
check('sig still shown', (await page.textContent('#pg-sig')).length > 0, true);
// What is emitted must not change what the shape is called.
check('hash unmoved', await page.textContent('#pg-hash'), withValues);
await page.click('#pg-omitText');
await settle();
check('and it comes back', await page.isVisible('#pg-text'), true);

console.log('\n== pin, then edit, reports whether it moved');
await page.click('#pg-pin');
await page.fill('#pg-body', JSON.stringify({ query: { term: { service: 'worker' } }, size: 50 }, null, 2));
await settle();
check('comparison shown', await page.isVisible('#pg-compare'), true);
// Under `structural` the literal is erased, so two services are one shape.
check('same shape under structural', (await page.textContent('#pg-compare-verdict')).includes('Same fingerprint'), true);

console.log('\n== a permalink restores the query and runs it');
const link = await page.evaluate('location.href');
const expected = await page.textContent('#pg-hash');
const shared = await context.newPage();
await shared.goto(link, { waitUntil: 'load' });
await shared.waitForFunction(
    'document.getElementById("pg-engine").textContent.startsWith("php ")',
    null,
    { timeout: 120000 },
);
check('permalink hash', await shared.textContent('#pg-hash'), expected);
// navigation.tracking rewrites the hash to the heading in view as you scroll,
// which would eat the permalink. The page hides the table of contents, so there
// are no anchors to track — this is the test of that reasoning.
await shared.mouse.wheel(0, 1200);
await shared.waitForTimeout(600);
check('scrolling left the permalink alone', (await shared.evaluate('location.hash')).startsWith('#b='), true);

console.log('\n== reached by an internal link, not a reload');
// The one failure mode the site brings with it: navigation.instant swaps the
// document without re-evaluating a module that has already been evaluated, so a
// playground that boots at import time is inert when it is arrived at rather
// than loaded. It boots from document$ instead, and this is the test of that.
const walked = await context.newPage();
await walked.goto(BASE + '/', { waitUntil: 'load' });
// The marker proves the navigation was the instant kind. Without it this test
// would pass on a full page load, which is the case that never broke.
await walked.evaluate('window.__notReloaded = true');
await walked.click('a.announce-playground');
await walked.waitForFunction(
    'document.getElementById("pg-hash") !== null && document.getElementById("pg-hash").textContent !== ""',
    null,
    { timeout: 15000 },
).catch(() => { /* reported below */ });
check('instant navigation left a live form', await walked.textContent('#pg-hash'), data.presets[0].digest.hash);
check('and the URL is the page', new URL(await walked.evaluate('location.href')).pathname, '/playground/');
check('and the document was never reloaded', await walked.evaluate('window.__notReloaded === true'), true);
await walked.close();

console.log('\n== an unparseable query is reported');
await page.fill('#pg-body', '{not json');
await settle();
check('error visible', await page.isVisible('#pg-error'), true);
check(
    'the library said it',
    (await page.textContent('#pg-error')).includes('could not be decoded as JSON'),
    true,
);

await browser.close();
stop();

console.log(failures.length === 0
    ? '\nALL CHECKS PASSED'
    : `\n${failures.length} FAILURE(S): ${failures.join(', ')}`);
process.exit(failures.length === 0 ? 0 : 1);
