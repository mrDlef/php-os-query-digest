/*
 * The playground, in one module and no dependencies.
 *
 * Two engines render the same thing:
 *
 *   precomputed  the presets, digested at build time by tools/build-playground.php
 *   php          the real library, in a PHP compiled to WebAssembly
 *
 * The page opens on the first engine so it is useful and instant, and switches
 * to the second the moment you change anything — which is the only moment the
 * 3.1 MB runtime is worth its download. Nothing is ever *approximated* by the
 * precomputed path: it is the same library, run earlier.
 */
// Served from this site, next to this file. Fetched at build time by
// tools/fetch-runtime.php and checked against playground/runtime.lock.json, so
// the page contacts nothing but the origin it was loaded from.
const RUNTIME_URL = './runtime/PhpWeb.mjs';
const PHP_VERSION = '8.3';
const LIBRARY_PATH = '/library.php';
const REQUEST_PATH = '/request.json';

/*
 * Constant on purpose: the query never reaches PHP through this string, it is
 * written to a file and read back. Interpolating a user's query into source
 * would be one escaping bug away from a syntax error on every odd quote.
 *
 * require_once, not require: the runtime is reused between runs, and a second
 * require would redeclare every class.
 */
const CODE = `<?php
require_once '${LIBRARY_PATH}';

$payload = json_decode(file_get_contents('${REQUEST_PATH}'), true);

try {
    $formatter = \\MrDlef\\OsQueryDigest\\Formatter::create(
        \\MrDlef\\OsQueryDigest\\Options::fromArray($payload['options'])
    );
    $explanation = $formatter->explain($payload['body'], $payload['index']);
    echo json_encode([
        'ok' => true,
        'php' => PHP_VERSION,
        'digest' => $explanation->digest()->toArray(),
        'rules' => array_map(
            static function (\\MrDlef\\OsQueryDigest\\Explain\\Rule $rule): array {
                return $rule->toArray();
            },
            $explanation->rules()
        ),
    ]);
} catch (\\Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
`;

const el = (id) => document.getElementById(id);
const ui = {
    presets: el('presets'),
    index: el('index'),
    body: el('body'),
    levels: el('levels'),
    maxClauses: el('maxClauses'),
    maxValues: el('maxValues'),
    maxLength: el('maxLength'),
    aggNames: el('aggNames'),
    rawIndex: el('rawIndex'),
    optionsBox: el('options-box'),
    optionsSummary: el('options-summary'),
    error: el('error'),
    idx: el('idx'),
    text: el('text'),
    sig: el('sig'),
    hash: el('hash'),
    notesBox: el('notes-box'),
    notes: el('notes'),
    rules: el('rules'),
    rulesCount: el('rules-count'),
    rulesEmpty: el('rules-empty'),
    pin: el('pin'),
    unpin: el('unpin'),
    copyLink: el('copy-link'),
    compare: el('compare'),
    compareVerdict: el('compare-verdict'),
    compareRules: el('compare-rules'),
    status: el('status'),
    engine: el('engine'),
    engineDetail: el('engine-detail'),
    boot: el('boot'),
};

const state = {
    meta: null,
    presets: [],
    selected: 0,
    pinned: null,
    php: null,
    booting: null,
    bootMs: null,
    lastMs: null,
};

/* ---------------------------------------------------------------- base64url */

