/**
 * IV-REPORTS-SURFACE — JS contract: the /reports page consumes all four
 * report endpoints, every card carries the isEstimate banner + unpriced
 * count (NN-03), CSV links exist per report, and inline validation is
 * wired (not toast-only).
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const appJs = readFileSync(join(root, 'js/app.js'), 'utf8');

test('reports page is routed from both switch statements', () => {
	const matches = appJs.match(/case 'reports': renderReports\(ctx\); break;/g);
	assert.ok(matches && matches.length === 2, 'reports must route in router + boot switch');
});

test('all four report endpoints are consumed', () => {
	assert.match(appJs, /ctx\.urls\.api\.valuationReport/);
	assert.match(appJs, /ctx\.urls\.api\.locationValueReport/);
	assert.match(appJs, /ctx\.urls\.api\.movementValueReport/);
	assert.match(appJs, /ctx\.urls\.api\.deadStockReport/);
});

test('every report card shows the estimate banner (NN-03 — never accounting)', () => {
	assert.match(appJs, /tr\('Estimate — not accounting'\)/);
	assert.match(appJs, /tr\('\{n\} unpriced'/);
	// Banner rendered per card, not once for the page.
	assert.match(appJs, /estimateBanner\(valuation\.unpricedCount\)/);
	assert.match(appJs, /estimateBanner\(locValue\.unpricedCount\)/);
	assert.match(appJs, /estimateBanner\(ds\.unpricedCount\)/);
});

test('CSV export links exist for valuation, location_value and dead_stock', () => {
	assert.match(appJs, /exportCsvHref\(ctx, 'valuation'\)/);
	assert.match(appJs, /exportCsvHref\(ctx, 'location_value'\)/);
	assert.match(appJs, /exportCsvHref\(ctx, 'dead_stock', \{ days: d \}\)/);
});

test('inline validation: dead-stock days 1..3650 and date-range order', () => {
	assert.match(appJs, /d < 1 \|\| d > 3650/);
	assert.match(appJs, /t < f/, 'reversed range must be rejected inline');
	assert.match(appJs, /iv-field__error/, 'field-error class (design-system), not toast-only');
});

test('movement-value exposes 7/30/90 quick pills and refresh announcements', () => {
	assert.match(appJs, /\[7, 30, 90\]\.map/);
	assert.match(appJs, /announce\(tr\('Report updated\.'\)\)/);
	assert.match(appJs, /aria-live': 'polite'/);
});
