// @ts-check

/**
 * Keyboard-only journey helpers — Tab navigation assertions without any
 * pointer input. A leg that cannot reach a control with Tab is a WCAG
 * keyboard-access failure, so the helper throws rather than falls back.
 */

/**
 * Press Tab until document.activeElement matches the given selector (and,
 * optionally, contains text). Bounded so a focus-trap bug fails loudly
 * instead of looping forever.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{ sel?: string, text?: string }} matcher
 * @param {{ max?: number, shift?: boolean }} [opts]
 */
export async function tabUntil(page, matcher, opts = {}) {
	const max = opts.max ?? 60
	for (let i = 0; i < max; i++) {
		const hit = await page.evaluate((m) => {
			const el = document.activeElement
			if (!el || el === document.body || el === document.documentElement) return false
			if (m.sel && !el.matches(m.sel)) return false
			if (m.text && !(el.textContent || '').includes(m.text)) return false
			return true
		}, matcher)
		if (hit) return
		await page.keyboard.press(opts.shift ? 'Shift+Tab' : 'Tab')
	}
	throw new Error(`tabUntil: focus never reached ${JSON.stringify(matcher)} after ${max} Tab presses`)
}

/**
 * Keyboard-activate an element: focus it and press Enter. `focus()` is the
 * suite's accepted entry point for page-level targets (full-page Tab walks
 * the whole NC chrome); everything inside dialogs must still tabUntil.
 *
 * @param {import('@playwright/test').Locator} locator
 * @param {import('@playwright/test').Page} page
 */
export async function activateByKeyboard(locator, page) {
	await locator.focus()
	await page.keyboard.press('Enter')
}
