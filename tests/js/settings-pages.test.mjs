/**
 * Fail-closed tests for settings-legacy-redirect.js
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const src = readFileSync(join(root, 'js/settings-legacy-redirect.js'), 'utf8');

function loadApi() {
	const sandbox = { window: {}, document: {}, Object, String, JSON };
	sandbox.window = sandbox;
	vm.runInNewContext(src, sandbox, { filename: 'settings-legacy-redirect.js' });
	return sandbox.InventoryCheckSettingsLegacyRedirect;
}

function stubDoc(section, urlsJson) {
	return {
		getElementById(id) {
			if (id !== 'app-content') return null;
			return {
				getAttribute(name) {
					if (name === 'data-iv-settings-section') return section;
					if (name === 'data-iv-urls') return urlsJson;
					return null;
				},
			};
		},
	};
}

test('ANCHOR_SECTIONS is frozen and includes license + app-admins', () => {
	const api = loadApi();
	assert.equal(Object.isFrozen(api.ANCHOR_SECTIONS), true);
	assert.equal(api.ANCHOR_SECTIONS['iv-license'], 'license');
	assert.equal(api.ANCHOR_SECTIONS['iv-app-admins'], 'access');
});

test('resolve returns null for unknown / prototype-polluted hashes', () => {
	const api = loadApi();
	const urls = JSON.stringify({
		settingsSections: { access: '/apps/inventorycheck/settings/access', license: '/apps/inventorycheck/settings/license' },
	});
	const doc = stubDoc('access', urls);
	assert.equal(api.resolve(doc, { hash: '#__proto__' }), null);
	assert.equal(api.resolve(doc, { hash: '#constructor' }), null);
	assert.equal(api.resolve(doc, { hash: '#not-a-real-anchor' }), null);
});

test('resolve returns null when already on owning section', () => {
	const api = loadApi();
	const urls = JSON.stringify({
		settingsSections: { license: '/apps/inventorycheck/settings/license' },
	});
	assert.equal(api.resolve(stubDoc('license', urls), { hash: '#iv-license' }), null);
});

test('resolve forwards to catalog URL and preserves fragment', () => {
	const api = loadApi();
	const urls = JSON.stringify({
		settingsSections: {
			access: '/apps/inventorycheck/settings/access',
			license: '/apps/inventorycheck/settings/license',
		},
	});
	assert.equal(
		api.resolve(stubDoc('access', urls), { hash: '#iv-license' }),
		'/apps/inventorycheck/settings/license#iv-license',
	);
});

test('resolve fails closed on malformed JSON or missing map', () => {
	const api = loadApi();
	assert.equal(api.resolve(stubDoc('access', '{'), { hash: '#iv-license' }), null);
	assert.equal(api.resolve(stubDoc('access', '{}'), { hash: '#iv-license' }), null);
	assert.equal(api.resolve(stubDoc('', '{}'), { hash: '#iv-license' }), null);
});
