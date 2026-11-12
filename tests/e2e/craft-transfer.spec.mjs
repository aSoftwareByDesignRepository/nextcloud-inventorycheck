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
		await expect(page.locator('.iv-toast--error').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#iv-transfer-result-live')).not.toBeEmpty()
		await shot(page, 'iv-admin-transfer-fileerror-light-1280')

		// correct passphrase → preview → report dialog (interpolated counters)
		await page.locator('#iv-transfer-import-passphrase').fill(PASS)
		const report = page.locator('#iv-transfer-report')
		await expect(report).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#iv-transfer-report-verdict')).toContainText(/\d/)
		await shot(page, 'iv-admin-transfer-report-dialog-light-1280')

		// apply → progress dialog stays open while the job waits queued
		await page.locator('#iv-transfer-confirm').click()
		const progress = page.locator('#iv-transfer-progress')
		await expect(progress).toBeVisible({ timeout: 10_000 })
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
		await expect(page.locator('.iv-toast--error').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('#iv-transfer-result-live')).not.toBeEmpty()
		await shot(page, 'iv-admin-transfer-fileerror-light-375')
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
		const report = page.locator('#iv-transfer-report')
		await expect(report).toBeVisible({ timeout: 30_000 })
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
