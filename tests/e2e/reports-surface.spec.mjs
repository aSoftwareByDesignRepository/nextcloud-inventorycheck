// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory, login } from './helpers/auth.mjs'

/**
 * IV-REPORTS-SURFACE — office journey: /reports renders all four estimate
 * cards, the dead-stock and movement-value controls fetch live data, and
 * the endpoints reject a field user (office-only, read-only).
 */

async function api(page, method, path, body) {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token =
				(typeof window.OC !== 'undefined' && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
				|| document.querySelector('meta[name="requesttoken"]')?.getAttribute('content')
				|| ''
			const res = await fetch(path, {
				method,
				credentials: 'same-origin',
				headers: {
					'requesttoken': token,
					'OCS-APIREQUEST': 'true',
					...(body ? { 'Content-Type': 'application/json' } : {}),
				},
				body: body ? JSON.stringify(body) : undefined,
			})
			let data = null
			try { data = await res.json() } catch { /* csv/text */ }
			return { status: res.status, data }
		},
		{ method, path, body },
	)
}

function expectOk(result, label = 'API') {
	expect(
		[200, 201, 204].includes(result.status),
		`${label} → ${result.status}: ${JSON.stringify(result.data)}`,
	).toBeTruthy()
}

const createdTags = []
function marker() {
	const tag = `rs${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
	createdTags.push(tag)
	return tag
}

const COMPOSE_CWD = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

function dbSql(sql) {
	try {
		return execFileSync('docker', [
			'compose', 'exec', '-T', 'mariadb', 'mariadb',
			'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-B', '-e', sql,
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
	} catch { return '' }
}

test.afterAll(() => {
	for (const tag of createdTags) {
		const like = `%${tag}%`
		dbSql(
			`DELETE m FROM oc_iv_movements m JOIN oc_iv_items i ON m.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE m FROM oc_iv_movements m JOIN oc_iv_locations o ON m.location_id = o.id OR m.counterparty_loc_id = o.id WHERE o.code LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_items i ON b.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_locations o ON b.location_id = o.id WHERE o.code LIKE '${like}';`
			+ `DELETE FROM oc_iv_items WHERE sku LIKE '${like}' OR scan_code LIKE '${like}' OR name LIKE '${like}';`
			+ `DELETE FROM oc_iv_locations WHERE code LIKE '${like}' OR name LIKE '${like}';`,
		)
	}
})

test('IV-REPORTS-SURFACE: /reports renders all four cards with live data', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()
	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `RS-L-${tag}`.slice(0, 64),
		name: `Report Loc ${tag}`,
		kind: 'warehouse',
	})
	expectOk(loc, 'create location')
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku: `RS-I-${tag}`.slice(0, 64),
		name: `Report Item ${tag}`,
		uom: 'pcs',
	})
	expectOk(item, 'create item')
	const itemId = item.data?.id ?? item.data?.item?.id
	const locId = loc.data?.id ?? loc.data?.location?.id
	const recv = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId, locationId: locId, qty: 7, reason: 'stock',
	})
	expectOk(recv, 'receive stock')

	// Backdate item + movement so it qualifies for a 1-day dead window
	// (the clock is max(item.created_at, last movement)).
	dbSql(
		`UPDATE oc_iv_items SET created_at = 1 WHERE sku LIKE '%${tag}%';`
		+ `UPDATE oc_iv_movements m JOIN oc_iv_items i ON m.item_id = i.id
		 SET m.created_at = 1 WHERE i.sku LIKE '%${tag}%';`,
	)

	// API shapes first.
	const valuation = await api(page, 'GET', '/index.php/apps/inventorycheck/api/reports/valuation')
	expectOk(valuation, 'valuation')
	expect(valuation.data.isEstimate).toBe(true)
	expect(Array.isArray(valuation.data.rows)).toBeTruthy()

	const locValue = await api(page, 'GET', '/index.php/apps/inventorycheck/api/reports/location-value')
	expectOk(locValue, 'location-value')
	expect(locValue.data.isEstimate).toBe(true)
	const locRow = (locValue.data.rows || []).find((r) => r.locationId === locId)
	expect(locRow, 'created location appears in location-value').toBeTruthy()
	expect(locRow.itemCount).toBe(1)

	const dead = await api(page, 'GET', '/index.php/apps/inventorycheck/api/reports/dead-stock?days=1')
	expectOk(dead, 'dead-stock')
	const deadRow = (dead.data.rows || []).find((r) => r.itemId === itemId)
	expect(deadRow, 'backdated item appears as dead stock').toBeTruthy()
	expect(deadRow.totalQty).toBe(7)

	const badDays = await api(page, 'GET', '/index.php/apps/inventorycheck/api/reports/dead-stock?days=0')
	expect(badDays.status).toBe(422)

	// UI: nav → reports → all four cards.
	await page.goto('/index.php/apps/inventorycheck/reports', { waitUntil: 'domcontentloaded' })
	await expect(page.locator('[data-iv-report="valuation"]')).toBeVisible({ timeout: 15_000 })
	await expect(page.locator('[data-iv-report="location-value"]')).toBeVisible()
	await expect(page.locator('[data-iv-report="movement-value"]')).toBeVisible()
	await expect(page.locator('[data-iv-report="dead-stock"]')).toBeVisible()
	// Estimate banner present on every populated card.
	await expect(page.locator('[data-iv-report="valuation"] [data-iv-estimate-banner]')).toBeVisible()
	await expect(page.locator('[data-iv-report="location-value"] [data-iv-estimate-banner]')).toBeVisible()
	// Dead-stock card shows our item after its default load (90d window,
	// created_at=1 qualifies).
	await expect(page.locator('[data-iv-report="dead-stock"]')).toContainText(`RS-I-${tag}`.slice(0, 64))
	// CSV links exist per card.
	await expect(page.locator('[data-iv-report="valuation"] a[href*="kind=valuation"]')).toBeVisible()
	await expect(page.locator('[data-iv-report="dead-stock"] a[href*="kind=dead_stock"]')).toBeVisible()
})

test('IV-REPORTS-SURFACE: report endpoints deny field users', async ({ browser }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	const uid = `ivrep${Date.now().toString(36)}`
	const pass = `Iv!${Math.random().toString(36).slice(2)}Aa`
	function occ(...args) {
		return execFileSync('docker', ['compose', 'exec', '-T', 'nextcloud', 'php', 'occ', ...args], {
			cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000,
		})
	}
	execFileSync('docker', [
		'compose', 'exec', '-T', '-e', `OC_PASS=${pass}`, 'nextcloud',
		'php', 'occ', 'user:add', '--password-from-env', uid,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
	try {
		occ('user:enable', uid)
		const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		const field = await ctx.newPage()
		try {
			await login(field, { username: uid, password: pass })
			await field.goto('/apps/dashboard/', { waitUntil: 'domcontentloaded' })
			for (const path of [
				'/index.php/apps/inventorycheck/api/reports/valuation',
				'/index.php/apps/inventorycheck/api/reports/location-value',
				'/index.php/apps/inventorycheck/api/reports/dead-stock?days=90',
				'/index.php/apps/inventorycheck/api/export?kind=valuation',
			]) {
				const res = await api(field, 'GET', path)
				expect([401, 403], `${path} must deny field → ${JSON.stringify(res.data)}`).toContain(res.status)
			}
		} finally {
			await ctx.close()
		}
	} finally {
		occ('user:delete', uid)
	}
})
