// @ts-check
/**
 * ATLAS_BTN_VARIANT_ARMOR — learned-regression guard for
 * button_variant_specificity_collapse
 * (iv-vis-btn-variant-collapse-ghost).
 *
 * app.js dialog() appends .iv-dialog-overlay > .iv-dialog to document.body —
 * outside #app-content(.iv-app) — where NC core
 * `button:not(.button-vue,[class^=vs__])` (specificity 0-1-1) repaints every
 * <button> with the pale --color-primary-element-light fill. A lone
 * .iv-btn--{variant} class (0-1-0) loses, so variant <button>s render
 * identical pale siblings. Anchors are never repainted by core — an in-page
 * <a> keeps the true variant paint and is the oracle this defect hid behind
 * on the sealed crafts.
 *
 * Probes (light + dark):
 *   - in-content <button class="iv-btn iv-btn--{v}"> vs <a> oracles — the
 *     real ghost surface (settings ACL "Clear grants" rows, app.js:5422)
 *   - real dialog (items page New-item dialog) .button.primary confirm
 *     <button> vs the primary <a> oracle — .iv-dialog scope armor
 *   - variant <button> probes injected into the real open dialog body vs
 *     <a> oracles — covers ghost/danger/tertiary inside .iv-dialog
 */
import { test, expect } from '@playwright/test'
import { ensureLoggedIn, openInventory } from './helpers/auth.mjs'

const THEMES = ['light', 'dark', 'dark-highcontrast', 'light-highcontrast']
const VARIANTS = ['primary', 'secondary', 'ghost', 'danger', 'tertiary']

/** Server-pinned OCS theme switch (learned: client flips fake HC readings). */
async function setUserTheme(page, themeId) {
	const failures = await page.evaluate(async ({ target, all }) => {
		const token = (window.OC && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		const problems = []
		for (const id of all.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, { method: 'DELETE', credentials: 'same-origin', headers })
			if (!res.ok && res.status !== 400) problems.push(`disable ${id}: HTTP ${res.status}`)
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, { method: 'PUT', credentials: 'same-origin', headers })
		if (!res.ok && res.status !== 400) problems.push(`enable ${target}: HTTP ${res.status}`)
		return problems
	}, { target: themeId, all: THEMES })
	if (failures.length) throw new Error(`theme ${themeId}: ${failures.join(';')}`)
}

/**
 * Dialog auto-focus lands on the first focusable control — a focused button
 * legitimately wears the :focus-visible paint, not the base fill the oracle
 * carries. Blur before sampling so base-vs-base is compared.
 * @param {import('@playwright/test').Page} page
 */
