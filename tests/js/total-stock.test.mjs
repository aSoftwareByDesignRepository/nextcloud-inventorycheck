/**
 * IV-TOTAL-STOCK — JS contract: the rendered total comes from the item
 * payload (server-side sum over visible locations), never from summing
 * the 50-row-capped balance rows (NN-02).
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const appJs = readFileSync(join(root, 'js/app.js'), 'utf8');
const appCss = readFileSync(join(root, 'css/app.css'), 'utf8');

test('items list renders the payload totalQty column before Reorder', () => {
	// Column consumes the server payload field, not a client-side sum.
	assert.match(appJs, /label:\s*tr\('On hand'\),\s*render:\s*function\s*\(r\)\s*\{[^}]*r\.totalQty/);
	// Positioned before the Reorder column (stock + threshold read as a pair).
	const onHand = appJs.indexOf("label: tr('On hand'), render: function (r) {\n\t\t\t\t\t\tvar n = Number(r.totalQty)");
	const reorder = appJs.indexOf("{ label: tr('Reorder'), key: 'reorderLevel' }");
	assert.ok(onHand > -1, 'On hand column with totalQty renderer missing');
	assert.ok(reorder > -1, 'Reorder column missing');
	assert.ok(onHand < reorder, 'On hand column must sit immediately before Reorder');
});

test('items list total column reuses the negative-stock badge', () => {
	const onHand = appJs.indexOf('var n = Number(r.totalQty)');
	assert.ok(onHand > -1);
	const slice = appJs.slice(onHand, onHand + 600);
	assert.match(slice, /iv-badge iv-badge--overdue/);
	assert.match(slice, /tr\('negative stock'\)/);
});

test('item detail renders the iv-balance-total line from payload, not row sums', () => {
	assert.match(appJs, /iv-balance-total/);
	assert.match(appJs, /tr\('Total'\)\s*\+\s*':\s*'\s*\+\s*formatQty\(Number\(item\.totalQty\)/);
	// NN-02: the sum line must not aggregate the rendered balance rows.
	const totalIdx = appJs.indexOf('iv-balance-total');
	const tail = appJs.slice(totalIdx - 200, totalIdx + 300);
	assert.doesNotMatch(tail, /bals\.data\.(reduce|map)/);
});

test('iv-balance-total is styled with design tokens only', () => {
	assert.match(appCss, /\.iv-balance-total\s*\{[^}]*var\(--iv-space/);
	assert.doesNotMatch(
		appCss.match(/\.iv-balance-total\s*\{[^}]*\}/)?.[0] ?? '',
		/#[0-9a-fA-F]{3,8}\b/,
		'no literal colors on the total line',
	);
});
