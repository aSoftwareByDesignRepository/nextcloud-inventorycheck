/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.InventoryCheckApi / InventoryCheckMessaging contract
 * the synced js/transfer.js expects. The app's own api() unwraps plain
 * data and throws ApiError{code} on failure; the transfer controller
 * answers {success,data} / {success:false,error,context} — the bridge
 * maps both shapes. App-specific — not synced.
 * Loaded before transfer.js on the Transfer settings section only.
 */
(function () {
	'use strict';
	function requestToken() {
		if (window.OC && OC.requestToken) {
			return OC.requestToken;
		}
		var head = document.querySelector('head[data-requesttoken]');
		if (head) {
			return head.getAttribute('data-requesttoken') || '';
		}
		return '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			const ctx = (j && (j.context !== undefined ? j.context : j.data)) || {};
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (ctx && typeof ctx === 'object') ? ctx : {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	function url(path) {
		return (window.OC && OC.generateUrl) ? OC.generateUrl('/apps/inventorycheck' + path) : path;
	}
	window.InventoryCheckApi = {
		url,
		async get(path, params) {
			let u = url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	window.InventoryCheckMessaging = {
		alert(text) {
			if (window.OC && OC.Notification && OC.Notification.showTemporary) {
				OC.Notification.showTemporary(text);
			}
			const el = document.getElementById('iv-transfer-result-live');
			if (el) el.textContent = text;
		},
		polite(text) { this.alert(text); },
	};
})();
