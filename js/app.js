/**
 * InventoryCheck web app (Check-family, no build step).
 *
 * Bootstrap reads #app-content[data-iv-*] and dispatches to a page module.
 * DOM construction only (never innerHTML with user data). Mutations go through
 * api() with CSRF request token; SPEC §7.1 errors become ApiError objects.
 */
(function () {
	'use strict';

	var APP = 'inventorycheck';

	function tr(text, vars) {
		if (typeof window !== 'undefined' && typeof window.t === 'function') {
			return window.t(APP, text, vars);
		}
		var out = text;
		if (vars) {
			Object.keys(vars).forEach(function (k) {
				out = out.replace('{' + k + '}', String(vars[k]));
			});
		}
		return out;
	}

	function kindMeta(kind) {
		switch (kind) {
			case 'receive': return { label: tr('Receive'), badge: 'iv-badge--done' };
			case 'issue': return { label: tr('Issue'), badge: 'iv-badge--overdue' };
			case 'transfer_out': return { label: tr('Transfer out'), badge: 'iv-badge--scheduled' };
			case 'transfer_in': return { label: tr('Transfer in'), badge: 'iv-badge--scheduled' };
			case 'adjust': return { label: tr('Adjust'), badge: 'iv-badge--neutral' };
			default: return { label: String(kind || ''), badge: '' };
		}
	}

	function locationKindLabel(kind) {
		switch (kind) {
			case 'warehouse': return tr('Warehouse');
			case 'shelf': return tr('Shelf');
			case 'van': return tr('Van');
			case 'site': return tr('Site');
			default: return tr('Other');
		}
	}

	function formatQty(n, scale) {
		if (n === null || n === undefined || n === '') return '0';
		if (typeof n === 'string') {
			// Already display-formatted by the API (may include decimals).
			return n;
		}
		var s = Number(scale);
		if (s === 3) {
			var abs = Math.abs(n);
			var whole = Math.floor(abs / 1000);
			var frac = abs % 1000;
			var out = frac === 0 ? String(whole) : (whole + '.' + String(frac).padStart(3, '0')).replace(/0+$/, '').replace(/\.$/, '');
			return n < 0 ? '-' + out : out;
		}
		return String(Number(n));
	}

	function qtyStep(ctx) {
		return (ctx && ctx.qtyScale === 3) ? '0.001' : '1';
	}

	function qtyInputAttrs(ctx, opts) {
		opts = opts || {};
		var attrs = {
			type: 'number',
			max: '1000000',
			required: opts.required === false ? undefined : '',
			className: 'iv-input',
			step: qtyStep(ctx),
			value: opts.value != null ? String(opts.value) : (opts.adjust ? '0' : '1'),
		};
		if (!opts.adjust) {
			attrs.min = opts.min != null ? String(opts.min) : (ctx && ctx.qtyScale === 3 ? '0.001' : '1');
		} else {
			attrs.min = '0';
		}
		return attrs;
	}

	/**
	 * Pure helpers exported for Node unit tests (no DOM).
	 * Reverse is offered once per transfer group — on the out leg only —
	 * so users cannot double-compensate by reversing both legs (UJ-6).
	 */
	function canReverseMovement(ctx, row) {
		if (!row || !row.kind) return false;
		if (row.kind === 'transfer_in') {
			return false;
		}
		if (row.kind === 'receive' || row.kind === 'transfer_out') {
			return true;
		}
		if (row.kind === 'issue' || row.kind === 'adjust') {
			return !!(ctx && (ctx.isOffice || ctx.isAppAdmin));
		}
		return false;
	}

	function movementListQuery(filters) {
		var f = filters || {};
		var limit = f.limit != null ? f.limit : 50;
		var offset = f.offset != null ? f.offset : 0;
		var parts = ['limit=' + limit, 'offset=' + offset];
		if (f.kind) parts.push('kind=' + encodeURIComponent(f.kind));
		if (f.itemId) parts.push('itemId=' + encodeURIComponent(String(f.itemId)));
		if (f.locationId) parts.push('locationId=' + encodeURIComponent(String(f.locationId)));
		if (f.from) parts.push('from=' + encodeURIComponent(String(f.from)));
		if (f.to) parts.push('to=' + encodeURIComponent(String(f.to)));
		if (f.transferGroup) parts.push('transferGroup=' + encodeURIComponent(f.transferGroup));
		return parts.join('&');
	}

	function dateInputToUnix(value, endOfDay) {
		var s = String(value || '').trim();
		if (!s) return null;
		var parts = s.split('-');
		if (parts.length !== 3) return null;
		var y = Number(parts[0]);
		var m = Number(parts[1]);
		var d = Number(parts[2]);
		if (!y || !m || !d) return null;
		var dt = endOfDay
			? new Date(y, m - 1, d, 23, 59, 59)
			: new Date(y, m - 1, d, 0, 0, 0);
		return Math.floor(dt.getTime() / 1000);
	}

	function masterLabel(row, codeKey) {
		if (!row) return '';
		return row.name + ' (' + row[codeKey] + ')';
	}

	/**
	 * Resolve a typed Item/Location query to an id (exact SKU/code, label, or unique name).
	 * Returns '' when empty; null when ambiguous / unknown (caller shows inline error).
	 */
	function resolveMasterId(query, rows, codeKey) {
		var q = String(query || '').trim().toLowerCase();
		if (!q) return '';
		var exactCode = null;
		var exactLabel = null;
		var exactName = null;
		var nameHits = [];
		(rows || []).forEach(function (r) {
			var code = String(r[codeKey] || '').toLowerCase();
			var name = String(r.name || '').toLowerCase();
			var label = masterLabel(r, codeKey).toLowerCase();
			if (code === q) exactCode = r;
			if (label === q) exactLabel = r;
			if (name === q) {
				exactName = r;
				nameHits.push(r);
			} else if (name.indexOf(q) >= 0 || code.indexOf(q) >= 0 || label.indexOf(q) >= 0) {
				nameHits.push(r);
			}
		});
		if (exactCode) return String(exactCode.id);
		if (exactLabel) return String(exactLabel.id);
		if (exactName && nameHits.length === 1) return String(exactName.id);
		if (nameHits.length === 1) return String(nameHits[0].id);
		if (nameHits.length === 0) return null;
		return null;
	}

	var IvApp = {
		kindMeta: kindMeta,
		locationKindLabel: locationKindLabel,
		formatQty: formatQty,
		qtyStep: qtyStep,
		canReverseMovement: canReverseMovement,
		movementListQuery: movementListQuery,
		dateInputToUnix: dateInputToUnix,
		masterLabel: masterLabel,
		resolveMasterId: resolveMasterId,
		isValidCode: function (value, max) {
			return typeof value === 'string'
				&& value.length >= 1
				&& value.length <= max
				&& /^[A-Za-z0-9._/-]+$/.test(value);
		},
	};
	if (typeof window !== 'undefined') {
		window.IvApp = IvApp;
	}
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = IvApp;
	}

	if (typeof document === 'undefined') {
		return;
	}

	function $(sel, root) {
		return (root || document).querySelector(sel);
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'text') {
					node.textContent = attrs[k];
				} else if (k === 'className') {
					node.className = attrs[k];
				} else if (k.slice(0, 2) === 'on' && typeof attrs[k] === 'function') {
					node.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
				} else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
					node.setAttribute(k, attrs[k] === true ? '' : String(attrs[k]));
				}
			});
		}
		(children || []).forEach(function (c) {
			if (c == null) return;
			node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	function ApiError(code, message, details, status) {
		this.name = 'ApiError';
		this.code = code || 'error';
		this.message = message || tr('Something went wrong.');
		this.details = details || [];
		this.status = status || 0;
	}
	ApiError.prototype = Object.create(Error.prototype);

	function requestToken() {
		// Nextcloud 28+ / 34: token lives on OC.requestToken and <head data-requesttoken>.
		// The legacy <meta name="requesttoken"> is gone — empty headers → 412 CSRF on every API call.
		if (typeof window.OC !== 'undefined' && window.OC.requestToken) {
			return window.OC.requestToken;
		}
		var head = document.querySelector('head[data-requesttoken]');
		if (head) {
			return head.getAttribute('data-requesttoken') || '';
		}
		var meta = document.querySelector('head meta[name="requesttoken"]');
		return meta ? meta.getAttribute('content') : '';
	}

	function api(method, url, body) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'requesttoken': requestToken(),
				'Accept': 'application/json',
			},
		};
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(url, opts).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) {
					var err = (data && data.error) || {};
					throw new ApiError(err.code, err.message, err.details, res.status);
				}
				return data;
			});
		});
	}

	function toast(message, isError) {
		var region = $('#iv-toast-region');
		var live = isError ? $('#iv-alert-region') : $('#iv-live-region');
		if (live) {
			live.textContent = '';
			window.setTimeout(function () { live.textContent = message; }, 10);
		}
		if (!region) return;
		var node = el('div', {
			className: 'toast iv-toast ' + (isError ? 'toast--error iv-toast--error' : 'toast--success iv-toast--ok'),
			role: isError ? 'alert' : 'status',
		}, [
			el('div', { className: 'toast-content iv-toast__message' }, [message]),
		]);
		region.appendChild(node);
		setTimeout(function () { node.remove(); }, isError ? 5000 : 3000);
	}

	function setBusy(root, busy) {
		if (!root) return;
		root.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	function emptyState(title, hint, cta) {
		var mainKids = [
			el('h2', { className: 'iv-empty__title', text: title }),
			el('p', { className: 'iv-empty__text', text: hint }),
		];
		var main = el('div', { className: 'iv-empty__main iv-empty-state__main' }, mainKids);
		var kids = [main];
		if (cta) {
			kids.push(el('div', { className: 'iv-empty__action iv-empty-state__action' }, [cta]));
		}
		return el('div', {
			className: 'iv-empty iv-empty-state',
			role: 'status',
		}, kids);
	}

	function readShell() {
		var root = $('#app-content');
		if (!root) return null;
		var urls = {};
		try { urls = JSON.parse(root.getAttribute('data-iv-urls') || '{}'); } catch (e) { urls = {}; }
		return {
			root: root,
			page: root.getAttribute('data-iv-page') || '',
			entityId: root.getAttribute('data-iv-entity-id'),
			userId: root.getAttribute('data-iv-current-user') || '',
			isAppAdmin: root.getAttribute('data-iv-is-app-admin') === '1',
			isSystemAdmin: root.getAttribute('data-iv-is-system-admin') === '1',
			isOffice: root.getAttribute('data-iv-is-office') === '1',
			allowNegative: root.getAttribute('data-iv-allow-negative') === '1',
			locationReorderHintEnabled: root.getAttribute('data-iv-location-reorder-hint') === '1',
			qtyScale: Number(root.getAttribute('data-iv-qty-scale') || '0') || 0,
			urls: urls,
			mount: $('#iv-page-root'),
			actions: $('#iv-page-actions'),
		};
	}

	function clear(node) {
		while (node.firstChild) node.removeChild(node.firstChild);
	}

	function btn(label, opts) {
		opts = opts || {};
		return el('button', {
			type: opts.type || 'button',
			className: 'button' + (opts.primary ? ' primary' : '') + (opts.className ? ' ' + opts.className : ''),
			disabled: !!opts.disabled,
			onclick: opts.onclick,
		}, [label]);
	}

	function field(labelText, input, opts) {
		opts = opts || {};
		var id = input.getAttribute('id') || ('iv-f-' + Math.random().toString(36).slice(2, 8));
		input.setAttribute('id', id);
		input.removeAttribute('aria-invalid');
		var errId = id + '-err';
		var err = el('p', {
			className: 'iv-field__error',
			id: errId,
			hidden: '',
			role: 'alert',
		});
		input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), errId].filter(Boolean).join(' ').trim());
		var wrap = el('div', {
			className: 'iv-field',
			'data-iv-field': opts.name || input.getAttribute('name') || id,
		}, [
			el('label', { className: 'iv-field__label', for: id, text: labelText }),
			input,
			err,
		]);
		wrap._ivInput = input;
		wrap._ivError = err;
		return wrap;
	}

	function clearFieldErrors(root) {
		if (!root) return;
		root.querySelectorAll('.iv-field__error').forEach(function (node) {
			node.hidden = true;
			node.textContent = '';
		});
		root.querySelectorAll('[aria-invalid="true"]').forEach(function (node) {
			node.removeAttribute('aria-invalid');
		});
	}

	function applyFieldErrors(root, details, fallbackMessage) {
		clearFieldErrors(root);
		if (!details || !details.length) return false;
		var applied = false;
		details.forEach(function (d) {
			var name = d && d.field ? String(d.field) : '';
			if (!name) return;
			var wrap = root.querySelector('[data-iv-field="' + name + '"]');
			if (!wrap || !wrap._ivError || !wrap._ivInput) return;
			wrap._ivInput.setAttribute('aria-invalid', 'true');
			wrap._ivError.hidden = false;
			var text = d.message || fallbackMessage || '';
			if (!text && d.code === 'unknown_user') {
				text = tr('This Nextcloud user does not exist.');
			} else if (!text && d.code === 'unknown_group') {
				text = tr('This Nextcloud group does not exist.');
			} else if (!text) {
				text = d.code || tr('Please check this field.');
			}
			wrap._ivError.textContent = text;
			applied = true;
		});
		return applied;
	}

	function dialog(title, bodyNodes, onConfirm, confirmLabel) {
		var previouslyFocused = document.activeElement;
		var overlay = el('div', { className: 'modal-backdrop iv-dialog-overlay', role: 'presentation' });
		var dialogEl = el('div', {
			className: 'modal iv-dialog',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': 'iv-dialog-title',
		});
		document.body.style.overflow = 'hidden';
		['header', 'app-navigation', 'iv-main-content'].forEach(function (id) {
			var node = document.getElementById(id);
			if (node) node.setAttribute('inert', '');
		});
		var onKey = null;
		var close = function () {
			if (onKey) {
				document.removeEventListener('keydown', onKey);
				onKey = null;
			}
			overlay.remove();
			document.body.style.overflow = '';
			['header', 'app-navigation', 'iv-main-content'].forEach(function (id) {
				var node = document.getElementById(id);
				if (node) node.removeAttribute('inert');
			});
			if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
				try { previouslyFocused.focus(); } catch (e) { /* ignore */ }
			}
		};
		dialogEl.appendChild(el('header', { className: 'iv-dialog__header' }, [
			el('h2', { id: 'iv-dialog-title', className: 'iv-dialog__title', text: title }),
			btn(tr('Close'), { className: 'iv-dialog__close', onclick: close }),
		]));
		dialogEl.appendChild(el('div', { className: 'iv-dialog__body' }, bodyNodes));
		var bodyEl = dialogEl.querySelector('.iv-dialog__body');
		var cancelBtn = btn(tr('Cancel'), { onclick: close });
		var confirmBtn = btn(confirmLabel || tr('Save'), {
			primary: true,
			onclick: function () {
				if (confirmBtn.disabled) {
					return;
				}
				clearFieldErrors(bodyEl);
				confirmBtn.disabled = true;
				cancelBtn.disabled = true;
				confirmBtn.setAttribute('aria-busy', 'true');
				Promise.resolve(onConfirm()).then(function (ok) {
					if (ok !== false) {
						close();
						return;
					}
					confirmBtn.disabled = false;
					cancelBtn.disabled = false;
					confirmBtn.removeAttribute('aria-busy');
				}).catch(function (err) {
					confirmBtn.disabled = false;
					cancelBtn.disabled = false;
					confirmBtn.removeAttribute('aria-busy');
					var inline = applyFieldErrors(bodyEl, err && err.details, err && err.message);
					toast(err.message || tr('Something went wrong.'), true);
					if (inline) {
						var bad = bodyEl.querySelector('[aria-invalid="true"]');
						if (bad && typeof bad.focus === 'function') bad.focus();
					}
				});
			},
		});
		dialogEl.appendChild(el('div', { className: 'iv-dialog__actions' }, [
			cancelBtn,
			confirmBtn,
		]));
		overlay.appendChild(dialogEl);
		overlay.addEventListener('click', function (ev) {
			if (ev.target === overlay) close();
		});
		onKey = function (ev) {
			if (ev.key === 'Escape') {
				ev.preventDefault();
				close();
				return;
			}
			if (ev.key !== 'Tab') {
				return;
			}
			var nodes = dialogEl.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
			var list = [];
			for (var i = 0; i < nodes.length; i++) {
				var n = nodes[i];
				if (!n.disabled && n.getAttribute('aria-hidden') !== 'true') {
					list.push(n);
				}
			}
			if (!list.length) {
				return;
			}
			var first = list[0];
			var last = list[list.length - 1];
			if (ev.shiftKey && document.activeElement === first) {
				ev.preventDefault();
				last.focus();
			} else if (!ev.shiftKey && document.activeElement === last) {
				ev.preventDefault();
				first.focus();
			}
		};
		document.addEventListener('keydown', onKey);
		document.body.appendChild(overlay);
		var focusable = dialogEl.querySelector('input, select, textarea, button');
		if (focusable) focusable.focus();
		return overlay;
	}

	function tableOrCards(columns, rows) {
		var wrap = el('div', { className: 'iv-table-wrap' });
		var table = el('table', { className: 'iv-table' });
		table.appendChild(el('thead', null, [
			el('tr', null, columns.map(function (c) {
				return el('th', { scope: 'col', text: c.label });
			})),
		]));
		var tbody = el('tbody');
		rows.forEach(function (row) {
			tbody.appendChild(el('tr', null, columns.map(function (c) {
				var cell = c.render ? c.render(row) : (row[c.key] == null ? '' : String(row[c.key]));
				return el('td', { 'data-label': c.label }, [
					typeof cell === 'string' || typeof cell === 'number'
						? document.createTextNode(String(cell))
						: cell,
				]);
			})));
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	// ── Pages ───────────────────────────────────────────────────────────

	function renderDashboard(ctx) {
		var mount = ctx.mount;
		setBusy(mount, true);
		clear(mount);
		var fetches = [
			api('GET', ctx.urls.api.lowStock + '?limit=20&offset=0'),
			api('GET', ctx.urls.api.movements + '?limit=20&offset=0'),
		];
		if (!ctx.allowNegative) {
			fetches.push(api('GET', ctx.urls.api.balances + '?negative=1&limit=20&offset=0'));
		}
		if (ctx.locationReorderHintEnabled && ctx.urls.api.lowStockPerLocation) {
			fetches.push(api('GET', ctx.urls.api.lowStockPerLocation + '?limit=20&offset=0'));
		}
		Promise.all(fetches).then(function (results) {
			var low = results[0];
			var mov = results[1];
			var idx = 2;
			var negatives = { data: [] };
			if (!ctx.allowNegative) {
				negatives = results[idx++] || { data: [] };
			}
			var perLoc = { data: [] };
			if (ctx.locationReorderHintEnabled && ctx.urls.api.lowStockPerLocation) {
				perLoc = results[idx] || { data: [] };
			}
			clear(mount);
			setBusy(mount, false);

			clear(ctx.actions);
			if (ctx.isOffice || ctx.isAppAdmin) {
				ctx.actions.appendChild(btn(tr('Receive stock'), {
					primary: true,
					onclick: function () { openReceiveDialog(ctx); },
				}));
			}
			ctx.actions.appendChild(btn(tr('Issue stock'), {
				onclick: function () { openIssueDialog(ctx); },
			}));
			ctx.actions.appendChild(btn(tr('Transfer stock'), {
				onclick: function () { openTransferDialog(ctx); },
			}));
			if (ctx.isOffice || ctx.isAppAdmin) {
				ctx.actions.appendChild(btn(tr('Adjust stock'), {
					onclick: function () { openAdjustDialog(ctx); },
				}));
			}

			return Promise.all([
				api('GET', ctx.urls.api.items + '?limit=200&offset=0&active=1'),
				api('GET', ctx.urls.api.locations + '?limit=200&offset=0&active=1'),
			]).then(function (lookups) {
				var itemMap = indexById(lookups[0].data);
				var locMap = indexById(lookups[1].data);

				if (!ctx.allowNegative && negatives.data && negatives.data.length) {
					mount.appendChild(el('section', {
						className: 'iv-section',
						'aria-labelledby': 'iv-neg-title',
					}, [
						el('h2', {
							id: 'iv-neg-title',
							className: 'iv-section__title',
							text: tr('Negative balances'),
						}),
						el('p', {
							className: 'iv-muted',
							text: tr('Negative stock is turned off. Adjust or receive to clear these.'),
						}),
						tableOrCards(
							[
								{ label: tr('Item'), render: function (r) {
									return labelFromMap(itemMap, r.itemId, 'sku');
								} },
								{ label: tr('Location'), render: function (r) {
									return labelFromMap(locMap, r.locationId, 'code');
								} },
								{ label: tr('On hand'), render: function (r) {
									return el('span', {
										className: 'iv-badge iv-badge--overdue',
										text: formatQty(r.qty) + ' — ' + tr('negative stock'),
									});
								} },
							],
							negatives.data
						),
					]));
				}

				mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-low-title' }, [
					el('h2', { id: 'iv-low-title', className: 'iv-section__title', text: tr('Low stock') }),
					low.data.length === 0
						? el('p', { className: 'iv-muted', text: tr('Nothing is below reorder level.') })
						: tableOrCards(
							[
								{ label: tr('Item'), render: function (r) { return r.item.name; } },
								{ label: tr('SKU'), render: function (r) { return r.item.sku; } },
								{ label: tr('On hand'), render: function (r) { return formatQty(r.totalQty); } },
								{ label: tr('Reorder at'), render: function (r) { return formatQty(r.reorderLevel); } },
							],
							low.data
						),
				]));

				if (ctx.locationReorderHintEnabled) {
					mount.appendChild(el('section', {
						className: 'iv-section',
						'aria-labelledby': 'iv-perloc-title',
					}, [
						el('h2', {
							id: 'iv-perloc-title',
							className: 'iv-section__title',
							text: tr('Low stock by location'),
						}),
						el('p', {
							className: 'iv-muted',
							text: tr('Hints where a single location is below the item reorder level.'),
						}),
						(!perLoc.data || perLoc.data.length === 0)
							? el('p', { className: 'iv-muted', text: tr('No per-location shortfalls.') })
							: tableOrCards(
								[
									{ label: tr('Item'), render: function (r) { return r.item.name; } },
									{ label: tr('Location'), render: function (r) {
										return labelFromMap(locMap, r.locationId, 'code');
									} },
									{ label: tr('On hand'), render: function (r) { return formatQty(r.qty); } },
									{ label: tr('Reorder at'), render: function (r) { return formatQty(r.reorderLevel); } },
								],
								perLoc.data
							),
					]));
				}

				mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-mov-title' }, [
					el('h2', { id: 'iv-mov-title', className: 'iv-section__title', text: tr('Recent movements') }),
					mov.data.length === 0
						? emptyState(
							tr('No movements yet'),
							tr('Receive stock to start the ledger.'),
							(ctx.isOffice || ctx.isAppAdmin)
								? btn(tr('Receive stock'), { primary: true, onclick: function () { openReceiveDialog(ctx); } })
								: null
						)
						: tableOrCards(
							[
								{ label: tr('Kind'), render: function (r) {
									var m = kindMeta(r.kind);
									return el('span', { className: 'iv-badge ' + m.badge, text: m.label });
								} },
								{ label: tr('Item'), render: function (r) {
									return labelFromMap(itemMap, r.itemId, 'sku');
								} },
								{ label: tr('Location'), render: function (r) {
									return labelFromMap(locMap, r.locationId, 'code');
								} },
								{ label: tr('Delta'), render: function (r) { return formatQty(r.qtyDelta); } },
								{ label: tr('After'), render: function (r) {
									var n = Number(r.qtyAfter);
									if (n < 0) {
										return el('span', {
											className: 'iv-badge iv-badge--overdue',
											text: formatQty(n) + ' — ' + tr('negative stock'),
										});
									}
									return formatQty(n);
								} },
							],
							mov.data
						),
				]));
			});
		}).catch(function (err) {
			setBusy(mount, false);
			clear(mount);
			mount.appendChild(emptyState(tr('Could not load dashboard'), err.message || '', null));
		});
	}

	function indexById(rows) {
		var map = {};
		(rows || []).forEach(function (r) { map[String(r.id)] = r; });
		return map;
	}

	function labelFromMap(map, id, codeKey) {
		var row = map[String(id)];
		if (!row) return '#' + id;
		return row.name + ' (' + row[codeKey] + ')';
	}

	function movementApi(ctx, kind) {
		var map = {
			receive: ctx.urls.api.movementReceive,
			issue: ctx.urls.api.movementIssue,
			transfer: ctx.urls.api.movementTransfer,
			adjust: ctx.urls.api.movementAdjust,
		};
		return map[kind] || ctx.urls.api.movements;
	}

	function exportCsvHref(ctx, kind) {
		var lang = (document.documentElement.getAttribute('lang') || 'en').toLowerCase().slice(0, 2);
		var href = ctx.urls.api.export + '?kind=' + encodeURIComponent(kind);
		if (lang === 'de') {
			href += '&lang=de';
		}
		return href;
	}

	function byCodeUrl(ctx, code) {
		var tmpl = ctx.urls.api.itemByCode || '';
		return tmpl.replace('__CODE__', encodeURIComponent(code));
	}

	function entityUrl(base, id) {
		return String(base || '').replace(/\/?$/, '/') + id;
	}

	function labelPrintUrl(ctx, id) {
		return urlWithId(ctx.urls.api.itemLabelPrint, id);
	}

	function labelSvgUrl(ctx, id) {
		return urlWithId(ctx.urls.api.itemLabel, id);
	}

	/**
	 * Substitute the `0` placeholder id baked into route templates generated
	 * with `['id' => 0]` (or `lineId`/`locationId` => 0) — same trick as
	 * {@see labelPrintUrl}, generalised for the Wave A–B endpoints.
	 */
	function urlWithId(tmpl, id) {
		return String(tmpl || '').replace(/\/0(\/|$)/, '/' + id + '$1');
	}

	function fillSelect(selectEl, rows, valueKey, labelFn, placeholder) {
		clear(selectEl);
		selectEl.appendChild(el('option', { value: '', text: placeholder }));
		(rows || []).forEach(function (r) {
			selectEl.appendChild(el('option', {
				value: String(r[valueKey]),
				text: labelFn(r),
			}));
		});
	}

	function fillDatalist(listEl, rows, codeKey) {
		clear(listEl);
		(rows || []).forEach(function (r) {
			listEl.appendChild(el('option', {
				value: masterLabel(r, codeKey),
			}));
		});
	}

	/** Fetch missing item/location rows so the table never shows raw #id stubs. */
	function hydrateMaps(ctx, itemMap, locMap, movements) {
		var missingItems = {};
		var missingLocs = {};
		(movements || []).forEach(function (r) {
			if (r.itemId && !itemMap[String(r.itemId)]) missingItems[String(r.itemId)] = true;
			if (r.locationId && !locMap[String(r.locationId)]) missingLocs[String(r.locationId)] = true;
		});
		var jobs = [];
		Object.keys(missingItems).slice(0, 40).forEach(function (id) {
			jobs.push(
				api('GET', entityUrl(ctx.urls.api.items, id)).then(function (row) {
					if (row && row.id != null) itemMap[String(row.id)] = row;
				}).catch(function () { /* keep #id fallback */ })
			);
		});
		Object.keys(missingLocs).slice(0, 40).forEach(function (id) {
			jobs.push(
				api('GET', entityUrl(ctx.urls.api.locations, id)).then(function (row) {
					if (row && row.id != null) locMap[String(row.id)] = row;
				}).catch(function () { /* keep #id fallback */ })
			);
		});
		return jobs.length ? Promise.all(jobs) : Promise.resolve();
	}

	function loadMasters(ctx) {
		return Promise.all([
			api('GET', ctx.urls.api.items + '?limit=200&offset=0&active=1'),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0&active=1'),
			api('GET', ctx.urls.api.favouriteLocations).catch(function () { return { data: [] }; }),
		]).then(function (res) {
			var favIds = (res[2].data || []).map(function (f) { return Number(f.id); });
			var locations = (res[1].data || []).slice().sort(function (a, b) {
				var af = favIds.indexOf(Number(a.id)) >= 0 ? 0 : 1;
				var bf = favIds.indexOf(Number(b.id)) >= 0 ? 0 : 1;
				if (af !== bf) return af - bf;
				return String(a.name).localeCompare(String(b.name));
			});
			return {
				items: res[0].data || [],
				locations: locations,
				favouriteIds: favIds,
			};
		});
	}

	function announceBalances(balances) {
		if (!balances || !balances.length) return;
		balances.forEach(function (b) {
			var key = b.itemId + ':' + b.locationId;
			var cell = document.querySelector('[data-iv-balance="' + key + '"]');
			if (cell) {
				cell.textContent = formatQty(b.qty);
				cell.classList.add('iv-flash');
				window.setTimeout(function () { cell.classList.remove('iv-flash'); }, 1400);
			}
		});
		var live = $('#iv-live-region');
		if (live) {
			live.textContent = tr('Stock updated.') + ' ' + balances.map(function (b) {
				return formatQty(b.qty);
			}).join(', ');
		}
	}

	function refreshAfterMutation(ctx, result) {
		announceBalances(result && result.balances);
		switch (ctx.page) {
			case 'movements': renderMovements(ctx); break;
			case 'item-detail': renderItemDetail(ctx); break;
			case 'location-detail': renderLocationDetail(ctx); break;
			case 'items': renderItems(ctx); break;
			case 'locations': renderLocations(ctx); break;
			default: renderDashboard(ctx); break;
		}
	}

	function openMovementDialog(ctx, opts) {
		var itemSelect = el('select', { required: '', className: 'iv-input', 'aria-required': 'true' });
		var locSelect = el('select', { required: '', className: 'iv-input', 'aria-required': 'true' });
		var toSelect = opts.transfer
			? el('select', { required: '', className: 'iv-input', 'aria-required': 'true' })
			: null;
		var codePaste = el('input', {
			type: 'text',
			className: 'iv-input',
			placeholder: tr('Paste SKU or scan code'),
			'aria-label': tr('Paste SKU or scan code'),
			autocomplete: 'off',
			spellcheck: 'false',
		});
		var qty = el('input', qtyInputAttrs(ctx, { adjust: !!opts.adjust }));
		var lotCode = el('input', {
			type: 'text',
			className: 'iv-input',
			maxlength: '64',
			placeholder: tr('Lot / serial (when required)'),
			'aria-label': tr('Lot or serial number'),
			autocomplete: 'off',
			spellcheck: 'false',
		});
		var reason = el('input', { type: 'text', maxlength: '512', className: 'iv-input' });
		var hint = el('p', {
			className: 'iv-field__hint',
			id: 'iv-bycode-hint',
			text: tr('Tip: wedge scanners type into the paste field — we resolve the item automatically.'),
		});
		codePaste.setAttribute('aria-describedby', 'iv-bycode-hint');

		codePaste.addEventListener('change', function () {
			var code = (codePaste.value || '').trim();
			if (!code) return;
			api('GET', byCodeUrl(ctx, code)).then(function (item) {
				itemSelect.value = String(item.id);
				toast(tr('Item found:') + ' ' + item.name);
			}).catch(function (err) {
				toast(err.message || tr('Code not found.'), true);
			});
		});

		loadMasters(ctx).then(function (masters) {
			fillSelect(itemSelect, masters.items, 'id', function (r) {
				return r.name + ' — ' + r.sku;
			}, tr('Choose an item'));
			fillSelect(locSelect, masters.locations, 'id', function (r) {
				var star = (masters.favouriteIds || []).indexOf(Number(r.id)) >= 0 ? '★ ' : '';
				return star + r.name + ' — ' + r.code;
			}, tr('Choose a location'));
			if (toSelect) {
				fillSelect(toSelect, masters.locations, 'id', function (r) {
					var star = (masters.favouriteIds || []).indexOf(Number(r.id)) >= 0 ? '★ ' : '';
					return star + r.name + ' — ' + r.code;
				}, tr('Choose destination'));
			}

			var fields = [
				field(tr('Find by code'), codePaste, { name: 'code' }),
				hint,
				field(tr('Item'), itemSelect, { name: 'itemId' }),
				field(opts.transfer ? tr('From location') : tr('Location'), locSelect, { name: 'locationId' }),
			];
			if (toSelect) {
				fields.push(field(tr('To location'), toSelect, { name: 'toLocationId' }));
			}
			fields.push(field(opts.adjust ? tr('New quantity') : tr('Quantity'), qty, { name: opts.adjust ? 'qty' : 'qty' }));
			fields.push(field(tr('Lot / serial (optional)'), lotCode, { name: 'lotCode' }));
			fields.push(el('p', {
				className: 'iv-field__hint',
				text: tr('Required when the item tracks lots or serial numbers. Serial items always use quantity 1.'),
			}));
			fields.push(field(tr('Reason (optional)'), reason, { name: 'reason' }));

			if (opts.prefill) {
				if (opts.prefill.itemId) itemSelect.value = String(opts.prefill.itemId);
				if (opts.prefill.locationId) locSelect.value = String(opts.prefill.locationId);
				if (opts.prefill.toLocationId && toSelect) toSelect.value = String(opts.prefill.toLocationId);
				if (opts.prefill.qty != null) qty.value = String(opts.prefill.qty);
				if (opts.prefill.lotCode) lotCode.value = String(opts.prefill.lotCode);
				if (opts.prefill.reason) reason.value = opts.prefill.reason;
			}
			// Wave B4: default from favourites when no prefill location.
			if ((!opts.prefill || !opts.prefill.locationId) && masters.favouriteIds && masters.favouriteIds.length) {
				locSelect.value = String(masters.favouriteIds[0]);
			}
			if (toSelect && (!opts.prefill || !opts.prefill.toLocationId) && masters.favouriteIds && masters.favouriteIds.length > 1) {
				var dest = masters.favouriteIds.find(function (id) {
					return String(id) !== String(locSelect.value);
				});
				if (dest) toSelect.value = String(dest);
			}

			function qtyPayload() {
				return qty.value.trim();
			}
			function lotPayload() {
				var v = lotCode.value.trim();
				return v === '' ? null : v;
			}

			dialog(opts.title, fields, function () {
				var itemId = Number(itemSelect.value);
				var locationId = Number(locSelect.value);
				if (!itemId || !locationId) {
					return Promise.reject(new ApiError('validation_failed', tr('Choose an item and location.')));
				}
				if (opts.transfer) {
					var toLocationId = Number(toSelect.value);
					if (!toLocationId || toLocationId === locationId) {
						return Promise.reject(new ApiError('validation_failed', tr('Choose two different locations.')));
					}
					return api('POST', movementApi(ctx, 'transfer'), {
						itemId: itemId,
						fromLocationId: locationId,
						toLocationId: toLocationId,
						qty: qtyPayload(),
						lotCode: lotPayload(),
						reason: reason.value || null,
					}).then(function (result) {
						toast(tr('Stock transferred.'));
						refreshAfterMutation(ctx, result);
					});
				}
				if (opts.adjust) {
					// Reverse of an adjust posts delta mode; stocktake UI posts set mode.
					if (opts.prefill && opts.prefill.mode === 'delta') {
						return api('POST', movementApi(ctx, 'adjust'), {
							itemId: itemId,
							locationId: locationId,
							mode: 'delta',
							qtyDelta: opts.prefill.qtyDelta,
							lotCode: lotPayload(),
							reason: reason.value || null,
						}).then(function (result) {
							toast(tr('Stock adjusted.'));
							refreshAfterMutation(ctx, result);
						});
					}
					return api('POST', movementApi(ctx, 'adjust'), {
						itemId: itemId,
						locationId: locationId,
						mode: 'set',
						qty: qtyPayload(),
						lotCode: lotPayload(),
						reason: reason.value || null,
					}).then(function (result) {
						toast(tr('Stock adjusted.'));
						refreshAfterMutation(ctx, result);
					});
				}
				return api('POST', movementApi(ctx, opts.path), {
					itemId: itemId,
					locationId: locationId,
					qty: qtyPayload(),
					lotCode: lotPayload(),
					reason: reason.value || null,
				}).then(function (result) {
					toast(opts.okToast || tr('Stock updated.'));
					refreshAfterMutation(ctx, result);
				});
			}, opts.confirm);
		}).catch(function (err) {
			toast(err.message || tr('Could not load items.'), true);
		});
	}

	function openReceiveDialog(ctx, prefill) {
		openMovementDialog(ctx, {
			title: tr('Receive stock'),
			path: 'receive',
			confirm: tr('Receive'),
			okToast: tr('Stock received.'),
			prefill: prefill || null,
		});
	}

	function openIssueDialog(ctx, prefill) {
		openMovementDialog(ctx, {
			title: tr('Issue stock'),
			path: 'issue',
			confirm: tr('Issue'),
			okToast: tr('Stock issued.'),
			prefill: prefill || null,
		});
	}

	function openTransferDialog(ctx, prefill) {
		openMovementDialog(ctx, {
			title: tr('Transfer stock'),
			transfer: true,
			confirm: tr('Transfer'),
			okToast: tr('Stock transferred.'),
			prefill: prefill || null,
		});
	}

	function openAdjustDialog(ctx, prefill) {
		openMovementDialog(ctx, {
			title: tr('Adjust stock'),
			adjust: true,
			confirm: tr('Adjust'),
			okToast: tr('Stock adjusted.'),
			prefill: prefill || null,
		});
	}

	function openReverseFromMovement(ctx, row) {
		var reason = 'reversal of #' + row.id;
		var qty = Math.abs(Number(row.qtyDelta) || 0);
		if (!qty) {
			toast(tr('Nothing to reverse.'), true);
			return;
		}
		if (row.kind === 'receive') {
			openIssueDialog(ctx, {
				itemId: row.itemId,
				locationId: row.locationId,
				qty: qty,
				reason: reason,
			});
			return;
		}
		if (row.kind === 'issue') {
			openReceiveDialog(ctx, {
				itemId: row.itemId,
				locationId: row.locationId,
				qty: qty,
				reason: reason,
			});
			return;
		}
		if (row.kind === 'adjust') {
			// Compensate with delta of −qtyDelta against *current* balance (S3).
			// Never mode=set to the historical pre-adjust qty — later movements
			// would be silently clobbered (UJ-6 / ledger safety).
			openAdjustDialog(ctx, {
				itemId: row.itemId,
				locationId: row.locationId,
				mode: 'delta',
				qtyDelta: -Number(row.qtyDelta),
				reason: reason,
			});
			return;
		}
		if (row.kind === 'transfer_out') {
			openTransferDialog(ctx, {
				itemId: row.itemId,
				locationId: row.counterpartyLocId,
				toLocationId: row.locationId,
				qty: qty,
				reason: reason,
			});
		}
	}

	/** Wave A2: dry-run then commit CSV import, both against the pasted/loaded text. */
	function openImportDialog(ctx) {
		var fileInput = el('input', {
			type: 'file',
			accept: '.csv,text/csv',
			className: 'iv-input',
			'aria-label': tr('CSV file'),
		});
		var csvText = el('textarea', {
			className: 'iv-input',
			rows: '6',
			placeholder: tr('Paste CSV here, or choose a file above.'),
		});
		var skipErrors = el('input', {
			type: 'checkbox',
			className: 'iv-switch__input',
			id: 'iv-import-skip',
		});
		var resultBox = el('div', { className: 'iv-import-result', 'aria-live': 'polite' });

		function readSelectedCsv() {
			if (fileInput.files && fileInput.files[0]) {
				return fileInput.files[0].text();
			}
			return Promise.resolve(csvText.value || '');
		}

		function runDryRun() {
			clear(resultBox);
			resultBox.appendChild(el('p', { className: 'iv-muted', text: tr('Checking…') }));
			readSelectedCsv().then(function (text) {
				if (!text.trim()) {
					clear(resultBox);
					resultBox.appendChild(el('p', { className: 'iv-muted', text: tr('Choose a file or paste CSV text first.') }));
					return;
				}
				return api('POST', ctx.urls.api.importDryRun, { csv: text }).then(function (report) {
					clear(resultBox);
					resultBox.appendChild(el('p', {
						text: tr('{ok} row(s) look fine.', { ok: String(report.ok) }),
					}));
					if (report.errors && report.errors.length) {
						resultBox.appendChild(el('ul', { className: 'iv-import-errors' }, report.errors.slice(0, 20).map(function (e) {
							return el('li', { text: tr('Line {line}: {message}', { line: String(e.line), message: e.message || e.code }) });
						})));
					}
				});
			}).catch(function (err) {
				clear(resultBox);
				resultBox.appendChild(el('p', { className: 'iv-muted', text: err.message || tr('Could not check the file.') }));
			});
		}

		dialog(tr('Import items from CSV'), [
			el('p', {
				className: 'iv-field__hint',
				text: tr('Columns: sku, scan_code, name, description, uom, reorder_level, active, supplier_note, last_price_minor, opening_location_code, opening_qty. German headers also work.'),
			}),
			field(tr('CSV file'), fileInput, { name: 'file' }),
			field(tr('Or paste CSV text'), csvText, { name: 'csv' }),
			btn(tr('Check for errors'), { onclick: runDryRun }),
			resultBox,
			el('label', { className: 'iv-switch', for: 'iv-import-skip' }, [
				skipErrors,
				el('span', { className: 'iv-switch__label', text: tr('Skip rows with errors instead of stopping') }),
			]),
		], function () {
			return readSelectedCsv().then(function (text) {
				if (!text.trim()) {
					return Promise.reject(new ApiError('validation_failed', tr('Choose a file or paste CSV text.')));
				}
				return api('POST', ctx.urls.api.importCommit, { csv: text, skipErrors: skipErrors.checked }).then(function (report) {
					toast(tr('Imported: {created} new, {received} received, {skipped} skipped.', {
						created: String(report.created),
						received: String(report.received),
						skipped: String(report.skipped),
					}));
					renderItems(ctx);
				});
			});
		}, tr('Import'));
	}

	function renderItems(ctx) {
		var mount = ctx.mount;
		var selected = {};
		var bulkBtn = null;
		function syncBulkBtn() {
			var count = Object.keys(selected).length;
			if (count === 0) {
				if (bulkBtn && bulkBtn.parentNode) {
					bulkBtn.parentNode.removeChild(bulkBtn);
				}
				bulkBtn = null;
				return;
			}
			if (!bulkBtn) {
				bulkBtn = btn(tr('Print labels ({count})', { count: String(count) }), {
					onclick: function () {
						var ids = Object.keys(selected);
						if (!ids.length) return;
						window.open(ctx.urls.api.itemBulkLabels + '?ids=' + ids.join(','), '_blank', 'noopener');
					},
				});
				// Prefer omit over disable: insert after New item when present.
				if (ctx.actions.firstChild && ctx.actions.firstChild.nextSibling) {
					ctx.actions.insertBefore(bulkBtn, ctx.actions.firstChild.nextSibling);
				} else {
					ctx.actions.appendChild(bulkBtn);
				}
			} else {
				bulkBtn.textContent = tr('Print labels ({count})', { count: String(count) });
			}
		}
		setBusy(mount, true);
		clear(mount);
		if (ctx.isOffice) {
			clear(ctx.actions);
			ctx.actions.appendChild(btn(tr('New item'), {
				primary: true,
				onclick: function () { openItemDialog(ctx, null); },
			}));
			ctx.actions.appendChild(el('a', {
				href: exportCsvHref(ctx, 'items'),
				className: 'button',
				text: tr('Export CSV'),
			}));
			ctx.actions.appendChild(btn(tr('Import CSV'), {
				onclick: function () { openImportDialog(ctx); },
			}));
		}
		var q = el('input', {
			id: 'iv-items-q',
			type: 'search',
			className: 'iv-input form-input',
			placeholder: tr('Search name or SKU'),
			autocomplete: 'off',
		});
		var listHost = el('div', { id: 'iv-items-list', className: 'iv-card' });
		var filterActiveHost = el('div', { id: 'iv-items-filter-active' });
		var loadSeq = 0;

		function appliedQuery() {
			return (q.value || '').trim();
		}

		function syncFilterActive() {
			clear(filterActiveHost);
			var term = appliedQuery();
			if (!term) return;
			filterActiveHost.appendChild(el('div', {
				className: 'iv-callout iv-callout--info iv-filter-active',
				role: 'status',
			}, [
				el('p', {
					className: 'iv-callout__text',
					text: tr('Search') + ': ' + term,
				}),
				btn(tr('Clear'), {
					onclick: function () {
						q.value = '';
						load();
					},
				}),
			]));
		}

		mount.appendChild(el('section', {
			id: 'iv-items-filter-panel',
			className: 'iv-card iv-filter-panel',
			'aria-labelledby': 'iv-items-filter-title',
		}, [
			el('header', { className: 'iv-filter-panel__head' }, [
				el('div', { className: 'iv-filter-panel__head-text' }, [
					el('h2', { id: 'iv-items-filter-title', text: tr('Filter') }),
					el('p', {
						className: 'iv-filter-panel__intro',
						text: tr('Find items by name or SKU.'),
					}),
				]),
			]),
			el('div', { className: 'iv-filter-panel__body' }, [
				el('form', {
					className: 'iv-filter-panel__form iv-filterbar',
					role: 'search',
					'aria-label': tr('Search items'),
					onsubmit: function (ev) {
						ev.preventDefault();
						load();
					},
				}, [
					el('div', {
						className: 'iv-filter-grid iv-filter-grid--simple',
						role: 'group',
						'aria-label': tr('Filter options'),
					}, [
						el('div', { className: 'iv-filter-field iv-filter-field--search' }, [
							el('label', { className: 'iv-filter-field__label', for: 'iv-items-q', text: tr('Search') }),
							el('div', { className: 'iv-filter-field__control' }, [q]),
						]),
						el('div', { className: 'iv-filter-field iv-filter-field--actions' }, [
							el('span', { className: 'iv-filter-field__label iv-sr-only', text: tr('Actions') }),
							el('div', { className: 'iv-filter-field__control iv-filter-field__control--actions' }, [
								btn(tr('Search'), { primary: true, type: 'submit' }),
								btn(tr('Clear'), {
									type: 'button',
									onclick: function () {
										q.value = '';
										load();
									},
								}),
							]),
						]),
					]),
				]),
			]),
		]));
		mount.appendChild(filterActiveHost);
		mount.appendChild(listHost);

		function load() {
			var seq = ++loadSeq;
			var term = appliedQuery();
			syncFilterActive();
			setBusy(listHost, true);
			api('GET', ctx.urls.api.items + '?limit=50&offset=0&q=' + encodeURIComponent(term)).then(function (res) {
				if (seq !== loadSeq) return;
				clear(listHost);
				setBusy(listHost, false);
				listHost.appendChild(el('header', { className: 'iv-card__header' }, [
					el('h2', { className: 'iv-card__title', text: tr('Items') }),
				]));
				var body = el('div', { className: 'iv-card__body' });
				listHost.appendChild(body);
				if (!res.data.length) {
					body.appendChild(emptyState(
						term ? tr('No items match these filters') : tr('No items yet'),
						term
							? tr('Clear the search or try another name or SKU.')
							: tr('Create an item with a SKU and reorder level.'),
						term
							? btn(tr('Clear'), {
								primary: true,
								onclick: function () {
									q.value = '';
									load();
								},
							})
							: ((ctx.isOffice || ctx.isAppAdmin)
								? btn(tr('New item'), { primary: true, onclick: function () { openItemDialog(ctx, null); } })
								: null)
					));
					return;
				}
				var columns = [];
				if (ctx.isOffice) {
					columns.push({ label: tr('Select'), render: function (r) {
						return el('input', {
							type: 'checkbox',
							'aria-label': tr('Select {name} for bulk labels', { name: r.name }),
							checked: selected[r.id] ? '' : null,
							onchange: function (ev) {
								if (ev.target.checked) {
									selected[r.id] = true;
								} else {
									delete selected[r.id];
								}
								syncBulkBtn();
							},
						});
					} });
				}
				columns.push(
					{ label: tr('Name'), render: function (r) {
						return el('a', { href: entityUrl(ctx.urls.pages.items, r.id), text: r.name });
					} },
					{ label: tr('SKU'), key: 'sku' },
					{ label: tr('Scan code'), key: 'scanCode' },
					{ label: tr('UoM'), key: 'uom' },
					{ label: tr('Reorder'), key: 'reorderLevel' },
					{ label: tr('Active'), render: function (r) { return r.active ? tr('Yes') : tr('No'); } },
					{ label: tr('Actions'), render: function (r) {
						if (!(ctx.isOffice || ctx.isAppAdmin)) {
							return el('span', { className: 'iv-muted', text: '—' });
						}
						return el('div', { className: 'iv-row__actions' }, [
							btn(tr('Edit'), {
								onclick: function () { openItemDialog(ctx, r); },
							}),
							btn(r.active ? tr('Deactivate') : tr('Reactivate'), {
								onclick: function () {
									api('PUT', entityUrl(ctx.urls.api.items, r.id), { active: !r.active }).then(function () {
										toast(r.active ? tr('Item deactivated.') : tr('Item reactivated.'));
										renderItems(ctx);
									}).catch(function (err) { toast(err.message, true); });
								},
							}),
						]);
					} },
				);
				body.appendChild(tableOrCards(columns, res.data));
			}).catch(function (err) {
				if (seq !== loadSeq) return;
				clear(listHost);
				toast(err.message, true);
			});
		}
		load();
	}

	function openItemDialog(ctx, existing) {
		var sku = el('input', { type: 'text', className: 'iv-input', maxlength: '64', value: existing ? existing.sku : '', required: '' });
		var scan = el('input', { type: 'text', className: 'iv-input', maxlength: '128', value: existing ? existing.scanCode : '' });
		var name = el('input', { type: 'text', className: 'iv-input', maxlength: '255', value: existing ? existing.name : '', required: '' });
		var uom = el('input', { type: 'text', className: 'iv-input', maxlength: '32', value: existing ? existing.uom : 'pcs' });
		var reorder = el('input', qtyInputAttrs(ctx, {
			adjust: true,
			value: existing ? existing.reorderLevel : '0',
			required: false,
		}));
		reorder.removeAttribute('required');
		var trackMode = el('select', { className: 'iv-input', 'aria-label': tr('Tracking mode') }, [
			el('option', { value: 'none', text: tr('No lot / serial tracking') }),
			el('option', { value: 'lot', text: tr('Lot / batch tracking') }),
			el('option', { value: 'serial', text: tr('Serial number tracking') }),
		]);
		trackMode.value = existing && existing.trackMode ? existing.trackMode : 'none';
		var supplierNote = el('input', {
			type: 'text', className: 'iv-input', maxlength: '255',
			value: existing && existing.supplierNote ? existing.supplierNote : '',
		});
		var lastPrice = el('input', {
			type: 'number', className: 'iv-input', min: '0', step: '1',
			value: existing && existing.lastPriceMinor != null ? String(existing.lastPriceMinor) : '',
		});
		dialog(existing ? tr('Edit item') : tr('New item'), [
			field(tr('SKU'), sku, { name: 'sku' }),
			field(tr('Scan code (optional)'), scan, { name: 'scanCode' }),
			field(tr('Name'), name, { name: 'name' }),
			field(tr('Unit'), uom, { name: 'uom' }),
			field(tr('Reorder level'), reorder, { name: 'reorderLevel' }),
			field(tr('Tracking mode'), trackMode, { name: 'trackMode' }),
			el('p', {
				className: 'iv-field__hint',
				text: tr('Lot and serial tracking require a code on every stock movement. Serial items always move one unit at a time.'),
			}),
			field(tr('Supplier note (optional)'), supplierNote, { name: 'supplierNote' }),
			field(tr('Last price in cents (optional)'), lastPrice, { name: 'lastPriceMinor' }),
			existing ? el('p', {
				className: 'iv-field__hint',
				text: tr('Changing SKU or scan code does not update already printed labels.'),
			}) : null,
		].filter(Boolean), function () {
			var body = {
				sku: sku.value.trim(),
				scanCode: scan.value.trim(),
				name: name.value.trim(),
				uom: uom.value.trim() || 'pcs',
				reorderLevel: reorder.value.trim(),
				trackMode: trackMode.value,
				supplierNote: supplierNote.value.trim() || null,
				lastPriceMinor: lastPrice.value.trim() === '' ? null : Number(lastPrice.value),
			};
			if (existing) {
				return api('PUT', entityUrl(ctx.urls.api.items, existing.id), body).then(function () {
					toast(tr('Item saved.'));
					if (ctx.page === 'item-detail') renderItemDetail(ctx);
					else renderItems(ctx);
				});
			}
			return api('POST', ctx.urls.api.items, body).then(function () {
				toast(tr('Item created.'));
				renderItems(ctx);
			});
		});
	}

	/** Wave A4: one primary item photo. Office may upload/replace/remove; everyone may view. */
	function renderItemPhoto(ctx, host, item, id) {
		clear(host);
		if (item.hasPhoto) {
			host.appendChild(el('img', {
				className: 'iv-item-photo__img',
				src: urlWithId(ctx.urls.api.itemPhoto, id) + '?v=' + String(item.updatedAt || Date.now()),
				alt: item.name,
				loading: 'lazy',
			}));
		} else {
			host.appendChild(el('p', { className: 'iv-muted', text: tr('No photo yet.') }));
		}
		if (!(ctx.isOffice || ctx.isAppAdmin)) {
			return;
		}
		var fileInput = el('input', {
			type: 'file',
			accept: 'image/jpeg,image/png,image/webp',
			'aria-label': tr('Upload photo'),
			onchange: function () {
				if (!fileInput.files || !fileInput.files[0]) return;
				var fd = new FormData();
				fd.append('file', fileInput.files[0]);
				fetch(urlWithId(ctx.urls.api.itemPhotoUpload, id), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { requesttoken: requestToken() },
					body: fd,
				}).then(function (res) {
					return res.json().catch(function () { return {}; }).then(function (data) {
						if (!res.ok) {
							var err = (data && data.error) || {};
							throw new ApiError(err.code, err.message, err.details, res.status);
						}
						return data;
					});
				}).then(function (updated) {
					toast(tr('Photo uploaded.'));
					renderItemPhoto(ctx, host, updated, id);
				}).catch(function (err) {
					toast(err.message || tr('Could not upload the photo.'), true);
				});
			},
		});
		var actions = [fileInput];
		if (item.hasPhoto) {
			actions.push(btn(tr('Remove photo'), {
				onclick: function () {
					api('DELETE', urlWithId(ctx.urls.api.itemPhotoDelete, id)).then(function (updated) {
						toast(tr('Photo removed.'));
						renderItemPhoto(ctx, host, updated, id);
					}).catch(function (err) { toast(err.message, true); });
				},
			}));
		}
		host.appendChild(el('div', { className: 'iv-item-photo__actions' }, actions));
	}

	function renderItemDetail(ctx) {
		var mount = ctx.mount;
		var id = Number(ctx.entityId);
		setBusy(mount, true);
		Promise.all([
			api('GET', entityUrl(ctx.urls.api.items, id)),
			api('GET', ctx.urls.api.balances + '?itemId=' + id + '&limit=50&offset=0'),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0&active=1'),
		]).then(function (results) {
			var item = results[0];
			var bals = results[1];
			var locMap = indexById(results[2].data);
			clear(mount);
			setBusy(mount, false);
			clear(ctx.actions);
			if (ctx.isOffice || ctx.isAppAdmin) {
				ctx.actions.appendChild(btn(tr('Edit'), {
					onclick: function () { openItemDialog(ctx, item); },
				}));
				ctx.actions.appendChild(btn(item.active ? tr('Deactivate') : tr('Reactivate'), {
					onclick: function () {
						api('PUT', entityUrl(ctx.urls.api.items, id), { active: !item.active }).then(function () {
							toast(item.active ? tr('Item deactivated.') : tr('Item reactivated.'));
							renderItemDetail(ctx);
						}).catch(function (err) { toast(err.message, true); });
					},
				}));
			}
			ctx.actions.appendChild(el('a', {
				href: labelPrintUrl(ctx, id),
				className: 'button primary',
				target: '_blank',
				rel: 'noopener noreferrer',
				text: tr('Print label'),
			}));
			var infoKids = [
				el('h2', { className: 'iv-section__title', text: item.name }),
				el('p', { className: 'iv-muted', text: tr('SKU') + ': ' + item.sku + ' · ' + tr('Scan code') + ': ' + item.scanCode }),
			];
			if (item.supplierNote) {
				infoKids.push(el('p', { className: 'iv-muted', text: tr('Supplier note') + ': ' + item.supplierNote }));
			}
			if (item.trackMode && item.trackMode !== 'none') {
				infoKids.push(el('p', {
					className: 'iv-muted',
					text: tr('Tracking mode') + ': ' + (item.trackMode === 'serial'
						? tr('Serial number tracking')
						: tr('Lot / batch tracking')),
				}));
			}
			if (item.lastPriceMinor != null) {
				infoKids.push(el('p', { className: 'iv-muted', text: tr('Last price') + ': ' + (Number(item.lastPriceMinor) / 100).toFixed(2) }));
			}
			var photoHost = el('div', { className: 'iv-item-photo' });
			infoKids.push(photoHost);
			mount.appendChild(el('section', { className: 'iv-section' }, infoKids));
			renderItemPhoto(ctx, photoHost, item, id);
			mount.appendChild(el('section', {
				className: 'iv-section',
				'aria-labelledby': 'iv-qr-title',
			}, [
				el('h2', { id: 'iv-qr-title', className: 'iv-section__title', text: tr('Label preview') }),
				el('p', {
					className: 'iv-muted',
					text: tr('QR and Code 128 encode the scan code. Print a sticker, or paste the code into a movement form with a wedge scanner.'),
				}),
				el('figure', { className: 'iv-label-preview' }, [
					el('img', {
						className: 'iv-label-preview__img',
						src: labelSvgUrl(ctx, id) + '?v=' + String(item.updatedAt || item.scanCode || id),
						alt: tr('QR and barcode for {code}', { code: item.scanCode }),
						width: '320',
						height: '400',
					}),
					el('figcaption', {
						className: 'iv-label-code',
						id: 'iv-label-code',
						text: item.scanCode,
					}),
				]),
				el('p', { className: 'iv-actions iv-actions--inline' }, [
					el('a', {
						href: labelPrintUrl(ctx, id),
						className: 'button',
						target: '_blank',
						rel: 'noopener noreferrer',
						text: tr('Print label'),
					}),
					el('a', {
						href: labelSvgUrl(ctx, id),
						className: 'button',
						download: 'label-' + id + '.svg',
						text: tr('Download SVG'),
					}),
				]),
			]));
			mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-bal-title' }, [
				el('h2', { id: 'iv-bal-title', className: 'iv-section__title', text: tr('Balances by location') }),
				bals.data.length === 0
					? el('p', { className: 'iv-muted', text: tr('No stock yet.') })
					: tableOrCards([
						{ label: tr('Location'), render: function (r) {
							return labelFromMap(locMap, r.locationId, 'code');
						} },
						{ label: tr('Qty'), render: function (r) {
							var n = Number(r.qty);
							var attrs = { 'data-iv-balance': id + ':' + r.locationId };
							if (n < 0) {
								return el('span', Object.assign({
									className: 'iv-badge iv-badge--overdue',
									text: formatQty(n) + ' — ' + tr('negative stock'),
								}, attrs));
							}
							return el('span', Object.assign({ text: formatQty(n) }, attrs));
						} },
					], bals.data),
			]));
		}).catch(function (err) {
			clear(mount);
			toast(err.message, true);
		});
	}

	function renderLocations(ctx) {
		var mount = ctx.mount;
		clear(mount);
		if (ctx.isOffice) {
			clear(ctx.actions);
			ctx.actions.appendChild(btn(tr('New location'), {
				primary: true,
				onclick: function () { openLocationDialog(ctx); },
			}));
		}

		var q = el('input', {
			id: 'iv-loc-q',
			type: 'search',
			className: 'iv-input form-input',
			placeholder: tr('Code or name'),
			autocomplete: 'off',
		});
		var listHost = el('div', { id: 'iv-locations-list', className: 'iv-card' });
		var filterActiveHost = el('div', { id: 'iv-loc-filter-active' });
		var loadSeq = 0;

		function appliedQuery() {
			return (q.value || '').trim();
		}

		function syncFilterActive() {
			clear(filterActiveHost);
			var term = appliedQuery();
			if (!term) return;
			filterActiveHost.appendChild(el('div', {
				className: 'iv-callout iv-callout--info iv-filter-active',
				role: 'status',
			}, [
				el('p', {
					className: 'iv-callout__text',
					text: tr('Search') + ': ' + term,
				}),
				btn(tr('Clear'), {
					onclick: function () {
						q.value = '';
						load();
					},
				}),
			]));
		}

		mount.appendChild(el('section', {
			id: 'iv-loc-filter-panel',
			className: 'iv-card iv-filter-panel',
			'aria-labelledby': 'iv-loc-filter-title',
		}, [
			el('header', { className: 'iv-filter-panel__head' }, [
				el('div', { className: 'iv-filter-panel__head-text' }, [
					el('h2', { id: 'iv-loc-filter-title', text: tr('Filter') }),
					el('p', {
						className: 'iv-filter-panel__intro',
						text: tr('Find locations by code or name.'),
					}),
				]),
			]),
			el('div', { className: 'iv-filter-panel__body' }, [
				el('form', {
					className: 'iv-filter-panel__form iv-filterbar',
					role: 'search',
					'aria-label': tr('Search locations'),
					onsubmit: function (ev) {
						ev.preventDefault();
						load();
					},
				}, [
					el('div', {
						className: 'iv-filter-grid iv-filter-grid--simple',
						role: 'group',
						'aria-label': tr('Filter options'),
					}, [
						el('div', { className: 'iv-filter-field iv-filter-field--search' }, [
							el('label', { className: 'iv-filter-field__label', for: 'iv-loc-q', text: tr('Search') }),
							el('div', { className: 'iv-filter-field__control' }, [q]),
						]),
						el('div', { className: 'iv-filter-field iv-filter-field--actions' }, [
							el('span', { className: 'iv-filter-field__label iv-sr-only', text: tr('Actions') }),
							el('div', { className: 'iv-filter-field__control iv-filter-field__control--actions' }, [
								btn(tr('Search'), { primary: true, type: 'submit' }),
								btn(tr('Clear'), {
									type: 'button',
									onclick: function () {
										q.value = '';
										load();
									},
								}),
							]),
						]),
					]),
				]),
			]),
		]));
		mount.appendChild(filterActiveHost);
		mount.appendChild(listHost);

		function load() {
			var seq = ++loadSeq;
			var term = appliedQuery();
			syncFilterActive();
			setBusy(listHost, true);
			api('GET', ctx.urls.api.locations + '?limit=50&offset=0&q=' + encodeURIComponent(term)).then(function (res) {
				if (seq !== loadSeq) return;
				clear(listHost);
				setBusy(listHost, false);
				if (!res.data.length) {
					listHost.appendChild(el('header', { className: 'iv-card__header' }, [
						el('h2', { id: 'iv-locations-title', className: 'iv-card__title', text: tr('Locations') }),
					]));
					var emptyBody = el('div', { className: 'iv-card__body' });
					listHost.appendChild(emptyBody);
					emptyBody.appendChild(emptyState(
						term ? tr('No locations match these filters') : tr('No locations yet'),
						term
							? tr('Clear the search or try another code or name.')
							: tr('Add a warehouse or van to hold stock.'),
						term
							? btn(tr('Clear'), {
								primary: true,
								onclick: function () {
									q.value = '';
									load();
								},
							})
							: ((ctx.isOffice || ctx.isAppAdmin)
								? btn(tr('New location'), { primary: true, onclick: function () { openLocationDialog(ctx); } })
								: null)
					));
					return;
				}
				listHost.appendChild(el('header', { className: 'iv-card__header' }, [
					el('h2', { id: 'iv-locations-title', className: 'iv-card__title', text: tr('Locations') }),
				]));
				listHost.appendChild(el('div', { className: 'iv-card__body' }, [
					tableOrCards([
						{ label: tr('Name'), render: function (r) {
							return el('a', { href: entityUrl(ctx.urls.pages.locations, r.id), text: r.name });
						} },
						{ label: tr('Code'), key: 'code' },
						{ label: tr('Kind'), render: function (r) { return locationKindLabel(r.kind); } },
						{ label: tr('Active'), render: function (r) { return r.active ? tr('Yes') : tr('No'); } },
						{ label: tr('Actions'), render: function (r) {
							if (!(ctx.isOffice || ctx.isAppAdmin)) {
								return el('span', { className: 'iv-muted', text: '—' });
							}
							return el('div', { className: 'iv-row__actions' }, [
								btn(tr('Edit'), {
									onclick: function () { openLocationDialog(ctx, r); },
								}),
								btn(r.active ? tr('Deactivate') : tr('Reactivate'), {
									onclick: function () {
										api('PUT', entityUrl(ctx.urls.api.locations, r.id), { active: !r.active }).then(function () {
											toast(r.active ? tr('Location deactivated.') : tr('Location reactivated.'));
											renderLocations(ctx);
										}).catch(function (err) { toast(err.message, true); });
									},
								}),
							]);
						} },
					], res.data),
				]));
			}).catch(function (err) {
				if (seq !== loadSeq) return;
				clear(listHost);
				toast(err.message, true);
			});
		}
		load();
	}

	function openLocationDialog(ctx, existing) {
		var code = el('input', {
			type: 'text', className: 'iv-input', maxlength: '64', required: '',
			value: existing ? existing.code : '',
		});
		var name = el('input', {
			type: 'text', className: 'iv-input', maxlength: '255', required: '',
			value: existing ? existing.name : '',
		});
		var kind = el('select', { className: 'iv-input' }, [
			el('option', { value: 'warehouse', text: tr('Warehouse') }),
			el('option', { value: 'shelf', text: tr('Shelf') }),
			el('option', { value: 'van', text: tr('Van') }),
			el('option', { value: 'site', text: tr('Site') }),
			el('option', { value: 'other', text: tr('Other') }),
		]);
		kind.value = existing ? existing.kind : 'other';
		dialog(existing ? tr('Edit location') : tr('New location'), [
			field(tr('Code'), code, { name: 'code' }),
			field(tr('Name'), name, { name: 'name' }),
			field(tr('Kind'), kind, { name: 'kind' }),
		], function () {
			var body = {
				code: code.value.trim(),
				name: name.value.trim(),
				kind: kind.value,
			};
			if (existing) {
				return api('PUT', entityUrl(ctx.urls.api.locations, existing.id), body).then(function () {
					toast(tr('Location saved.'));
					if (ctx.page === 'location-detail') renderLocationDetail(ctx);
					else renderLocations(ctx);
				});
			}
			return api('POST', ctx.urls.api.locations, body).then(function () {
				toast(tr('Location created.'));
				renderLocations(ctx);
			});
		});
	}

	function renderLocationDetail(ctx) {
		var mount = ctx.mount;
		var id = Number(ctx.entityId);
		Promise.all([
			api('GET', entityUrl(ctx.urls.api.locations, id)),
			api('GET', ctx.urls.api.balances + '?locationId=' + id + '&nonZero=1&limit=50&offset=0'),
			api('GET', ctx.urls.api.items + '?limit=200&offset=0&active=1'),
			api('GET', ctx.urls.api.favouriteLocations),
		]).then(function (results) {
			var loc = results[0];
			var bals = results[1];
			var itemMap = indexById(results[2].data);
			var favourites = results[3].data || [];
			var isFavourite = favourites.some(function (f) { return Number(f.id) === id; });
			clear(mount);
			clear(ctx.actions);
			if (ctx.isOffice || ctx.isAppAdmin) {
				ctx.actions.appendChild(btn(tr('Edit'), {
					onclick: function () { openLocationDialog(ctx, loc); },
				}));
				ctx.actions.appendChild(btn(loc.active ? tr('Deactivate') : tr('Reactivate'), {
					onclick: function () {
						api('PUT', entityUrl(ctx.urls.api.locations, id), { active: !loc.active }).then(function () {
							toast(loc.active ? tr('Location deactivated.') : tr('Location reactivated.'));
							renderLocationDetail(ctx);
						}).catch(function (err) { toast(err.message, true); });
					},
				}));
			}
			ctx.actions.appendChild(btn(isFavourite ? tr('★ Remove from favourites') : tr('☆ Add to favourites'), {
				onclick: function () {
					var call = isFavourite
						? api('DELETE', urlWithId(ctx.urls.api.favouriteLocationRemove, id))
						: api('POST', ctx.urls.api.favouriteLocations, { locationId: id });
					call.then(function () {
						toast(isFavourite ? tr('Removed from favourites.') : tr('Added to favourites.'));
						renderLocationDetail(ctx);
					}).catch(function (err) { toast(err.message, true); });
				},
			}));
			mount.appendChild(el('h2', { className: 'iv-section__title', text: loc.name }));
			mount.appendChild(el('p', { className: 'iv-muted', text: loc.code + ' · ' + locationKindLabel(loc.kind) }));
			mount.appendChild(bals.data.length === 0
				? el('p', { className: 'iv-muted', text: tr('No stock at this location.') })
				: tableOrCards([
					{ label: tr('Item'), render: function (r) {
						return labelFromMap(itemMap, r.itemId, 'sku');
					} },
					{ label: tr('Qty'), render: function (r) {
						return el('span', {
							'data-iv-balance': r.itemId + ':' + id,
							text: formatQty(r.qty),
						});
					} },
				], bals.data));
		}).catch(function (err) {
			clear(mount);
			toast(err.message, true);
		});
	}

	function paginationBar(total, limit, offset, onPage) {
		var page = Math.floor(offset / limit) + 1;
		var pages = Math.max(1, Math.ceil(total / limit));
		var from = total === 0 ? 0 : offset + 1;
		var to = Math.min(offset + limit, total);
		var kids = [];
		// Prefer omit over disable (plan §7): no dead Previous/Next buttons.
		if (offset > 0) {
			kids.push(btn(tr('Previous'), {
				onclick: function () { onPage(Math.max(0, offset - limit)); },
			}));
		}
		kids.push(el('p', {
			className: 'iv-pagination__info',
			text: tr('Showing {from}–{to} of {total}', {
				from: String(from),
				to: String(to),
				total: String(total),
			}) + (pages > 1 ? ' · ' + tr('Page {page} of {pages}', {
				page: String(page),
				pages: String(pages),
			}) : ''),
		}));
		if (offset + limit < total) {
			kids.push(btn(tr('Next'), {
				onclick: function () { onPage(offset + limit); },
			}));
		}
		return el('nav', {
			className: 'iv-pagination',
			'aria-label': tr('Pagination'),
		}, kids);
	}

	function renderMovements(ctx, filters) {
		var mount = ctx.mount;
		var state = Object.assign({
			kind: '',
			itemId: '',
			itemQuery: '',
			locationId: '',
			locationQuery: '',
			fromDate: '',
			toDate: '',
			transferGroup: null,
			limit: 50,
			offset: 0,
		}, filters || {});
		setBusy(mount, true);
		clear(ctx.actions);
		if (ctx.isOffice || ctx.isAppAdmin) {
			ctx.actions.appendChild(el('a', {
				href: exportCsvHref(ctx, 'movements'),
				className: 'button',
				text: tr('Export CSV'),
			}));
			ctx.actions.appendChild(el('a', {
				href: exportCsvHref(ctx, 'movements_datev'),
				className: 'button',
				text: tr('Export DATEV-style CSV'),
			}));
		}
		var fromUnix = dateInputToUnix(state.fromDate, false);
		var toUnix = dateInputToUnix(state.toDate, true);
		var query = movementListQuery({
			kind: state.kind || null,
			itemId: state.itemId || null,
			locationId: state.locationId || null,
			from: fromUnix,
			to: toUnix,
			transferGroup: state.transferGroup || null,
			limit: state.limit,
			offset: state.offset,
		});
		Promise.all([
			api('GET', ctx.urls.api.movements + '?' + query),
			loadMasters(ctx),
		]).then(function (results) {
			var res = results[0];
			var masters = results[1];
			var items = masters.items || [];
			var locations = masters.locations || [];
			var itemMap = indexById(items);
			var locMap = indexById(locations);
			// Enrich labels in the background — never block the filter paint on N+1 GETs.
			hydrateMaps(ctx, itemMap, locMap, res.data || []).then(function () {
				if (!mount.querySelector('#iv-mov-filter-panel')) return;
				mount.querySelectorAll('[data-iv-label-item]').forEach(function (node) {
					var id = node.getAttribute('data-iv-label-item');
					node.textContent = labelFromMap(itemMap, id, 'sku');
				});
				mount.querySelectorAll('[data-iv-label-loc]').forEach(function (node) {
					var id = node.getAttribute('data-iv-label-loc');
					node.textContent = labelFromMap(locMap, id, 'code');
				});
			});
			clear(mount);
			setBusy(mount, false);

			var kindSelect = el('select', {
				className: 'iv-input form-select',
				id: 'iv-mov-kind',
			});
			[
				{ v: '', t: tr('All kinds') },
				{ v: 'receive', t: tr('Receive') },
				{ v: 'issue', t: tr('Issue') },
				{ v: 'transfer_out', t: tr('Transfer out') },
				{ v: 'transfer_in', t: tr('Transfer in') },
				{ v: 'adjust', t: tr('Adjust') },
			].forEach(function (o) {
				kindSelect.appendChild(el('option', {
					value: o.v,
					text: o.t,
					selected: state.kind === o.v ? '' : null,
				}));
			});

			var itemQuery = '';
			if (state.itemId && itemMap[String(state.itemId)]) {
				itemQuery = masterLabel(itemMap[String(state.itemId)], 'sku');
			} else if (state.itemQuery) {
				itemQuery = state.itemQuery;
			}
			var locQuery = '';
			if (state.locationId && locMap[String(state.locationId)]) {
				locQuery = masterLabel(locMap[String(state.locationId)], 'code');
			} else if (state.locationQuery) {
				locQuery = state.locationQuery;
			}

			var itemInput = el('input', {
				type: 'search',
				className: 'iv-input form-input',
				id: 'iv-mov-item',
				value: itemQuery,
				placeholder: tr('SKU or name'),
				autocomplete: 'off',
				list: 'iv-mov-item-dl',
			});
			var itemList = el('datalist', { id: 'iv-mov-item-dl' });
			fillDatalist(itemList, items, 'sku');

			var locInput = el('input', {
				type: 'search',
				className: 'iv-input form-input',
				id: 'iv-mov-loc',
				value: locQuery,
				placeholder: tr('Code or name'),
				autocomplete: 'off',
				list: 'iv-mov-loc-dl',
			});
			var locList = el('datalist', { id: 'iv-mov-loc-dl' });
			fillDatalist(locList, locations, 'code');

			var fromInput = el('input', {
				type: 'date',
				className: 'iv-input form-input',
				id: 'iv-mov-from',
				value: state.fromDate || '',
			});
			var toInput = el('input', {
				type: 'date',
				className: 'iv-input form-input',
				id: 'iv-mov-to',
				value: state.toDate || '',
			});

			var filterError = el('p', {
				id: 'iv-mov-date-error',
				className: 'iv-callout iv-callout--danger iv-filter-feedback',
				role: 'alert',
				hidden: '',
			});

			function readFilters(extra) {
				return Object.assign({
					kind: kindSelect.value || '',
					itemId: state.itemId || '',
					itemQuery: itemInput.value || '',
					locationId: state.locationId || '',
					locationQuery: locInput.value || '',
					fromDate: fromInput.value || '',
					toDate: toInput.value || '',
					transferGroup: state.transferGroup,
					limit: state.limit,
					offset: 0,
				}, extra || {});
			}

			function dateRangeInvalid() {
				return !!(fromInput.value && toInput.value && fromInput.value > toInput.value);
			}

			function showFilterError(msg) {
				filterError.hidden = !msg;
				filterError.textContent = msg || '';
			}

			function syncDateValidity() {
				var bad = dateRangeInvalid();
				fromInput.setAttribute('aria-invalid', bad ? 'true' : 'false');
				toInput.setAttribute('aria-invalid', bad ? 'true' : 'false');
				if (bad) {
					showFilterError(tr('"From" must be on or before "To".'));
				} else if (filterError.textContent === tr('"From" must be on or before "To".')) {
					showFilterError('');
				}
				return !bad;
			}

			function applyFilters() {
				if (!syncDateValidity()) {
					fromInput.focus();
					return;
				}
				var nextItemId = '';
				var itemText = (itemInput.value || '').trim();
				if (itemText) {
					var resolvedItem = resolveMasterId(itemText, items, 'sku');
					if (resolvedItem === null) {
						itemInput.setAttribute('aria-invalid', 'true');
						showFilterError(tr('No matching item — pick a suggestion or clear the field.'));
						itemInput.focus();
						return;
					}
					nextItemId = resolvedItem;
				}
				itemInput.setAttribute('aria-invalid', 'false');

				var nextLocId = '';
				var locText = (locInput.value || '').trim();
				if (locText) {
					var resolvedLoc = resolveMasterId(locText, locations, 'code');
					if (resolvedLoc === null) {
						locInput.setAttribute('aria-invalid', 'true');
						showFilterError(tr('No matching location — pick a suggestion or clear the field.'));
						locInput.focus();
						return;
					}
					nextLocId = resolvedLoc;
				}
				locInput.setAttribute('aria-invalid', 'false');
				showFilterError('');

				renderMovements(ctx, readFilters({
					itemId: nextItemId,
					itemQuery: itemText,
					locationId: nextLocId,
					locationQuery: locText,
				}));
			}

			fromInput.setAttribute('aria-describedby', 'iv-mov-date-error');
			toInput.setAttribute('aria-describedby', 'iv-mov-date-error');
			itemInput.setAttribute('aria-describedby', 'iv-mov-date-error');
			locInput.setAttribute('aria-describedby', 'iv-mov-date-error');
			fromInput.addEventListener('change', syncDateValidity);
			toInput.addEventListener('change', syncDateValidity);
			// Kind is a closed list — apply immediately (one less click).
			kindSelect.addEventListener('change', function () {
				applyFilters();
			});

			mount.appendChild(el('section', {
				id: 'iv-mov-filter-panel',
				className: 'iv-card iv-filter-panel',
				'aria-labelledby': 'iv-mov-filter-title',
			}, [
				el('header', { className: 'iv-filter-panel__head' }, [
					el('div', { className: 'iv-filter-panel__head-text' }, [
						el('h2', { id: 'iv-mov-filter-title', text: tr('Filter') }),
						el('p', {
							className: 'iv-filter-panel__intro',
							text: tr('Narrow the list by kind, item, location, or date, then click Apply.'),
						}),
					]),
				]),
				el('div', { className: 'iv-filter-panel__body' }, [
					el('form', {
						className: 'iv-filter-panel__form iv-filterbar',
						role: 'search',
						'aria-label': tr('Filter movements'),
						novalidate: '',
						onsubmit: function (ev) {
							ev.preventDefault();
							applyFilters();
						},
					}, [
						el('div', {
							className: 'iv-filter-grid iv-filter-grid--movements',
							role: 'group',
							'aria-label': tr('Filter options'),
						}, [
							el('div', { className: 'iv-filter-field iv-filter-field--kind' }, [
								el('label', { className: 'iv-filter-field__label', for: 'iv-mov-kind', text: tr('Kind') }),
								el('div', { className: 'iv-filter-field__control' }, [kindSelect]),
							]),
							el('div', { className: 'iv-filter-field iv-filter-field--item' }, [
								el('label', { className: 'iv-filter-field__label', for: 'iv-mov-item', text: tr('Item') }),
								el('div', { className: 'iv-filter-field__control' }, [itemInput, itemList]),
							]),
							el('div', { className: 'iv-filter-field iv-filter-field--location' }, [
								el('label', { className: 'iv-filter-field__label', for: 'iv-mov-loc', text: tr('Location') }),
								el('div', { className: 'iv-filter-field__control' }, [locInput, locList]),
							]),
							el('div', { className: 'iv-filter-field iv-filter-field--actions' }, [
								el('span', { className: 'iv-filter-field__label iv-sr-only', text: tr('Actions') }),
								el('div', { className: 'iv-filter-field__control iv-filter-field__control--actions' }, [
									btn(tr('Apply'), { primary: true, type: 'submit' }),
									btn(tr('Clear'), {
										type: 'button',
										onclick: function () { renderMovements(ctx, null); },
									}),
								]),
							]),
							el('div', {
								className: 'iv-filter-field iv-filter-field--dates',
								role: 'group',
								'aria-labelledby': 'iv-mov-date-range-label',
							}, [
								el('span', {
									id: 'iv-mov-date-range-label',
									className: 'iv-filter-field__label',
									text: tr('Date range'),
								}),
								el('div', { className: 'iv-filter-field__control' }, [
									el('div', { className: 'iv-date-range' }, [
										el('div', { className: 'iv-date-range__part' }, [
											el('label', {
												className: 'iv-date-range__sublabel iv-sr-only',
												for: 'iv-mov-from',
												text: tr('From'),
											}),
											fromInput,
										]),
										el('span', {
											className: 'iv-date-range__sep',
											'aria-hidden': 'true',
											text: tr('to'),
										}),
										el('div', { className: 'iv-date-range__part' }, [
											el('label', {
												className: 'iv-date-range__sublabel iv-sr-only',
												for: 'iv-mov-to',
												text: tr('To'),
											}),
											toInput,
										]),
									]),
								]),
							]),
						]),
						filterError,
					]),
				]),
			]));
			syncDateValidity();

			var filtersActive = !!(
				state.kind
				|| state.itemId
				|| state.locationId
				|| state.fromDate
				|| state.toDate
				|| state.transferGroup
			);
			if (filtersActive) {
				var chips = [];
				if (state.kind) chips.push(tr('Kind') + ': ' + (kindSelect.options[kindSelect.selectedIndex] || {}).text);
				if (state.itemId) chips.push(tr('Item') + ': ' + (itemQuery || ('#' + state.itemId)));
				if (state.locationId) chips.push(tr('Location') + ': ' + (locQuery || ('#' + state.locationId)));
				if (state.fromDate || state.toDate) {
					chips.push(tr('From') + ' ' + (state.fromDate || '…') + ' → ' + (state.toDate || '…'));
				}
				if (state.transferGroup) {
					chips.push(tr('Showing transfer group') + ': ' + state.transferGroup);
				}
				mount.appendChild(el('div', {
					className: 'iv-callout iv-callout--info iv-filter-active',
					role: 'status',
				}, [
					el('p', {
						className: 'iv-callout__text',
						text: chips.join(' · '),
					}),
					btn(tr('Clear'), {
						onclick: function () { renderMovements(ctx, null); },
					}),
				]));
			}

			if (!res.data.length) {
				mount.appendChild(emptyState(
					filtersActive ? tr('No movements match these filters') : tr('No movements yet'),
					filtersActive
						? tr('Clear filters or widen the date range.')
						: tr('Bookings appear here after receive, issue, transfer, or adjust.'),
					filtersActive
						? btn(tr('Clear'), { primary: true, onclick: function () { renderMovements(ctx, null); } })
						: ((ctx.isOffice || ctx.isAppAdmin)
							? btn(tr('Receive stock'), { primary: true, onclick: function () { openReceiveDialog(ctx); } })
							: btn(tr('Issue stock'), { primary: true, onclick: function () { openIssueDialog(ctx); } }))
				));
				return;
			}
			mount.appendChild(tableOrCards([
				{ label: tr('Kind'), render: function (r) {
					var m = kindMeta(r.kind);
					return el('span', { className: 'iv-badge ' + m.badge, text: m.label });
				} },
				{ label: tr('Item'), render: function (r) {
					return el('span', {
						'data-iv-label-item': String(r.itemId),
						text: labelFromMap(itemMap, r.itemId, 'sku'),
					});
				} },
				{ label: tr('Location'), render: function (r) {
					return el('span', {
						'data-iv-label-loc': String(r.locationId),
						text: labelFromMap(locMap, r.locationId, 'code'),
					});
				} },
				{ label: tr('Delta'), key: 'qtyDelta' },
				{ label: tr('After'), key: 'qtyAfter' },
				{ label: tr('Group'), render: function (r) {
					if (!r.transferGroup) {
						return el('span', { className: 'iv-muted', text: '—' });
					}
					return el('button', {
						type: 'button',
						className: 'button iv-linkish',
						text: String(r.transferGroup).slice(0, 8) + '…',
						title: r.transferGroup,
						'aria-label': tr('Filter by transfer group'),
						onclick: function () {
							renderMovements(ctx, readFilters({
								transferGroup: r.transferGroup,
								offset: 0,
							}));
						},
					});
				} },
				{ label: tr('By'), key: 'createdBy' },
				{ label: tr('Actions'), render: function (r) {
					if (!canReverseMovement(ctx, r)) {
						return el('span', { className: 'iv-muted', text: '—' });
					}
					return btn(tr('Reverse'), {
						onclick: function () { openReverseFromMovement(ctx, r); },
					});
				} },
			], res.data));
			mount.appendChild(paginationBar(res.total, state.limit, state.offset, function (nextOffset) {
				renderMovements(ctx, readFilters({
					itemId: state.itemId || '',
					locationId: state.locationId || '',
					offset: nextOffset,
				}));
			}));
		}).catch(function (err) {
			clear(mount);
			toast(err.message, true);
		});
	}

	function renderSettings(ctx) {
		var mount = ctx.mount;
		if (!ctx.isAppAdmin) {
			clear(mount);
			mount.appendChild(emptyState(tr('Access denied'), tr('Only app administrators can open settings.'), null));
			return;
		}
		setBusy(mount, true);
		Promise.all([
			api('GET', ctx.urls.api.config),
			api('GET', ctx.urls.api.license),
			api('GET', ctx.urls.api.flangeStatus),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0&active=1'),
		]).then(function (results) {
			var cfg = results[0];
			var lic = results[1];
			var flange = results[2];
			var flangeLocations = results[3].data || [];
			clear(mount);
			setBusy(mount, false);

			var restriction = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				checked: cfg.accessRestrictionEnabled ? '' : null,
				id: 'iv-access-restriction',
			});
			var neg = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				checked: cfg.allowNegativeStock ? '' : null,
				id: 'iv-allow-negative',
			});
			var officeUsers = el('textarea', {
				className: 'iv-input',
				rows: '3',
				id: 'iv-office-users',
				text: (cfg.officeUsers || []).join('\n'),
			});
			var officeGroups = el('textarea', {
				className: 'iv-input',
				rows: '2',
				id: 'iv-office-groups',
				text: (cfg.officeGroups || []).join('\n'),
			});
			var allowedUsers = el('textarea', {
				className: 'iv-input',
				rows: '3',
				id: 'iv-allowed-users',
				text: (cfg.allowedUsers || []).join('\n'),
			});
			var allowedGroups = el('textarea', {
				className: 'iv-input',
				rows: '2',
				id: 'iv-allowed-groups',
				text: (cfg.allowedGroups || []).join('\n'),
			});
			var appAdmins = el('textarea', {
				className: 'iv-input',
				rows: '3',
				id: 'iv-app-admins',
				text: (cfg.appAdmins || []).join('\n'),
				'aria-describedby': 'iv-app-admins-hint',
			});
			var canEditAppAdmins = !!(ctx.isSystemAdmin || cfg.isSystemAdmin);
			if (!canEditAppAdmins) {
				appAdmins.setAttribute('readonly', '');
			}

			function lines(ta) {
				return ta.value.split(/\n+/).map(function (s) { return s.trim(); }).filter(Boolean);
			}

			var accessKids = [
				el('h2', { id: 'iv-access-title', className: 'iv-section__title', text: tr('Access') }),
				el('p', {
					className: 'iv-section__hint',
					text: tr('By default every logged-in user can open InventoryCheck. Turn on the restriction to limit access to the lists below. Administrators always keep access.'),
				}),
				el('label', { className: 'iv-switch', for: 'iv-access-restriction' }, [
					restriction,
					el('span', { className: 'iv-switch__label', text: tr('Restrict access to allow-listed users and groups') }),
				]),
				field(tr('Allowed users (one per line)'), allowedUsers, { name: 'allowedUsers' }),
				field(tr('Allowed groups (one per line)'), allowedGroups, { name: 'allowedGroups' }),
				field(tr('Delegated app administrators'), appAdmins, { name: 'appAdmins' }),
				el('p', {
					className: 'iv-field__hint',
					id: 'iv-app-admins-hint',
					text: canEditAppAdmins
						? tr('Nextcloud user IDs who may change access policy, office lists, and the license (in addition to system administrators).')
						: tr('Only Nextcloud system administrators can change the app administrator list.'),
				}),
				btn(tr('Save access'), {
					primary: true,
					onclick: function () {
						var payload = {
							accessRestrictionEnabled: restriction.checked,
							allowedUsers: lines(allowedUsers),
							allowedGroups: lines(allowedGroups),
						};
						if (canEditAppAdmins) {
							payload.appAdmins = lines(appAdmins);
						}
						clearFieldErrors(mount);
						api('POST', ctx.urls.api.configAccess, payload).then(function () {
							toast(tr('Access settings saved.'));
						}).catch(function (err) {
							applyFieldErrors(mount, err && err.details, err && err.message);
							toast(err.message, true);
						});
					},
				}),
			];

			mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-access-title' }, accessKids));

			mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-office-title' }, [
				el('h2', { id: 'iv-office-title', className: 'iv-section__title', text: tr('Office / storekeeper') }),
				field(tr('Office users (one per line)'), officeUsers, { name: 'officeUsers' }),
				field(tr('Office groups (one per line)'), officeGroups, { name: 'officeGroups' }),
				el('label', { className: 'iv-switch', for: 'iv-allow-negative' }, [
					neg,
					el('span', { className: 'iv-switch__label', text: tr('Allow negative stock') }),
				]),
				btn(tr('Save office settings'), {
					primary: true,
					onclick: function () {
						clearFieldErrors(mount);
						api('POST', ctx.urls.api.configOffice, {
							officeUsers: lines(officeUsers),
							officeGroups: lines(officeGroups),
							allowNegativeStock: neg.checked,
						}).then(function () {
							toast(tr('Office settings saved.'));
						}).catch(function (err) {
							applyFieldErrors(mount, err && err.details, err && err.message);
							toast(err.message, true);
						});
					},
				}),
			]));

			var notifyUsers = el('textarea', {
				className: 'iv-input',
				rows: '3',
				id: 'iv-notify-users',
				text: (cfg.lowStockNotifyUsers || []).join('\n'),
			});
			var notifyGroups = el('textarea', {
				className: 'iv-input',
				rows: '2',
				id: 'iv-notify-groups',
				text: (cfg.lowStockNotifyGroups || []).join('\n'),
			});
			var reorderHint = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-reorder-hint',
				checked: cfg.locationReorderHintEnabled ? '' : null,
			});
			mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-notify-title' }, [
				el('h2', { id: 'iv-notify-title', className: 'iv-section__title', text: tr('Low stock notifications') }),
				el('p', {
					className: 'iv-section__hint',
					text: tr('These users and groups get a notification the first time an item drops below its reorder level (at most once a day per item).'),
				}),
				field(tr('Notify users (one per line)'), notifyUsers, { name: 'lowStockNotifyUsers' }),
				field(tr('Notify groups (one per line)'), notifyGroups, { name: 'lowStockNotifyGroups' }),
				el('label', { className: 'iv-switch', for: 'iv-reorder-hint' }, [
					reorderHint,
					el('span', {
						className: 'iv-switch__label',
						text: tr('Also show low-stock hints per location, not just the total across all locations'),
					}),
				]),
				btn(tr('Save notification settings'), {
					primary: true,
					onclick: function () {
						clearFieldErrors(mount);
						return api('POST', ctx.urls.api.configNotify, {
							lowStockNotifyUsers: lines(notifyUsers),
							lowStockNotifyGroups: lines(notifyGroups),
							locationReorderHintEnabled: reorderHint.checked,
						}).then(function () {
							toast(tr('Notification settings saved.'));
						}).catch(function (err) {
							applyFieldErrors(mount, err && err.details, err && err.message);
							toast(err.message, true);
						});
					},
				}),
			]));

			var fracEnabled = Number(cfg.qtyScale) === 3;
			var fracKids = [
				el('h2', { id: 'iv-frac-title', className: 'iv-section__title', text: tr('Fractional quantities') }),
				el('p', {
					className: 'iv-section__hint',
					text: tr('Optional. Turn this on if you book cable metres or similar with up to three decimal places. Existing whole numbers become ×1000 storage units. This cannot be undone.'),
				}),
			];
			if (fracEnabled) {
				fracKids.push(el('p', {
					className: 'iv-muted',
					role: 'status',
					text: tr('Fractional quantities are on (up to 3 decimal places). This cannot be turned off.'),
				}));
			} else {
				fracKids.push(btn(tr('Enable fractional quantities (up to 3 decimals)'), {
					primary: true,
					onclick: function () {
						if (!ctx.urls.api.configFractional) return;
						if (!window.confirm(tr('This permanently switches every quantity to milli-units (×1000). You cannot turn it off later. Continue?'))) {
							return;
						}
						clearFieldErrors(mount);
						api('POST', ctx.urls.api.configFractional, {}).then(function (res) {
							toast(tr('Fractional quantities enabled. Reload the page to use decimal steps.'));
							if (res && res.qtyScale != null) {
								ctx.qtyScale = Number(res.qtyScale) || 3;
							}
							renderSettings(ctx);
						}).catch(function (err) {
							applyFieldErrors(mount, err && err.details, err && err.message);
							toast(err.message, true);
						});
					},
				}));
			}
			mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-frac-title' }, fracKids));

			var aclEnabled = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-loc-acl',
				checked: cfg.locationAclEnabled ? '' : null,
			});
			var aclSubjectType = el('select', { className: 'iv-input', id: 'iv-acl-subject-type' }, [
				el('option', { value: 'user', text: tr('User') }),
				el('option', { value: 'group', text: tr('Group') }),
			]);
			var aclSubjectId = el('input', {
				type: 'text',
				className: 'iv-input',
				id: 'iv-acl-subject-id',
				placeholder: tr('User id or group id'),
				'aria-label': tr('User id or group id'),
			});
			var aclLocationSelect = el('select', {
				className: 'iv-input',
				id: 'iv-acl-loc-ids',
				multiple: '',
				size: String(Math.min(8, Math.max(3, flangeLocations.length || 3))),
				'aria-label': tr('Locations to grant'),
			});
			flangeLocations.forEach(function (loc) {
				aclLocationSelect.appendChild(el('option', {
					value: String(loc.id),
					text: loc.name + ' — ' + loc.code,
				}));
			});
			var aclListHost = el('div', { className: 'iv-stack', id: 'iv-acl-list', role: 'status' });
			var locLabelById = {};
			flangeLocations.forEach(function (loc) {
				locLabelById[String(loc.id)] = loc.name + ' — ' + loc.code;
			});

			function renderAclAssignments(payload) {
				clear(aclListHost);
				var rows = (payload && payload.assignments) || [];
				if (!rows.length) {
					aclListHost.appendChild(el('p', { className: 'iv-muted', text: tr('No location grants yet. Field users see nothing while this is on.') }));
					return;
				}
				rows.forEach(function (r) {
					var locLabel = locLabelById[String(r.locationId)] || ('#' + r.locationId);
					aclListHost.appendChild(el('p', {
						text: r.subjectType + ':' + r.subjectId + ' → ' + locLabel,
					}));
				});
			}

			function loadAcl() {
				if (!ctx.urls.api.configLocationAcl) return Promise.resolve();
				return api('GET', ctx.urls.api.configLocationAcl).then(renderAclAssignments).catch(function (err) {
					toast(err.message, true);
				});
			}
			loadAcl();

			mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-acl-title' }, [
				el('h2', { id: 'iv-acl-title', className: 'iv-section__title', text: tr('Location access') }),
				el('p', {
					className: 'iv-section__hint',
					text: tr('Optional. When on, field users only see locations granted to them or their groups. Office and admins still see everything. With no grants, field users see none.'),
				}),
				el('label', { className: 'iv-switch', for: 'iv-loc-acl' }, [
					aclEnabled,
					el('span', { className: 'iv-switch__label', text: tr('Limit field users to granted locations') }),
				]),
				field(tr('Grant to'), aclSubjectType, { name: 'subjectType' }),
				field(tr('User or group id'), aclSubjectId, { name: 'subjectId' }),
				field(tr('Locations to grant'), aclLocationSelect, { name: 'locationIds' }),
				el('p', {
					className: 'iv-field__hint',
					text: tr('Hold Ctrl (or Cmd on Mac) to select several locations.'),
				}),
				btn(tr('Save location access'), {
					primary: true,
					onclick: function () {
						if (!ctx.urls.api.configLocationAcl) return;
						clearFieldErrors(mount);
						var ids = Array.prototype.slice.call(aclLocationSelect.selectedOptions).map(function (o) {
							return Number(o.value);
						}).filter(function (n) { return n > 0; });
						return api('PUT', ctx.urls.api.configLocationAcl, {
							enabled: aclEnabled.checked,
							subjectType: aclSubjectType.value,
							subjectId: aclSubjectId.value.trim(),
							locationIds: ids,
						}).then(function (payload) {
							toast(tr('Location access saved.'));
							renderAclAssignments(payload);
						}).catch(function (err) {
							applyFieldErrors(mount, err && err.details, err && err.message);
							toast(err.message, true);
						});
					},
				}),
				el('h3', { className: 'iv-section__subtitle', text: tr('Current grants') }),
				aclListHost,
			]));

			var maintToggle = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-flange-maint',
				checked: flange.maintFlangeEnabled ? '' : null,
			});
			var projectToggle = el('input', {
				type: 'checkbox',
				className: 'iv-switch__input',
				id: 'iv-flange-project',
				checked: flange.projectFlangeEnabled ? '' : null,
			});
			var defaultLocSelect = el('select', { className: 'iv-input', id: 'iv-flange-default-loc' });
			fillSelect(defaultLocSelect, flangeLocations, 'id', function (r) {
				return r.name + ' — ' + r.code;
			}, tr('No default location'));
			if (flange.defaultIssueLocationId) defaultLocSelect.value = String(flange.defaultIssueLocationId);
			mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-flange-title' }, [
				el('h2', { id: 'iv-flange-title', className: 'iv-section__title', text: tr('Connections to other Check apps') }),
				el('p', {
					className: 'iv-section__hint',
					text: tr('MaintenanceCheck and ProjectCheck can ask InventoryCheck to issue stock automatically for their work. Nothing changes unless you turn this on.'),
				}),
				el('label', { className: 'iv-switch', for: 'iv-flange-maint' }, [
					maintToggle,
					el('span', {
						className: 'iv-switch__label',
						text: flange.maintenanceCheckEnabled
							? tr('Let MaintenanceCheck issue stock for work orders')
							: tr('Let MaintenanceCheck issue stock for work orders (MaintenanceCheck is not installed yet)'),
					}),
				]),
				el('label', { className: 'iv-switch', for: 'iv-flange-project' }, [
					projectToggle,
					el('span', {
						className: 'iv-switch__label',
						text: flange.projectCheckEnabled
							? tr('Let ProjectCheck issue stock for projects')
							: tr('Let ProjectCheck issue stock for projects (ProjectCheck is not installed yet)'),
					}),
				]),
				field(tr('Default issue location'), defaultLocSelect, { name: 'defaultIssueLocationId' }),
				btn(tr('Save connection settings'), {
					primary: true,
					onclick: function () {
						clearFieldErrors(mount);
						return api('POST', ctx.urls.api.flangeSettings, {
							maintFlangeEnabled: maintToggle.checked,
							projectFlangeEnabled: projectToggle.checked,
							defaultIssueLocationId: defaultLocSelect.value || null,
						}).then(function () {
							toast(tr('Connection settings saved.'));
						}).catch(function (err) {
							applyFieldErrors(mount, err && err.details, err && err.message);
							toast(err.message, true);
						});
					},
				}),
			]));

			var keyInput = el('textarea', {
				className: 'iv-input',
				rows: '3',
				id: 'iv-license-key',
				placeholder: 'IV2.…',
				'aria-label': tr('License key'),
			});
			var stateText = lic.state
				? (lic.state.customerId + ' · ' + lic.state.validUntil + (lic.state.valid ? ' · ' + tr('Valid') : ' · ' + tr('Expired')))
				: tr('No license stored');
			var seatUid = el('input', {
				type: 'text',
				className: 'iv-input',
				id: 'iv-seat-uid',
				placeholder: tr('Nextcloud user id'),
				'aria-label': tr('Nextcloud user id'),
			});
			var deviceLabel = el('input', {
				type: 'text',
				className: 'iv-input',
				id: 'iv-device-label',
				placeholder: tr('Scanner label'),
				'aria-label': tr('Scanner label'),
			});
			var seatsHost = el('div', { className: 'iv-stack', id: 'iv-seats-list' });
			var devicesHost = el('div', { className: 'iv-stack', id: 'iv-devices-list' });

			function deviceStateLabel(d) {
				if (!d.active || d.state === 'inactive') return tr('Inactive');
				if (d.state === 'paired') return tr('Paired');
				if (d.state === 'expired') return tr('Expired pair code');
				if (d.state === 'pending' || d.hasPendingPairCode) return tr('Pending pair code');
				return tr('Empty slot');
			}

			function showPairCodeOnce(code) {
				dialog(
					tr('One-time pairing code'),
					[
						el('p', { text: tr('Copy this code now. It is shown only once.') }),
						el('p', {
							className: 'iv-mono',
							id: 'iv-pair-code-once',
							text: code,
							'aria-label': tr('Pairing code'),
						}),
					],
					function () {
						if (navigator.clipboard && code) {
							return navigator.clipboard.writeText(code).then(function () {
								toast(tr('Pairing code copied.'));
							}).catch(function () {
								toast(tr('Could not copy. Select the code manually.'), true);
								return false;
							});
						}
						toast(tr('Select the code manually to copy.'), true);
						return false;
					},
					tr('Copy code')
				);
			}

			function refreshSeatsAndDevices() {
				return Promise.all([
					api('GET', ctx.urls.api.licenseSeats + '?limit=100&offset=0'),
					api('GET', ctx.urls.api.licenseDevices + '?limit=100&offset=0'),
				]).then(function (lists) {
					clear(seatsHost);
					if (!lists[0].data.length) {
						seatsHost.appendChild(el('p', { className: 'iv-muted', text: tr('No seats assigned yet.') }));
					} else {
						lists[0].data.forEach(function (s) {
							var titleKids = [el('span', { className: 'iv-row__title', text: s.displayName || s.uid })];
							if (s.withinLimit === false) {
								titleKids.push(el('span', {
									className: 'iv-chip iv-chip--over-limit',
									text: tr('Over seat limit'),
								}));
							}
							seatsHost.appendChild(el('div', { className: 'iv-row' }, [
								el('div', { className: 'iv-row__main' }, titleKids),
								btn(tr('Remove'), {
									onclick: function () {
										api('DELETE', ctx.urls.api.licenseSeats.replace(/\/?$/, '/') + encodeURIComponent(s.uid))
											.then(function () {
												toast(tr('Seat removed.'));
												renderSettings(ctx);
											})
											.catch(function (err) { toast(err.message, true); });
									},
								}),
							]));
						});
					}
					clear(devicesHost);
					if (!lists[1].data.length) {
						devicesHost.appendChild(el('p', { className: 'iv-muted', text: tr('No device slots yet.') }));
					} else {
						lists[1].data.forEach(function (d) {
							var actions = [];
							if (d.active) {
								actions.push(btn(tr('Regenerate code'), {
									onclick: function () {
										api('POST', ctx.urls.api.licenseDevices.replace(/\/?$/, '/') + d.id + '/pair-code')
											.then(function (res) {
												showPairCodeOnce(res.pairCode || '');
												return refreshSeatsAndDevices();
											})
											.catch(function (err) { toast(err.message, true); });
									},
								}));
								actions.push(btn(tr('Deactivate'), {
									onclick: function () {
										api('DELETE', ctx.urls.api.licenseDevices.replace(/\/?$/, '/') + d.id)
											.then(function () {
												toast(tr('Device deactivated.'));
												renderSettings(ctx);
											})
											.catch(function (err) { toast(err.message, true); });
									},
								}));
							}
							devicesHost.appendChild(el('div', { className: 'iv-row' }, [
								el('div', { className: 'iv-row__main' }, [
									el('span', { className: 'iv-row__title', text: d.label }),
									el('span', { className: 'iv-muted', text: deviceStateLabel(d) }),
								]),
								el('div', { className: 'iv-row__actions' }, actions),
							]));
						});
					}
				});
			}

			mount.appendChild(el('section', {
				className: 'iv-section',
				id: 'iv-license',
				'aria-labelledby': 'iv-license-title',
			}, [
				el('h2', { id: 'iv-license-title', className: 'iv-section__title', text: tr('License / Mobile & Scanner') }),
				el('p', { className: 'iv-muted', text: stateText }),
				el('p', { className: 'iv-muted', text: tr('Seats') + ': ' + lic.seats.assigned + ' / ' + lic.seats.limit
					+ ' · ' + tr('Devices') + ': ' + lic.devices.active + ' / ' + lic.devices.limit }),
				field(tr('Paste IV2 key'), keyInput, { name: 'key' }),
				btn(tr('Apply key'), {
					primary: true,
					onclick: function () {
						api('POST', ctx.urls.api.license, { key: keyInput.value }).then(function () {
							toast(tr('License applied.'));
							renderSettings(ctx);
						}).catch(function (err) { toast(err.message, true); });
					},
				}),
				el('h3', { className: 'iv-section__subtitle', text: tr('Mobile seats') }),
				seatsHost,
				field(tr('Assign seat to user'), seatUid, { name: 'uid' }),
				btn(tr('Assign seat'), {
					onclick: function () {
						api('POST', ctx.urls.api.licenseSeats, { uid: seatUid.value.trim() }).then(function () {
							toast(tr('Seat assigned.'));
							renderSettings(ctx);
						}).catch(function (err) {
							toast(err.message, true);
							if (err && err.code === 'seat_limit_reached') {
								toast(tr('All licensed seats are assigned. Ask about upgrading mobile seats.'), true);
							}
						});
					},
				}),
				el('h3', { className: 'iv-section__subtitle', text: tr('Scanner device slots') }),
				devicesHost,
				field(tr('New device label'), deviceLabel, { name: 'label' }),
				btn(tr('Create device slot'), {
					onclick: function () {
						api('POST', ctx.urls.api.licenseDevices, { label: deviceLabel.value.trim() }).then(function (res) {
							deviceLabel.value = '';
							showPairCodeOnce(res.pairCode || '');
							return refreshSeatsAndDevices();
						}).catch(function (err) {
							toast(err.message, true);
							if (err && err.code === 'device_limit_reached') {
								toast(tr('All licensed device slots are used. Ask about upgrading scanner licenses.'), true);
							}
						});
					},
				}),
				el('p', { className: 'iv-muted', text: tr('Native scanner app: coming soon. Device slots and pairing codes work now.') }),
				el('p', null, [
					el('a', {
						href: 'mailto:info@software-by-design.de?subject=' + encodeURIComponent('InventoryCheck: Mobile / Scanner Lizenzen'),
						className: 'button',
						text: tr('Ask about mobile & scanner licenses'),
					}),
				]),
			]));
			refreshSeatsAndDevices().catch(function (err) { toast(err.message, true); });
		}).catch(function (err) {
			clear(mount);
			toast(err.message, true);
		});
	}

	// ── Stocktake / cycle counts (Wave B1) ─────────────────────────────

	function cycleStatusMeta(status) {
		switch (status) {
			case 'open': return { label: tr('Open'), badge: 'iv-badge--neutral' };
			case 'counting': return { label: tr('Counting'), badge: 'iv-badge--scheduled' };
			case 'closed': return { label: tr('Closed'), badge: 'iv-badge--done' };
			default: return { label: String(status || ''), badge: '' };
		}
	}

	function stocktakeDetailUrl(ctx, id) {
		return urlWithId(ctx.urls.pages.stocktakeCampaign, id);
	}

	function openNewCampaignDialog(ctx) {
		var locSelect = el('select', { required: '', className: 'iv-input', 'aria-required': 'true' });
		var name = el('input', { type: 'text', className: 'iv-input', maxlength: '255', required: '' });
		loadMasters(ctx).then(function (masters) {
			fillSelect(locSelect, masters.locations, 'id', function (r) {
				return r.name + ' — ' + r.code;
			}, tr('Choose a location'));
			var today = new Date();
			name.value = tr('Stocktake {date}', { date: today.toISOString().slice(0, 10) });
			dialog(tr('New stocktake'), [
				field(tr('Location'), locSelect, { name: 'locationId' }),
				field(tr('Name'), name, { name: 'name' }),
				el('p', {
					className: 'iv-field__hint',
					text: tr('This takes a snapshot of the current system quantity for every active item at this location.'),
				}),
			], function () {
				var locationId = Number(locSelect.value);
				if (!locationId) {
					return Promise.reject(new ApiError('validation_failed', tr('Choose a location.')));
				}
				return api('POST', ctx.urls.api.cycleCounts, {
					locationId: locationId,
					name: name.value.trim(),
				}).then(function (campaign) {
					toast(tr('Stocktake created.'));
					window.location.href = stocktakeDetailUrl(ctx, campaign.id);
				});
			}, tr('Create'));
		}).catch(function (err) {
			toast(err.message || tr('Could not load locations.'), true);
		});
	}

	function renderStocktakeList(ctx) {
		var mount = ctx.mount;
		setBusy(mount, true);
		clear(mount);
		clear(ctx.actions);
		if (ctx.isOffice || ctx.isAppAdmin) {
			ctx.actions.appendChild(btn(tr('New stocktake'), {
				primary: true,
				onclick: function () { openNewCampaignDialog(ctx); },
			}));
		}
		Promise.all([
			api('GET', ctx.urls.api.cycleCounts + '?limit=50&offset=0'),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0'),
		]).then(function (results) {
			var res = results[0];
			var locMap = indexById(results[1].data);
			clear(mount);
			setBusy(mount, false);
			if (!res.data.length) {
				mount.appendChild(emptyState(
					tr('No stocktakes yet'),
					tr('Start a cycle count to compare system stock with what is actually on the shelf.'),
					(ctx.isOffice || ctx.isAppAdmin)
						? btn(tr('New stocktake'), { primary: true, onclick: function () { openNewCampaignDialog(ctx); } })
						: null
				));
				return;
			}
			mount.appendChild(tableOrCards([
				{ label: tr('Name'), render: function (r) {
					return el('a', { href: stocktakeDetailUrl(ctx, r.id), text: r.name });
				} },
				{ label: tr('Location'), render: function (r) {
					return labelFromMap(locMap, r.locationId, 'code');
				} },
				{ label: tr('Status'), render: function (r) {
					var m = cycleStatusMeta(r.status);
					return el('span', { className: 'iv-badge ' + m.badge, text: m.label });
				} },
			], res.data));
		}).catch(function (err) {
			clear(mount);
			toast(err.message, true);
		});
	}

	function renderStocktakeDetail(ctx) {
		var mount = ctx.mount;
		var id = Number(ctx.entityId);
		setBusy(mount, true);
		clear(mount);
		Promise.all([
			api('GET', entityUrl(ctx.urls.api.cycleCounts, id)),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0'),
			api('GET', ctx.urls.api.items + '?limit=200&offset=0'),
		]).then(function (results) {
			var campaign = results[0];
			var locMap = indexById(results[1].data);
			var itemMap = indexById(results[2].data);
			clear(mount);
			setBusy(mount, false);
			clear(ctx.actions);
			var canOperate = ctx.isOffice || ctx.isAppAdmin;

			if (canOperate && campaign.status === 'open') {
				ctx.actions.appendChild(btn(tr('Start counting'), {
					primary: true,
					onclick: function () {
						api('POST', urlWithId(ctx.urls.api.cycleCountStart, id)).then(function () {
							toast(tr('Counting started.'));
							renderStocktakeDetail(ctx);
						}).catch(function (err) { toast(err.message, true); });
					},
				}));
			}
			if (canOperate && campaign.status === 'counting') {
				ctx.actions.appendChild(btn(tr('Close stocktake'), {
					primary: true,
					onclick: function () { openCloseCampaignDialog(ctx, campaign); },
				}));
			}

			var meta = cycleStatusMeta(campaign.status);
			mount.appendChild(el('section', { className: 'iv-section' }, [
				el('h2', { className: 'iv-section__title', text: campaign.name }),
				el('p', { className: 'iv-muted' }, [
					labelFromMap(locMap, campaign.locationId, 'code') + ' · ',
					el('span', { className: 'iv-badge ' + meta.badge, text: meta.label }),
				]),
			]));

			if (!campaign.lines || !campaign.lines.length) {
				mount.appendChild(el('p', { className: 'iv-muted', text: tr('No active items to count.') }));
				return;
			}

			var countedSoFar = campaign.lines.filter(function (l) { return l.qtyCounted != null; }).length;
			var conflictCount = campaign.lines.filter(function (l) { return l.conflict; }).length;
			mount.appendChild(el('p', {
				className: 'iv-muted',
				text: tr('Counted {done} of {total}.', { done: String(countedSoFar), total: String(campaign.lines.length) }),
			}));
			if (conflictCount > 0) {
				mount.appendChild(el('p', {
					className: 'iv-section__hint',
					role: 'status',
					text: tr('{count} line(s) changed after this stocktake started. Live quantity differs from the snapshot — resolve before closing, or confirm you accept the counted quantities.', { count: String(conflictCount) }),
				}));
			}

			mount.appendChild(tableOrCards([
				{ label: tr('Item'), render: function (r) {
					return labelFromMap(itemMap, r.itemId, 'sku');
				} },
				{ label: tr('System qty'), key: 'systemQty' },
				{ label: tr('Live qty'), render: function (r) {
					return document.createTextNode(formatQty(r.currentQty != null ? r.currentQty : r.systemQty));
				} },
				{ label: tr('Status'), render: function (r) {
					if (!r.conflict) {
						return el('span', { className: 'iv-muted', text: tr('OK') });
					}
					return el('span', {
						className: 'iv-badge iv-badge--warning',
						text: tr('Changed since snapshot'),
					});
				} },
				{ label: tr('Counted qty'), render: function (r) {
					if (campaign.status !== 'counting' || !canOperate) {
						return r.qtyCounted == null
							? el('span', { className: 'iv-muted', text: tr('Not counted') })
							: document.createTextNode(formatQty(r.qtyCounted));
					}
					var input = el('input', {
						type: 'number',
						min: '0',
						max: '1000000',
						step: qtyStep(ctx),
						className: 'iv-input',
						style: 'max-width:8rem',
						value: r.qtyCounted != null ? String(r.qtyCounted) : '',
						'aria-label': tr('Counted quantity'),
					});
					var save = btn(tr('Save'), {
						onclick: function () {
							if (input.value.trim() === '') {
								toast(tr('Enter a quantity of zero or more.'), true);
								return;
							}
							api('PUT', urlWithId(ctx.urls.api.cycleCountSetCount, r.id), { qtyCounted: input.value.trim() }).then(function () {
								toast(tr('Count saved.'));
								renderStocktakeDetail(ctx);
							}).catch(function (err) { toast(err.message, true); });
						},
					});
					return el('div', { className: 'iv-row__actions' }, [input, save]);
				} },
			], campaign.lines));
		}).catch(function (err) {
			clear(mount);
			toast(err.message, true);
		});
	}

	function openCloseCampaignDialog(ctx, campaign) {
		var uncounted = (campaign.lines || []).filter(function (l) { return l.qtyCounted == null; }).length;
		var conflicts = (campaign.lines || []).filter(function (l) { return l.conflict; }).length;
		var abandon = el('input', { type: 'checkbox', className: 'iv-switch__input', id: 'iv-abandon-uncounted' });
		var ackConflicts = el('input', { type: 'checkbox', className: 'iv-switch__input', id: 'iv-ack-conflicts' });
		var body = [
			el('p', { text: tr('Closing posts one stock adjustment per counted line so on-hand quantities match what was counted.') }),
		];
		if (conflicts > 0) {
			body.push(el('p', {
				className: 'iv-section__hint',
				role: 'status',
				text: tr('{count} line(s) changed after this stocktake started. Closing with the counted quantities will overwrite those later movements.', { count: String(conflicts) }),
			}));
			body.push(el('label', { className: 'iv-switch', for: 'iv-ack-conflicts' }, [
				ackConflicts,
				el('span', { className: 'iv-switch__label', text: tr('I reviewed the conflicts and accept the counted quantities') }),
			]));
		}
		if (uncounted > 0) {
			body.push(el('p', {
				className: 'iv-muted',
				text: tr('{count} item(s) were never counted.', { count: String(uncounted) }),
			}));
			body.push(el('label', { className: 'iv-switch', for: 'iv-abandon-uncounted' }, [
				abandon,
				el('span', { className: 'iv-switch__label', text: tr('Close anyway and leave uncounted items unchanged') }),
			]));
		}
		dialog(tr('Close stocktake'), body, function () {
			if (uncounted > 0 && !abandon.checked) {
				return Promise.reject(new ApiError('count_incomplete', tr('Count every item, or choose to leave uncounted items unchanged.')));
			}
			if (conflicts > 0 && !ackConflicts.checked) {
				return Promise.reject(new ApiError('count_conflict', tr('Review conflict lines, then confirm you accept the counted quantities.')));
			}
			var qs = '?abandonUncounted=' + (abandon.checked ? '1' : '0')
				+ '&acknowledgeConflicts=' + (ackConflicts.checked ? '1' : '0');
			return api('POST', urlWithId(ctx.urls.api.cycleCountClose, campaign.id) + qs, {}).then(function () {
				toast(tr('Stocktake closed.'));
				renderStocktakeDetail(ctx);
			});
		}, tr('Close'));
	}

	function renderStocktake(ctx) {
		if (ctx.entityId) {
			renderStocktakeDetail(ctx);
		} else {
			renderStocktakeList(ctx);
		}
	}

	function boot() {
		var ctx = readShell();
		if (!ctx || !ctx.mount) return;
		switch (ctx.page) {
			case 'dashboard': renderDashboard(ctx); break;
			case 'items': renderItems(ctx); break;
			case 'item-detail': renderItemDetail(ctx); break;
			case 'locations': renderLocations(ctx); break;
			case 'location-detail': renderLocationDetail(ctx); break;
			case 'movements': renderMovements(ctx); break;
			case 'stocktake': renderStocktake(ctx); break;
			case 'settings': renderSettings(ctx); break;
			default:
				clear(ctx.mount);
				ctx.mount.appendChild(emptyState(tr('Unknown page'), '', null));
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
