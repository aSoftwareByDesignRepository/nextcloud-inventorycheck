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
	'a11y-stocktake.html',
	'a11y-stocktake-create.html',
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
		beforeParse(window) {
			window.HTMLCanvasElement.prototype.getContext = () => null;
		},
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
	assert.match(html, /id="iv-settings-pages"/);
	assert.match(html, /aria-label="Settings pages"/);
	assert.match(html, /id="iv-license"/);
	assert.match(html, /data-iv-field="appAdmins"/);
	assert.match(html, /Delegated app administrators/);
	assert.match(html, /Assign seat/);
	assert.match(html, /Create device slot/);
	assert.match(html, /One-time pairing code/);
	assert.match(html, /data-support-us="1"/);
	assert.match(html, /Allow negative stock/);
	assert.match(html, /id="iv-frac-title"/);
	assert.match(html, /Fractional quantities/);
	assert.match(html, /id="iv-acl-title"/);
	assert.match(html, /Location access/);
	assert.match(html, /id="iv-acl-loc-ids"/);
	assert.match(html, /Search locations/);
	assert.doesNotMatch(html, /Hold Ctrl/);
	assert.doesNotMatch(html, /<select[^>]*id="iv-acl-loc-ids"[^>]*multiple/);
});

test('settings fixture exposes Wave D warehouse policy controls', () => {
	const html = loadFixture('a11y-settings.html');
	assert.match(html, /id="iv-wave-d"/);
	assert.match(html, /Scan &amp; adjust policies|Scan & adjust policies/);
	assert.match(html, /id="iv-require-adjust-reason"/);
	assert.match(html, /Require reason code on adjust/);
	assert.match(html, /id="iv-require-location-scan"/);
	assert.match(html, /Require scanned location barcode before booking/);
	assert.match(html, /id="iv-default-location"/);
	assert.match(html, /id="iv-target-stock"/);
	assert.match(html, /variance report/i);
	assert.match(html, /reorder CSV/i);
	assert.match(html, /iv-switch-field/);
	assert.match(html, /iv-form-actions/);
	assert.match(html, /iv-settings-page/);
});

test('settings fixture uses directory pickers, never raw id text inputs, for allow-lists/app-admins/ACL subject/seat uid', () => {
	const html = loadFixture('a11y-settings.html');
	assert.doesNotMatch(html, /one per line/);
	assert.doesNotMatch(html, /Nextcloud user id/);
	assert.doesNotMatch(html, /User or group id/);
	assert.doesNotMatch(html, /<textarea[^>]*id="iv-allowed-users"/);
	assert.doesNotMatch(html, /<textarea[^>]*id="iv-app-admins"/);
	assert.doesNotMatch(html, /<input[^>]*id="iv-acl-subject-id"[^>]*type="text"/);
	assert.doesNotMatch(html, /<input[^>]*id="iv-seat-uid"[^>]*type="text"/);
	assert.match(html, /data-iv-field="allowedUsers"/);
	assert.match(html, /data-iv-field="subjectId"/);
	assert.match(html, /data-iv-field="uid"/);
	const pickerCount = (html.match(/class="iv-picker(?:\s|")/g) || []).length;
	assert.equal(pickerCount, 5, 'expected 5 pickers: allowedUsers, appAdmins, ACL subject, ACL locations, seat uid');
	assert.match(html, /role="combobox"/);
	assert.match(html, /role="listbox"/);
});

test('dashboard fixture flags negative balances with text not color alone', () => {
	const html = loadFixture('a11y-dashboard.html');
	assert.match(html, /Negative balances/);
	assert.match(html, /negative stock/);
	assert.match(html, /iv-badge--overdue/);
	assert.match(html, /iv-howto-dashboard/);
	assert.match(html, /How booking works/);
	assert.match(html, /iv-quickstart__item/);
	assert.match(html, /dashboard_howto_v1/);
	assert.doesNotMatch(html, /iv-howto[^"]*iv-empty|iv-empty[^"]*iv-howto/);
});

test('dashboard tables expose captions for AT (reorder / recent / negatives)', () => {
	const html = loadFixture('a11y-dashboard.html');
	assert.match(html, /<caption[^>]*>Negative balances<\/caption>/);
	assert.match(html, /<caption[^>]*>Low stock<\/caption>/);
	assert.match(html, /<caption[^>]*>Recent movements<\/caption>/);
});

test('movements fixture includes DutyCheck-style how-to card', () => {
	const html = loadFixture('a11y-movements.html');
	assert.match(html, /iv-howto-movements/);
	assert.match(html, /How movements work/);
	assert.match(html, /movements_howto_v1/);
	assert.match(html, /iv-quickstart__item/);
});

test('item detail fixture keeps selectable label code for A12', () => {
	const html = loadFixture('a11y-item-detail.html');
	assert.match(html, /id="iv-label-code"/);
	assert.match(html, /iv-label-code/);
	assert.match(html, /QR and barcode for FILTER-42/);
	assert.match(html, /data-symbology="code128b"/);
	assert.match(html, /Print label/);
	assert.match(html, /Download SVG/);
});

test('stocktake-create fixture is tap-to-start page chooser, never a giant native select or modal', () => {
	const html = loadFixture('a11y-stocktake-create.html');
	assert.doesNotMatch(html, /aria-modal="true"/);
	assert.doesNotMatch(html, /iv-dialog-overlay/);
	assert.match(html, /New stocktake/);
	assert.match(html, /iv-stocktake-new/);
	assert.match(html, /iv-page-header__lead/);
	assert.match(html, /Tap a location below to start counting/);
	assert.match(html, /iv-stocktake-howto/);
	assert.match(html, /How a stocktake works/);
	assert.match(html, /iv-quickstart__item/);
	assert.match(html, /data-iv-dismiss-hint="stocktake_create_howto_v1"/);
	assert.match(html, /Where are you counting/);
	assert.match(html, /iv-sr-only/);
	assert.match(html, /data-iv-loc-chooser="1"/);
	assert.match(html, /role="listbox"/);
	assert.match(html, /Start stocktake at Warehouse North/);
	assert.match(html, /iv-loc-chooser__name/);
	assert.match(html, /iv-stocktake-more/);
	assert.doesNotMatch(html, /<select[^>]*data-iv-field="locationId"/);
	assert.doesNotMatch(html, /<select[^>]*aria-required="true"/);
	assert.doesNotMatch(html, />Create</);
});
