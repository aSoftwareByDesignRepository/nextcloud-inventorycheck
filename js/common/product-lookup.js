/**
 * Online product lookup — item-dialog panel.
 *
 * Exposes InventoryCheckProductLookup on window. All collaborators (DOM
 * builders, api(), tr()) are injected so the logic is testable in node.
 *
 * Security contract:
 *  - upstream suggestion text is untrusted: assigned via .value / textContent
 *    only, never innerHTML;
 *  - suggestions fill EMPTY fields only and never auto-save;
 *  - stale responses (barcode changed, dialog closed) are discarded.
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */
(function (global) {
	'use strict';

	var GTIN_RE = /^(\d{8}|\d{12}|\d{13}|\d{14})$/;
	var DEBOUNCE_MS = 1000;

	/** Human-readable provider names — ids travel on the wire, labels are UI. */
	var PROVIDER_LABELS = {
		openfoodfacts: 'Open Food Facts',
		openbeautyfacts: 'Open Beauty Facts',
		openpetfoodfacts: 'Open Pet Food Facts',
		openproductsfacts: 'Open Products Facts',
		upcitemdb: 'UPCitemdb',
	};

	function providerLabel(id) {
		return PROVIDER_LABELS[id] || id || '';
	}

	function isGtinFormat(value) {
		return GTIN_RE.test(String(value == null ? '' : value).trim());
	}

	/**
	 * GS1 check digit — weights alternate 3,1 from the rightmost data digit.
	 * Mirrors lib/Service/Lookup/Gtin.php::hasValidCheckDigit exactly so a bad
	 * code is rejected client-side and never reaches the server or upstream
	 * (spec NH-01).
	 */
	function hasValidCheckDigit(value) {
		var code = String(value == null ? '' : value).trim();
		if (!isGtinFormat(code)) {
			return false;
		}
		var len = code.length;
		var sum = 0;
		for (var i = 0; i < len - 1; i++) {
			var digit = code.charCodeAt(len - 2 - i) - 48;
			sum += digit * (i % 2 === 0 ? 3 : 1);
		}
		var expected = (10 - (sum % 10)) % 10;
		return (code.charCodeAt(len - 1) - 48) === expected;
	}

	function isGtin(value) {
		return isGtinFormat(value) && hasValidCheckDigit(value);
	}

	/** User-opened web search — browser navigation only, never fetched by code. */
	function webSearchUrl(code) {
		var trimmed = String(code == null ? '' : code).trim();
		if (!isGtinFormat(trimmed)) {
			return '';
		}
		return 'https://duckduckgo.com/?q=' + encodeURIComponent('"' + trimmed + '"');
	}

	/**
	 * Source link allow-list, mirroring ProductLookupService::safeUrl — the
	 * server already drops unsafe URLs, but the client must never render a
	 * non-http(s) href either (a proxied/stubbed/future provider response is
	 * equally untrusted). Returns '' when unsafe.
	 */
	function safeSourceUrl(url) {
		var u = String(url == null ? '' : url);
		if (u === '' || u.length > 4096 || /[\x00-\x20\x7f]/.test(u)) {
			return '';
		}
		try {
			var parsed = new URL(u);
			if ((parsed.protocol !== 'https:' && parsed.protocol !== 'http:')
				|| parsed.username !== '' || parsed.password !== '') {
				return '';
			}
			return u;
		} catch (e) {
			return '';
		}
	}

	/**
	 * Fill-empty-only suggestion application.
	 *
	 * @param {object} targets {name:{value}, description:{value}, sku:{value}}
	 * @param {object} suggestion {name, description}
	 * @param {string} barcode normalized GTIN used for SKU when empty
	 * @returns {{name: boolean, description: boolean, sku: boolean}} what was filled
	 */
	function fillEmptyFields(targets, suggestion, barcode) {
		var applied = { name: false, description: false, sku: false };
		if (!targets || !suggestion) {
			return applied;
		}
		var name = suggestion.name == null ? '' : String(suggestion.name).trim();
		var description = suggestion.description == null ? '' : String(suggestion.description).trim();
		if (targets.name && name !== '' && String(targets.name.value || '').trim() === '') {
			targets.name.value = name.slice(0, 255);
			applied.name = true;
		}
		if (targets.description && description !== ''
			&& String(targets.description.value || '').trim() === '') {
			targets.description.value = description.slice(0, 10000);
			applied.description = true;
		}
		if (targets.sku && String(targets.sku.value || '').trim() === '' && barcode !== '') {
			targets.sku.value = String(barcode).slice(0, 64);
			applied.sku = true;
		}
		return applied;
	}

	/**
	 * Stale-response guard: a response is dropped when a newer request started,
	 * the dialog was closed (scan input detached), or the barcode changed.
	 */
	function isStaleResponse(gen, latestGen, currentCode, expectedCode, scanConnected) {
		if (scanConnected === false) {
			return true;
		}
		if (gen !== latestGen) {
			return true;
		}
		return String(currentCode || '').trim() !== String(expectedCode || '').trim();
	}

	/**
	 * Debounce scheduling for auto-fire. Returns a cancel handle; the callback
	 * fires at most once per delay window of the latest call.
	 */
	function debounce(fn, delayMs) {
		var timer = null;
		return function () {
			if (timer) {
				clearTimeout(timer);
			}
			timer = setTimeout(function () {
				timer = null;
				fn();
			}, delayMs == null ? DEBOUNCE_MS : delayMs);
		};
	}

	/**
	 * Build the lookup panel for the item dialog.
	 *
	 * deps: {
	 *   tr(text, vars), el(tag, attrs, kids), btn(label, opts), clear(node),
	 *   api(method, url, body), lookupUrl (string), autofire (bool),
	 *   fields: {scan, sku, name, description} — real inputs (have .value,
	 *   .isConnected, .addEventListener)
	 * }
	 */
	function createPanel(deps) {
		var tr = deps.tr;
		var el = deps.el;
		var fields = deps.fields;
		var autofire = deps.autofire === true;

		var status = el('p', {
			className: 'iv-lookup__status',
			role: 'status',
			'aria-live': 'polite',
			text: tr('Scan or type a barcode to look it up online.'),
		});
		var sourceHost = el('p', { className: 'iv-lookup__source' });
		var brandHost = el('p', { className: 'iv-lookup__brand', hidden: '' });
		var webLink = el('a', {
			className: 'iv-lookup__web',
			target: '_blank',
			rel: 'noopener noreferrer',
			'aria-disabled': 'true',
			text: tr('Search the web for this barcode'),
		});
		var lookupBtn = deps.btn(tr('Look up product'), {
			'data-iv-action': 'product-lookup',
			disabled: true,
			onclick: function () { runLookup(); },
		});
		var generation = 0;
		var inFlight = false;

		function syncLinks() {
			var code = fields.scan.value.trim();
			var url = webSearchUrl(code);
			if (isGtin(code)) {
				webLink.href = url;
				webLink.removeAttribute('aria-disabled');
				lookupBtn.disabled = inFlight;
			} else {
				webLink.removeAttribute('href');
				webLink.setAttribute('aria-disabled', 'true');
				lookupBtn.disabled = true;
				if (code !== '') {
					status.textContent = tr('Enter a valid EAN or UPC barcode (8, 12, 13 or 14 digits).');
				} else {
					status.textContent = tr('Scan or type a barcode to look it up online.');
				}
			}
		}

		function runLookup() {
			var code = fields.scan.value.trim();
			if (!isGtin(code) || !deps.lookupUrl) {
				syncLinks();
				return;
			}
			// Dialog closed mid-debounce: scan node is detached — skip the call.
			if (fields.scan.isConnected === false) {
				return;
			}
			var gen = ++generation;
			inFlight = true;
			lookupBtn.disabled = true;
			lookupBtn.setAttribute('aria-busy', 'true');
			deps.clear(sourceHost);
			brandHost.hidden = true;
			status.textContent = tr('Searching product databases…');
			return Promise.resolve(deps.api('POST', deps.lookupUrl, { barcode: code })).then(function (res) {
				if (isStaleResponse(gen, generation, fields.scan.value, code, fields.scan.isConnected)) {
					return;
				}
				if (res && res.found === true && res.name) {
					fillEmptyFields(
						{ name: fields.name, description: fields.description, sku: fields.sku },
						{ name: res.name, description: res.description },
						code
					);
					status.textContent = tr('Suggestion from {provider} applied — review it, then save.', {
						provider: providerLabel(res.provider),
					});
					// NH-03: brand is display-only — it never lands in a field.
					if (typeof res.brand === 'string' && res.brand.trim() !== '') {
						brandHost.textContent = tr('Brand: {brand}', { brand: res.brand });
						brandHost.hidden = false;
					}
					var sourceUrl = safeSourceUrl(res.sourceUrl);
					if (sourceUrl !== '') {
						sourceHost.appendChild(el('a', {
							href: sourceUrl,
							target: '_blank',
							rel: 'noopener noreferrer',
							text: tr('Open product page'),
						}));
					}
				} else {
					status.textContent = tr('No product found for this barcode — fill in the details yourself.');
				}
			}).catch(function (err) {
				if (isStaleResponse(gen, generation, fields.scan.value, code, fields.scan.isConnected)) {
					return;
				}
				status.textContent = (err && err.message)
					|| tr('Lookup unavailable — fill in the details yourself.');
			}).then(function () {
				if (gen === generation) {
					inFlight = false;
					lookupBtn.removeAttribute('aria-busy');
					syncLinks();
				}
			});
		}

		var autoFire = debounce(runLookup);
		fields.scan.addEventListener('input', function () {
			syncLinks();
			// Auto-fire only when the admin enabled it — the explicit button
			// stays available either way (spec BQ-2 + product_lookup_autofire).
			if (autofire) {
				autoFire();
			}
		});
		fields.scan.addEventListener('change', function () {
			if (autofire) {
				runLookup();
			}
		});
		syncLinks();

		return el('div', { className: 'iv-lookup' }, [
			el('p', {
				className: 'iv-field__hint',
				text: tr('Online lookup sends only the barcode to free product databases.'),
			}),
			status,
			brandHost,
			sourceHost,
			el('div', { className: 'iv-lookup__actions' }, [lookupBtn, webLink]),
		]);
	}

	var api = {
		GTIN_RE: GTIN_RE,
		isGtinFormat: isGtinFormat,
		hasValidCheckDigit: hasValidCheckDigit,
		isGtin: isGtin,
		webSearchUrl: webSearchUrl,
		safeSourceUrl: safeSourceUrl,
		providerLabel: providerLabel,
		fillEmptyFields: fillEmptyFields,
		isStaleResponse: isStaleResponse,
		debounce: debounce,
		createPanel: createPanel,
	};
	if (global) {
		global.InventoryCheckProductLookup = api;
	}
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
