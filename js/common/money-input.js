/**
 * Money input helpers — typed decimal amount ⇄ integer minor units (cents).
 *
 * Users type "12.99" or "12,99" (European decimal comma); the API only ever
 * sees minor units. Keeping this in one place stops the Chrome number-input
 * trap where a comma entry silently becomes a hundred-times-too-big value.
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */
(function (global) {
	'use strict';

	// Whole-part cap keeps us well below 2^31 minor units on the server side.
	var RE = /^(\d{1,9})(?:[.,](\d{1,2}))?$/;

	/**
	 * Parse a typed amount.
	 * @param {string} raw
	 * @returns {number|string|null} integer minor units, '' for empty input,
	 *          or null when the text is not a valid money amount.
	 */
	function parse(raw) {
		var s = String(raw == null ? '' : raw).trim();
		if (s === '') {
			return '';
		}
		var m = RE.exec(s);
		if (!m) {
			return null;
		}
		var whole = parseInt(m[1], 10);
		var frac = m[2] ? (m[2].length === 1 ? m[2] + '0' : m[2]) : '00';
		return whole * 100 + parseInt(frac, 10);
	}

	/**
	 * Minor units → text an input can prefill with ("40000" → "400.00").
	 * Always the dotted form; the parse side accepts the comma back.
	 */
	function toInput(minor) {
		if (minor === null || minor === undefined || minor === '') {
			return '';
		}
		var n = Number(minor);
		if (!isFinite(n)) {
			return '';
		}
		return (n / 100).toFixed(2);
	}

	global.SbdMoneyInput = { parse: parse, toInput: toInput };
})(typeof window !== 'undefined' ? window : this);
