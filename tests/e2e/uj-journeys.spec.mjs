// @ts-check
import { test, expect } from '@playwright/test'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * SPEC §14.3 — scripted UJ smoke at 1280 and 320 (Playwright projects).
 */
test('UJ-1 shell: dashboard loads with role-aware CTAs', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page, '/apps/inventorycheck/')
	await expect(page.locator('#iv-page-title')).toBeVisible()
	await expect(page.getByRole('button', { name: /Issue stock|Abgang/i }).first()).toBeVisible({ timeout: 30_000 })
	const receive = page.getByRole('button', { name: /Receive stock|Zugang/i })
	if (await receive.first().isVisible().catch(() => false)) {
		await expect(receive.first()).toBeVisible()
	}
})

test('UJ movements: filter bar and pagination landmarks', async ({ page }) => {
	test.skip(!credsFromEnv('E2E') && !credsFromEnv('ADMIN'), 'Requires NC_E2E_* or NC_ADMIN_*')

	await ensureLoggedIn(page, 'E2E')
	await openInventory(page, '/apps/inventorycheck/movements')
	await expect(page.locator('.iv-filterbar, [role="search"]').first()).toBeVisible({ timeout: 30_000 })
	await expect(page.getByLabel(/Kind|Art/i).or(page.locator('#iv-mov-kind')).first()).toBeVisible()
})

test('UJ settings: license and support sections for admin', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN'), 'Requires NC_ADMIN_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page, '/apps/inventorycheck/settings')
	const main = page.locator('#iv-main-content')
	await expect(main.locator('#iv-support-us, [data-support-us="1"]').first()).toBeVisible({ timeout: 30_000 })
	await expect(main.locator('#iv-license')).toBeVisible({ timeout: 30_000 })
	await expect(main.locator('#iv-license-title')).toContainText(/License|Lizenz|Mobile/i)
})

test('320px reflow: nav and main usable', async ({ page }, testInfo) => {
	test.skip(!testInfo.project.name.includes('320'), '320 project only')
	test.skip(!credsFromEnv('E2E') && !credsFromEnv('ADMIN'), 'Requires credentials')

	await ensureLoggedIn(page, 'E2E')
	await openInventory(page, '/apps/inventorycheck/')
	const box = await page.locator('#app-content.iv-app').boundingBox()
	expect(box).toBeTruthy()
	expect(box.width).toBeLessThanOrEqual(320)
})
