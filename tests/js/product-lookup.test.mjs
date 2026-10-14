import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const L = require('../../js/common/product-lookup.js');

// ── Pure helpers ────────────────────────────────────────────────────────────

test('isGtinFormat accepts 8/12/13/14 digits only', () => {
	for (const ok of ['73513537', '036000291452', '4006381333931', '00614141999996']) {
		assert.equal(L.isGtinFormat(ok), true, ok);
	}
	for (const bad of ['', '1234567', '123456789', '12345678901', '123456789012345',
		'abcdefghijklm', '40063813339 1', null, undefined, 123]) {
		assert.equal(L.isGtinFormat(bad), false, JSON.stringify(bad));
	}
	// Surrounding whitespace is normalized — same as the service's
	// trim($barcode) before Gtin::isValid (JS/PHP parity).
	assert.equal(L.isGtinFormat(' 4006381333931'), true);
	assert.equal(L.isGtinFormat('4006381333931\n'), true);
});

test('hasValidCheckDigit enforces the GS1 check digit', () => {
	for (const ok of ['4006381333931', '036000291452', '73513537', '00614141999996']) {
		assert.equal(L.hasValidCheckDigit(ok), true, ok);
	}
	// Same length + digits, wrong check digit — must be rejected.
	for (const bad of ['4006381333932', '036000291453', '73513538', '00614141999997']) {
		assert.equal(L.hasValidCheckDigit(bad), false, bad);
	}
	// Format garbage can never pass.
	assert.equal(L.hasValidCheckDigit('12345'), false);
});

test('isGtin = format + check digit — the pre-request bar', () => {
	assert.equal(L.isGtin('4006381333931'), true);
	assert.equal(L.isGtin('4006381333932'), false); // bad check digit
	assert.equal(L.isGtin('4006381333'), false);    // bad length
});

test('webSearchUrl quotes the GTIN and rejects invalid codes', () => {
	assert.equal(
		L.webSearchUrl('4006381333931'),
		'https://duckduckgo.com/?q=' + encodeURIComponent('"4006381333931"'),
	);
	assert.equal(L.webSearchUrl('not-a-code'), '');
	assert.equal(L.webSearchUrl(''), '');
});

test('safeSourceUrl allows http(s) only — no javascript, creds, or control chars', () => {
	assert.equal(L.safeSourceUrl('https://example.org/p/1'), 'https://example.org/p/1');
	assert.equal(L.safeSourceUrl('http://example.org/p/1'), 'http://example.org/p/1');
	for (const bad of [
		'javascript:alert(1)',
		'javascript://evil.example/%0Aalert(1)',
		'data:text/html,<b>x</b>',
		'ftp://example.org/x',
		'https://user:pw@evil.example/x',
		'https://evil.example/x\n',
		'not a url',
		'',
		null,
		undefined,
		123,
	]) {
		assert.equal(L.safeSourceUrl(bad), '', JSON.stringify(bad));
	}
});

test('providerLabel maps ids, falls back to raw id', () => {
	assert.equal(L.providerLabel('openfoodfacts'), 'Open Food Facts');
	assert.equal(L.providerLabel('upcitemdb'), 'UPCitemdb');
	assert.equal(L.providerLabel('unknown-x'), 'unknown-x');
	assert.equal(L.providerLabel(''), '');
});

test('fillEmptyFields fills only empty fields and SKU with barcode', () => {
	const targets = {
		name: { value: '' },
		description: { value: '' },
		sku: { value: '' },
	};
	const applied = L.fillEmptyFields(targets, { name: 'Widget', description: 'A widget' }, '4006381333931');
	assert.equal(targets.name.value, 'Widget');
	assert.equal(targets.description.value, 'A widget');
	assert.equal(targets.sku.value, '4006381333931');
	assert.deepEqual(applied, { name: true, description: true, sku: true });
});

test('fillEmptyFields never overwrites user input (NN-03)', () => {
	const targets = {
		name: { value: 'Typed by user' },
		description: { value: '   ' },      // whitespace-only counts as empty
		sku: { value: 'SKU-1' },
	};
	const applied = L.fillEmptyFields(
		targets,
		{ name: 'Upstream Name', description: 'Upstream desc' },
		'4006381333931',
	);
	assert.equal(targets.name.value, 'Typed by user');
	assert.equal(targets.description.value, 'Upstream desc'); // whitespace = empty
	assert.equal(targets.sku.value, 'SKU-1');
	assert.deepEqual(applied, { name: false, description: true, sku: false });
});

test('fillEmptyFields tolerates null/poisoned suggestions', () => {
	const targets = { name: { value: '' }, description: { value: '' }, sku: { value: '' } };
	// No suggestion → nothing is applied, not even the SKU (fill happens only
	// when a suggestion applies — spec US-001 AC-5).
	assert.deepEqual(L.fillEmptyFields(targets, null, '4006381333931'), { name: false, description: false, sku: false });
	assert.deepEqual(L.fillEmptyFields(null, { name: 'x' }, '1'), { name: false, description: false, sku: false });
	const evil = { name: { value: '' }, description: { value: '' }, sku: { value: '' } };
	L.fillEmptyFields(evil, { name: '<script>alert(1)</script>' }, 'x');
	// Value assignment only — the string lands as data, never markup.
	assert.equal(evil.name.value, '<script>alert(1)</script>');
});

