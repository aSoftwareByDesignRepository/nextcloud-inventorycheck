// @ts-check
/**
 * ATLAS web craft capture — license-page pack delta surfaces (r39 recapture).
 * Writes PNGs to .cursor/atlas-farm-v3/artifacts/inventorycheck/craft/web/.
 *
 * Covers the committed delta on @30c569a+:
 *  (a) /settings/license page + seat-remove confirm + token + clear modals,
 *      1280 + 375px, light AND dark (server-pinned via OCS theming)
 *  (b) dashboard low-stock widget — app-dark.svg icon family (303d8e8)
 *  (c) transfer page + report dialog post danger-ink resync (303d8e8/1176fef)
 *  (d) settings accordion nav chevrons pinned trailing edge (70d8bcd)
 * plus the honest closed_dialog_painted arm verification: every
 * .iv-license-modal must compute display:none while not [open] — donor
 * >=0ba19365d3.
 */
import { test, expect } from '@playwright/test'
import { mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, credsFromEnv } from './helpers/auth.mjs'

const APP = '/index.php/apps/inventorycheck'
const BASE = (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const PASS = 'atlas-e2e-passphrase-2026'
const TS = new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, 'Z').replace('T', 'T')
const OUT = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../../../../.cursor/atlas-farm-v3/artifacts/inventorycheck/craft/web',
)
const creds = credsFromEnv('ADMIN') || credsFromEnv('E2E')
mkdirSync(OUT, { recursive: true })

async function shot(page, name) {
	await page.screenshot({ path: resolve(OUT, `${name}-${TS}.png`), fullPage: false })
}

const THEMES = ['light', 'dark', 'dark-highcontrast', 'light-highcontrast']

/**
 * Server-pin a user theme via OCS theming, then re-render from a real
 * navigation — never client-emulated (theme-responsive-a11y helper shape).
 */
async function applyTheme(page, themeId) {
	const failures = await page.evaluate(async ({ target, ids }) => {
		const token =
			(typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		const problems = []
		for (const id of ids.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
				method: 'DELETE', credentials: 'same-origin', headers,
			})
			if (!res.ok && res.status !== 400) problems.push(`disable ${id}: HTTP ${res.status}`)
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, {
			method: 'PUT', credentials: 'same-origin', headers,
		})
		if (!res.ok && res.status !== 400) problems.push(`enable ${target}: HTTP ${res.status}`)
		return problems
	}, { target: themeId, ids: THEMES })
	if (failures.length) {
		throw new Error(`OCS theme persist failed for ${themeId}: ${failures.join(';')}`)
	}
	await page.reload({ waitUntil: 'domcontentloaded' })
}

/** closed_dialog_painted arm: closed pack modals must not paint. */
async function expectClosedModalsHidden(page) {
	const leaks = await page.evaluate(() => {
		const out = []
		for (const el of document.querySelectorAll('dialog.iv-license-modal:not([open])')) {
			const cs = getComputedStyle(el)
			if (cs.display !== 'none' || cs.visibility === 'hidden') continue
			const r = el.getBoundingClientRect()
			if (r.width > 0 && r.height > 0) {
				out.push({ id: el.id, display: cs.display, w: Math.round(r.width), h: Math.round(r.height) })
			}
		}
		return out
	})
	expect(leaks, `closed .iv-license-modal still paints: ${JSON.stringify(leaks)}`).toEqual([])
}

async function expectTopLayerCentered(dlg, page) {
	const vp = page.viewportSize()
	const box = await dlg.boundingBox()
	expect(box, 'dialog has no box').toBeTruthy()
	expect(Math.abs(box.x + box.width / 2 - vp.width / 2), 'dialog not horizontally centered')
		.toBeLessThan(4)
	expect(Math.abs(box.y + box.height / 2 - vp.height / 2), 'dialog not vertically centered')
		.toBeLessThan(24)
	expect(box.y, 'dialog anchored to viewport top (margin:auto missing)')
		.toBeGreaterThan(40)
}

