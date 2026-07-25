/**
 * AC-21 — axe-core WCAG 2.1 AA on all required surfaces:
 * dashboard, item detail, movement dialogs/filters, settings/license.
 */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const require = createRequire(import.meta.url);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const fixturesDir = path.join(root, 'tests/fixtures');
const sharedCss = readFileSync(path.join(fixturesDir, 'a11y-shared.css'), 'utf8');
const axeSource = readFileSync(require.resolve('axe-core/axe.js'), 'utf8');

const requiredSurfaces = [
	'a11y-shell.html',
	'a11y-dashboard.html',
	'a11y-item-detail.html',
	'a11y-movements.html',
	'a11y-settings.html',
];

function loadFixture(name) {
	let html = readFileSync(path.join(fixturesDir, name), 'utf8');
	if (html.includes('<!--STYLE-->')) {
		html = html.replace('<!--STYLE-->', `<style>\n${sharedCss}\n</style>`);
	}
	return html;
}

async function axeScan(name) {
	const html = loadFixture(name);
	const dom = new JSDOM(html, {
		url: `https://inventorycheck.test/apps/inventorycheck/${name}`,
		runScripts: 'dangerously',
		pretendToBeVisual: true,
	});
	dom.window.eval(axeSource);
	assert.equal(typeof dom.window.axe?.run, 'function', `${name}: axe must load`);
	const results = await dom.window.axe.run(dom.window.document, {
		runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
		resultTypes: ['violations'],
	});
	const bad = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
	dom.window.close();
	return { name, bad, all: results.violations };
}

test('AC-21 fixtures cover dashboard item movements settings', () => {
	const files = new Set(readdirSync(fixturesDir));
	for (const name of requiredSurfaces) {
		assert.ok(files.has(name), `missing fixture ${name}`);
	}
});

for (const name of requiredSurfaces) {
	test(`axe zero serious/critical: ${name}`, async () => {
		const { bad } = await axeScan(name);
		assert.equal(
			bad.length,
			0,
			bad.map((v) => `${v.id}: ${v.help} (${v.nodes.length})`).join('\n') || 'axe clean',
		);
	});
}

test('movements fixture exposes filter form and receive dialog', () => {
	const html = loadFixture('a11y-movements.html');
	assert.match(html, /role="search"/);
	assert.match(html, /Filter movements/);
	assert.match(html, /aria-modal="true"/);
	assert.match(html, /Receive stock/);
	assert.match(html, /aria-label="Pagination"/);
});

test('settings fixture exposes license seats devices and support', () => {
	const html = loadFixture('a11y-settings.html');
	assert.match(html, /id="iv-license"/);
	assert.match(html, /id="iv-app-admins"/);
	assert.match(html, /Delegated app administrators/);
	assert.match(html, /Assign seat/);
	assert.match(html, /Create device slot/);
	assert.match(html, /One-time pairing code/);
	assert.match(html, /data-support-us="1"/);
	assert.match(html, /Allow negative stock/);
});

test('dashboard fixture flags negative balances with text not color alone', () => {
	const html = loadFixture('a11y-dashboard.html');
	assert.match(html, /Negative balances/);
	assert.match(html, /negative stock/);
	assert.match(html, /iv-badge--overdue/);
});

test('item detail fixture keeps selectable label code for A12', () => {
	const html = loadFixture('a11y-item-detail.html');
	assert.match(html, /id="iv-label-code"/);
	assert.match(html, /iv-label-code/);
	assert.match(html, /QR code for FILTER-42/);
	assert.match(html, /Print label/);
});