test('isStaleResponse detects generation, detach, and code drift', () => {
	assert.equal(L.isStaleResponse(1, 1, '4006381333931', '4006381333931', true), false);
	assert.equal(L.isStaleResponse(1, 2, '4006381333931', '4006381333931', true), true);  // newer request
	assert.equal(L.isStaleResponse(1, 1, '4006381333931', '4006381333931', false), true); // dialog closed
	assert.equal(L.isStaleResponse(1, 1, '036000291452', '4006381333931', true), true);   // barcode edited
});

test('debounce fires once per burst (trailing edge)', async () => {
	let calls = 0;
	const fire = L.debounce(() => { calls += 1; }, 15);
	fire(); fire(); fire();
	assert.equal(calls, 0);
	await new Promise((r) => setTimeout(r, 40));
	assert.equal(calls, 1);
});

// ── createPanel with fake DOM ───────────────────────────────────────────────

function fakeNode(tag, attrs, children) {
	return {
		tag,
		attrs: attrs || {},
		children: children || [],
		textContent: (attrs && attrs.text) || '',
		hidden: false,
		disabled: !!(attrs && attrs.disabled !== undefined && attrs.disabled !== null && attrs.disabled !== ''),
		isConnected: true,
		appendChild(c) { this.children.push(c); },
		setAttribute(k, v) { this.attrs[k] = v; },
		removeAttribute(k) { delete this.attrs[k]; },
		addEventListener(ev, fn) { (this._listeners ||= {})[ev] = fn; },
		fire(ev) { this._listeners && this._listeners[ev] && this._listeners[ev](); },
	};
}

function makeDeps(apiImpl, { autofire = true } = {}) {
	const fields = {
		scan: fakeNode('input', {}, []),
		sku: fakeNode('input', {}, []),
		name: fakeNode('input', {}, []),
		description: fakeNode('input', {}, []),
	};
	for (const f of Object.values(fields)) {
		f.value = '';
		f.addEventListener = function (ev, fn) { (this._listeners ||= {})[ev] = fn; };
		f.fire = function (ev) { this._listeners && this._listeners[ev] && this._listeners[ev](); };
	}
	const calls = [];
	const deps = {
		tr: (text, vars) => {
			let out = text;
			if (vars) for (const k of Object.keys(vars)) out = out.replace('{' + k + '}', String(vars[k]));
			return out;
		},
		el: (tag, attrs, kids) => fakeNode(tag, attrs, kids),
		btn: (label, opts) => {
			const n = fakeNode('button', { 'data-iv-action': opts['data-iv-action'], disabled: opts.disabled ? '' : null }, [label]);
			n.label = label;
			n.click = () => opts.onclick && opts.onclick();
			return n;
		},
		clear: (node) => { node.children = []; },
		api: (method, url, body) => { calls.push({ method, url, body }); return apiImpl(body); },
		lookupUrl: '/apps/inventorycheck/api/product-lookup',
		autofire,
		fields,
	};
	return { deps, fields, calls };
}

function findByClass(node, cls) {
	if (!node || !node.children) return null;
	if (node.attrs && node.attrs.className === cls) return node;
	for (const c of node.children) {
		const hit = findByClass(c, cls);
		if (hit) return hit;
	}
	return null;
}

function findButton(node) {
	if (!node || !node.children) return null;
	if (node.tag === 'button') return node;
	for (const c of node.children) {
		const hit = findButton(c);
		if (hit) return hit;
	}
	return null;
}

const VALID_GTIN = '4006381333931';

function hitPayload() {
	return {
		found: true,
		barcode: VALID_GTIN,
		name: 'Nutella 400g',
		brand: 'Ferrero',
		description: 'Hazelnut spread',
		sourceUrl: 'https://world.openfoodfacts.org/product/4006381333931',
		provider: 'openfoodfacts',
	};
}

test('panel: invalid code never fires a request', async () => {
	const { deps, fields, calls } = makeDeps(() => Promise.resolve({ found: false }));
	const panel = L.createPanel(deps);
	fields.scan.value = '123';           // bad length
	fields.scan.fire('change');
	fields.scan.value = '4006381333932'; // bad check digit
	fields.scan.fire('change');
	await new Promise((r) => setTimeout(r, 20));
	assert.equal(calls.length, 0);
});

