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

	function formatQty(n) {
		return String(Number(n));
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

	var IvApp = {
		kindMeta: kindMeta,
		locationKindLabel: locationKindLabel,
		formatQty: formatQty,
		canReverseMovement: canReverseMovement,
		movementListQuery: movementListQuery,
		dateInputToUnix: dateInputToUnix,
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
			isOffice: root.getAttribute('data-iv-is-office') === '1',
			allowNegative: root.getAttribute('data-iv-allow-negative') === '1',
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

	function applyFieldErrors(root, details) {
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
			wrap._ivError.textContent = d.message || d.code || tr('Please check this field.');
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
					var inline = applyFieldErrors(bodyEl, err && err.details);
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
		Promise.all(fetches).then(function (results) {
			var low = results[0];
			var mov = results[1];
			var negatives = !ctx.allowNegative ? results[2] : { data: [] };
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

	function byCodeUrl(ctx, code) {
		var tmpl = ctx.urls.api.itemByCode || '';
		return tmpl.replace('__CODE__', encodeURIComponent(code));
	}

	function entityUrl(base, id) {
		return String(base || '').replace(/\/?$/, '/') + id;
	}

	function labelPrintUrl(ctx, id) {
		return String(ctx.urls.api.itemLabelPrint || '').replace(/\/0(\/|$)/, '/' + id + '$1');
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

	function loadMasters(ctx) {
		return Promise.all([
			api('GET', ctx.urls.api.items + '?limit=200&offset=0&active=1'),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0&active=1'),
		]).then(function (res) {
			return { items: res[0].data || [], locations: res[1].data || [] };
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
		var qtyAttrs = {
			type: 'number',
			max: '1000000',
			required: '',
			className: 'iv-input',
			value: opts.adjust ? '0' : '1',
		};
		if (!opts.adjust) {
			qtyAttrs.min = '1';
		}
		var qty = el('input', qtyAttrs);
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
				return r.name + ' — ' + r.code;
			}, tr('Choose a location'));
			if (toSelect) {
				fillSelect(toSelect, masters.locations, 'id', function (r) {
					return r.name + ' — ' + r.code;
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
			fields.push(field(tr('Reason (optional)'), reason, { name: 'reason' }));

			if (opts.prefill) {
				if (opts.prefill.itemId) itemSelect.value = String(opts.prefill.itemId);
				if (opts.prefill.locationId) locSelect.value = String(opts.prefill.locationId);
				if (opts.prefill.toLocationId && toSelect) toSelect.value = String(opts.prefill.toLocationId);
				if (opts.prefill.qty != null) qty.value = String(opts.prefill.qty);
				if (opts.prefill.reason) reason.value = opts.prefill.reason;
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
						qty: Number(qty.value),
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
							qtyDelta: Number(opts.prefill.qtyDelta),
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
						qty: Number(qty.value),
						reason: reason.value || null,
					}).then(function (result) {
						toast(tr('Stock adjusted.'));
						refreshAfterMutation(ctx, result);
					});
				}
				return api('POST', movementApi(ctx, opts.path), {
					itemId: itemId,
					locationId: locationId,
					qty: Number(qty.value),
					reason: reason.value || null,
				}).then(function (result) {
					toast(opts.okToast);
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

	function renderItems(ctx) {
		var mount = ctx.mount;
		setBusy(mount, true);
		clear(mount);
		if (ctx.isOffice) {
			clear(ctx.actions);
			ctx.actions.appendChild(btn(tr('New item'), {
				primary: true,
				onclick: function () { openItemDialog(ctx, null); },
			}));
		}
		var q = el('input', {
			id: 'iv-items-q',
			type: 'search',
			className: 'iv-input form-input',
			placeholder: tr('Search name or SKU'),
			'aria-label': tr('Search items'),
		});
		mount.appendChild(el('section', {
			className: 'iv-card iv-filter-panel',
			'aria-labelledby': 'iv-items-filter-title',
		}, [
			el('header', { className: 'iv-filter-panel__head' }, [
				el('h2', { id: 'iv-items-filter-title', text: tr('Filter') }),
				el('p', {
					className: 'iv-filter-panel__intro',
					text: tr('Find items by name or SKU.'),
				}),
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
					el('div', { className: 'iv-filter-grid' }, [
						el('div', { className: 'iv-filter-field' }, [
							el('label', { className: 'iv-filter-field__label', for: 'iv-items-q', text: tr('Search') }),
							el('div', { className: 'iv-filter-field__control' }, [q]),
						]),
						el('div', { className: 'iv-filter-actions' }, [
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
		]));
		var listHost = el('div', { id: 'iv-items-list', className: 'iv-card' });
		mount.appendChild(listHost);

		function load() {
			setBusy(listHost, true);
			api('GET', ctx.urls.api.items + '?limit=50&offset=0&q=' + encodeURIComponent(q.value || '')).then(function (res) {
				clear(listHost);
				setBusy(listHost, false);
				listHost.appendChild(el('header', { className: 'iv-card__header' }, [
					el('h2', { className: 'iv-card__title', text: tr('Items') }),
				]));
				var body = el('div', { className: 'iv-card__body' });
				listHost.appendChild(body);
				if (!res.data.length) {
					body.appendChild(emptyState(
						tr('No items yet'),
						tr('Create an item with a SKU and reorder level.'),
						(ctx.isOffice || ctx.isAppAdmin)
							? btn(tr('New item'), { primary: true, onclick: function () { openItemDialog(ctx, null); } })
							: null
					));
					return;
				}
				body.appendChild(tableOrCards([
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
				], res.data));
			}).catch(function (err) {
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
		var reorder = el('input', { type: 'number', className: 'iv-input', min: '0', max: '1000000', value: existing ? String(existing.reorderLevel) : '0' });
		dialog(existing ? tr('Edit item') : tr('New item'), [
			field(tr('SKU'), sku, { name: 'sku' }),
			field(tr('Scan code (optional)'), scan, { name: 'scanCode' }),
			field(tr('Name'), name, { name: 'name' }),
			field(tr('Unit'), uom, { name: 'uom' }),
			field(tr('Reorder level'), reorder, { name: 'reorderLevel' }),
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
				reorderLevel: Number(reorder.value),
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
			mount.appendChild(el('section', { className: 'iv-section' }, [
				el('h2', { className: 'iv-section__title', text: item.name }),
				el('p', { className: 'iv-muted', text: tr('SKU') + ': ' + item.sku + ' · ' + tr('Scan code') + ': ' + item.scanCode }),
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
		setBusy(mount, true);
		if (ctx.isOffice) {
			clear(ctx.actions);
			ctx.actions.appendChild(btn(tr('New location'), {
				primary: true,
				onclick: function () { openLocationDialog(ctx); },
			}));
		}
		api('GET', ctx.urls.api.locations + '?limit=50&offset=0').then(function (res) {
			clear(mount);
			setBusy(mount, false);
			if (!res.data.length) {
				mount.appendChild(emptyState(
					tr('No locations yet'),
					tr('Add a warehouse or van to hold stock.'),
					(ctx.isOffice || ctx.isAppAdmin)
						? btn(tr('New location'), { primary: true, onclick: function () { openLocationDialog(ctx); } })
						: null
				));
				return;
			}
			mount.appendChild(el('section', { className: 'iv-card', 'aria-labelledby': 'iv-locations-title' }, [
				el('header', { className: 'iv-card__header' }, [
					el('h2', { id: 'iv-locations-title', className: 'iv-card__title', text: tr('Locations') }),
				]),
				el('div', { className: 'iv-card__body' }, [
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
				]),
			]));
		}).catch(function (err) {
			clear(mount);
			toast(err.message, true);
		});
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
		]).then(function (results) {
			var loc = results[0];
			var bals = results[1];
			var itemMap = indexById(results[2].data);
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
		return el('nav', {
			className: 'iv-pagination',
			'aria-label': tr('Pagination'),
		}, [
			btn(tr('Previous'), {
				disabled: offset <= 0,
				onclick: function () {
					if (offset <= 0) return;
					onPage(Math.max(0, offset - limit));
				},
			}),
			el('p', {
				className: 'iv-pagination__info',
				text: tr('Showing {from}–{to} of {total}', {
					from: String(from),
					to: String(to),
					total: String(total),
				}) + (pages > 1 ? ' · ' + tr('Page {page} of {pages}', {
					page: String(page),
					pages: String(pages),
				}) : ''),
			}),
			btn(tr('Next'), {
				disabled: offset + limit >= total,
				onclick: function () {
					if (offset + limit >= total) return;
					onPage(offset + limit);
				},
			}),
		]);
	}

	function renderMovements(ctx, filters) {
		var mount = ctx.mount;
		var state = Object.assign({
			kind: '',
			itemId: '',
			locationId: '',
			fromDate: '',
			toDate: '',
			transferGroup: null,
			limit: 50,
			offset: 0,
		}, filters || {});
		setBusy(mount, true);
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
			api('GET', ctx.urls.api.items + '?limit=200&offset=0'),
			api('GET', ctx.urls.api.locations + '?limit=200&offset=0'),
		]).then(function (results) {
			var res = results[0];
			var items = results[1].data || [];
			var locations = results[2].data || [];
			var itemMap = indexById(items);
			var locMap = indexById(locations);
			clear(mount);
			setBusy(mount, false);

			var kindSelect = el('select', {
				className: 'iv-input',
				id: 'iv-mov-kind',
				'aria-label': tr('Kind'),
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
			var itemSelect = el('select', {
				className: 'iv-input',
				id: 'iv-mov-item',
				'aria-label': tr('Item'),
			});
			fillSelect(itemSelect, items, 'id', function (r) {
				return r.name + ' (' + r.sku + ')';
			}, tr('All items'));
			if (state.itemId) itemSelect.value = String(state.itemId);
			var locSelect = el('select', {
				className: 'iv-input',
				id: 'iv-mov-loc',
				'aria-label': tr('Location'),
			});
			fillSelect(locSelect, locations, 'id', function (r) {
				return r.name + ' (' + r.code + ')';
			}, tr('All locations'));
			if (state.locationId) locSelect.value = String(state.locationId);
			var fromInput = el('input', {
				type: 'date',
				className: 'iv-input',
				id: 'iv-mov-from',
				value: state.fromDate || '',
				'aria-label': tr('From date'),
			});
			var toInput = el('input', {
				type: 'date',
				className: 'iv-input',
				id: 'iv-mov-to',
				value: state.toDate || '',
				'aria-label': tr('To date'),
			});

			function readFilters(extra) {
				return Object.assign({
					kind: kindSelect.value || '',
					itemId: itemSelect.value || '',
					locationId: locSelect.value || '',
					fromDate: fromInput.value || '',
					toDate: toInput.value || '',
					transferGroup: state.transferGroup,
					limit: state.limit,
					offset: 0,
				}, extra || {});
			}

			mount.appendChild(el('section', {
				className: 'iv-card iv-filter-panel',
				'aria-labelledby': 'iv-mov-filter-title',
			}, [
				el('header', { className: 'iv-filter-panel__head' }, [
					el('h2', { id: 'iv-mov-filter-title', text: tr('Filter') }),
					el('p', {
						className: 'iv-filter-panel__intro',
						text: tr('Narrow bookings by kind, item, location, or date.'),
					}),
				]),
				el('div', { className: 'iv-filter-panel__body' }, [
					el('form', {
						className: 'iv-filter-panel__form iv-filterbar',
						role: 'search',
						'aria-label': tr('Filter movements'),
						onsubmit: function (ev) {
							ev.preventDefault();
							renderMovements(ctx, readFilters());
						},
					}, [
						el('div', { className: 'iv-filter-grid iv-filter-grid--extended' }, [
							el('div', { className: 'iv-filter-field' }, [
								el('label', { className: 'iv-filter-field__label', for: 'iv-mov-kind', text: tr('Kind') }),
								el('div', { className: 'iv-filter-field__control' }, [kindSelect]),
							]),
							el('div', { className: 'iv-filter-field' }, [
								el('label', { className: 'iv-filter-field__label', for: 'iv-mov-item', text: tr('Item') }),
								el('div', { className: 'iv-filter-field__control' }, [itemSelect]),
							]),
							el('div', { className: 'iv-filter-field' }, [
								el('label', { className: 'iv-filter-field__label', for: 'iv-mov-loc', text: tr('Location') }),
								el('div', { className: 'iv-filter-field__control' }, [locSelect]),
							]),
							el('div', { className: 'iv-filter-field iv-filter-field--dates' }, [
								el('span', { className: 'iv-filter-field__label', text: tr('Date range') }),
								el('div', { className: 'iv-filter-field__control' }, [
									el('div', { className: 'iv-date-range' }, [
										el('div', { className: 'iv-date-range__part' }, [
											el('label', { className: 'iv-date-range__sublabel', for: 'iv-mov-from', text: tr('From') }),
											fromInput,
										]),
										el('span', { className: 'iv-date-range__sep', text: '–' }),
										el('div', { className: 'iv-date-range__part' }, [
											el('label', { className: 'iv-date-range__sublabel', for: 'iv-mov-to', text: tr('To') }),
											toInput,
										]),
									]),
								]),
							]),
							el('div', { className: 'iv-filter-actions' }, [
								btn(tr('Apply filters'), { primary: true, type: 'submit' }),
								btn(tr('Clear filters'), {
									type: 'button',
									onclick: function () { renderMovements(ctx, null); },
								}),
							]),
						]),
					]),
				]),
			]));

			if (state.transferGroup) {
				mount.appendChild(el('div', { className: 'iv-filter-bar' }, [
					el('p', {
						className: 'iv-muted',
						text: tr('Showing transfer group') + ': ' + state.transferGroup,
					}),
					btn(tr('Clear filter'), {
						onclick: function () {
							renderMovements(ctx, readFilters({ transferGroup: null }));
						},
					}),
				]));
			}

			if (!res.data.length) {
				mount.appendChild(emptyState(
					tr('No movements yet'),
					tr('Bookings appear here after receive, issue, transfer, or adjust.'),
					(ctx.isOffice || ctx.isAppAdmin)
						? btn(tr('Receive stock'), { primary: true, onclick: function () { openReceiveDialog(ctx); } })
						: btn(tr('Issue stock'), { primary: true, onclick: function () { openIssueDialog(ctx); } })
				));
				return;
			}
			mount.appendChild(tableOrCards([
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
				renderMovements(ctx, readFilters({ offset: nextOffset }));
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
		]).then(function (results) {
			var cfg = results[0];
			var lic = results[1];
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

			function lines(ta) {
				return ta.value.split(/\n+/).map(function (s) { return s.trim(); }).filter(Boolean);
			}

			mount.appendChild(el('section', { className: 'iv-section', 'aria-labelledby': 'iv-access-title' }, [
				el('h2', { id: 'iv-access-title', className: 'iv-section__title', text: tr('Access') }),
				el('label', { className: 'iv-switch', for: 'iv-access-restriction' }, [
					restriction,
					el('span', { className: 'iv-switch__label', text: tr('Restrict access to allow-listed users and groups') }),
				]),
				field(tr('Allowed users (one per line)'), allowedUsers, { name: 'allowedUsers' }),
				field(tr('Allowed groups (one per line)'), allowedGroups, { name: 'allowedGroups' }),
				btn(tr('Save access'), {
					primary: true,
					onclick: function () {
						api('POST', ctx.urls.api.configAccess, {
							accessRestrictionEnabled: restriction.checked,
							allowedUsers: lines(allowedUsers),
							allowedGroups: lines(allowedGroups),
							appAdmins: cfg.appAdmins || [],
						}).then(function () {
							toast(tr('Access settings saved.'));
						}).catch(function (err) { toast(err.message, true); });
					},
				}),
			]));

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
						api('POST', ctx.urls.api.configOffice, {
							officeUsers: lines(officeUsers),
							officeGroups: lines(officeGroups),
							allowNegativeStock: neg.checked,
						}).then(function () {
							toast(tr('Office settings saved.'));
						}).catch(function (err) { toast(err.message, true); });
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
