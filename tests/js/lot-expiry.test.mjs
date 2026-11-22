/**
 * IV-LOT-EXPIRY-PACK — JS contract: the issue dialog FEFO picker consumes
 * the server-sorted payload (never re-sorted client-side), zero-qty lots
 * are disabled, and the Übersicht watch card is hidden-when-empty.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const appJs = readFileSync(join(root, 'js/app.js'), 'utf8');

test('issue dialog exposes a FEFO lot picker fed by the item-lots endpoint', () => {
	assert.match(appJs, /data-iv-lot-picker/);
	assert.match(appJs, /ctx\.urls\.api\.itemLots/);
	// Sorted server-side — the picker must not re-sort.
	const picker = appJs.slice(appJs.indexOf('function refreshLotPicker'), appJs.indexOf('var lotWrap'));
	assert.doesNotMatch(picker, /\.sort\(/, 'client-side re-sort would break AC-15 ordering parity');
});

test('picker labels the first in-stock lot as the FEFO pick and disables empty lots', () => {
	assert.match(appJs, /tr\('expires first'\)/);
	assert.match(appJs, /tr\('no stock'\)/);
	assert.match(appJs, /disabled/);
});

test('watch card is hidden when empty (no dead empty state)', () => {
	const card = appJs.slice(appJs.indexOf('data-iv-expiring-card') - 400, appJs.indexOf('data-iv-expiring-card'));
	assert.match(card, /expiring\.data\s*&&\s*expiring\.data\.length/,
		'card must be gated on non-empty data');
});

test('watch card state badges carry text labels (never color-only)', () => {
	assert.match(appJs, /iv-badge--overdue[\s\S]{0,80}tr\('expired'\)/);
	assert.match(appJs, /iv-badge--warn[\s\S]{0,80}tr\('\{n\} days left'/);
});

test('settings exposes the 0-off expiry threshold hint', () => {
	assert.match(appJs, /name:\s*'expiryWarnDays'/);
	assert.match(appJs, /iv-expiry-days-hint/);
	assert.match(appJs, /min:\s*'0'/);
	assert.match(appJs, /max:\s*'365'/);
});

test('item detail shows the lots section only for lot-tracked items', () => {
	assert.match(appJs, /data-iv-lot-section/);
	assert.match(appJs, /item\.trackMode === 'lot'/);
});