async function blurFocus(page) {
	await page.evaluate(() => {
		if (document.activeElement instanceof HTMLElement) document.activeElement.blur()
	})
	// .iv-btn carries transition: background-color .15s — blur starts the
	// hover/focus → base repaint; sampling mid-flight reads neither paint.
	await page.waitForTimeout(300)
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 */
async function paintOf(page, selector) {
	return page.locator(selector).first().evaluate((el) => {
		const cs = getComputedStyle(el)
		return {
			tag: el.tagName,
			bg: cs.backgroundColor,
			border: cs.borderTopColor,
			ink: cs.color,
		}
	})
}

/**
 * In-page <a> oracles carry each variant's true paint (core never repaints
 * anchors); in-content <button> oracles prove the app-scope armoring.
 * @param {import('@playwright/test').Page} page
 * @param {string[]} variants
 */
async function injectOracles(page, variants) {
	await page.evaluate((vs) => {
		document.querySelectorAll('.iv-variant-oracle').forEach((el) => el.remove())
		const host = document.getElementById('iv-main-content')
			|| document.getElementById('app-content')
		if (!host) throw new Error('no oracle host')
		for (const variant of vs) {
			const a = document.createElement('a')
			a.className = `iv-btn iv-btn--${variant} iv-variant-oracle iv-variant-oracle--a-${variant}`
			a.href = '#'
			host.appendChild(a)
		}
		for (const variant of vs) {
			const b = document.createElement('button')
			b.className = `iv-btn iv-btn--${variant} iv-variant-oracle iv-variant-oracle--btn-${variant}`
			b.type = 'button'
			host.appendChild(b)
		}
	}, variants)
}

/** @param {import('@playwright/test').Page} page */
async function closeAnyDialog(page) {
	await page.evaluate(() => {
		const btn = document.querySelector('.iv-dialog-overlay .iv-dialog__close')
		if (btn instanceof HTMLElement) btn.click()
	})
	await page.waitForSelector('.iv-dialog-overlay', { state: 'detached', timeout: 10_000 }).catch(() => {})
}

test.describe('Button-variant armor (button_variant_specificity_collapse)', () => {
	test('ATLAS_BTN_VARIANT_ARMOR dialog + in-content <button> variants paint like <a> oracles (light + dark)', async ({ page }) => {
		test.setTimeout(240_000)
		await ensureLoggedIn(page, 'E2E')

		try {
			for (const theme of ['light', 'dark']) {
				await openInventory(page, '/apps/inventorycheck/items')
				await setUserTheme(page, theme)
				await openInventory(page, '/apps/inventorycheck/items')
				await page.waitForSelector('#app-content.iv-app', { timeout: 30_000 })
				await injectOracles(page, VARIANTS)

				// Real dialog: items page "New item" mounts .iv-dialog-overlay
				// on document.body with a .button.primary confirm <button>.
				await page.mouse.move(4, 4)
				const trigger = page.locator('#iv-page-actions button.primary').first()
				await expect(trigger).toBeVisible({ timeout: 15_000 })
				await trigger.click()
				await page.waitForSelector('.iv-dialog-overlay .iv-dialog', { state: 'visible', timeout: 15_000 })
				// Variant <button> probes inside the REAL open dialog body —
				// ghost/danger/tertiary have no stock dialog action but the
				// .iv-dialog scope must armor them all the same.
				await page.evaluate((vs) => {
					const body = document.querySelector('.iv-dialog-overlay .iv-dialog__body')
					if (!body) throw new Error('no dialog body')
					for (const variant of vs) {
						const b = document.createElement('button')
						b.type = 'button'
						b.className = `iv-btn iv-btn--${variant} iv-variant-probe iv-variant-probe--${variant}`
						b.textContent = 'probe'
						body.appendChild(b)
					}
				}, VARIANTS)
				await blurFocus(page)

				// Real confirm: <button class="button primary"> vs <a> oracle.
				const dlgConfirm = await paintOf(page, '.iv-dialog-overlay .iv-dialog__actions .button.primary')
				const primaryOracle = await paintOf(page, 'a.iv-variant-oracle--a-primary')
				expect(dlgConfirm.tag, `${theme} primary: dialog confirm must be a real <button>`).toBe('BUTTON')
				expect(dlgConfirm.bg, `${theme} primary: dialog <button> fill must equal the <a> oracle`).toBe(primaryOracle.bg)
				expect(dlgConfirm.border, `${theme} primary: dialog <button> border must equal the oracle`).toBe(primaryOracle.border)
				expect(dlgConfirm.ink, `${theme} primary: dialog <button> ink must equal the oracle`).toBe(primaryOracle.ink)

				// Variant probes inside .iv-dialog vs <a> oracles.
				for (const variant of VARIANTS) {
					const btn = await paintOf(page, `.iv-dialog-overlay .iv-variant-probe--${variant}`)
					const oracle = await paintOf(page, `a.iv-variant-oracle--a-${variant}`)
					expect(btn.tag, `${theme} ${variant}: probe must be a real <button>`).toBe('BUTTON')
					expect(btn.bg, `${theme} ${variant}: dialog <button> fill must equal the <a> oracle`).toBe(oracle.bg)
					expect(btn.border, `${theme} ${variant}: dialog <button> border must equal the oracle`).toBe(oracle.border)
					expect(btn.ink, `${theme} ${variant}: dialog <button> ink must equal the oracle`).toBe(oracle.ink)
				}
				// Ghost is transparent by design — the core pale fill is the bug.
				const gBtn = await paintOf(page, '.iv-dialog-overlay .iv-variant-probe--ghost')
				expect(gBtn.bg, `${theme} ghost: ghost must not carry a fill`).toBe('rgba(0, 0, 0, 0)')
				await closeAnyDialog(page)

				// In-content leg: real <button> oracles under .iv-app must match
				// the <a> oracles — the settings ACL "Clear grants" ghost shape.
				for (const variant of VARIANTS) {
					const btn = await paintOf(page, `button.iv-variant-oracle--btn-${variant}`)
					const oracle = await paintOf(page, `a.iv-variant-oracle--a-${variant}`)
					expect(btn.tag, `${theme} in-content ${variant}: probe must be a real <button>`).toBe('BUTTON')
					expect(btn.bg, `${theme} in-content ${variant}: <button> fill must equal the <a> oracle`).toBe(oracle.bg)
					expect(btn.border, `${theme} in-content ${variant}: <button> border must equal the oracle`).toBe(oracle.border)
					expect(btn.ink, `${theme} in-content ${variant}: <button> ink must equal the <a> oracle`).toBe(oracle.ink)
				}
				const gContent = await paintOf(page, 'button.iv-variant-oracle--btn-ghost')
				expect(gContent.bg, `${theme} in-content ghost: must not carry the pale core fill`).toBe('rgba(0, 0, 0, 0)')
			}
		} finally {
			await closeAnyDialog(page)
			await setUserTheme(page, 'light').catch(() => {})
		}
	})
})
