// @ts-check
/**
 * ATLAS web craft capture — transfer settings surfaces. Writes PNGs to
 * .cursor/atlas-farm-v3/artifacts/inventorycheck/craft/web/.
 * Flow: settings/transfer load → uniform file error → real
 * export→preview→report dialog → apply → progress dialog → after-apply.
 *
 * dead_platform_toast_api: every failure leg must reach a VISIBLE
 * app-owned .iv-toast--error, not only the visually-hidden live region
 * (OC.Notification is gone from NC≥31 bundles — the old bridge silently
 * no-ops there).
 */
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { login, credsFromEnv } from './helpers/auth.mjs'

const APP = '/index.php/apps/inventorycheck'
const BASE = (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const PASS = 'atlas-e2e-passphrase-2026'
const OUT = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../../../../.cursor/atlas-farm-v3/artifacts/inventorycheck/craft/web',
)
// tests/e2e → apps dir; docker compose resolves the project from parents.
const COMPOSE_CWD = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')
const creds = credsFromEnv('ADMIN') || credsFromEnv('E2E')
mkdirSync(OUT, { recursive: true })

function occ(...args) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 }).trim()
}

function db(sql) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', 'mariadb', 'mariadb',
		'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-B', '-e', sql,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 30_000 }).trim()
}

async function shot(page, name) {
	await page.screenshot({ path: resolve(OUT, `${name}.png`), fullPage: false })
}

/**
 * native_dialog_margin_reset_uncentered (2026-11-12 family class): NC core's
 * 'html,body,…,dialog,…{margin:0}' reset collapses the UA 'margin:auto' that
 * centers a top-layer <dialog>. margin:auto on .iv-dialog restores it; the
 * ≤480px media query re-asserts margin:0 for the full-bleed sheet
 * (dialog.iv-dialog at 0,1,1 so app unified-dialog rules cannot keep the
 * sheet capped). RED when the dialog anchors to the viewport top-left.
 * @param {import('@playwright/test').Locator} dlg — native <dialog>
 */
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

async function expectFullBleedSheet(dlg, page) {
	const vp = page.viewportSize()
	const box = await dlg.boundingBox()
	expect(box, 'dialog has no box').toBeTruthy()
	expect(box.x, 'dialog not full-bleed left').toBeLessThan(8)
	expect(box.width, 'dialog not full-bleed width').toBeGreaterThan(vp.width - 16)
}

/**
 * overlay_toast_button_armor_gap (2026-11-12 family sweep, donor leg
 * dutycheck @8e369e3): <button class="iv-toast__close"> renders inside
 * the body-mounted #iv-toasts stack (appended to document.body, outside
 * #app-content.iv-app), so NC core's native button repaint (width:130px
 * + pale fill + border at 0-1-1, margin-inline-start:0 at 0-2-1) must
 * not reach it. Assert the painted geometry is the declared transparent
 * ~44px icon-close docked at the toast's inline-end — not the collapsed
 * ~130px pale core-skin block. RED on the pre-armor css, GREEN with it.
 * @param {import('@playwright/test').Locator} close
 * @param {string} hostSelector — closest() host the close docks inside
 */
