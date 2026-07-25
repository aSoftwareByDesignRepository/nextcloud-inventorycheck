// @ts-check
import { test, expect } from '@playwright/test'
import { mkdirSync, copyFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * AC-22 — live UAT screenshots archived under docs/uat/screenshots/.
 * Captures InventoryCheck surfaces + MobilityCheck shell when enabled.
 */
const shotDir = resolve(dirname(fileURLToPath(import.meta.url)), '../../docs/uat/screenshots')
const artifactDir = resolve(dirname(fileURLToPath(import.meta.url)), 'artifacts')

test('AC-22 live screenshot archive vs MobilityCheck', async ({ page }, testInfo) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')
	mkdirSync(shotDir, { recursive: true })
	mkdirSync(artifactDir, { recursive: true })

	const vp = testInfo.project.name.includes('320') ? '320' : '1280'
	await ensureLoggedIn(page, 'ADMIN')

	const surfaces = [
		{ path: '/apps/inventorycheck/', name: `live-dashboard-${vp}.png` },
		{ path: '/apps/inventorycheck/items', name: `live-items-${vp}.png` },
		{ path: '/apps/inventorycheck/movements', name: `live-movements-${vp}.png` },
		{ path: '/apps/inventorycheck/settings', name: `live-settings-${vp}.png` },
	]

	for (const surface of surfaces) {
		await openInventory(page, surface.path)
		await page.waitForTimeout(800)
		const dest = resolve(shotDir, surface.name)
		await page.screenshot({ path: dest, fullPage: true })
		copyFileSync(dest, resolve(artifactDir, surface.name))
	}

	const ok = await page.goto('/apps/mobilitycheck/').then(() => true).catch(() => false)
	if (ok) {
		const shell = page.locator('#app-content, main, #content')
		if (await shell.first().isVisible({ timeout: 8_000 }).catch(() => false)) {
			const name = `live-mobilitycheck-shell-${vp}.png`
			const dest = resolve(shotDir, name)
			await page.screenshot({ path: dest, fullPage: true })
			copyFileSync(dest, resolve(artifactDir, name))
		}
	}

	await expect(page.locator('body')).toBeVisible()
})
