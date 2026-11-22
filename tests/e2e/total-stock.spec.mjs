// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * IV-TOTAL-STOCK — the support-reported journey: an office user sees the
 * total stock across locations in the items list column and on the item
 * detail "Total" line (AC-1/5/13/14).
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
					'Content-Type': 'application/json',
					requesttoken: token,
					'OCS-APIRequest': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let data = null
			try {
				data = text ? JSON.parse(text) : null
			} catch {
				data = { raw: text }
			}
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
	const tag = `ts${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
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
	} catch (err) {
		// eslint-disable-next-line no-console
		console.error('total-stock teardown dbSql failed:', String(err).slice(0, 400))
		return ''
	}
}

test.afterAll(() => {
	for (const tag of createdTags) {
		const like = `%${tag}%`
		dbSql(
			`DELETE n FROM oc_iv_notif_log n JOIN oc_iv_items i ON n.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE m FROM oc_iv_movements m JOIN oc_iv_items i ON m.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE m FROM oc_iv_movements m JOIN oc_iv_locations o ON m.location_id = o.id OR m.counterparty_loc_id = o.id WHERE o.code LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_items i ON b.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_locations o ON b.location_id = o.id WHERE o.code LIKE '${like}';`
			+ `DELETE a FROM oc_iv_loc_acl a JOIN oc_iv_locations o ON a.location_id = o.id WHERE o.code LIKE '${like}';`
			+ `DELETE FROM oc_iv_items WHERE sku LIKE '${like}' OR scan_code LIKE '${like}' OR name LIKE '${like}';`
			+ `DELETE FROM oc_iv_locations WHERE code LIKE '${like}' OR name LIKE '${like}';`,
		)
	}
})

test('IV-TOTAL-STOCK: items list column + detail sum line show the scoped total', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()
	const locA = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `TS-A-${tag}`.slice(0, 64),
		name: `Shelf A ${tag}`,
		kind: 'warehouse',
	})
	expectOk(locA, 'create location A')
	const locB = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `TS-B-${tag}`.slice(0, 64),
		name: `Shelf B ${tag}`,
		kind: 'site',
	})
	expectOk(locB, 'create location B')

	const sku = `TS-${tag}`.slice(0, 64)
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku,
		name: `Total Stock ${tag}`,
		uom: 'pcs',
	})
	expectOk(item, 'create item')
	const itemId = item.data.id

	expectOk(await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId, locationId: locA.data.id, qty: 5, reason: 'seed A',
	}), 'receive A')
	expectOk(await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId, locationId: locB.data.id, qty: 7, reason: 'seed B',
	}), 'receive B')

	// AC-13: list row carries the server-side sum.
	const list = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items?q=${encodeURIComponent(sku)}&limit=5`)
	expectOk(list, 'items list')
	expect(list.data.data[0].totalQty).toBe(12)

	// AC-14: detail carries the same sum.
	const detail = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items/${itemId}`)
	expectOk(detail, 'item detail')
	expect(detail.data.totalQty).toBe(12)

	// UI: the items list shows the On hand column with the summed value.
	await openInventory(page, '/apps/inventorycheck/items')
	const search = page.locator('#iv-items-q')
	if (await search.isVisible().catch(() => false)) {
		await search.fill(sku)
		await page.waitForTimeout(800)
	}
	const row = page.locator('tr, [data-cell]').filter({ hasText: sku }).first()
	await expect(row, 'item row must show in the list').toBeVisible({ timeout: 20_000 })
	await expect(row.filter({ hasText: '12' }).first()).toBeVisible()

	// UI: item detail renders the "Total"/"Summe" line from the payload.
	await openInventory(page, `/apps/inventorycheck/items/${itemId}`)
	const total = page.locator('.iv-balance-total')
	await expect(total).toBeVisible({ timeout: 20_000 })
	await expect(total).toContainText('12')
})