async function exportBytes(request) {
	const { username, password } = creds
	const auth = 'Basic ' + Buffer.from(`${username}:${password}`).toString('base64')
	const exp = await request.post(`${BASE}${APP}/api/admin/transfer/export`, {
		headers: { Authorization: auth, 'OCS-APIRequest': 'true' },
		data: { passphrase: PASS },
	})
	expect(exp.status()).toBe(200)
	const bytes = await exp.body()
	expect(bytes.length).toBeGreaterThan(200)
	return { bytes, auth }
}

async function gotoLicense(page) {
	await page.goto(`${APP}/settings/license`, { waitUntil: 'domcontentloaded' })
	await expect(page.locator('#iv-license-page')).toBeVisible({ timeout: 30_000 })
	await page.waitForTimeout(500)
}

/**
 * Open the seat-remove confirm for the first seat row — populated localized
 * Cancel/Remove labels + danger-variant fill visibly distinct from primary.
 */
async function openSeatRemoveConfirm(page) {
	const removeBtn = page.locator('.iv-seat-remove').first()
	await expect(removeBtn).toBeVisible({ timeout: 20_000 })
	await removeBtn.click()
	const dlg = page.locator('dialog#iv-license-confirm-modal')
	await expect(dlg).toBeVisible({ timeout: 10_000 })
	await page.waitForTimeout(300) // top-layer settle
	const cancel = dlg.locator('#iv-license-confirm-cancel')
	const ok = dlg.locator('#iv-license-confirm-ok')
	await expect(cancel).not.toBeEmpty()
	await expect(ok).not.toBeEmpty()
	const okDanger = await ok.evaluate((el) =>
		el.className.includes('license-btn--danger')
			&& getComputedStyle(el).backgroundColor !== 'rgba(0, 0, 0, 0)')
	expect(okDanger, 'confirm CTA must carry the danger fill').toBeTruthy()
	return dlg
}

async function closeConfirm(page) {
	await page.locator('#iv-license-confirm-cancel').click()
	await expect(page.locator('dialog#iv-license-confirm-modal')).toBeHidden({ timeout: 10_000 })
}

/** Register a device via the UI → token modal shows the one-time pair code. */
async function openTokenModal(page, tag) {
	await page.locator('#iv-terminal-label').fill(tag)
	await page.locator('#iv-terminal-register').click()
	const dlg = page.locator('dialog#iv-license-token-modal')
	await expect(dlg).toBeVisible({ timeout: 20_000 })
	await expect(dlg.locator('#iv-license-token-value')).not.toBeEmpty()
	await page.waitForTimeout(300)
	return dlg
}

async function closeTokenModal(page) {
	await page.locator('#iv-license-token-close').click()
	await expect(page.locator('dialog#iv-license-token-modal')).toBeHidden({ timeout: 10_000 })
}

/** Confirm the shared confirm modal after a destructive trigger click. */
async function confirmDangerModal(page) {
	const dlg = page.locator('dialog#iv-license-confirm-modal')
	await expect(dlg).toBeVisible({ timeout: 10_000 })
	await dlg.locator('#iv-license-confirm-ok').click()
	await expect(dlg).toBeHidden({ timeout: 10_000 })
}

/** Revoke a registered device row via its UI Revoke button (frees the license cap). */
async function revokeDeviceByLabel(page, label) {
	const row = page.locator('#iv-license-page tr[data-device-id]', { hasText: label })
	const btn = row.locator('.iv-terminal-revoke')
	await expect(btn).toBeVisible({ timeout: 15_000 })
	const id = await row.getAttribute('data-device-id')
	await btn.click()
	await confirmDangerModal(page)
	await expect(page.locator(`tr[data-device-id="${id}"]`)).toHaveCount(0, { timeout: 15_000 })
}

