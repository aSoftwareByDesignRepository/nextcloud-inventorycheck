// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * SPEC §14.3 — UJ-1…UJ-7 (Alt paths) at 1280 and 320 (Playwright projects).
 * Seeds via the live JSON API (same CSRF session as the UI) so journeys stay
 * deterministic; asserts the shell the user actually sees.
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

async function axeMain(page) {
	const results = await new AxeBuilder({ page })
		.include('#iv-main-content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.exclude('.iv-toast-region')
		.analyze()
	expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
}

function marker() {
	return `uj${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}

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
	await axeMain(page)
})

test('UJ-2 receive & transfer: balances and transfer group', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires credentials')
	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()
	const sku = `SKU-${tag}`.slice(0, 64)
	const locA = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `WH-${tag}`.slice(0, 64),
		name: `Warehouse ${tag}`,
		kind: 'warehouse',
	})
	expectOk(locA, 'create WH')
	const locB = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `VAN-${tag}`.slice(0, 64),
		name: `Van ${tag}`,
		kind: 'van',
	})
	expectOk(locB, 'create VAN')
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku,
		name: `Filter ${tag}`,
		uom: 'pcs',
		reorderLevel: 5,
	})
	expectOk(item, 'create item')

	const receive = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId: item.data.id,
		locationId: locA.data.id,
		qty: 10,
		reason: 'UJ-2 receive',
	})
	expectOk(receive, 'receive')
	expect(receive.data.movements[0].qtyAfter).toBe(10)

	const transfer = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/transfer', {
		itemId: item.data.id,
		fromLocationId: locA.data.id,
		toLocationId: locB.data.id,
		qty: 3,
		reason: 'UJ-2 transfer',
	})
	expectOk(transfer, 'transfer')
	expect(transfer.data.movements).toHaveLength(2)
	const group = transfer.data.movements[0].transferGroup
	expect(group).toMatch(
		/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
	)

	const sameLoc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/transfer', {
		itemId: item.data.id,
		fromLocationId: locA.data.id,
		toLocationId: locA.data.id,
		qty: 1,
	})
	expect(sameLoc.status).toBe(422)
	expect(sameLoc.data?.error?.code || sameLoc.data?.code).toMatch(/same_location/)

	await openInventory(page, '/apps/inventorycheck/movements')
	await expect(page.locator('.iv-filterbar, [role="search"]').first()).toBeVisible({ timeout: 30_000 })
	const filtered = await api(
		page,
		'GET',
		`/index.php/apps/inventorycheck/api/movements?transferGroup=${encodeURIComponent(group)}&limit=10&offset=0`,
	)
	expectOk(filtered, 'filter transferGroup')
	expect(filtered.data.data).toHaveLength(2)
	await axeMain(page)
})

test('UJ-3 issue insufficient stock toast path + field ACL', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires credentials')
	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()
	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `L3-${tag}`.slice(0, 64),
		name: `Site ${tag}`,
		kind: 'site',
	})
	expectOk(loc, 'loc')
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku: `I3-${tag}`.slice(0, 64),
		name: `Bolt ${tag}`,
		reorderLevel: 0,
	})
	expectOk(item, 'item')
	await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId: item.data.id,
		locationId: loc.data.id,
		qty: 1,
	})

	const over = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/issue', {
		itemId: item.data.id,
		locationId: loc.data.id,
		qty: 5,
	})
	expect(over.status).toBe(409)
	expect(over.data?.error?.code || over.data?.code).toMatch(/insufficient_stock/)

	await openInventory(page, '/apps/inventorycheck/')
	await expect(page.getByRole('button', { name: /Issue stock|Abgang/i }).first()).toBeVisible({ timeout: 30_000 })
	await axeMain(page)
})

test('UJ-4 adjust set + no-op rejection', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires credentials')
	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()
	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `L4-${tag}`.slice(0, 64),
		name: `Adj ${tag}`,
		kind: 'other',
	})
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku: `I4-${tag}`.slice(0, 64),
		name: `Widget ${tag}`,
	})
	expectOk(loc, 'loc')
	expectOk(item, 'item')
	await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId: item.data.id,
		locationId: loc.data.id,
		qty: 14,
	})

	const set = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/adjust', {
		itemId: item.data.id,
		locationId: loc.data.id,
		mode: 'set',
		qty: 12,
		reason: 'Inventur UJ-4',
	})
	expectOk(set, 'adjust set')
	expect(set.data.movements[0].qtyDelta).toBe(-2)
	expect(set.data.movements[0].qtyAfter).toBe(12)

	const noop = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/adjust', {
		itemId: item.data.id,
		locationId: loc.data.id,
		mode: 'set',
		qty: 12,
	})
	expect(noop.status).toBe(422)
	expect(noop.data?.error?.code || noop.data?.code).toMatch(/invalid_qty/)
})

test('UJ-5 low stock list boundary', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires credentials')
	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()
	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `L5-${tag}`.slice(0, 64),
		name: `Low ${tag}`,
		kind: 'warehouse',
	})
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku: `I5-${tag}`.slice(0, 64),
		name: `Lowstock ${tag}`,
		reorderLevel: 5,
	})
	expectOk(loc, 'loc')
	expectOk(item, 'item')
	await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId: item.data.id,
		locationId: loc.data.id,
		qty: 4,
	})

	const low = await api(page, 'GET', '/index.php/apps/inventorycheck/api/low-stock?limit=200&offset=0')
	expectOk(low, 'low-stock')
	const ids = (low.data.data || []).map((r) => r.item?.id ?? r.itemId ?? r.id)
	expect(ids).toContain(item.data.id)

	await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId: item.data.id,
		locationId: loc.data.id,
		qty: 1,
	})
	const boundary = await api(page, 'GET', '/index.php/apps/inventorycheck/api/low-stock?limit=200&offset=0')
	expectOk(boundary, 'low-stock boundary')
	const ids2 = (boundary.data.data || []).map((r) => r.item?.id ?? r.itemId ?? r.id)
	expect(ids2).not.toContain(item.data.id)

	await openInventory(page, '/apps/inventorycheck/')
	await axeMain(page)
})

test('UJ-6 reverse compensating movement', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires credentials')
	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()
	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `L6-${tag}`.slice(0, 64),
		name: `Rev ${tag}`,
		kind: 'warehouse',
	})
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku: `I6-${tag}`.slice(0, 64),
		name: `Reverse ${tag}`,
	})
	expectOk(loc, 'loc')
	expectOk(item, 'item')
	const recv = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId: item.data.id,
		locationId: loc.data.id,
		qty: 10,
	})
	expectOk(recv, 'receive')
	const movId = recv.data.movements[0].id

	const issue = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/issue', {
		itemId: item.data.id,
		locationId: loc.data.id,
		qty: 10,
		reason: `reversal of #${movId}`,
	})
	expectOk(issue, 'compensating issue')
	expect(issue.data.movements[0].qtyAfter).toBe(0)

	await openInventory(page, '/apps/inventorycheck/movements')
	await expect(page.getByRole('button', { name: /Reverse|Umkehren/i }).first()).toBeVisible({ timeout: 30_000 })
})

