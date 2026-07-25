// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * AC-21 — live axe on InventoryCheck surfaces (not static fixtures).
 * Scoped to #app-content.iv-app so Nextcloud chrome is out of scope.
 */
const routes = [
	{ path: '/apps/inventorycheck/', ready: '#iv-page-title, .iv-section, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/items', ready: '#iv-page-title, .iv-toolbar, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/locations', ready: '#iv-page-title, .iv-empty, .iv-row', creds: 'E2E' },
	{ path: '/apps/inventorycheck/movements', ready: '#iv-page-title, .iv-filter-panel, .iv-filterbar, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/settings', ready: '#iv-page-title, #iv-support-us, .iv-section', creds: 'ADMIN' },
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
	await expect(dialog.getByLabel(/Item|Artikel/i).or(dialog.locator('select').first())).toBeVisible()
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
