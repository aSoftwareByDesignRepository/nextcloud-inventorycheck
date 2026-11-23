import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const appJs = readFileSync(new URL('../../js/app.js', import.meta.url), 'utf8');
const routesPhp = readFileSync(new URL('../../appinfo/routes.php', import.meta.url), 'utf8');
const pageCtl = readFileSync(new URL('../../lib/Controller/PageController.php', import.meta.url), 'utf8');

test('C-2: receive rows get a delivery-note cell, other kinds get a dash', () => {
	assert.match(appJs, /function deliveryNoteCell\(ctx, mov, refresh\)/);
	assert.match(appJs, /mov\.kind !== 'receive'/);
	assert.match(appJs, /mov\.hasDeliveryNote/);
});

test('C-2: viewer is a native dialog with authenticated img src', () => {
	assert.match(appJs, /function openDeliveryNoteDialog\(ctx, mov, refresh, triggerEl\)/);
	assert.match(appJs, /urlWithId\(ctx\.urls\.api\.deliveryNote, mov\.id\)/);
	assert.match(appJs, /className: 'iv-delivery-note__img'/);
	assert.match(appJs, /alt: tr\('Delivery note for movement \{id\}'/);
});

test('C-2: upload posts multipart file with the request token', () => {
	assert.match(appJs, /fd\.append\('file', file\)/);
	assert.match(appJs, /urlWithId\(ctx\.urls\.api\.deliveryNoteUpload, movId\)/);
	assert.match(appJs, /headers: \{ requesttoken: requestToken\(\) \}/);
});

test('C-2: oversize is refused client-side before upload', () => {
	assert.match(appJs, /file\.size > 10485760/);
	assert.match(appJs, /photo_too_large/);
});

test('C-2: routes + page URLs are registered', () => {
	assert.match(routesPhp, /movementEvidence#show/);
	assert.match(routesPhp, /movementEvidence#upload/);
	assert.match(routesPhp, /movementEvidence#destroy/);
	assert.match(routesPhp, /mobile#deliveryNoteUpload/);
	assert.match(routesPhp, /mobile#deliveryNote'/);
	assert.match(pageCtl, /'deliveryNoteUpload' =>/);
	assert.match(pageCtl, /'deliveryNoteDelete' =>/);
});
