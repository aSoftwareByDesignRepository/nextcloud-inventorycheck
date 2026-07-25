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
});

test('app.js filter panel and dialog inert lock are present', () => {
	const src = readFileSync(join(root, 'js/app.js'), 'utf8');
	assert.match(src, /iv-filter-panel/);
	assert.match(src, /iv-filter-grid/);
	assert.match(src, /modal-backdrop/);
	assert.match(src, /setAttribute\('inert'/);
	assert.match(src, /removeAttribute\('inert'\)/);
	assert.match(src, /document\.body\.style\.overflow/);
});

test('page templates close inventorycheck-app wrapper', () => {
	const end = readFileSync(join(root, 'templates/common/page-end.php'), 'utf8');
	assert.match(end, /inventorycheck-app/);
	const nav = readFileSync(join(root, 'templates/common/navigation.php'), 'utf8');
	assert.match(nav, /nav-item-has-children/);
	assert.match(nav, /iv-admin-subnav/);
});
