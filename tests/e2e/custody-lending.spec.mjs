// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * IV-CUSTODY-LENDING Wave A — person create → checkout decrements stock →
 * checkin returns under CAS → people page + item lent-out section render.
 * Anonymize is refused while a loan is open.
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

function expectOk(result, label = 'API') {
	expect(
		[200, 201, 204].includes(result.status),
		`${label} → ${result.status}: ${JSON.stringify(result.data)}`,
	).toBeTruthy()
}

const createdTags = []
function marker() {
	const tag = `cl${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
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
			+ `DELETE l FROM oc_iv_loans l JOIN oc_iv_items i ON l.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE l FROM oc_iv_loans l JOIN oc_iv_persons p ON l.person_id = p.id WHERE p.display_name LIKE '${like}';`
			+ `DELETE m FROM oc_iv_movements m JOIN oc_iv_items i ON m.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_items i ON b.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE FROM oc_iv_items WHERE sku LIKE '${like}';`
			+ `DELETE FROM oc_iv_persons WHERE display_name LIKE '${like}';`
			+ `DELETE FROM oc_iv_locations WHERE code LIKE '${like}';`,
		)
	}
})

test('F2: person → checkout → checkin lifecycle + UI surfaces', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()

	// --- fixture: location + item with stock ---
	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `CL-LOC-${tag}`.slice(0, 32), name: `CL Loc ${tag}`,
	})
	expectOk(loc, 'create location')

	const sku = `CL-I-${tag}`.slice(0, 64)
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku, name: `CL Item ${tag}`,
	})
	expectOk(item, 'create item')
	const itemId = item.data.id

	const recv = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId, locationId: loc.data.id, qty: 5,
	})
	expectOk(recv, 'receive stock')

	// --- person create + search ---
	const person = await api(page, 'POST', '/index.php/apps/inventorycheck/api/persons', {
		displayName: `CL Person ${tag}`, memberNo: `M-${tag}`.slice(0, 32),
	})
	expectOk(person, 'create person')
	const personId = person.data.person.id

	const found = await api(page, 'GET', `/index.php/apps/inventorycheck/api/persons/search?search=${encodeURIComponent(tag)}`)
	expectOk(found, 'search person')
	expect(found.data.results.some((r) => r.type === 'person' && r.person.id === personId)).toBeTruthy()

	// --- checkout decrements stock ---
	const checkout = await api(page, 'POST', '/index.php/apps/inventorycheck/api/loans', {
		itemId, locationId: loc.data.id, personId, qty: 2,
		dueAt: Math.floor(Date.now() / 1000) + 86400,
	})
	expectOk(checkout, 'checkout')
	const loanId = checkout.data.loan.id

	const bal1 = await api(page, 'GET', `/index.php/apps/inventorycheck/api/balances?itemId=${itemId}`)
	expectOk(bal1, 'balances')
	const q1 = bal1.data.data.find((b) => b.locationId === loc.data.id)?.qty ?? bal1.data.data[0]?.qty
	expect(q1).toBe(3)

	// --- over-return is refused by CAS ---
	const over = await api(page, 'POST', `/index.php/apps/inventorycheck/api/loans/${loanId}/checkin`, { qty: 5 })
	expect([409, 422].includes(over.status), `over-return → ${over.status}`).toBeTruthy()

	// --- checkin restores stock ---
	const checkin = await api(page, 'POST', `/index.php/apps/inventorycheck/api/loans/${loanId}/checkin`, {
		qty: 2, conditionIn: 'ok',
	})
	expectOk(checkin, 'checkin')
	const bal2 = await api(page, 'GET', `/index.php/apps/inventorycheck/api/balances?itemId=${itemId}`)
	const q2 = bal2.data.data.find((b) => b.locationId === loc.data.id)?.qty ?? bal2.data.data[0]?.qty
	expect(q2).toBe(5)

	// --- a returned loan can be anonymized ---
	const anon = await api(page, 'POST', `/index.php/apps/inventorycheck/api/persons/${personId}/anonymize`)
	expectOk(anon, 'anonymize')

	// --- open loan blocks anonymization ---
	const p2 = await api(page, 'POST', '/index.php/apps/inventorycheck/api/persons', { displayName: `CL P2 ${tag}` })
	expectOk(p2, 'person2')
	const loan2 = await api(page, 'POST', '/index.php/apps/inventorycheck/api/loans', {
		itemId, locationId: loc.data.id, personId: p2.data.person.id, qty: 1,
	})
	expectOk(loan2, 'checkout2')
	const blocked = await api(page, 'POST', `/index.php/apps/inventorycheck/api/persons/${p2.data.person.id}/anonymize`)
	expect(blocked.status, `anonymize-with-open-loan → ${blocked.status}`).toBe(409)

	// --- UI: people page lists the register ---
	await openInventory(page, '/apps/inventorycheck/people')
	await expect(page.locator('body')).toContainText('People', { timeout: 15_000 })
	await expect(page.locator('body')).toContainText(`CL P2 ${tag}`)

	// --- UI: item detail shows the lent-out section ---
	await openInventory(page, `/apps/inventorycheck/items/${itemId}`)
	await expect(page.locator('body')).toContainText('Lent out', { timeout: 15_000 })
})