async function expectOverlayCloseArmored(close, hostSelector) {
	await expect(close).toBeVisible()
	const m = await close.evaluate((el, sel) => {
		const r = el.getBoundingClientRect()
		const cs = getComputedStyle(el)
		const host = el.closest(sel).getBoundingClientRect()
		return {
			x: r.x, w: r.width, h: r.height,
			bottom: r.y + r.height,
			hostX: host.x, hostW: host.width, hostBottom: host.y + host.height,
			bg: cs.backgroundColor, bw: cs.borderWidth,
			dir: cs.direction,
		}
	}, hostSelector)
	expect(m.bg, 'close keeps transparent background (no pale core skin)')
		.toBe('rgba(0, 0, 0, 0)')
	expect(m.bw, 'close keeps border:0').toBe('0px')
	expect(m.w, 'close ≈44px — core width:130px repaint would blow it out')
		.toBeLessThanOrEqual(56)
	expect(m.w).toBeGreaterThanOrEqual(40)
	expect(m.h).toBeGreaterThanOrEqual(40)
	expect(m.h).toBeLessThanOrEqual(64)
	// inline-end dock + single row: glyph sits in the host's trailing half
	// (leading half under rtl) and inside its height — the collapsed block
	// wrapped to a second row instead.
	const center = m.x + m.w / 2
	const dockedEnd = m.dir === 'rtl'
		? center < m.hostX + m.hostW / 2
		: center > m.hostX + m.hostW / 2
	expect(dockedEnd, 'close docks at the host inline-end').toBe(true)
	expect(m.bottom, 'close stays on the host row (no wrap)')
		.toBeLessThanOrEqual(m.hostBottom + 1)
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

test.describe('craft transfer', () => {
	test.skip(!creds, 'no NC_ADMIN_*/NC_E2E_* creds')

	test('admin transfer surface + report/progress dialogs 1280', async ({ page, request }) => {
		test.setTimeout(240_000)
		const { bytes, auth } = await exportBytes(request)

		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, creds)
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#iv-transfer-export-card')).toBeVisible({ timeout: 20_000 })
		await page.waitForTimeout(600)
		await shot(page, 'iv-admin-transfer-light-1280')

		// uniform file error — wrong passphrase on a real container
		await page.locator('#iv-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#iv-transfer-import-passphrase').fill('wrong-passphrase-000')
		// dead_platform_toast_api: the failure must paint a VISIBLE app-owned
		// toast, not only the visually-hidden live region.
		const errToast = page.locator('.iv-toast--error').first()
		await expect(errToast).toBeVisible({ timeout: 30_000 })
		await expectOverlayCloseArmored(errToast.locator('.iv-toast__close'), '.iv-toast')
		await expect(page.locator('#iv-transfer-result-live')).not.toBeEmpty()
		await shot(page, 'iv-admin-transfer-fileerror-light-1280')

		// correct passphrase → preview → report dialog (interpolated counters)
		await page.locator('#iv-transfer-import-passphrase').fill(PASS)
		const report = page.locator('dialog#iv-transfer-report')
		await expect(report).toBeVisible({ timeout: 30_000 })
		await page.waitForTimeout(400) // top-layer settle
		await expectTopLayerCentered(report, page)
		await expect(page.locator('#iv-transfer-report-verdict')).toContainText(/\d/)
		await shot(page, 'iv-admin-transfer-report-dialog-light-1280')

		// apply → progress dialog stays open while the job waits queued
		await page.locator('#iv-transfer-confirm').click()
		const progress = page.locator('dialog#iv-transfer-progress')
		await expect(progress).toBeVisible({ timeout: 10_000 })
		await expectTopLayerCentered(progress, page)
		await expect(page.locator('#iv-transfer-progress-text')).not.toBeEmpty()
		await shot(page, 'iv-admin-transfer-progress-light-1280')

		const jobId = db("SELECT id FROM oc_jobs WHERE class LIKE '%TransferApplyJob%' ORDER BY id DESC LIMIT 1")
		expect(jobId).toMatch(/^\d+$/)
		const before = await request.get(`${BASE}${APP}/api/admin/transfer/status`, { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } })
		const priorReceiptId = (((await before.json()).data || {}).lastReceipt || {}).id || ''
		occ('background-job:execute', jobId)

		let receipt = {}
		for (let i = 0; i < 60; i++) {
			const s = await request.get(`${BASE}${APP}/api/admin/transfer/status`, { headers: { Authorization: auth, 'OCS-APIRequest': 'true' } })
			receipt = (((await s.json()).data || {}).lastReceipt) || {}
			if (receipt.id && receipt.id !== priorReceiptId
				&& (receipt.result === 'applied' || receipt.result === 'failed')) break
			await new Promise((r) => setTimeout(r, 1000))
		}
		expect(receipt.result).toBe('applied')

		// page reloads itself on done — settle, then capture post-apply state
		await page.waitForLoadState('domcontentloaded')
		await expect(page.locator('#iv-transfer-last')).toBeVisible({ timeout: 20_000 })
		await page.waitForTimeout(600)
		await shot(page, 'iv-admin-transfer-after-apply-light-1280')
	})

	test('admin transfer surface mobile 375', async ({ page, request }) => {
		await page.setViewportSize({ width: 375, height: 812 })
		await login(page, creds)
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#iv-transfer-export-card')).toBeVisible({ timeout: 20_000 })
		await page.waitForTimeout(600)
		await shot(page, 'iv-admin-transfer-light-375')

		// dead_platform_toast_api at narrow width: a failed preview must paint
		// a visible .iv-toast--error (not only the hidden live region).
		const { bytes } = await exportBytes(request)
		await page.locator('#iv-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#iv-transfer-import-passphrase').fill('wrong-passphrase-000')
		const errToast375 = page.locator('.iv-toast--error').first()
		await expect(errToast375).toBeVisible({ timeout: 30_000 })
		await expectOverlayCloseArmored(errToast375.locator('.iv-toast__close'), '.iv-toast')
		await expect(page.locator('#iv-transfer-result-live')).not.toBeEmpty()
		await shot(page, 'iv-admin-transfer-fileerror-light-375')

		// ≤480px: media query re-asserts margin:0 — the report dialog is a
		// full-bleed sheet, not a centered card (native_dialog_margin_reset_-
		// uncentered narrow leg).
		await page.locator('#iv-transfer-import-passphrase').fill(PASS)
		const report375 = page.locator('dialog#iv-transfer-report')
		await expect(report375).toBeVisible({ timeout: 30_000 })
		await page.waitForTimeout(400) // top-layer settle
		await expectFullBleedSheet(report375, page)
		await shot(page, 'iv-admin-transfer-report-dialog-light-375')

		// Leave the surface clean for sibling lanes.
		await page.locator('#iv-transfer-cancel').click().catch(() => {})
	})

	test('admin transfer replace-confirm token row wide (cramped_form_controls)', async ({ page, request }) => {
		test.setTimeout(180_000)
		const { bytes } = await exportBytes(request)

		await page.setViewportSize({ width: 1280, height: 800 })
		await login(page, creds)
		await page.goto(`${APP}/settings/transfer`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#iv-transfer-export-card')).toBeVisible({ timeout: 20_000 })

		// Replace mode → real preview → report dialog footer shows the token
		// row. Preview needs no maintenance mode (a warning callout renders
		// instead) — the dialog must not be applied.
		await page.locator('#iv-transfer-import-form input[type="radio"][value="replace"]').check()
		await page.locator('#iv-transfer-file').setInputFiles({
			name: 't.nctransfer', mimeType: 'application/json', buffer: bytes,
		})
		await page.locator('#iv-transfer-import-passphrase').fill(PASS)
		const report = page.locator('dialog#iv-transfer-report')
		await expect(report).toBeVisible({ timeout: 30_000 })
		await page.waitForTimeout(400) // top-layer settle
		// destructive-type confirm surface (replace mode) — same centered
		// top-layer contract as the merge preview.
		await expectTopLayerCentered(report, page)
		const token = page.locator('#iv-transfer-replace-token')
		await expect(page.locator('#iv-transfer-replace-confirm')).toBeVisible()
		await expect(token).toBeVisible()

		// cramped_form_controls fix live: the input escapes the core 130px pin.
		const w = await token.evaluate((el) => el.getBoundingClientRect().width)
		expect(w, `token input ${Math.round(w)}px still on the NC-core 130px pin`).toBeGreaterThan(140)

		await shot(page, 'iv-admin-transfer-replace-confirm-light-1280')

		// Cancel out — never apply a replace preview on the shared instance.
		await page.locator('#iv-transfer-cancel').click()
		await expect(report).toBeHidden({ timeout: 10_000 })
	})
})
