'use strict';

/**
 * Soft-keyboard IME helper — must never inflate dialogs on desktop focus.
 */

const { describe, it, beforeEach } = require('node:test');
const assert = require('node:assert/strict');
const api = require('../../js/common/keep-focused-visible.js');

const {
	KEYBOARD_SHRINK_PX,
	PAD_ATTR,
	needsImeReveal,
	softKeyboardLikelyOpen,
	shouldAutoReveal,
	resolvePadHost,
	ensureFocusedVisible,
	ensureKeyboardScrollRoom,
	_resetPadHostForTests,
} = api;

describe('inventorycheck keep-focused-visible', () => {
	beforeEach(() => {
		_resetPadHostForTests();
		delete global.window;
		delete global.document;
		delete global.Element;
		delete global.HTMLButtonElement;
		delete global.HTMLInputElement;
		delete global.HTMLSelectElement;
	});

	it('exports soft-keyboard gate constants', () => {
		assert.equal(KEYBOARD_SHRINK_PX, 120);
		assert.equal(typeof needsImeReveal, 'function');
		assert.equal(typeof softKeyboardLikelyOpen, 'function');
		assert.equal(typeof shouldAutoReveal, 'function');
	});

	it('softKeyboardLikelyOpen requires a large visualViewport shrink', () => {
		assert.equal(
			softKeyboardLikelyOpen({
				innerHeight: 900,
				visualViewport: { height: 820, offsetTop: 0 },
			}),
			false,
		);
		assert.equal(
			softKeyboardLikelyOpen({
				innerHeight: 900,
				visualViewport: { height: 500, offsetTop: 0 },
			}),
			true,
		);
	});

	it('needsImeReveal ignores buttons, checkboxes, selects, and date pickers', () => {
		function Element() {}
		function HTMLButtonElement() {}
		function HTMLInputElement() {}
		function HTMLSelectElement() {}
		global.Element = Element;
		global.HTMLButtonElement = HTMLButtonElement;
		global.HTMLInputElement = HTMLInputElement;
		global.HTMLSelectElement = HTMLSelectElement;
		Object.setPrototypeOf(HTMLButtonElement.prototype, Element.prototype);
		Object.setPrototypeOf(HTMLInputElement.prototype, Element.prototype);
		Object.setPrototypeOf(HTMLSelectElement.prototype, Element.prototype);

		const btn = new HTMLButtonElement();
		btn.matches = () => true;
		assert.equal(needsImeReveal(btn), false);

		const checkbox = new HTMLInputElement();
		checkbox.type = 'checkbox';
		checkbox.matches = () => true;
		assert.equal(needsImeReveal(checkbox), false);

		const select = new HTMLSelectElement();
		select.matches = () => true;
		assert.equal(needsImeReveal(select), false);

		const date = new HTMLInputElement();
		date.type = 'date';
		date.matches = () => true;
		assert.equal(needsImeReveal(date), false);

		const search = new HTMLInputElement();
		search.type = 'search';
		search.matches = () => true;
		assert.equal(needsImeReveal(search), true);
	});

	it('resolvePadHost prefers .iv-dialog__body over the dialog shell', () => {
		const body = { style: { paddingBottom: '' }, className: 'iv-dialog__body' };
		const dialog = {
			style: { paddingBottom: '' },
			className: 'iv-dialog',
			querySelector: (sel) => (String(sel).includes('.iv-dialog__body') ? body : null),
		};
		const field = {
			closest(sel) {
				if (String(sel).includes('.iv-dialog') || String(sel).includes('[role="dialog"]')) {
					return dialog;
				}
				return null;
			},
		};
		const doc = { querySelector: () => null, documentElement: {} };
		assert.equal(resolvePadHost(doc, field), body);
	});

	it('desktop focus without soft keyboard never pads the dialog', () => {
		function Element() {}
		function HTMLInputElement() {}
		global.Element = Element;
		global.HTMLInputElement = HTMLInputElement;
		Object.setPrototypeOf(HTMLInputElement.prototype, Element.prototype);

		const body = {
			style: { paddingBottom: '' },
			attrs: {},
			getAttribute(k) {
				return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null;
			},
			setAttribute(k, v) {
				this.attrs[k] = v;
			},
			removeAttribute(k) {
				delete this.attrs[k];
			},
			getBoundingClientRect: () => ({ top: 100, bottom: 140, height: 40, left: 0, right: 300 }),
		};
		const dialog = {
			style: { paddingBottom: '' },
			className: 'iv-dialog',
			querySelector: () => body,
		};
		const field = new HTMLInputElement();
		field.type = 'search';
		field.matches = () => true;
		field.closest = (sel) =>
			String(sel).includes('.iv-dialog') || String(sel).includes('[role="dialog"]') ? dialog : null;
		field.getBoundingClientRect = () => ({ top: 100, bottom: 140, height: 40, left: 0, right: 300 });

		global.document = {
			querySelector: () => null,
			querySelectorAll: () => [],
			documentElement: {},
			getElementById: () => null,
		};
		global.window = {
			innerHeight: 900,
			visualViewport: { height: 820, offsetTop: 0 },
			getComputedStyle: () => ({
				position: 'static',
				display: 'block',
				visibility: 'visible',
				overflowY: 'visible',
				overflowX: 'visible',
			}),
		};

		assert.equal(shouldAutoReveal(field, global.window), false);
		const result = ensureFocusedVisible(field, global.window);
		assert.deepEqual(result, { moved: false, delta: 0 });
		assert.equal(body.style.paddingBottom, '');
		assert.equal(dialog.style.paddingBottom, '');
		assert.equal(body.getAttribute(PAD_ATTR), null);
	});

	it('ensureKeyboardScrollRoom can clear an existing pad', () => {
		const host = {
			style: { paddingBottom: '' },
			attrs: {},
			getAttribute(k) {
				return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null;
			},
			setAttribute(k, v) {
				this.attrs[k] = v;
			},
			removeAttribute(k) {
				delete this.attrs[k];
			},
			querySelector: () => null,
		};
		const field = {
			closest(sel) {
				if (
					String(sel).includes('.iv-dialog') ||
					String(sel).includes('[role="dialog"]') ||
					String(sel).includes('.modal')
				) {
					return host;
				}
				return null;
			},
		};
		global.document = {
			querySelector: () => null,
			getElementById: () => null,
			documentElement: {},
		};

		ensureKeyboardScrollRoom(global.document, 200, field);
		assert.equal(host.style.paddingBottom, '200px');
		assert.equal(host.getAttribute(PAD_ATTR), '');
		ensureKeyboardScrollRoom(global.document, 0, field);
		assert.equal(host.style.paddingBottom, '');
		assert.equal(host.getAttribute(PAD_ATTR), null);
	});
});
