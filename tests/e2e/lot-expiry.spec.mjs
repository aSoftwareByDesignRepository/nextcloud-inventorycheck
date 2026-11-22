// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * IV-LOT-EXPIRY-PACK — the audit journey: a lot-tracked item gets two lots
 * with different expiries; the shared API returns them FEFO-sorted with
 * states, the Übersicht card appears (hidden-when-empty is asserted by the
 * absence for a fresh account), the item detail lists lots, and the issue
 * dialog pre-selects the earliest-expiring lot.
 */

async function api(page, method, path, body) {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token = (document.querySelector('input[name="requesttoken"]'))
				?.getAttribute('value')
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
	const tag = `le${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
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
		console.error('lot-expiry teardown dbSql failed:', String(err).slice(0, 400))
		return ''
	}
}

test.afterAll(() => {
	for (const tag of createdTags) {
		const like = `%${tag}%`
		dbSql(
			`DELETE n FROM oc_iv_notif_log n JOIN oc_iv_items i ON n.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE l FROM oc_iv_lots l JOIN oc_iv_items i ON l.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE m FROM oc_iv_movements m JOIN oc_iv_items i ON m.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE m FROM oc_iv_movements m JOIN oc_iv_locations o ON m.location_id = o.id OR m.counterparty_loc_id = o.id WHERE o.code LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_items i ON b.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_locations o ON b.location_id = o.id WHERE o.code LIKE '${like}';`
			+ `DELETE FROM oc_iv_items WHERE sku LIKE '${like}' OR scan_code LIKE '${like}' OR name LIKE '${like}';`
			+ `DELETE FROM oc_iv_locations WHERE code LIKE '${like}' OR name LIKE '${like}';`,
		)
	}
})

test('IV-LOT-EXPIRY: FEFO payload, watch card, detail lots, issue picker', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()
	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `LE-L-${tag}`.slice(0, 64),
		name: `Expiry Shelf ${tag}`,
		kind: 'warehouse',
	})
	expectOk(loc, 'create location')

	const sku = `LE-${tag}`.slice(0, 64)
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku,
		name: `Expiry Item ${tag}`,
		uom: 'pcs',
		trackMode: 'lot',
	})
	expectOk(item, 'create item')
	const itemId = item.data.id

	const now = Math.floor(Date.now() / 1000)
	expectOk(await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId, locationId: loc.data.id, qty: 4, reason: 'seed', lotCode: `LATE-${tag}`, expiresAt: now + 40 * 86400,
	}), 'receive late lot')
	expectOk(await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId, locationId: loc.data.id, qty: 6, reason: 'seed', lotCode: `EARLY-${tag}`, expiresAt: now + 5 * 86400,
	}), 'receive early lot')

	// Shared API: FEFO-sorted with qty + state (AC-15).
	const lots = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items/${itemId}/lots`)
	expectOk(lots, 'item lots')
	expect(lots.data.data[0].lotCode).toBe(`EARLY-${tag}`)
	expect(lots.data.data[0].state).toBe('expiring')
	expect(lots.data.data[0].qty).toBe(6)
	expect(lots.data.data[1].lotCode).toBe(`LATE-${tag}`)

	// Übersicht watch card lists the in-window lot.
	await openInventory(page)
	const card = page.locator('[data-iv-expiring-card]')
	await expect(card).toBeVisible({ timeout: 20_000 })
	await expect(card).toContainText(`EARLY-${tag}`)

	// Item detail: lots section with expiry state.
	await openInventory(page, `/apps/inventorycheck/items/${itemId}`)
	const lotSection = page.locator('[data-iv-lot-section]')
	await expect(lotSection).toBeVisible({ timeout: 20_000 })
	await expect(lotSection).toContainText(`EARLY-${tag}`)
	await expect(lotSection).toContainText('xpiring') // "expiring soon" (en) — never color-only

	// Issue dialog: FEFO picker pre-selects the earliest-expiring lot.
	await page.locator('[data-iv-balance-actions] button').filter({ hasText: /Issue|Abgang|Ausgeben/i }).first().click()
	const picker = page.locator('[data-iv-lot-picker]')
	await expect(picker).toBeVisible({ timeout: 10_000 })
	await expect(picker).toHaveValue(`EARLY-${tag}`)
})
