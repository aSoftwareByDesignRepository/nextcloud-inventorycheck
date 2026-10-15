// @ts-check
/**
 * ATLAS_UI_INVARIANTS — InventoryCheck representative surfaces.
 *
 * Wires apps/_shared/e2e/atlas-ui-invariants.js onto the surfaces an
 * office user actually works on. Covered classes: a11y-dom (icon-only
 * names, <24px targets, focusable-in-aria-hidden, unannounced error
 * callouts), stored-xss (item name is a free-text field rendered in the
 * /items list and item detail), console errors, raw i18n keys,
 * mutation freshness, double-submit, form-loss-on-5xx, O(1) list requests.
 *
 * Fixture hygiene: every created item carries the run stamp in sku+name and
 * is deleted via the real API in finally — zero residue on the shared lab.
 * Fresh items (no movements, no stock) are deletable by design.
 */
import { test, expect } from '@playwright/test'
import { createRequire } from 'node:module'
import { ensureLoggedIn, openInventory, credsFromEnv } from './helpers/auth.mjs'

const require = createRequire(import.meta.url)
const {
	ATLAS_XSS_PAYLOADS,
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoDuplicateSubmit,
	assertNoInjection,
	assertNoRawI18nKeys,
	assertFormSurvivesFailure,
	assertSurfaceFresh,
	countApiRequests,
	trackConsoleErrors,
} = require('../../../_shared/e2e/atlas-ui-invariants.js')

const CONTENT = '#app-content'
// Worker-unique stamp — Date.now alone collides when two workers share a millisecond.
const STAMP = `ivinv${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`