test('panel: manual button performs a lookup and fills empty fields', async () => {
	const { deps, fields, calls } = makeDeps(() => Promise.resolve(hitPayload()));
	const panel = L.createPanel(deps);
	const btn = findButton(panel);
	fields.scan.value = VALID_GTIN;
	fields.scan.fire('input');
	btn.click();
	await new Promise((r) => setTimeout(r, 0));

	assert.equal(calls.length, 1);
	assert.equal(calls[0].method, 'POST');
	assert.equal(calls[0].body.barcode, VALID_GTIN);
	assert.equal(fields.name.value, 'Nutella 400g');
	assert.equal(fields.description.value, 'Hazelnut spread');
	assert.equal(fields.sku.value, VALID_GTIN);

	const status = findByClass(panel, 'iv-lookup__status');
	assert.match(status.textContent, /Open Food Facts/);
	const brand = findByClass(panel, 'iv-lookup__brand');
	assert.equal(brand.hidden, false);
	assert.match(brand.textContent, /Ferrero/);
	const source = findByClass(panel, 'iv-lookup__source');
	assert.equal(source.children[0].attrs.href, 'https://world.openfoodfacts.org/product/4006381333931');
	assert.equal(source.children[0].attrs.rel, 'noopener noreferrer');
});

test('panel: poisoned sourceUrl never becomes a link (defense-in-depth)', async () => {
	const { deps, fields } = makeDeps(() => Promise.resolve({
		...hitPayload(),
		sourceUrl: 'javascript:alert(1)',
	}));
	const panel = L.createPanel(deps);
	fields.scan.value = VALID_GTIN;
	findButton(panel).click();
	await new Promise((r) => setTimeout(r, 0));
	const source = findByClass(panel, 'iv-lookup__source');
	assert.equal(source.children.length, 0, 'unsafe scheme must not render an <a>');
});

test('panel: autofire=false disables debounce and blur, button still works', async () => {
	const { deps, fields, calls } = makeDeps(() => Promise.resolve({ found: false }), { autofire: false });
	L.createPanel(deps);
	fields.scan.value = VALID_GTIN;
	fields.scan.fire('input');
	fields.scan.fire('change');
	await new Promise((r) => setTimeout(r, 1100)); // past the 1s debounce
	assert.equal(calls.length, 0, 'autofire off must mean zero automatic requests');
});

test('panel: autofire debounces a burst into one request', async () => {
	const { deps, fields, calls } = makeDeps(() => Promise.resolve({ found: false }), { autofire: true });
	L.createPanel(deps);
	for (const ch of ['4', '40', '400', '4006', '40063', '400638', '4006381', '40063813', '400638133', '4006381333', '40063813339', '400638133393', '4006381333931']) {
		fields.scan.value = ch;
		fields.scan.fire('input');
	}
	await new Promise((r) => setTimeout(r, 1150));
	assert.equal(calls.length, 1, 'one debounced call per burst');
	assert.equal(calls[0].body.barcode, VALID_GTIN);
});

test('panel: stale response is discarded when the barcode changes mid-flight', async () => {
	let resolveFirst;
	const gate = new Promise((r) => { resolveFirst = r; });
	let call = 0;
	const { deps, fields, calls } = makeDeps(() => {
		call += 1;
		return call === 1 ? gate : Promise.resolve({ found: false });
	});
	const panel = L.createPanel(deps);
	const btn = findButton(panel);
	fields.scan.value = VALID_GTIN;
	btn.click();
	// User edits the barcode while request 1 is in flight.
	fields.scan.value = '036000291452';
	resolveFirst(hitPayload());
	await new Promise((r) => setTimeout(r, 10));
	assert.equal(fields.name.value, '', 'stale hit must not write fields');
});

test('panel: miss and error states announce status text', async () => {
	const miss = makeDeps(() => Promise.resolve({ found: false }));
	const panel1 = L.createPanel(miss.deps);
	miss.fields.scan.value = VALID_GTIN;
	findButton(panel1).click();
	await new Promise((r) => setTimeout(r, 0));
	assert.match(findByClass(panel1, 'iv-lookup__status').textContent, /No product found/);

	const err = makeDeps(() => Promise.reject(Object.assign(new Error('rate'), { code: 'rate_limited', message: 'Please wait a few seconds before searching again.' })));
	const panel2 = L.createPanel(err.deps);
	err.fields.scan.value = VALID_GTIN;
	findButton(panel2).click();
	await new Promise((r) => setTimeout(r, 0));
	assert.match(findByClass(panel2, 'iv-lookup__status').textContent, /wait a few seconds/);
});

test('panel: live region + privacy hint + web link wiring', async () => {
	const { deps } = makeDeps(() => Promise.resolve({ found: false }));
	const panel = L.createPanel(deps);
	const status = findByClass(panel, 'iv-lookup__status');
	assert.equal(status.attrs.role, 'status');
	assert.equal(status.attrs['aria-live'], 'polite');
	const json = JSON.stringify(panel.children);
	assert.match(json, /only the barcode/i);
	const webLink = findByClass(panel, 'iv-lookup__web');
	assert.equal(webLink.attrs.target, '_blank');
	assert.equal(webLink.attrs.rel, 'noopener noreferrer');
});
