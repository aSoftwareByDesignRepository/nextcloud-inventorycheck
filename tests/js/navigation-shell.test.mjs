/**
 * Navigation shell contract — AZ-parity submenu toggle semantics.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');

test('navigation.js toggles aria-expanded and hidden without race on double click', () => {
	const src = readFileSync(join(root, 'js/common/navigation.js'), 'utf8');
	assert.match(src, /aria-expanded/);
	assert.match(src, /setAttribute\('hidden'/);
	assert.match(src, /removeAttribute\('hidden'\)/);
	assert.match(src, /ArrowDown/);
	assert.match(src, /ArrowUp/);
	assert.match(src, /stopPropagation/);
	// Bachus: Settings click from outside settings navigates to first section.
	assert.match(src, /\/settings\(\\\/\|\$\)/);
	assert.match(src, /window\.location\.assign/);
});

test('app.js filter panel and dialog inert lock are present', () => {
	const src = readFileSync(join(root, 'js/app.js'), 'utf8');
	assert.match(src, /iv-filter-panel/);
	assert.match(src, /iv-filter-grid/);
	assert.match(src, /modal-backdrop/);
	assert.match(src, /setAttribute\('inert'/);
	assert.match(src, /removeAttribute\('inert'\)/);
	assert.match(src, /document\.body\.style\.overflow/);
	// Items list must use AZ filter-panel — not bare iv-toolbar.
	assert.match(src, /iv-items-filter-title/);
	assert.doesNotMatch(src, /className:\s*'iv-toolbar'/);
});

test('app.js stocktake conflict gate is present (UC-C2)', () => {
	const src = readFileSync(join(root, 'js/app.js'), 'utf8');
	assert.match(src, /Changed since snapshot/);
	assert.match(src, /acknowledgeConflicts/);
	assert.match(src, /Accept conflicts and close/);
	assert.match(src, /Confirming means you reviewed the conflicts and accept the counted quantities/);
	assert.match(src, /Live qty/);
});

test('page templates close inventorycheck-app wrapper', () => {
	const end = readFileSync(join(root, 'templates/common/page-end.php'), 'utf8');
	assert.match(end, /inventorycheck-app/);
	const nav = readFileSync(join(root, 'templates/common/navigation.php'), 'utf8');
	assert.match(nav, /nav-menu/);
	assert.match(nav, /l->t\('Settings'\)/);
	assert.match(nav, /nav-item-has-children/);
	assert.match(nav, /iv-settings-subnav/);
	assert.match(nav, /settingsSectionUrls/);
	assert.match(nav, /iv-nav__hint/);
	assert.match(nav, /iv-nav__label/);
	assert.match(nav, /Low stock and recent bookings/);
	const navCss = readFileSync(join(root, 'css/navigation.css'), 'utf8');
	assert.match(navCss, /a\[aria-current="page"\] \.iv-nav__hint/);
	assert.match(navCss, /color:\s*var\(--color-primary-element-text\)/);
	assert.match(navCss, /Maxcontrast hints stay dark-on-dark/);
	// Mobile drawer: never force #app-navigation to width:100% (white slab bug).
	assert.match(navCss, /never set width:100% on #app-navigation/);
	assert.match(navCss, /@media only screen and \(width < 1024px\)/);
	assert.match(navCss, /width:\s*var\(--navigation-width,\s*300px\)/);
	assert.doesNotMatch(
		navCss,
		/#content\.app-inventorycheck #app-navigation,\s*\n#content\.app-inventorycheck #app-navigation \.nav-menu,\s*\n#content\.app-inventorycheck #app-navigation \.nav-submenu \{[^}]*width:\s*100%/s,
	);
	const start = readFileSync(join(root, 'templates/common/page-start.php'), 'utf8');
	assert.match(start, /navUrls\['settings'\]/);
});

test('app.js clean stocktake closes without a confirm dialog', () => {
	const src = readFileSync(join(root, 'js/app.js'), 'utf8');
	assert.match(src, /function closeStocktake/);
	assert.match(src, /uncounted === 0 && conflicts === 0/);
	assert.match(src, /function postStocktakeClose/);
	assert.match(src, /Close and leave uncounted/);
	assert.doesNotMatch(src, /iv-ack-conflicts/);
	assert.doesNotMatch(src, /iv-abandon-uncounted/);
});