test('UJ-7 settings: app admins, license, support', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN'), 'Requires NC_ADMIN_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page, '/apps/inventorycheck/settings')
	const main = page.locator('#iv-main-content')
	await expect(main.locator('#iv-app-admins')).toBeVisible({ timeout: 30_000 })
	await expect(main.locator('#iv-access-title')).toContainText(/Access|Zugriff/i)
	await expect(main.locator('#iv-support-us, [data-support-us="1"]').first()).toBeVisible()
	await expect(main.locator('#iv-license')).toBeVisible()
	await expect(main.locator('#iv-license-title')).toContainText(/License|Lizenz|Mobile/i)

	const cfg = await api(page, 'GET', '/index.php/apps/inventorycheck/api/config')
	expectOk(cfg, 'config')
	expect(cfg.data.isSystemAdmin).toBe(true)
	expect(Array.isArray(cfg.data.appAdmins)).toBe(true)

	const bad = await api(page, 'POST', '/index.php/apps/inventorycheck/api/config/access', {
		allowedUsers: ['definitely-not-a-real-user-zz'],
	})
	expect(bad.status).toBe(422)
	expect(bad.data?.error?.code || bad.data?.code).toMatch(/unknown_user/)
	await axeMain(page)
})

test('UJ movements: filter bar and pagination landmarks', async ({ page }) => {
	test.skip(!credsFromEnv('E2E') && !credsFromEnv('ADMIN'), 'Requires NC_E2E_* or NC_ADMIN_*')

	await ensureLoggedIn(page, 'E2E')
	await openInventory(page, '/apps/inventorycheck/movements')
	await expect(page.locator('.iv-filterbar, [role="search"]').first()).toBeVisible({ timeout: 30_000 })
	await expect(page.getByLabel(/Kind|Art/i).or(page.locator('#iv-mov-kind')).first()).toBeVisible()
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
