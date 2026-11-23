// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * IV-CUSTODY Wave C — member portal lifecycle + abuse invariants.
 *
 * Proves end-to-end: token issue → uniform shell (valid AND bogus tokens
 * render identically, zero oracle) → throttled JSON API → own-data scope →
 * badge-only stock (NN-01, never a count) → member request → office
 * approve → loan → damage report → revoke → 404. axe WCAG 2.1 AA on the
 * guest surface; Escape + focus restore on the portal dialog.
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
	const tag = `pc${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
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
			+ `DELETE r FROM oc_iv_loan_requests r JOIN oc_iv_persons p ON r.person_id = p.id WHERE p.display_name LIKE '${like}';`
			+ `DELETE r FROM oc_iv_reservations r JOIN oc_iv_items i ON r.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE t FROM oc_iv_member_tokens t JOIN oc_iv_persons p ON t.person_id = p.id WHERE p.display_name LIKE '${like}';`
			+ `DELETE m FROM oc_iv_movements m JOIN oc_iv_items i ON m.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_items i ON b.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE FROM oc_iv_items WHERE sku LIKE '${like}';`
			+ `DELETE FROM oc_iv_persons WHERE display_name LIKE '${like}';`
			+ `DELETE FROM oc_iv_locations WHERE code LIKE '${like}';`,
		)
	}
})

test('Wave C: member portal — token, badges, request → approve, damage, revoke', async ({ page, request }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	// Test hygiene: prior runs leave iv-portal attempts in the brute-force
	// table — accumulated attempts would throttle this run's legit calls.
	dbSql(`DELETE FROM oc_bruteforce_attempts WHERE action LIKE 'iv-portal%';`)

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()

	// --- fixture: location + item + stock + person ---
	const loc = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `PC-LOC-${tag}`.slice(0, 32), name: `PC Loc ${tag}`,
	})
	expectOk(loc, 'create location')

	const sku = `PC-I-${tag}`.slice(0, 64)
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku, name: `PC Item ${tag}`,
	})
	expectOk(item, 'create item')
	const itemId = item.data.id

	const recv = await api(page, 'POST', '/index.php/apps/inventorycheck/api/movements/receive', {
		itemId, locationId: loc.data.id, qty: 5,
	})
	expectOk(recv, 'receive stock')

	const person = await api(page, 'POST', '/index.php/apps/inventorycheck/api/persons', {
		displayName: `PC Person ${tag}`, memberNo: `PM-${tag}`.slice(0, 32),
	})
	expectOk(person, 'create person')
	const personId = person.data.person.id

	// --- issue member token (raw returned once) ---
	const issue = await api(page, 'POST', `/index.php/apps/inventorycheck/api/persons/${personId}/member-token`, {})
	expectOk(issue, 'issue token')
	const rawToken = issue.data.token
	expect(typeof rawToken).toBe('string')
	expect(rawToken.length).toBeGreaterThanOrEqual(24)

	// token state is live; hash is never exposed
	const tokState = await api(page, 'GET', `/index.php/apps/inventorycheck/api/persons/${personId}/member-token`)
	expectOk(tokState, 'token state')
	expect(tokState.data.live).toBeTruthy()
	expect(JSON.stringify(tokState.data)).not.toContain('hash')

	// --- shell: valid AND bogus tokens render identically (no oracle) ---
	const shellOk = await request.get(`/index.php/apps/inventorycheck/portal/${rawToken}`)
	expect(shellOk.status()).toBe(200)
	const bodyOk = await shellOk.text()
	const shellBad = await request.get('/index.php/apps/inventorycheck/portal/ivm_bogus000000000000000000')
	expect(shellBad.status()).toBe(200)
	const bodyBad = await shellBad.text()
	// Same template for both: after removing per-request NC tokens and the
	// route token itself, the shells must be byte-identical (zero oracle).
	const normalize = (html, tok) => html
		.replaceAll(tok, '__T__')
		.replace(/data-requesttoken="[^"]*"/g, 'data-requesttoken="__R__"')
		.replace(/nonce="[^"]*"/g, 'nonce="__N__"')
		.replace(/data-csrf="[^"]*"/g, 'data-csrf="__C__"')
	expect(normalize(bodyOk, rawToken)).toBe(normalize(bodyBad, 'ivm_bogus000000000000000000'))

	// --- JSON API: valid token → own scope, badge-only items ---
	const stateRes = await request.get(`/index.php/apps/inventorycheck/portal/${rawToken}/api`)
	expect(stateRes.status()).toBe(200)
	const state = await stateRes.json()
	expect(state.person.displayName).toContain(tag)
	expect(Array.isArray(state.loans)).toBeTruthy()
	expect(Array.isArray(state.items)).toBeTruthy()
	const listed = state.items.find((i) => i.id === itemId)
	expect(listed).toBeTruthy()
	expect(listed.badge).toBe('available')
	// NN-01: badge only — no count, no balance, no location breakdown.
	expect(listed.qty).toBeUndefined()
	expect(listed.balance).toBeUndefined()
	expect(JSON.stringify(state.items)).not.toMatch(/"available":\d/)

	// --- invalid token → uniform 404 on every portal JSON route ---
	for (const badPath of [
		'/index.php/apps/inventorycheck/portal/ivm_bogus000000000000000000/api',
	]) {
		const r = await request.get(badPath)
		expect(r.status(), `bogus token ${badPath} → ${r.status()}`).toBe(404)
	}
	const badWrite = await request.post('/index.php/apps/inventorycheck/portal/ivm_bogus000000000000000000/requests', {
		data: { itemId, qty: 1 },
	})
	expect(badWrite.status()).toBe(404)

	// --- member submits a request ---
	const reqRes = await request.post(`/index.php/apps/inventorycheck/portal/${rawToken}/requests`, {
		data: { itemId, qty: 1, purpose: `pc-purpose-${tag}` },
	})
	expect(reqRes.status(), `portal request → ${reqRes.status()}: ${await reqRes.text()}`).toBe(201)
	const memberReq = (await reqRes.json()).request
	expect(memberReq.status).toBe('pending')

	// request is visible in member's own state
	const state2 = await (await request.get(`/index.php/apps/inventorycheck/portal/${rawToken}/api`)).json()
	expect(state2.requests.some((r) => r.id === memberReq.id && r.status === 'pending')).toBeTruthy()

	// --- office queue: pending shows, approve mints the loan atomically ---
	const queue = await api(page, 'GET', '/index.php/apps/inventorycheck/api/loan-requests')
	expectOk(queue, 'request queue')
	const queued = queue.data.requests.find((r) => r.id === memberReq.id)
	expect(queued, 'request in office queue').toBeTruthy()

	const approve = await api(page, 'POST', `/index.php/apps/inventorycheck/api/loan-requests/${memberReq.id}/approve`, {
		locationId: loc.data.id,
	})
	expectOk(approve, 'approve request')
	const loanId = approve.data.loan.id

	// second approve is refused (decided already)
	const reapprove = await api(page, 'POST', `/index.php/apps/inventorycheck/api/loan-requests/${memberReq.id}/approve`, {
		locationId: loc.data.id,
	})
	expect([409, 422, 404].includes(reapprove.status), `re-approve → ${reapprove.status}`).toBeTruthy()

	// member sees their own open loan (own-scope)
	const state3 = await (await request.get(`/index.php/apps/inventorycheck/portal/${rawToken}/api`)).json()
	expect(state3.loans.some((l) => l.id === loanId && l.qty === 1)).toBeTruthy()

	// stock decremented office-side
	const bal = await api(page, 'GET', `/index.php/apps/inventorycheck/api/balances?itemId=${itemId}`)
	expectOk(bal, 'balances')
	const q = bal.data.data.find((b) => b.locationId === loc.data.id)?.qty ?? bal.data.data[0]?.qty
	expect(q).toBe(4)

	// --- member damage report lands on own loan ---
	const dmg = await request.post(`/index.php/apps/inventorycheck/portal/${rawToken}/loans/${loanId}/damage`, {
		data: { note: `pc-scratch-${tag}` },
	})
	expect(dmg.status()).toBe(200)
	const dmgEmpty = await request.post(`/index.php/apps/inventorycheck/portal/${rawToken}/loans/${loanId}/damage`, {
		data: { note: '   ' },
	})
	expect([400, 422].includes(dmgEmpty.status()), `empty damage note → ${dmgEmpty.status()}: ${await dmgEmpty.text()}`).toBeTruthy()

	// foreign-loan damage → uniform 404 (no IDOR)
	const person2 = await api(page, 'POST', '/index.php/apps/inventorycheck/api/persons', {
		displayName: `PC Other ${tag}`, memberNo: `PO-${tag}`.slice(0, 32),
	})
	expectOk(person2, 'create person2')
	const issue2 = await api(page, 'POST', `/index.php/apps/inventorycheck/api/persons/${person2.data.person.id}/member-token`, {})
	expectOk(issue2, 'issue token2')
	const foreign = await request.post(`/index.php/apps/inventorycheck/portal/${issue2.data.token}/loans/${loanId}/damage`, {
		data: { note: 'nope' },
	})
	expect(foreign.status(), `foreign loan damage → ${foreign.status()}`).toBe(404)

	// foreign token's own scope cannot see our person/loan
	const stateForeign = await (await request.get(`/index.php/apps/inventorycheck/portal/${issue2.data.token}/api`)).json()
	expect(stateForeign.person.displayName).toContain('PC Other')
	expect(stateForeign.loans.some((l) => l.id === loanId)).toBeFalsy()

	// --- reservation: office hold → availability flips → badge reserved → convert ---
	const now = Math.floor(Date.now() / 1000)
	const resv = await api(page, 'POST', '/index.php/apps/inventorycheck/api/reservations', {
		itemId, locationId: loc.data.id, qty: 4, fromTs: now - 60, toTs: now + 86400,
		personId, eventLabel: `pc-event-${tag}`,
	})
	expectOk(resv, 'create reservation')
	const resvId = resv.data.reservation?.id ?? resv.data.id

	const avail = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items/${itemId}/availability?locationId=${loc.data.id}`)
	expectOk(avail, 'availability')
	expect(avail.data.available).toBe(0)

	// member-side badge flips to reserved (all stock held), still no counts
	const stateR = await (await request.get(`/index.php/apps/inventorycheck/portal/${rawToken}/api`)).json()
	const badgeR = stateR.items.find((i) => i.id === itemId)
	expect(badgeR.badge).toBe('reserved')
	expect(badgeR.qty).toBeUndefined()

	const conv = await api(page, 'POST', `/index.php/apps/inventorycheck/api/reservations/${resvId}/convert`, {})
	expectOk(conv, 'convert reservation')

	// --- cancel request (own, pending) ---
	const reqRes2 = await request.post(`/index.php/apps/inventorycheck/portal/${rawToken}/requests`, {
		data: { itemId, qty: 1, purpose: `pc-cancel-${tag}` },
	})
	expect(reqRes2.status()).toBe(201)
	const req2 = (await reqRes2.json()).request
	const cancel = await request.post(`/index.php/apps/inventorycheck/portal/${rawToken}/requests/${req2.id}/cancel`)
	expect(cancel.status()).toBe(200)

	// --- revoke → every portal route 404s ---
	const revoke = await api(page, 'DELETE', `/index.php/apps/inventorycheck/api/persons/${personId}/member-token`)
	expectOk(revoke, 'revoke token')
	const afterRevoke = await request.get(`/index.php/apps/inventorycheck/portal/${rawToken}/api`)
	expect(afterRevoke.status(), `revoked token → ${afterRevoke.status()}`).toBe(404)

	// re-issue (rotate) → old stays dead, new works
	const issue3 = await api(page, 'POST', `/index.php/apps/inventorycheck/api/persons/${personId}/member-token`, {})
	expectOk(issue3, 'rotate token')
	expect(issue3.data.token).not.toBe(rawToken)
	const oldDead = await request.get(`/index.php/apps/inventorycheck/portal/${rawToken}/api`)
	expect(oldDead.status()).toBe(404)
	const newLive = await request.get(`/index.php/apps/inventorycheck/portal/${issue3.data.token}/api`)
	expect(newLive.status()).toBe(200)

	// --- shell + axe WCAG 2.1 AA on the live guest surface ---
	await page.goto(`/index.php/apps/inventorycheck/portal/${issue3.data.token}`)
	await expect(page.locator('#iv-portal-root')).toBeVisible({ timeout: 30_000 })
	// portal.js resolves state async — wait for the busy flag to drop
	await expect(page.locator('#iv-portal-root')).not.toHaveAttribute('aria-busy', 'true', { timeout: 30_000 })

	const results = await new AxeBuilder({ page })
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('.toastify')
		.analyze()
	const bad = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
	expect(bad, JSON.stringify(bad, null, 2)).toEqual([])

	// keyboard: "Report a problem" opens the damage dialog; Escape closes it
	const dmgBtn = page.locator('.iv-portal-list button', { hasText: /problem|Report/i }).first()
	await expect(dmgBtn).toBeVisible()
	await dmgBtn.focus()
	await page.keyboard.press('Enter')
	const dlg = page.locator('[role="dialog"]').first()
	await expect(dlg).toBeVisible()
	await page.keyboard.press('Escape')
	await expect(dlg).toBeHidden({ timeout: 5_000 })
})
