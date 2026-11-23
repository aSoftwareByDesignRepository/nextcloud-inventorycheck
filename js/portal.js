/**
 * Member portal — standalone guest surface (no app.js dependency).
 * Token comes from the URL; every API answer decides validity
 * (uniform 404 → single "link not valid" message, no oracle).
 */
(function () {
	'use strict';

	var tr = function (s, vars) {
		var out = OC.L10N.translate('inventorycheck', s);
		Object.keys(vars || {}).forEach(function (k) {
			out = out.split('{' + k + '}').join(String(vars[k]));
		});
		return out;
	};

	function el(tag, attrs, kids) {
		var node = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			if (k === 'className') node.className = attrs[k];
			else if (k === 'text') node.textContent = attrs[k];
			else if (k === 'value') node.value = attrs[k];
			else if (k === 'disabled' || k === 'required') { if (attrs[k]) node.setAttribute(k, ''); }
			else if (k === 'hidden') { if (attrs[k]) node.setAttribute('hidden', ''); }
			else if (k.slice(0, 2) === 'on' && typeof attrs[k] === 'function') node.addEventListener(k.slice(2), attrs[k]);
			else node.setAttribute(k, attrs[k]);
		});
		(kids || []).forEach(function (k) { if (k) node.appendChild(k); });
		return node;
	}
	function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }

	function api(method, url, body) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' },
		};
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		return fetch(url, opts).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) {
					var e = new Error((data && data.error && data.error.message) || 'error');
					e.code = (data && data.error && data.error.code) || 'error';
					e.status = res.status;
					throw e;
				}
				return data;
			});
		});
	}

	var root = document.getElementById('iv-portal-root');
	var token = root ? root.getAttribute('data-token') : '';
	var apiBase = OC.generateUrl('/apps/inventorycheck/portal/' + encodeURIComponent(token));

	function fail(msg) {
		root.removeAttribute('aria-busy');
		clear(root);
		root.appendChild(el('h1', { className: 'iv-section__title', text: tr('Your lending page') }));
		root.appendChild(el('p', { className: 'iv-muted', role: 'alert', text: msg }));
	}

	function fmtTs(ts) {
		if (!ts) return '—';
		return new Date(ts * 1000).toLocaleDateString();
	}
	function badge(kind, text) {
		var cls = 'iv-badge ' + (kind === 'available' ? 'iv-badge--success' : (kind === 'overdue' ? 'iv-badge--overdue' : 'iv-badge--warn'));
		return el('span', { className: cls, text: text });
	}

	function render(state) {
		root.removeAttribute('aria-busy');
		clear(root);
		var itemNames = {};
		(state.items || []).forEach(function (it) { itemNames[it.id] = it.name; });
		var name = (state.person && state.person.displayName) || '';

		root.appendChild(el('h1', { className: 'iv-section__title', text: tr('Your lending page') }));
		if (name) root.appendChild(el('p', { className: 'iv-muted', text: tr('Hello {name}.', { name: name }) }));

		// — open loans —
		var loanSec = el('section', { className: 'iv-section' }, [
			el('h2', { className: 'iv-section__title', text: tr('Your borrowed items') }),
		]);
		if (!state.loans || !state.loans.length) {
			loanSec.appendChild(el('p', { className: 'iv-muted', text: tr('You have nothing borrowed right now.') }));
		} else {
			var ul = el('ul', { className: 'iv-portal-list' });
			state.loans.forEach(function (l) {
				var li = el('li', { className: 'iv-portal-list__item' }, [
					el('span', null, [
						el('strong', { text: l.itemName || ('#' + l.itemId) }),
						document.createTextNode(' × ' + l.qty + ' '),
						l.dueAt ? badge(l.overdue ? 'overdue' : 'available',
							(l.overdue ? tr('overdue') + ' · ' : tr('due') + ' ') + fmtTs(l.dueAt)) : null,
					]),
					el('button', {
						className: 'iv-btn iv-btn--ghost', type: 'button',
						text: tr('Report a problem'),
						onclick: function () { reportDamage(l); },
					}),
				]);
				ul.appendChild(li);
			});
			loanSec.appendChild(ul);
		}
		root.appendChild(loanSec);

		// — my requests —
		var reqSec = el('section', { className: 'iv-section' }, [
			el('h2', { className: 'iv-section__title', text: tr('Your requests') }),
		]);
		var reqs = state.requests || [];
		if (!reqs.length) {
			reqSec.appendChild(el('p', { className: 'iv-muted', text: tr('No requests yet.') }));
		} else {
			var rq = el('ul', { className: 'iv-portal-list' });
			reqs.forEach(function (r) {
				var statusText = r.status === 'pending' ? tr('waiting')
					: (r.status === 'fulfilled' ? tr('approved')
					: (r.status === 'declined' ? tr('declined') : tr('cancelled')));
				var li = el('li', { className: 'iv-portal-list__item' }, [
					el('span', null, [
						el('strong', { text: itemNames[r.itemId] || ('#' + r.itemId) }),
						document.createTextNode(' × ' + r.qty + ' '),
						badge(r.status === 'pending' ? 'overdue' : 'available', statusText),
						r.wantedFrom || r.wantedTo
							? document.createTextNode(' · ' + fmtTs(r.wantedFrom) + ' → ' + fmtTs(r.wantedTo))
							: null,
					]),
					r.status === 'pending' ? el('button', {
						className: 'iv-btn iv-btn--ghost', type: 'button',
						text: tr('Cancel'),
						onclick: function () { cancelRequest(r); },
					}) : null,
				]);
				rq.appendChild(li);
			});
			reqSec.appendChild(rq);
		}
		root.appendChild(reqSec);

		// — request form —
		var avail = (state.items || []).filter(function (i) { return i.badge === 'available'; });
		var formSec = el('section', { className: 'iv-section' }, [
			el('h2', { className: 'iv-section__title', text: tr('Request an item') }),
		]);
		if (!avail.length) {
			formSec.appendChild(el('p', { className: 'iv-muted', text: tr('Nothing available to request right now.') }));
		} else {
			var itemSel = el('select', { className: 'iv-input', required: true, 'aria-label': tr('Item') },
				avail.map(function (i) { return el('option', { value: String(i.id), text: i.name }); }));
			var qtyIn = el('input', { className: 'iv-input', type: 'number', min: '1', value: '1', required: true, 'aria-label': tr('Quantity') });
			var fromIn = el('input', { className: 'iv-input', type: 'date', 'aria-label': tr('Needed from') });
			var toIn = el('input', { className: 'iv-input', type: 'date', 'aria-label': tr('Needed until') });
			var purp = el('textarea', { className: 'iv-input', rows: '2', maxlength: '2000', 'aria-label': tr('What for? (optional)'), placeholder: tr('What for? (optional)') });
			var status = el('p', { className: 'iv-muted', role: 'status', 'aria-live': 'polite' });
			var submit = el('button', {
				className: 'iv-btn iv-btn--primary', type: 'submit', text: tr('Send request'),
			});
			var form = el('form', {
				className: 'iv-form',
				onsubmit: function (e) {
					e.preventDefault();
					submit.disabled = true;
					api('POST', apiBase + '/requests', {
						itemId: parseInt(itemSel.value, 10),
						qty: parseInt(qtyIn.value, 10) || 0,
						purpose: purp.value.trim() || null,
						wantedFrom: fromIn.value ? Math.floor(new Date(fromIn.value + 'T00:00:00').getTime() / 1000) : null,
						wantedTo: toIn.value ? Math.floor(new Date(toIn.value + 'T23:59:59').getTime() / 1000) : null,
					}).then(function () {
						status.textContent = tr('Request sent — the office will take a look.');
						return refresh();
					}).catch(function (err) {
						submit.disabled = false;
						status.textContent = err.status === 404 ? tr('This link is not valid.') : (err.message || tr('Could not send.'));
					});
				},
			}, [
				el('div', { className: 'iv-field' }, [el('label', { text: tr('Item') }), itemSel]),
				el('div', { className: 'iv-field' }, [el('label', { text: tr('Quantity') }), qtyIn]),
				el('div', { className: 'iv-field' }, [el('label', { text: tr('Needed from') }), fromIn]),
				el('div', { className: 'iv-field' }, [el('label', { text: tr('Needed until') }), toIn]),
				el('div', { className: 'iv-field' }, [purp]),
				submit,
				status,
			]);
			formSec.appendChild(form);
		}
		root.appendChild(formSec);
	}

	function refresh() {
		return api('GET', apiBase + '/api').then(render).catch(function () {
			fail(tr('This link is not valid or has expired. Ask the office for a new one.'));
		});
	}
	function cancelRequest(r) {
		api('POST', apiBase + '/requests/' + r.id + '/cancel', {}).then(refresh).catch(function () {
			fail(tr('This link is not valid.'));
		});
	}
	/** Overlay dialog — same recipe as app.js dialog(), scaled to the guest page. */
	function miniDialog(title, bodyNodes, onConfirm, confirmLabel) {
		var prev = document.activeElement;
		var overlay = el('div', { className: 'modal-backdrop iv-dialog-overlay', role: 'presentation' });
		var dlg = el('div', {
			className: 'modal iv-dialog', role: 'dialog', 'aria-modal': 'true',
			'aria-labelledby': 'iv-portal-dialog-title',
		});
		document.body.style.overflow = 'hidden';
		var closed = false;
		var onKey = null;
		var close = function () {
			if (closed) return;
			closed = true;
			if (onKey) document.removeEventListener('keydown', onKey);
			overlay.remove();
			document.body.style.overflow = '';
			if (prev && prev.isConnected && typeof prev.focus === 'function') { try { prev.focus(); } catch (e) {} }
		};
		var err = el('p', { className: 'iv-field-error', role: 'alert', hidden: true });
		var cancel = el('button', { className: 'iv-btn', type: 'button', text: tr('Cancel'), onclick: close });
		var ok = el('button', {
			className: 'iv-btn iv-btn--primary', type: 'button', text: confirmLabel || tr('OK'),
			onclick: function () {
				if (ok.disabled) return;
				ok.disabled = true;
				Promise.resolve(onConfirm(err)).then(function (closeIt) {
					ok.disabled = false;
					if (closeIt !== false) close();
				});
			},
		});
		dlg.appendChild(el('header', { className: 'iv-dialog__header' }, [
			el('h2', { id: 'iv-portal-dialog-title', className: 'iv-dialog__title', text: title }),
			el('button', {
				type: 'button', className: 'button iv-dialog__close',
				'aria-label': tr('Close'), title: tr('Close'), onclick: close, text: '×',
			}),
		]));
		var body = el('div', { className: 'iv-dialog__body' }, bodyNodes);
		body.appendChild(err);
		dlg.appendChild(body);
		dlg.appendChild(el('div', { className: 'iv-dialog__actions' }, [cancel, ok]));
		overlay.appendChild(dlg);
		overlay.addEventListener('click', function (ev) { if (ev.target === overlay) close(); });
		onKey = function (ev) { if (ev.key === 'Escape') { ev.preventDefault(); close(); } };
		document.addEventListener('keydown', onKey);
		document.body.appendChild(overlay);
		var first = dlg.querySelector('input, select, textarea, button:not(.iv-dialog__close)');
		if (first) first.focus();
	}

	function reportDamage(l) {
		var note = el('textarea', { className: 'iv-input', rows: '3', maxlength: '2000', required: true });
		miniDialog(tr('Report a problem'), [
			el('div', { className: 'iv-field' }, [
				el('label', { text: tr('Describe the problem (broken, scratched, missing parts…)') }),
				note,
			]),
		], function (err) {
			var v = note.value.trim();
			if (!v) { err.hidden = false; err.textContent = tr('Please describe the problem briefly.'); return false; }
			return api('POST', apiBase + '/loans/' + l.id + '/damage', { note: v }).then(function () {
				notify(tr('Thank you — the office has been notified.'));
				return true;
			}).catch(function (e) {
				err.hidden = false;
				err.textContent = e.status === 404 ? tr('This link is not valid.') : tr('Could not send — please try again.');
				return false;
			});
		}, tr('Send'));
	}

	var live = el('p', { className: 'iv-muted', role: 'status', 'aria-live': 'polite', hidden: true });
	document.body.appendChild(live);
	function notify(msg) { live.hidden = false; live.textContent = msg; }

	refresh();
})();
