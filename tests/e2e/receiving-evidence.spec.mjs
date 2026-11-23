// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * IV-RECEIVING-EVIDENCE (C-2) — Lieferschein photo on a receive movement:
 * upload → hasDeliveryNote flag → authenticated GET → replace → delete.
 * Non-receive rows reject with 409; field users cannot upload.
 */

async function api(page, method, path, body) {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token =
				(typeof window.OC !== 'undefined' && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
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
			try { data = await res.json() } catch { /* text */ }
			return { status: res.status, data }
		},
		{ method, path, body },
	)
}

/** Multipart upload of a tiny PNG built in-page. */
async function upload(page, movId, { bytes = 'png', oversize = false } = {}) {
	return page.evaluate(
		async ({ movId, bytes, oversize }) => {
			const token =
				(typeof window.OC !== 'undefined' && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
				|| ''
			const PNG = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAKklEQVR4nO3OMQ0AIAwEsGv+o+UBBjN41b0DZJmZmdnNzMzMzMzMzPwvA+gAK+RB0g1iAAAAAElFTkSuQmCC'
			const raw = bytes === 'png' ? atob(PNG) : 'this is not an image at all, just bytes'
			const buf = new Uint8Array(oversize ? 11 * 1024 * 1024 : raw.length)
			for (let i = 0; i < raw.length; i++) buf[i] = raw.charCodeAt(i)
			const mime = bytes === 'png' ? 'image/png' : 'text/plain'
			const fd = new FormData()
			fd.append('file', new Blob([buf], { type: mime }), 'note.png')
			const res = await fetch(`/index.php/apps/inventorycheck/api/movements/${movId}/delivery-note`, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'requesttoken': token, 'OCS-APIREQUEST': 'true' },
				body: fd,
			})
			let data = null
			try { data = await res.json() } catch { /* text */ }
			return { status: res.status, data }
		},
		{ movId, bytes, oversize },
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
	const tag = `re${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
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
			`DELETE j FROM oc_iv_journal j JOIN oc_iv_movements m ON j.movement_id = m.id JOIN oc_iv_items i ON m.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE m FROM oc_iv_movements m JOIN oc_iv_items i ON m.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_items i ON b.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE FROM oc_iv_items WHERE sku LIKE '${like}';`
			+ `DELETE FROM oc_iv_locations WHERE code LIKE '${like}';`,
		)
	}
})

test('C-2: delivery-note lifecycle on a receive movement', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()

	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `RE-LOC-${tag}`.slice(0, 32), name: `RE Loc ${tag}`,
	})
	expectOk(loc, 'create location')

	const sku = `RE-I-${tag}`.slice(0, 64)
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku, name: `RE Item ${tag}`,
	})
	expectOk(item, 'create item')
	const itemId = item.data.id

	const recv = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId, locationId: loc.data.id, qty: 5,
	})
	expectOk(recv, 'receive stock')
	const movId = recv.data.movement?.id ?? recv.data.movements?.[0]?.id
	expect(movId, 'movement id in receive response').toBeTruthy()

	// NN-02: a failed upload must never disturb the committed booking.
	const badUpload = await upload(page, movId, { bytes: 'not-an-image' })
	expect(badUpload.status).toBe(422)
	const stillThere = await api(page, 'GET', `/index.php/apps/inventorycheck/api/movements?itemId=${itemId}`)
	expectOk(stillThere, 'movement list')
	expect(stillThere.data.data.some((m) => m.id === movId)).toBeTruthy()

	// NN-04: delivery-note write on a non-receive kind → 409 invalid_kind.
	const issue = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/issue', {
		itemId, locationId: loc.data.id, qty: 1,
	})
	expectOk(issue, 'issue stock')
	const issueId = issue.data.movement?.id ?? issue.data.movements?.[0]?.id
	const wrongKind = await upload(page, issueId, {})
	expect(wrongKind.status).toBe(409)
	expect(wrongKind.data?.error?.code).toBe('invalid_kind')

	// AC-8: oversize file → 422 with limit copy.
	const big = await upload(page, movId, { oversize: true })
	expect(big.status).toBe(422)
	expect(big.data?.error?.code).toBe('photo_too_large')

	// AC-2: upload lands the flag on the movement DTO (never the path — NN-01).
	const up = await upload(page, movId, {})
	expectOk(up, 'upload note')
	expect(up.data.movement.hasDeliveryNote).toBe(true)
	expect(up.data.movement.deliveryNotePath).toBeUndefined()
	expect(up.data.movement.delivery_note_path).toBeUndefined()

	// AC-5: authenticated GET streams the image.
	const img = await page.evaluate(async (movId) => {
		const res = await fetch(`/index.php/apps/inventorycheck/api/movements/${movId}/delivery-note`, {
			credentials: 'same-origin',
		})
		return { status: res.status, type: res.headers.get('content-type') || '', len: (await res.arrayBuffer()).byteLength }
	}, movId)
	expect(img.status).toBe(200)
	expect(img.type).toContain('image/')
	expect(img.len).toBeGreaterThan(0)

	// AC-10: replace works (old blob deleted server-side).
	const up2 = await upload(page, movId, {})
	expectOk(up2, 'replace note')
	expect(up2.data.movement.hasDeliveryNote).toBe(true)

	// AC-6: remove clears the flag.
	const del = await api(page, 'DELETE', `/index.php/apps/inventorycheck/api/movements/${movId}/delivery-note`)
	expectOk(del, 'remove note')
	expect(del.data.movement.hasDeliveryNote).toBe(false)

	// Movements list carries the flag per row.
	const list = await api(page, 'GET', `/index.php/apps/inventorycheck/api/movements?itemId=${itemId}`)
	expectOk(list, 'movement list')
	const recvRow = list.data.data.find((m) => m.id === movId)
	expect(recvRow.hasDeliveryNote).toBe(false)
})

test('C-2: movements list shows the Note column on receive rows', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()

	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `RE2-LOC-${tag}`.slice(0, 32), name: `RE2 Loc ${tag}`,
	})
	expectOk(loc, 'create location')
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku: `RE2-I-${tag}`.slice(0, 64), name: `RE2 Item ${tag}`,
	})
	expectOk(item, 'create item')
	const recv = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId: item.data.id, locationId: loc.data.id, qty: 3,
	})
	expectOk(recv, 'receive')
	const movId = recv.data.movement?.id ?? recv.data.movements?.[0]?.id
	const up = await upload(page, movId, {})
	expectOk(up, 'attach note')

	await openInventory(page, '/apps/inventorycheck/movements')
	await expect(page.locator('[data-iv-action="delivery-note"]').first()).toBeVisible({ timeout: 10000 })
})
