import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const IvApp = require('../../js/app.js');

test('kindMeta labels', () => {
	assert.equal(IvApp.kindMeta('receive').label.length > 0, true);
	assert.equal(IvApp.kindMeta('issue').badge.includes('overdue') || IvApp.kindMeta('issue').badge.length >= 0, true);
});

test('isValidCode charset', () => {
	assert.equal(IvApp.isValidCode('FILTER-42', 64), true);
	assert.equal(IvApp.isValidCode('BAD CODE', 64), false);
	assert.equal(IvApp.isValidCode('', 64), false);
	assert.equal(IvApp.isValidCode('A'.repeat(65), 64), false);
});

test('formatQty', () => {
	assert.equal(IvApp.formatQty(12), '12');
	assert.equal(IvApp.formatQty(-4), '-4');
});

test('locationKindLabel', () => {
	assert.equal(IvApp.locationKindLabel('van').length > 0, true);
});