/** session-cookie JSON call into the app API (browser context = logged in) */
async function api(page, method, path, body) {
	return page.evaluate(
		async ({ method, path, body }) => {
			const API = '/index.php/apps/inventorycheck/api'
			const token =
				(typeof window.OC !== 'undefined' && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
				|| document.querySelector('meta[name="requesttoken"]')?.getAttribute('content')
				|| ''
			const res = await fetch(`${API}${path}`, {
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

async function createItem(page, fields) {
	const r = await api(page, 'POST', '/items', { uom: 'pcs', ...fields })
	return r.status === 200 && r.data && r.data.id ? r.data.id : null
}

async function deleteItem(page, id) {
	await api(page, 'DELETE', `/items/${id}`)
}

/** Fill the items live-search and wait for the filtered list to settle. */
async function filterItems(page, term) {
	const input = page.locator('#iv-items-q')
	await input.fill(term)
	// bindLiveSearch debounces — wait for the query to hit the wire.
	await page.waitForResponse(
		(res) => /\/api\/items\?/.test(res.url()) && res.request().method() === 'GET' && res.url().includes('q='),
		{ timeout: 15000 },
	)
	await page.locator('#iv-main-content .iv-table, #iv-main-content .iv-empty').first()
		.waitFor({ state: 'visible', timeout: 15000 })
}

/** Open the New-item dialog; returns the dialog locator. */
async function openItemDialog(page) {
	await page.locator('[data-iv-action="item-new"]').first().click()
	const dialogEl = page.locator('.iv-dialog')
	await dialogEl.waitFor({ state: 'visible', timeout: 15000 })
	return dialogEl
}

test.describe('InventoryCheck UI invariants (atlas-ui-invariants)', () => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	test.beforeEach(async ({ page }) => {
		await ensureLoggedIn(page, 'ADMIN')
	})

	// ── a11y-dom + console + raw-i18n sweep over representative surfaces ──
	for (const [label, url] of [
		['dashboard', '/apps/inventorycheck/'],
		['items', '/apps/inventorycheck/items'],
		['locations', '/apps/inventorycheck/locations'],
		['movements', '/apps/inventorycheck/movements'],
		['stocktake', '/apps/inventorycheck/stocktake'],
		['settings-access', '/apps/inventorycheck/settings/access'],
	]) {
		test(`a11y-dom /${label}`, async ({ page }) => {
			const errs = trackConsoleErrors(page)
			await openInventory(page, url)
			// Radios/checkboxes under 24px are legal only with a >=24px label
			// target (WCAG 2.5.8 equivalent-target). Prove that before the sweep.
			const unlabeled = await page.evaluate(() => {
				const bad = []
				document
					.querySelectorAll('#app-content input[type="radio"], #app-content input[type="checkbox"]')
					.forEach((inp) => {
						const ir = inp.getBoundingClientRect()
						const ics = getComputedStyle(inp)
						if (ir.width === 0 || ics.display === 'none' || ics.visibility === 'hidden') return
						const lbl = inp.closest('label')
							|| (inp.id ? document.querySelector(`label[for="${CSS.escape(inp.id)}"]`) : null)
						if (!lbl) {
							bad.push(`no label: ${inp.id || inp.outerHTML.slice(0, 60)}`)
							return
						}
						const r = lbl.getBoundingClientRect()
						const cs = getComputedStyle(lbl)
						if (cs.display === 'none' || cs.visibility === 'hidden' || (r.width === 0 && r.height === 0)) return
						if (r.width < 24 || r.height < 24) {
							bad.push(`label ${Math.round(r.width)}x${Math.round(r.height)} < 24px: ${inp.id}`)
						}
					})
				return bad
			})
			expect(unlabeled, `radio/checkbox without a >=24px label target on /${label}:\n${unlabeled.join('\n')}`).toEqual([])
			const findings = await assertA11yDom(page, {
				content: CONTENT,
				// labelled small inputs are legal — the label is the pointer target
				sizeAllow: 'label',
			})
			expect(findings, `a11y-dom findings on /${label}:\n${findings.join('\n')}`).toEqual([])
			assertNoConsoleErrors(errs, { allow: [/favicon/i] })
			await assertNoRawI18nKeys(page, { content: CONTENT })
		})
	}

	// ── a11y-dom on the open item dialog (error-capable state) ──
	test('a11y-dom on item create dialog', async ({ page }) => {
		await openInventory(page, '/apps/inventorycheck/items')
		await openItemDialog(page)
		// sizeAllow 'label': row bulk-select checkboxes in the table behind the
		// dialog are wrapped in .iv-check-hit labels (the >=24px pointer target).
		const findings = await assertA11yDom(page, { content: 'body', sizeAllow: 'label' })
		expect(findings, `a11y-dom findings in item dialog:\n${findings.join('\n')}`).toEqual([])
		await page.keyboard.press('Escape').catch(() => {})
	})

	// ── stored XSS: free-text item name rendered in list + detail ──
	test('stored-xss escaped on items list and detail', async ({ page }) => {
		const name = `${STAMP} ${ATLAS_XSS_PAYLOADS[0]}`
		const id = await createItem(page, { sku: `${STAMP}-xss`, name })
		try {
			expect(id, 'item create via API').toBeTruthy()
			await openInventory(page, '/apps/inventorycheck/items')
			await filterItems(page, STAMP)
			await assertNoInjection(page)
			await openInventory(page, `/apps/inventorycheck/items/${id}`)
			await assertNoInjection(page)
		} finally {
			if (id) await deleteItem(page, id)
		}
	})

	// ── mutation freshness: create then delete must surface without F5 ──
	test('mutation freshness on items list', async ({ page }) => {
		const name = `${STAMP}-fresh`
		const id = await createItem(page, { sku: `${STAMP}-fresh`, name })
		try {
			expect(id, 'item create via API').toBeTruthy()
			await assertSurfaceFresh(page, {
				content: CONTENT,
				mutate: async () => {},
				visit: async () => {
					await openInventory(page, '/apps/inventorycheck/items')
					await filterItems(page, STAMP)
				},
				expect: { present: name },
			})
		} finally {
			if (id) await deleteItem(page, id)
		}
		await assertSurfaceFresh(page, {
			content: CONTENT,
			mutate: async () => {},
			visit: async () => {
				await openInventory(page, '/apps/inventorycheck/items')
				await filterItems(page, STAMP)
			},
			expect: { absent: name },
		})
	})

	// ── double-submit: rapid second submit must not reach the server ──
	test('double-submit on item create sends one POST', async ({ page }) => {
		await openInventory(page, '/apps/inventorycheck/items')
		const dialogEl = await openItemDialog(page)
		await dialogEl.locator('.iv-field[data-iv-field="sku"] input').fill(`${STAMP}-dbl`)
		await dialogEl.locator('.iv-field[data-iv-field="name"] input').fill(`${STAMP}-dbl`)
		const submit = dialogEl.locator('[data-iv-action="dialog-confirm"]').first()
		try {
			await assertNoDuplicateSubmit(page, {
				mutatingUrl: /\/api\/items(\?|$)/,
				method: 'POST',
				// force: the correct behavior guards mid-flight — a real user
				// can still machine-gun clicks regardless of disabled state.
				submit: () => submit.click({ force: true, timeout: 5000 }).catch(() => {}),
			})
		} finally {
			// The helper counts emitted requests, not committed rows — a
			// successful save closes the dialog; wait for that settle point
			// (or a beat on failure) before sweeping, or the q-list races the
			// still in-flight POST and misses the row it must delete.
			await dialogEl.waitFor({ state: 'detached', timeout: 5000 }).catch(() => {})
			await page.keyboard.press('Escape').catch(() => {})
			// Sweep any row that escaped — sku carries the stamp.
			const list = await api(page, 'GET', `/items?limit=50&offset=0&q=${encodeURIComponent(STAMP)}`)
			const rows = (list.data && list.data.data) || []
			for (const it of rows) {
				if (it.id) await deleteItem(page, it.id)
			}
		}
	})

	// ── form survival on 5xx: inputs retained + error rendered ──
	test('item form survives injected 500', async ({ page }) => {
		await openInventory(page, '/apps/inventorycheck/items')
		const dialogEl = await openItemDialog(page)
		await assertFormSurvivesFailure(page, {
			failUrl: /\/api\/items(\?|$)/,
			method: 'POST',
			fields: {
				'.iv-dialog .iv-field[data-iv-field="sku"] input': `${STAMP}-keep`,
				'.iv-dialog .iv-field[data-iv-field="name"] input': `${STAMP}-keep`,
			},
			submit: () => dialogEl.locator('[data-iv-action="dialog-confirm"]').first().click(),
			// dialog() catch → toast(err, true) renders .iv-toast--error (role=alert)
			errorSel: '.iv-toast--error',
		})
		await page.keyboard.press('Escape').catch(() => {})
	})

	// ── O(1) list render: /items must not fan out per-row requests ──
	test('items list render is O(1) in API calls', async ({ page }) => {
		const hits = await countApiRequests(page, async () => {
			await openInventory(page, '/apps/inventorycheck/items')
			await page.locator('#iv-main-content .iv-table, #iv-main-content .iv-empty').first()
				.waitFor({ state: 'visible', timeout: 30000 })
			await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
		}, /\/api\//)
		const listCalls = hits.filter((h) => /\/api\/items\?/.test(h.url) && h.method === 'GET')
		expect(listCalls.length, `item list fetches: ${listCalls.map((h) => h.url).join(' | ')}`).toBeLessThanOrEqual(2)
		const perRow = hits.filter((h) => /\/api\/items\/\d+/.test(h.url))
		expect(perRow, `per-row N+1 fetches: ${perRow.map((h) => h.url).join(' | ')}`).toEqual([])
	})
})
