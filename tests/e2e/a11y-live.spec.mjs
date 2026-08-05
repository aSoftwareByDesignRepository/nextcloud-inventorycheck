// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * AC-21 — live axe on InventoryCheck surfaces (not static fixtures).
 * Scoped to #app-content.iv-app so Nextcloud chrome is out of scope.
 */
const routes = [
	{ path: '/apps/inventorycheck/', ready: '#iv-page-title, .iv-howto, .iv-section, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/items', ready: '#iv-page-title, .iv-howto, #iv-items-filter-panel, .iv-filter-panel, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/locations', ready: '#iv-page-title, .iv-howto, #iv-loc-filter-panel, .iv-filter-panel, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/movements', ready: '#iv-page-title, .iv-howto, .iv-filter-panel, .iv-filterbar, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/stocktake', ready: '#iv-page-title, .iv-howto, .iv-stocktake-howto, .iv-section, .iv-empty, .iv-row', creds: 'E2E' },
	{ path: '/apps/inventorycheck/stocktake/create', ready: '#iv-page-title, .iv-howto, .iv-stocktake-howto, .iv-stocktake-new, [data-iv-loc-chooser="1"], .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/settings/access', ready: '#iv-page-title, #iv-access-title, .iv-settings-page', creds: 'ADMIN' },
	{ path: '/apps/inventorycheck/settings/office', ready: '#iv-page-title, .iv-howto, #iv-office-title, .iv-settings-page', creds: 'ADMIN' },
	{ path: '/apps/inventorycheck/settings/notifications', ready: '#iv-page-title, .iv-howto, #iv-notify-title, .iv-settings-page', creds: 'ADMIN' },
	{ path: '/apps/inventorycheck/settings/quantities', ready: '#iv-page-title, .iv-howto, #iv-frac-title, .iv-settings-page', creds: 'ADMIN' },
	{ path: '/apps/inventorycheck/settings/location-access', ready: '#iv-page-title, .iv-howto, #iv-loc-acl-title, .iv-settings-page', creds: 'ADMIN' },
	{ path: '/apps/inventorycheck/settings/connections', ready: '#iv-page-title, .iv-howto, #iv-connections-title, .iv-settings-page', creds: 'ADMIN' },
	{ path: '/apps/inventorycheck/settings/policies', ready: '#iv-page-title, .iv-howto, #iv-policies-title, .iv-settings-page', creds: 'ADMIN' },
	{ path: '/apps/inventorycheck/settings/license', ready: '#iv-page-title, .iv-howto, #iv-license-title, .iv-settings-page', creds: 'ADMIN' },
	{ path: '/apps/inventorycheck/settings/support', ready: '#iv-page-title, .iv-howto, #iv-support-us, [data-support-us="1"]', creds: 'ADMIN' },
]

for (const route of routes) {
	test(`a11y live WCAG 2.1 AA: ${route.path}`, async ({ page }) => {
		test.skip(!credsFromEnv(route.creds) && !credsFromEnv('E2E'), `Requires NC_${route.creds}_*`)

		await ensureLoggedIn(page, route.creds)
		await openInventory(page, route.path)
		await expect(page.locator(route.ready).first()).toBeVisible({ timeout: 30_000 })

		const results = await new AxeBuilder({ page })
			.include('#app-content.iv-app')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('.toastify')
			.analyze()

		const bad = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
		expect(bad, JSON.stringify(bad, null, 2)).toEqual([])
	})
}

test('movement dialog opens with labelled fields (A6)', async ({ page }) => {
	test.skip(!credsFromEnv('E2E') && !credsFromEnv('ADMIN'), 'Requires NC_E2E_* or NC_ADMIN_*')

	await ensureLoggedIn(page, 'E2E')
	await openInventory(page, '/apps/inventorycheck/')
	const issue = page.getByRole('button', { name: /Issue stock|Abgang/i }).first()
	await expect(issue).toBeVisible({ timeout: 30_000 })
	await issue.click()
	const dialog = page.locator('.iv-dialog, [aria-modal="true"]').first()
	await expect(dialog).toBeVisible({ timeout: 10_000 })
	await expect(dialog.locator('.iv-dialog__title, #iv-dialog-title')).toBeVisible()
	const qty = dialog.locator('input[type="number"], input[type="text"], input[type="search"]').first()
	await expect(qty).toBeVisible({ timeout: 10_000 })
	// Desktop focus must never inflate the dialog with fake soft-keyboard padding.
	await qty.focus()
	await expect(dialog).not.toHaveAttribute('style', /padding-bottom:\s*[1-9]\d{2,}px/)
	await expect(dialog.locator('.iv-dialog__body')).not.toHaveAttribute('style', /padding-bottom:\s*[1-9]\d{2,}px/)
	await page.keyboard.press('Escape')
	await expect(dialog).toBeHidden({ timeout: 5_000 })
})

test('a11y live WCAG 2.1 AA: item detail', async ({ page }) => {
	test.skip(!credsFromEnv('E2E') && !credsFromEnv('ADMIN'), 'Requires credentials')

	await ensureLoggedIn(page, 'E2E')
	await openInventory(page, '/apps/inventorycheck/items')
	const link = page.locator('#iv-main-content a[href*="/items/"], .iv-row a[href*="/items/"]').first()
	if (!(await link.isVisible({ timeout: 8_000 }).catch(() => false))) {
		test.skip(true, 'No items seeded — item detail axe skipped')
	}
	await link.click()
	await expect(page.locator('#iv-page-title')).toBeVisible({ timeout: 30_000 })
	const results = await new AxeBuilder({ page })
		.include('#app-content.iv-app')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	const bad = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
	expect(bad, JSON.stringify(bad, null, 2)).toEqual([])
})