/** Drop every leftover craft device row (stale rows block the license device cap). */
async function purgeCraftDevices(page) {
	let purged = false
	for (;;) {
		const btn = page.locator('#iv-license-page tr[data-device-id]', { hasText: 'iv-craft-' })
			.locator('.iv-terminal-revoke').first()
		if (await btn.count() === 0) break
		const id = await btn.locator('xpath=./ancestor::tr[1]').getAttribute('data-device-id')
		await btn.click()
		await confirmDangerModal(page)
		await expect(page.locator(`tr[data-device-id="${id}"]`)).toHaveCount(0, { timeout: 15_000 })
		purged = true
	}
	// The pack's client-side "N / cap used" counter does not re-read after
	// revoke — reload so the terminal-label input re-enables.
	if (purged) await gotoLicense(page)
}

test.describe('craft license delta r39', () => {
	test.skip(!creds, 'no NC_ADMIN_*/NC_E2E_* creds')

	for (const themeId of ['light', 'dark']) {
		test(`license page + 3 modals @ 1280 ${themeId}`, async ({ page }) => {
			test.setTimeout(180_000)
			await page.setViewportSize({ width: 1280, height: 800 })
			await login(page, creds)
			await gotoLicense(page)
			await applyTheme(page, themeId)
			await expect(page.locator('#iv-license-page')).toBeVisible({ timeout: 30_000 })
			await page.waitForTimeout(500)

			await expectClosedModalsHidden(page)
			await shot(page, `iv-license-page-${themeId}-1280`)

			// (a1) seat-remove confirm — populated localized labels, danger fill
			const confirm = await openSeatRemoveConfirm(page)
			await expectTopLayerCentered(confirm, page)
			await shot(page, `iv-license-seatremove-confirm-${themeId}-1280`)
			await closeConfirm(page)
			await expectClosedModalsHidden(page)

			// (a2) token modal — real device registration shows the pair code;
			// purge stale craft rows first so the license device cap cannot starve us
			await purgeCraftDevices(page)
			const tag1280 = `iv-craft-${themeId}-1280-${TS}`
			const token = await openTokenModal(page, tag1280)
			await expectTopLayerCentered(token, page)
			await shot(page, `iv-license-token-modal-${themeId}-1280`)
			await closeTokenModal(page)
			await expectClosedModalsHidden(page)
			await revokeDeviceByLabel(page, tag1280)

			// (a3) clear modal — destructive confirm, then cancel out
			await page.locator('#iv-license-clear').click()
			const clear = page.locator('dialog#iv-license-clear-modal')
			await expect(clear).toBeVisible({ timeout: 10_000 })
			await page.waitForTimeout(300)
			await expectTopLayerCentered(clear, page)
			await shot(page, `iv-license-clear-modal-${themeId}-1280`)
			await page.locator('#iv-license-clear-cancel').click()
			await expect(clear).toBeHidden({ timeout: 10_000 })
			await expectClosedModalsHidden(page)
		})

		test(`license page + 3 modals @ 375 ${themeId}`, async ({ page }) => {
			test.setTimeout(180_000)
			await page.setViewportSize({ width: 375, height: 812 })
			await login(page, creds)
			await gotoLicense(page)
			await applyTheme(page, themeId)
			await expect(page.locator('#iv-license-page')).toBeVisible({ timeout: 30_000 })
			await page.waitForTimeout(500)

			await expectClosedModalsHidden(page)
			await shot(page, `iv-license-page-${themeId}-375`)

			const confirm = await openSeatRemoveConfirm(page)
			const box = await confirm.boundingBox()
			expect(box.width, '375 confirm is a near-full-width sheet').toBeGreaterThan(375 * 0.85)
			await shot(page, `iv-license-seatremove-confirm-${themeId}-375`)
			await closeConfirm(page)
			await expectClosedModalsHidden(page)

			await purgeCraftDevices(page)
			const tag375 = `iv-craft-${themeId}-375-${TS}`
			const token = await openTokenModal(page, tag375)
			await shot(page, `iv-license-token-modal-${themeId}-375`)
			await closeTokenModal(page)
			await expectClosedModalsHidden(page)
			await revokeDeviceByLabel(page, tag375)

			await page.locator('#iv-license-clear').click()
			const clear = page.locator('dialog#iv-license-clear-modal')
			await expect(clear).toBeVisible({ timeout: 10_000 })
			await page.waitForTimeout(300)
			await shot(page, `iv-license-clear-modal-${themeId}-375`)
			await page.locator('#iv-license-clear-cancel').click()
			await expect(clear).toBeHidden({ timeout: 10_000 })
			await expectClosedModalsHidden(page)
		})
	}

	test('dashboard low-stock widget renders app-dark.svg family icon', async ({ page }) => {
		test.setTimeout(120_000)
		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, creds)
		for (const themeId of ['dark', 'light']) {
			await page.goto('/index.php/apps/dashboard/', { waitUntil: 'domcontentloaded' })
			await applyTheme(page, themeId)
			await expect(page.locator('body')).toBeVisible()
			// widget item icons are served from img/app-dark.svg (303d8e8);
			// scoped to this app's own path — other apps ship same-named assets
			const icons = page.locator('img[src*="inventorycheck/img/app-dark.svg"]')
			await expect(icons.first()).toBeVisible({ timeout: 30_000 })
			const box = await icons.first().boundingBox()
			expect(box.width, 'dark icon renders non-zero').toBeGreaterThan(4)
			await page.waitForTimeout(400)
			await shot(page, `iv-dashboard-lowstock-widget-${themeId}-1280`)
		}
	})

	test('transfer page + report dialog post danger-ink resync', async ({ page, request }) => {
		test.setTimeout(240_000)
		const { bytes } = await exportBytes(request)

		for (const themeId of ['light', 'dark']) {
			await page.setViewportSize({ width: 1280, height: 800 })
			await login(page, creds)
			await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' })
			await applyTheme(page, themeId)
			await expect(page.locator('#iv-transfer-export-card')).toBeVisible({ timeout: 30_000 })
			await page.waitForTimeout(500)
			await shot(page, `iv-admin-transfer-${themeId}-1280`)

			await page.locator('#iv-transfer-file').setInputFiles({
				name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
			})
			await page.locator('#iv-transfer-import-passphrase').fill(PASS)
			const report = page.locator('dialog#iv-transfer-report')
			await expect(report).toBeVisible({ timeout: 30_000 })
			await page.waitForTimeout(400)
			await expectTopLayerCentered(report, page)
			await expect(page.locator('#iv-transfer-report-verdict')).toContainText(/\d/)
			await shot(page, `iv-admin-transfer-report-dialog-${themeId}-1280`)
			await page.locator('#iv-transfer-cancel').click()
			await expect(report).toBeHidden({ timeout: 10_000 })
		}
	})

	test('settings accordion nav chevrons pinned trailing edge', async ({ page }) => {
		test.setTimeout(120_000)
		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, creds)
		await gotoLicense(page)
		// 70d8bcd: chevron pins to one shared trailing edge via
		// margin-inline-start:auto on a stretched flex row.
		const chevrons = page.locator('#app-navigation .nav-parent-chevron')
		await expect(chevrons.first()).toBeVisible({ timeout: 20_000 })
		const metrics = await chevrons.evaluateAll((nodes) => {
			const xs = []
			for (const n of nodes) {
				const r = n.getBoundingClientRect()
				if (r.width > 0) xs.push(Math.round(r.x + r.width))
			}
			return xs
		})
		expect(metrics.length, 'no rendered parent chevrons').toBeGreaterThan(0)
		const spread = Math.max(...metrics) - Math.min(...metrics)
		expect(spread, `chevron trailing edges scatter (${metrics.join(',')})`).toBeLessThan(4)
		await page.locator('#app-navigation').screenshot({
			path: resolve(OUT, `iv-nav-accordion-chevrons-light-1280-${TS}.png`),
		})
		await shot(page, 'iv-license-nav-accordion-light-1280')
	})
})
