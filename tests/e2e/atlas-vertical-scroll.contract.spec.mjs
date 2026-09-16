// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall license settings must scroll to the end.
 * Catches unpaired overflow-x:clip shells that truncate bottom content.
 */
import { createRequire } from 'module'
import { test } from '@playwright/test'
import { ensureLoggedIn } from './helpers/auth.mjs'

const require = createRequire(import.meta.url)
const { assertAtlasVerticalScrollReachable } = require('../../../_shared/e2e/atlas-vertical-scroll-contract.js')

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test('license settings scrolls to end at desktop height', async ({ page }) => {
		test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')
		await page.setViewportSize({ width: 1280, height: 640 })
		await ensureLoggedIn(page, 'ADMIN')
		await page.goto('/apps/inventorycheck/settings/license', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#iv-license, #iv-license-title, .iv-license-status', { timeout: 45_000 })
		test.skip((await page.locator('.iv-access-denied').count()) > 0, 'License access denied')
		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#iv-license, #iv-license-title, .iv-license-status, #iv-support-us',
			bottomSlopPx: 12,
		})
	})

	test('license settings stays reachable at phone height', async ({ page }) => {
		test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')
		await page.setViewportSize({ width: 390, height: 667 })
		await ensureLoggedIn(page, 'ADMIN')
		await page.goto('/apps/inventorycheck/settings/license', { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('#iv-license, #iv-license-title, .iv-license-status', { timeout: 45_000 })
		test.skip((await page.locator('.iv-access-denied').count()) > 0, 'License access denied')
		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#iv-license, #iv-license-title, .iv-license-status, #iv-support-us',
			bottomSlopPx: 16,
		})
	})
})
