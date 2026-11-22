import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const appJs = readFileSync(new URL('../../js/app.js', import.meta.url), 'utf8');

test('IV-CUSTOM-FIELDS: item dialog sends customValues keyed by field id', () => {
	assert.match(appJs, /body\.customValues = customValues/);
	assert.match(appJs, /customValues\[c\.def\.id\]/);
	// Date fields use epoch semantics via the local-day helper.
	assert.match(appJs, /c\.def\.type === 'date'[\s\S]*dateInputToUnix\(v\)/);
});

test('IV-CUSTOM-FIELDS: controls render under a disclosure, typed per def', () => {
	assert.match(appJs, /disclosureSection\(tr\('Custom fields'\)/);
	// Error cells are cf_<id> so server errors land inline (AC-16).
	assert.match(appJs, /\{ name: 'cf_' \+ d\.id \}/);
	// Four type renderings exist.
	assert.match(appJs, /d\.type === 'select'/);
	assert.match(appJs, /d\.type === 'number'/);
	assert.match(appJs, /d\.type === 'date'/);
	// Required marks + client-side precheck mirror the server gate (NN-03).
	assert.match(appJs, /c\.def\.required && c\.input\.value\.trim\(\) === ''/);
});

test('IV-CUSTOM-FIELDS: stale select options stay readable, flagged obsolete', () => {
	assert.match(appJs, /\(d\.options \|\| \[\]\)\.indexOf\(String\(val\)\) === -1/);
	assert.match(appJs, /tr\('\(obsolete\)'\)/);
});

test('IV-CUSTOM-FIELDS: item detail renders the merged def+value block', () => {
	assert.match(appJs, /data-iv-custom-fields/);
	assert.match(appJs, /item\.customFields/);
	// Stale badge on the read surface.
	assert.match(appJs, /c\.stale[\s\S]*iv-badge--warn/);
});

test('IV-CUSTOM-FIELDS: settings fields section CRUD wired to /api/fields', () => {
	assert.match(appJs, /section === 'fields'/);
	assert.match(appJs, /renderFieldsSettings/);
	assert.match(appJs, /openFieldDialog/);
	assert.match(appJs, /ctx\.urls\.api\.fields/);
	assert.match(appJs, /ctx\.urls\.api\.fieldValueCount/);
	assert.match(appJs, /urlWithId\(ctx\.urls\.api\.fieldById, r\.id\)/);
	// Type immutable on edit — the select is disabled.
	assert.match(appJs, /typeSel\.disabled = true/);
});
