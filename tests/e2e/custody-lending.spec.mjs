// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'
import { tabUntil, activateByKeyboard } from './helpers/keyboard.mjs'

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
		// NOTE: oc_iv_journal rows are never deleted — the hash chain is
		// append-only; any mid-chain delete permanently flags verify() as
		// seq_gap on this shared instance (audit residue is by design).
		dbSql(
			`DELETE l FROM oc_iv_loans l JOIN oc_iv_items i ON l.item_id = i.id WHERE i.sku LIKE '${like}';`
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
	// Fail at the real step when a 2xx lacks the id (shared-instance transient
	// once surfaced as a misleading downstream `unknown_location` 404).
	expect(loc.data?.id, `create location id: ${JSON.stringify(loc.data)}`).toBeGreaterThan(0)

	const sku = `CL-I-${tag}`.slice(0, 64)
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku, name: `CL Item ${tag}`,
	})
	expectOk(item, 'create item')
	expect(item.data?.id, `create item id: ${JSON.stringify(item.data)}`).toBeGreaterThan(0)
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
	expect(person.data?.person?.id, `create person id: ${JSON.stringify(person.data)}`).toBeGreaterThan(0)
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

	// --- UI: people page lists the register (locale-safe: structural ready
	// marker + fixture name — never an EN visible-text needle; the fixture
	// user's language is shared state other lanes may switch) ---
	await openInventory(page, '/apps/inventorycheck/people')
	await expect(page.locator('#iv-page-title, .iv-section, .iv-empty, .iv-table').first()).toBeVisible({ timeout: 15_000 })
	await expect(page.locator('body')).toContainText(`CL P2 ${tag}`)

	// --- UI: item detail shows the lent-out section — the still-open loan2
	// renders the person name inside the loans table ---
	await openInventory(page, `/apps/inventorycheck/items/${itemId}`)
	await expect(
		page.locator('table, .iv-table').filter({ hasText: `CL P2 ${tag}` }).first(),
	).toBeVisible({ timeout: 15_000 })
})

test('F2 UX proof: keyboard-only checkout→checkin journey (Tab/Enter only)', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()

	// --- fixtures via API — fixture seeding is not the journey under test ---
	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `KB-L-${tag}`.slice(0, 32), name: `KB Loc ${tag}`,
	})
	expectOk(loc, 'create location')
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku: `KB-I-${tag}`.slice(0, 64), name: `KB Item ${tag}`,
	})
	expectOk(item, 'create item')
	const itemId = item.data.id
	expectOk(await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId, locationId: loc.data.id, qty: 3,
	}), 'receive stock')
	const person = await api(page, 'POST', '/index.php/apps/inventorycheck/api/persons', {
		displayName: `KB Person ${tag}`, memberNo: `KB-${tag}`.slice(0, 32),
	})
	expectOk(person, 'create person')

	// --- keyboard journey starts on the item detail page ---
	await openInventory(page, `/apps/inventorycheck/items/${itemId}`)

	// Checkout trigger — focused then Enter (suite convention: focus() is the
	// accepted page-level entry point; everything inside the dialog must be
	// reached by Tab).
	const checkoutBtn = page.locator('[data-iv-action="loan-checkout"]').first()
	await expect(checkoutBtn).toBeVisible({ timeout: 20_000 })
	await activateByKeyboard(checkoutBtn, page)
	const dlg = page.locator('.iv-dialog')
	await expect(dlg).toBeVisible({ timeout: 10_000 })

	// Person combobox (WAI-ARIA): Tab to it, type the member tag, Tab into the
	// result listbox, Enter picks the option.
	await tabUntil(page, { sel: 'input[role="combobox"]' })
	await page.keyboard.type(tag)
	const personOpt = dlg.locator('li[role="option"]').filter({ hasText: `KB Person ${tag}` }).first()
	await expect(personOpt).toBeVisible({ timeout: 10_000 })
	await tabUntil(page, { sel: 'li[role="option"]', text: `KB Person ${tag}` })
	await page.keyboard.press('Enter')

	// Quantity: defaults to 1 — ArrowUp on the number input raises it to 2.
	await tabUntil(page, { sel: '[data-iv-field="qty"] input' })
	await page.keyboard.press('ArrowUp')

	// Confirm via Tab + Enter — never a pointer click.
	await tabUntil(page, { sel: '[data-iv-action="dialog-confirm"]' })
	await page.keyboard.press('Enter')
	await expect(dlg).toBeHidden({ timeout: 15_000 })

	// Server-side proof the checkout landed: open loan with outstanding 2,
	// balance reduced to 1.
	const loans = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items/${itemId}/loans`)
	expectOk(loans, 'item loans')
	const open = (loans.data.loans || []).find((l) => Number(l.outstanding) > 0)
	expect(open, `open loan after keyboard checkout: ${JSON.stringify(loans.data)}`).toBeTruthy()
	expect(Number(open.outstanding)).toBe(2)

	// Checkin: the row action in the refreshed loans table, Enter opens the
	// dialog, Tab to confirm, Enter posts.
	const checkinBtn = page.locator('[data-iv-action="loan-checkin"]').first()
	await expect(checkinBtn).toBeVisible({ timeout: 20_000 })
	await activateByKeyboard(checkinBtn, page)
	const dlg2 = page.locator('.iv-dialog')
	await expect(dlg2).toBeVisible({ timeout: 10_000 })
	await tabUntil(page, { sel: '[data-iv-action="dialog-confirm"]' })
	await page.keyboard.press('Enter')
	await expect(dlg2).toBeHidden({ timeout: 15_000 })

	// Stock restored to 3 — the round-trip completed without pointer input.
	const bal = await api(page, 'GET', `/index.php/apps/inventorycheck/api/balances?itemId=${itemId}`)
	expectOk(bal, 'balances')
	const q = bal.data.data.find((b) => b.locationId === loc.data.id)?.qty ?? bal.data.data[0]?.qty
	expect(q).toBe(3)
})