function encode64(text) {
    const bytes = new TextEncoder().encode(text);
    let binary = '';
    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function decode64(text) {
    const padded = text.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return new TextDecoder().decode(bytes);
}

/* -------------------------------------------------------------------- form */

/** The option spec, holding only what differs from the library's defaults. */
function optionsSpec() {
    const defaults = state.meta.defaults;
    const spec = {};

    const level = ui.levels.querySelector('input:checked');
    if (level && level.value !== defaults.normalization) {
        spec.normalization = level.value;
    }

    for (const key of ['maxClauses', 'maxValues', 'maxLength']) {
        const raw = ui[key].value.trim();
        if (raw === '') {
            continue;
        }
        const value = raw === 'none' ? null : Number.parseInt(raw, 10);
        if (raw !== 'none' && !Number.isInteger(value)) {
            continue;
        }
        if (value !== defaults[key]) {
            spec[key] = value;
        }
    }

    if (ui.aggNames.checked !== defaults.aggNames) {
        spec.aggNames = ui.aggNames.checked;
    }
    if (ui.rawIndex.checked) {
        spec.indexNormalizer = state.meta.indexModes[1];
    }

    return spec;
}

function applyOptions(spec) {
    const defaults = state.meta.defaults;
    const level = spec.normalization ?? defaults.normalization;

    for (const input of ui.levels.querySelectorAll('input')) {
        input.checked = input.value === level;
    }
    for (const key of ['maxClauses', 'maxValues', 'maxLength']) {
        const value = key in spec ? spec[key] : defaults[key];
        ui[key].value = value === null ? 'none' : String(value);
    }
    ui.aggNames.checked = (spec.aggNames ?? defaults.aggNames) === true;
    ui.rawIndex.checked = spec.indexNormalizer === state.meta.indexModes[1];
}

function describeOptions(spec) {
    const parts = Object.keys(spec).sort();

    return parts.length === 0 ? '(defaults)' : '(' + parts.length + ' changed)';
}

/**
 * Whether the form still holds exactly what a preset holds — the only case in
 * which the precomputed answer is the right answer.
 */
function matchesPreset(preset) {
    if (!preset) {
        return false;
    }

    const spec = optionsSpec();
    const theirs = preset.options ?? {};

    return ui.body.value.trim() === preset.body.trim()
        && ui.index.value.trim() === (preset.index ?? '')
        && JSON.stringify(sorted(spec)) === JSON.stringify(sorted(theirs));
}

function sorted(object) {
    const out = {};
    for (const key of Object.keys(object).sort()) {
        out[key] = object[key];
    }

    return out;
}

/* ------------------------------------------------------------------ render */

function render(result) {
    const failed = result.ok === false;

    ui.error.hidden = !failed;
    ui.error.textContent = failed ? result.error : '';

    const digest = result.digest ?? {};
    ui.idx.textContent = digest.idx ?? '';
    ui.text.textContent = digest.q ?? '';
    ui.sig.textContent = digest.sig ?? '';
    ui.hash.textContent = digest.hash ?? '';

    const notes = digest.notes ?? [];
    ui.notesBox.hidden = notes.length === 0;
    ui.notes.replaceChildren(...notes.map((note) => {
        const item = document.createElement('li');
        item.textContent = note;

        return item;
    }));

    const rules = result.rules ?? [];
    ui.rulesCount.textContent = rules.length === 0 ? '' : '(' + rules.length + ')';
    ui.rulesEmpty.hidden = failed || rules.length > 0;
    ui.rules.replaceChildren(...rules.map(ruleItem));

    ui.optionsSummary.textContent = describeOptions(optionsSpec());
    renderCompare(result);
}

function ruleItem(rule) {
    const item = document.createElement('li');

    const id = document.createElement('span');
    id.className = 'id';
    id.textContent = rule.rule
        + (rule.count > 1 ? ' ×' + rule.count : '')
        + (rule.on ? ' [' + rule.on.join(', ') + ']' : '');

    const why = document.createElement('span');
    why.className = 'why';
    why.textContent = rule.why ?? '';

    item.append(id, why);

    return item;
}

function renderCompare(result) {
    const pinned = state.pinned;
    if (!pinned || result.ok === false) {
        ui.compare.hidden = true;

        return;
    }

    ui.compare.hidden = false;

    const hash = result.digest?.hash ?? '';
    const same = hash === pinned.hash;
    ui.compareVerdict.replaceChildren();

    const verdict = document.createElement('span');
    verdict.className = same ? 'same' : '';
    verdict.textContent = same
        ? 'Same fingerprint: ' + hash
        : 'Different fingerprint: ' + pinned.hash + ' → ' + hash;
    ui.compareVerdict.append(verdict);

    const mine = (result.rules ?? []).map((rule) => rule.rule);
    const theirs = pinned.ruleIds;
    const gained = mine.filter((id) => !theirs.includes(id));
    const lost = theirs.filter((id) => !mine.includes(id));

    const lines = [];
    for (const id of gained) {
        lines.push(['+ ' + id, state.meta.rules[id] ?? '']);
    }
    for (const id of lost) {
        lines.push(['− ' + id, state.meta.rules[id] ?? '']);
    }
    if (lines.length === 0) {
        lines.push([same ? 'the same rules fired' : 'the same rules fired, on different values', '']);
    }

    ui.compareRules.replaceChildren(...lines.map(([label, why]) => {
        const item = document.createElement('li');
        const id = document.createElement('span');
        id.className = 'id';
        id.textContent = label;
        const explanation = document.createElement('span');
        explanation.className = 'why';
        explanation.textContent = why;
        item.append(id, explanation);

        return item;
    }));
}

function setEngine(name, detail, loading = false) {
    ui.engine.textContent = name;
    ui.engineDetail.textContent = detail;
    ui.status.dataset.state = loading ? 'loading' : 'idle';
    ui.boot.hidden = state.php !== null || loading;
}

/* --------------------------------------------------------------------- php */

async function ensurePhp() {
    if (state.php) {
        return state.php;
    }
    if (state.booting) {
        return state.booting;
    }

    setEngine('loading php', 'about 3.1 MB, once', true);

    state.booting = (async () => {
        const started = performance.now();
        const { PhpWeb } = await import(RUNTIME_URL);
        const php = new PhpWeb({ version: PHP_VERSION });

        php.addEventListener('output', (event) => {
            php._buffer += (event.detail ?? []).join('');
        });
        php.addEventListener('error', (event) => {
            php._errors += (event.detail ?? []).join('');
        });
        php._buffer = '';
        php._errors = '';

        await php.binary;

        // A file, not part of the script: php.run() wraps what it is handed in
        // a prologue, so declare(strict_types=1) could never be the first
        // statement there. A required file keeps its own, and the library must
        // run in strict mode — it is the library that way.
        const library = await (await fetch('./data/library.php.txt')).text();
        await php.writeFile(LIBRARY_PATH, library);

        state.bootMs = Math.round(performance.now() - started);
        state.php = php;

        return php;
    })();

    try {
        return await state.booting;
    } finally {
        state.booting = null;
    }
}

async function runInPhp() {
    let php;
    try {
        php = await ensurePhp();
    } catch (error) {
        setEngine('php unavailable', String(error?.message ?? error).slice(0, 120));

        return;
    }

    const payload = {
        body: ui.body.value,
        index: ui.index.value.trim() === '' ? null : ui.index.value.trim(),
        options: optionsSpec(),
    };

    php._buffer = '';
    php._errors = '';
    await php.writeFile(REQUEST_PATH, JSON.stringify(payload));

    const started = performance.now();
    await php.run(CODE);
    state.lastMs = Math.round(performance.now() - started);

    let result;
    try {
        result = JSON.parse(php._buffer);
    } catch {
        result = {
            ok: false,
            error: (php._errors || php._buffer || 'PHP produced no output').trim().slice(0, 500),
        };
    }

    render(result);
    setEngine(
        'php ' + (result.php ?? PHP_VERSION),
        'boot ' + state.bootMs + ' ms · this query ' + state.lastMs + ' ms',
    );
}

/* ------------------------------------------------------------------- flow */

let pending = null;

function schedule() {
    ui.optionsSummary.textContent = describeOptions(optionsSpec());

    const preset = state.presets[state.selected];
    if (matchesPreset(preset) && !state.php) {
        render({ ok: true, digest: preset.digest, rules: preset.rules });
        setEngine('precomputed', 'digested at build time — no PHP loaded yet');

        return;
    }

    window.clearTimeout(pending);
    pending = window.setTimeout(runInPhp, 350);
}

function selectPreset(index) {
    state.selected = index;

    const preset = state.presets[index];
    ui.index.value = preset.index ?? '';
    ui.body.value = preset.body;
    applyOptions(preset.options ?? {});

    for (const chip of ui.presets.children) {
        chip.setAttribute('aria-pressed', String(Number(chip.dataset.index) === index));
    }

    writeFragment();
    schedule();
}

function writeFragment() {
    const parts = ['b=' + encode64(ui.body.value)];
    if (ui.index.value.trim() !== '') {
        parts.push('i=' + encode64(ui.index.value.trim()));
    }
    const spec = optionsSpec();
    if (Object.keys(spec).length > 0) {
        parts.push('o=' + encode64(JSON.stringify(spec)));
    }

    history.replaceState(null, '', '#' + parts.join('&'));
}

function readFragment() {
    const fragment = location.hash.replace(/^#/, '');
    if (fragment === '') {
        return false;
    }

    const params = new URLSearchParams(fragment);
    const body = params.get('b');
    if (body === null) {
        return false;
    }

    try {
        ui.body.value = decode64(body);
        ui.index.value = params.get('i') === null ? '' : decode64(params.get('i'));
        applyOptions(params.get('o') === null ? {} : JSON.parse(decode64(params.get('o'))));
    } catch {
        return false;
    }

    return true;
}

/* ------------------------------------------------------------------- boot */

async function start() {
    const data = await (await fetch('./data/presets.json')).json();
    state.meta = data.meta;
    state.presets = data.presets;

    ui.levels.replaceChildren(...state.meta.levels.map((level) => {
        const label = document.createElement('label');
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'normalization';
        input.value = level;
        input.checked = level === state.meta.defaults.normalization;
        const text = document.createElement('span');
        text.textContent = level;
        label.append(input, text);

        return label;
    }));

    ui.presets.replaceChildren(...state.presets.map((preset, index) => {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.textContent = preset.label;
        chip.dataset.index = String(index);
        chip.setAttribute('aria-pressed', 'false');
        chip.addEventListener('click', () => selectPreset(index));

        return chip;
    }));

    for (const input of [ui.body, ui.index, ui.maxClauses, ui.maxValues, ui.maxLength]) {
        input.addEventListener('input', () => { writeFragment(); schedule(); });
    }
    for (const input of [ui.aggNames, ui.rawIndex]) {
        input.addEventListener('change', () => { writeFragment(); schedule(); });
    }
    ui.levels.addEventListener('change', () => { writeFragment(); schedule(); });

    ui.boot.addEventListener('click', () => { runInPhp(); });

    ui.pin.addEventListener('click', () => {
        state.pinned = {
            hash: ui.hash.textContent,
            ruleIds: [...ui.rules.children].map((item) => item.querySelector('.id').textContent.split(' ')[0]),
        };
        ui.pin.textContent = 'Pinned — now change the query';
        schedule();
    });

    ui.unpin.addEventListener('click', () => {
        state.pinned = null;
        ui.pin.textContent = 'Pin as reference';
        ui.compare.hidden = true;
    });

    ui.copyLink.addEventListener('click', async () => {
        writeFragment();
        try {
            await navigator.clipboard.writeText(location.href);
            ui.copyLink.textContent = 'Copied';
        } catch {
            ui.copyLink.textContent = location.href.length > 0 ? 'Copy from the address bar' : 'Copy failed';
        }
        window.setTimeout(() => { ui.copyLink.textContent = 'Copy link'; }, 2000);
    });

    // A shared link is by definition not a preset, so it needs the real thing.
    if (readFragment()) {
        ui.optionsBox.open = true;
        setEngine('loading php', 'about 3.1 MB, once', true);
        await runInPhp();

        return;
    }

    selectPreset(0);
}

start().catch((error) => {
    ui.error.hidden = false;
    ui.error.textContent = 'The playground could not start: ' + (error?.message ?? error);
});
