// @ts-check
/**
 * Rendered contract for the 2026-11-04 fail-tier class-gate hit
 * (required_field_or_step_undiscoverable): select-matching rules in
 * css/app.css painted the `background:` SHORTHAND, resetting the chevron's
 * background-image/position/repeat — live victims: every inline
 * `select.iv-input` inside #app-content (#iv-mov-kind movements filter,
 * #iv-acl-subject-type, #iv-flange-default-loc, restock suggestion-mode)
 * read as plain text fields with no picker affordance.
 *
 * Proof: real selects keep appearance:none + a url() background-image,
 * no-repeat, a non-zero position in light AND dark. DOM-probe works on
 * collapsed/disclosure-closed surfaces too — only layout properties
 * collapse on hidden elements, the background longhands still resolve.
 * (Companion of tests/js/select-chevron-contract.test.mjs, which pins the
 * static CSS side; mirrors ticketcheck/maintenancecheck.)
 */
import { test, expect } from '@playwright/test'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

const THEMES = ['light', 'dark', 'dark-highcontrast', 'light-highcontrast']

/** Server-pinned OCS theme switch (learned: client flips fake readings). */
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
 * @param {import('@playwright/test').Locator} locator
 * @param {string} label
 * @param {string} theme
 */
async function expectChevron(locator, label, theme) {
	await expect(locator, `${theme} ${label}: select missing from DOM`).toHaveCount(1)
	const probe = await locator.evaluate((node) => {
		const cs = getComputedStyle(node)
		return {
			image: cs.backgroundImage,
			repeat: cs.backgroundRepeat,
			position: cs.backgroundPosition,
			appearance: cs.appearance,
		}
	})
	expect(probe.appearance, `${theme} ${label}: appearance:none lost`).toBe('none')
	expect(probe.image, `${theme} ${label}: chevron background-image missing — select reads as a text field`).toContain('url(')
	expect(probe.repeat, `${theme} ${label}: chevron tiles (caret-grid regression)`).toBe('no-repeat')
	expect(probe.position, `${theme} ${label}: chevron unpositioned`).not.toBe('0% 0%')
}

test.describe('select chevron contract (required_field_or_step_undiscoverable)', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(240_000)

	test('inline form + filter + settings selects keep a computed chevron in light and dark', async ({ page }) => {
		test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')
		await page.setViewportSize({ width: 1280, height: 800 })

		await openInventory(page, '/apps/inventorycheck/movements')
		for (const theme of ['light', 'dark']) {
			await setUserTheme(page, theme)

			// movements: inline kind-filter select.iv-input — the flagged
			// enforcer + bare-class rules wiped the caret here pre-fix.
			// openInventory navigates fresh, so the persisted theme renders.
			await openInventory(page, '/apps/inventorycheck/movements')
			await expectChevron(page.locator('select#iv-mov-kind'), '#iv-mov-kind', theme)

			// settings/location-access: inline ACL subject-type select
			await openInventory(page, '/apps/inventorycheck/settings/location-access')
			await expectChevron(page.locator('select#iv-acl-subject-type'), '#iv-acl-subject-type', theme)

			// settings/connections: inline flange default-location select
			await openInventory(page, '/apps/inventorycheck/settings/connections')
			await expectChevron(page.locator('select#iv-flange-default-loc'), '#iv-flange-default-loc', theme)
		}

		await setUserTheme(page, 'light')
	})
})
