// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * IV-COMPLIANCE-PACK — movement commits chain journal entries; the
 * settings journey renders status → verify → badge; tampering is
 * flagged and the chain is restored afterwards.
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
			try { data = await res.json() } catch { /* binary/text */ }
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

const COMPOSE_CWD = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

function dbSql(sql) {
	try {
		return execFileSync('docker', [
			'compose', 'exec', '-T', 'mariadb', 'mariadb',
			'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-B', '-e', sql,
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
	} catch { return '' }
}

const tag = `jrn${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
let journalEntryId = 0
let movementId = 0
let itemId = 0
let locationId = 0

test.afterAll(() => {
	// Restore the chain exactly: our entry was appended at the tail —
	// deleting it leaves a contiguous, verifiable chain again.
	if (journalEntryId) {
		dbSql(`DELETE FROM oc_iv_journal WHERE id = ${journalEntryId}`)
	}
	if (movementId) {
		dbSql(`DELETE FROM oc_iv_movements WHERE id = ${movementId}`)
	}
	if (itemId) {
		dbSql(
			`DELETE FROM oc_iv_balances WHERE item_id = ${itemId};`
			+ `DELETE FROM oc_iv_items WHERE id = ${itemId};`,
		)
	}
	if (locationId) {
		dbSql(`DELETE FROM oc_iv_locations WHERE id = ${locationId}`)
	}
})

test('IV-COMPLIANCE-PACK: movement → journal entry → verify journey → tamper flagged', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	// Seed a location + item + receive — the commit must chain one entry.
	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `JRN-L-${tag}`, name: `Journal loc ${tag}`, kind: 'warehouse',
	})
	expectOk(loc, 'create location')
	locationId = loc.data.id

	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku: `JRN-I-${tag}`, name: `Journal item ${tag}`,
	})
	expectOk(item, 'create item')
	itemId = item.data.id

	const recv = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId, locationId, qty: 3, reason: `chain ${tag}`,
	})
	expectOk(recv, 'receive')
	movementId = recv.data.movements[0].id

	const headSeq = dbSql(
		`SELECT seq, id FROM oc_iv_journal WHERE movement_id = ${movementId} LIMIT 1`,
	)
	const [seqStr, idStr] = headSeq.trim().split('\t')
	expect(Number(seqStr)).toBeGreaterThan(0)
	journalEntryId = Number(idStr)

	// Status endpoint reports the entry.
	const status = await api(page, 'GET', '/index.php/apps/inventorycheck/api/journal')
	expectOk(status, 'journal status')
	expect(status.data.entries).toBeGreaterThan(0)

	// Verify walks the chain — intact while untouched.
	const intact = await api(page, 'POST', '/index.php/apps/inventorycheck/api/journal/verify')
	expectOk(intact, 'verify')
	expect(intact.data.intact).toBe(true)
	expect(intact.data.checked).toBeGreaterThan(0)

	// Tamper the tail entry — verify must flag exactly its seq.
	dbSql(`UPDATE oc_iv_journal SET payload = '{"seq":-1}' WHERE id = ${journalEntryId}`)
	const tampered = await api(page, 'POST', '/index.php/apps/inventorycheck/api/journal/verify')
	expect(tampered.data.intact).toBe(false)
	expect(tampered.data.firstBadSeq).toBe(Number(seqStr))

	// Settings journey: status → verify button → badge.
	await page.goto('/index.php/apps/inventorycheck/settings/journal')
	await expect(page.locator('#iv-journal-title, [data-iv-section="journal"]')).toHaveCount(1)
	const verifyBtn = page.getByRole('button', { name: 'Verify integrity' })
	await expect(verifyBtn).toBeVisible()
	await verifyBtn.click()
	await expect(page.locator('.iv-badge--critical')).toBeVisible({ timeout: 15_000 })

	// Restore the chain by removing the tampered tail entry + movement.
	dbSql(`DELETE FROM oc_iv_journal WHERE id = ${journalEntryId}`)
	journalEntryId = 0
	const healed = await api(page, 'POST', '/index.php/apps/inventorycheck/api/journal/verify')
	expect(healed.data.intact).toBe(true)

	// Downloads: real artifacts, correct types.
	for (const [path, magic] of [
		['/index.php/apps/inventorycheck/api/stock-list.ods', 'PK'],
		['/index.php/apps/inventorycheck/api/stock-list.pdf', '%PDF'],
	]) {
		const res = await page.evaluate(async (p) => {
			const r = await fetch(p, { credentials: 'same-origin' })
			const buf = new Uint8Array(await r.arrayBuffer())
			return { status: r.status, head: Array.from(buf.slice(0, 4)).map((b) => String.fromCharCode(b)).join(''), size: buf.length }
		}, path)
		expect(res.status).toBe(200)
		expect(res.head.startsWith(magic === 'PK' ? 'PK' : '%PDF')).toBeTruthy()
		expect(res.size).toBeGreaterThan(500)
	}
})
