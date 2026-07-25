/**
 * Pure JS helpers — reverse ACL, movement query builder, date parsing.
 */
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const IvApp = require('../../js/app.js');

test('canReverseMovement: transfer_in never, transfer_out always', () => {
	const field = { isOffice: false, isAppAdmin: false };
	const office = { isOffice: true, isAppAdmin: false };
	assert.equal(IvApp.canReverseMovement(field, { kind: 'transfer_in' }), false);
	assert.equal(IvApp.canReverseMovement(field, { kind: 'transfer_out' }), true);
	assert.equal(IvApp.canReverseMovement(field, { kind: 'receive' }), true);
	assert.equal(IvApp.canReverseMovement(field, { kind: 'issue' }), false);
	assert.equal(IvApp.canReverseMovement(office, { kind: 'issue' }), true);
	assert.equal(IvApp.canReverseMovement(office, { kind: 'adjust' }), true);
	assert.equal(IvApp.canReverseMovement(field, null), false);
	assert.equal(IvApp.canReverseMovement(field, {}), false);
});

test('movementListQuery encodes filters and defaults', () => {
	assert.equal(IvApp.movementListQuery({}), 'limit=50&offset=0');
	assert.match(
		IvApp.movementListQuery({
			kind: 'issue',
			itemId: 3,
			locationId: 9,
			from: 100,
			to: 200,
			transferGroup: 'a b',
			limit: 25,
			offset: 50,
		}),
		/kind=issue/,
	);
	const q = IvApp.movementListQuery({
		kind: 'issue',
		itemId: 3,
		locationId: 9,
		from: 100,
		to: 200,
		transferGroup: 'a b',
		limit: 25,
		offset: 50,
	});
	assert.match(q, /itemId=3/);
	assert.match(q, /locationId=9/);
	assert.match(q, /from=100/);
	assert.match(q, /to=200/);
	assert.match(q, /transferGroup=a%20b/);
	assert.match(q, /limit=25/);
	assert.match(q, /offset=50/);
});

test('dateInputToUnix start and end of day', () => {
	assert.equal(IvApp.dateInputToUnix('', false), null);
	assert.equal(IvApp.dateInputToUnix('bad', false), null);
	const start = IvApp.dateInputToUnix('2026-07-24', false);
	const end = IvApp.dateInputToUnix('2026-07-24', true);
	assert.equal(typeof start, 'number');
	assert.equal(typeof end, 'number');
	assert.ok(end > start);
	assert.equal(end - start, 23 * 3600 + 59 * 60 + 59);
});
