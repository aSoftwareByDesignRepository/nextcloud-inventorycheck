/**
 * Legacy /settings#anchor → split sub-page forwarding.
 *
 * ANCHOR_SECTIONS mirrors OCA\InventoryCheck\Service\SettingsSectionCatalog::LEGACY_ANCHORS.
 * Security: target URLs come only from the server-rendered data-iv-urls map;
 * hash text never becomes a URL by itself.
 */
(function (root) {
	'use strict';

	const ANCHOR_SECTIONS = Object.freeze({
		'iv-access-title': 'access',
		'iv-access-restriction': 'access',
		'iv-app-admins': 'access',
		'iv-app-admins-hint': 'access',
		'iv-office-title': 'office',
		'iv-allow-negative': 'office',
		'iv-notify-title': 'notifications',
		'iv-reorder-hint': 'notifications',
		'iv-frac-title': 'quantities',
		'iv-loc-acl': 'location-access',
		'iv-acl-list': 'location-access',
		'iv-flange-maint': 'connections',
		'iv-flange-project': 'connections',
		'iv-flange-default-loc': 'connections',
		'iv-require-adjust-reason': 'policies',
		'iv-require-location-scan': 'policies',
		'iv-license': 'license',
		'iv-license-title': 'license',
		'iv-license-key': 'license',
		'iv-support-us': 'support',
		'iv-support-us-title': 'support',
	});

	/**
	 * @param {object} doc
	 * @param {object} loc
	 * @returns {string|null}
	 */
	function resolve(doc, loc) {
		const hash = String((loc && loc.hash) || '').replace(/^#/, '');
		if (!Object.prototype.hasOwnProperty.call(ANCHOR_SECTIONS, hash)) {
			return null;
		}
		const targetSection = ANCHOR_SECTIONS[hash];
		const rootEl = doc && typeof doc.getElementById === 'function'
			? doc.getElementById('app-content')
			: null;
		if (!rootEl || typeof rootEl.getAttribute !== 'function') {
			return null;
		}
		const currentSection = String(rootEl.getAttribute('data-iv-settings-section') || '');
		if (currentSection === '' || currentSection === targetSection) {
			return null;
		}
		let urls = null;
		try {
			urls = JSON.parse(String(rootEl.getAttribute('data-iv-urls') || '{}'));
		} catch (_err) {
			return null;
		}
		const sectionUrl = urls && urls.settingsSections ? urls.settingsSections[targetSection] : null;
		if (typeof sectionUrl !== 'string' || sectionUrl === '') {
			return null;
		}
		return sectionUrl + '#' + hash;
	}

	function run() {
		const target = resolve(document, window.location);
		if (target) {
			window.location.replace(target);
		}
	}

	const api = Object.freeze({ ANCHOR_SECTIONS, resolve, run });
	if (root) {
		root.InventoryCheckSettingsLegacyRedirect = api;
	}
	if (typeof document !== 'undefined') {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', run);
		} else {
			run();
		}
	}
})(typeof window !== 'undefined' ? window : null);
