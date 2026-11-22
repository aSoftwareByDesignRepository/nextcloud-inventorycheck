/**
 * IV-SUB-LOCATIONS — JS contract: pathLabel-first rendering, roots picker
 * in the location dialog, parent-aware list ordering, parent context on
 * the detail surface. Source-level guards so a refactor cannot silently
 * drop the hierarchy affordances.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const appJs = readFileSync(join(root, 'js/app.js'), 'utf8');
const appCss = readFileSync(join(root, 'css/app.css'), 'utf8');

test('labelFromMap prefers server pathLabel over bare name (AC-8)', () => {
	assert.match(appJs, /row\.pathLabel \|\| row\.name/, 'labelFromMap must prefer pathLabel');
});

test('location dialog offers a roots-only parent picker', () => {
	assert.match(appJs, /locations \+ '\?roots=1'/, 'picker must fetch ?roots=1');
	assert.match(appJs, /tr\('Belongs under \(optional\)'\)/);
	assert.match(appJs, /tr\('None — top level'\)/);
	// Editing a location must not offer itself as its own parent.
	assert.match(appJs, /Number\(r\.id\) === Number\(existing\.id\)\) return;/);
});

test('parent select submits parentId (null when empty)', () => {
	assert.match(appJs, /parentId:\s*parentSel\.value \? Number\(parentSel\.value\) : null/);
});

test('children render indented with a muted parent prefix', () => {
	assert.match(appJs, /iv-loc-parent-label/);
	assert.match(appJs, /iv-loc-child/);
	assert.match(appCss, /\.iv-loc-child\s*\{\s*padding-inline-start/);
	assert.match(appCss, /\.iv-loc-parent-label\s*\{/);
});

test('location detail shows parent context + inactive marker', () => {
	assert.match(appJs, /tr\('inside \{parent\}'/);
	assert.match(appJs, /loc\.parentActive === false \? ' \(' \+ tr\('inactive'\) \+ '\)'/);
});

test('list groups children under their roots when possible (AC-8)', () => {
	assert.match(appJs, /byParent\[r\.parentId\]/, 'children must bucket under their parent id');
});
