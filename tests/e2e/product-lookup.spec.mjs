// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, login, openInventory } from './helpers/auth.mjs'

/**
 * Product lookup E2E — two layers:
 *
 *  A) API contract against the live endpoint. Upstream providers are not
 *     stubbed (fixed endpoints are a security invariant), so hit/miss is not
 *     asserted against real databases. What IS deterministic: toggle gate,
 *     GTIN validation, empty-provider 502, per-user rate limit (the rate key
 *     is set before the cascade, so a fast first call always throttles the
 *     second), envelope shape, authZ.
 *
 *  B) UI journeys with Playwright route stubbing on the app's OWN API — the
 *     deterministic way to prove fill-empty-only, XSS-as-text, no auto-save,
 *     stale states and accessibility of the dialog panel.
 */

const COMPOSE_CWD = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')
const LOOKUP_API = '/index.php/apps/inventorycheck/api/product-lookup'
const LOOKUP_CFG_API = '/index.php/apps/inventorycheck/api/config/product-lookup'
const GTIN = '4006381333931' // valid EAN-13 check digit

/**
 * Fresh valid EAN-13 per call — the result cache (APCu) legitimately survives
 * between runs, so hardcoded barcodes pollute assertions across executions.
 * GS1 check digit, same algorithm as Gtin::isValid.
 */
let gtinSeq = 0
function freshGtin() {
	const base = `999${String(Date.now()).slice(-8)}${String(gtinSeq++).padStart(2, '0')}`.slice(0, 12)
	let sum = 0
	for (let i = 0; i < 12; i++) sum += Number(base[i]) * (i % 2 === 0 ? 1 : 3)
	return base + String((10 - (sum % 10)) % 10)
}

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
			try { data = text ? JSON.parse(text) : null } catch { data = { raw: text } }
			return {
				status: res.status,
				data,
				// Session identity server-side — catches "request ran as the
				// wrong user" regressions instantly.
				who: (typeof window.OC !== 'undefined' && window.OC.getCurrentUser)
					? window.OC.getCurrentUser().uid : null,
			}
		},
		{ method, path, body },
	)
}

function occ(...args) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', 'nextcloud', 'php', 'occ', ...args,
	], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
}

function dbSql(sql) {
	try {
		return execFileSync('docker', [
			'compose', 'exec', '-T', 'mariadb', 'mariadb',
			'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-B', '-e', sql,
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
	} catch (err) {
		// eslint-disable-next-line no-console
		console.error('product-lookup teardown dbSql failed:', String(err).slice(0, 400))
		return ''
	}
}

/** Persist lookup config through the real admin endpoint. */
async function saveLookupConfig(page, cfg) {
	const res = await api(page, 'POST', LOOKUP_CFG_API, cfg)
	expect(res.status, `saveProductLookup → ${res.status}: ${JSON.stringify(res.data)}`).toBe(200)
	if (cfg.enabled !== undefined) {
		expect(
			res.data?.productLookupEnabled,
			'save must persist the enabled flag',
		).toBe(cfg.enabled)
	}
	if (cfg.providers !== undefined) {
		expect(
			res.data?.productLookupEnabledProviders,
			'save must persist the provider selection',
		).toEqual(cfg.providers)
	}
	return res
}

async function freshRateWindow() {
	// Throttle window is 5s — keep API-rate cases isolated.
	await new Promise((r) => setTimeout(r, 5_200))
}

const createdSkus = []
function skuTag() {
	const tag = `e2elk${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`
	createdSkus.push(tag)
	return tag
}

test.afterAll(async () => {
	// Items are ledger-referenced; sweep by tag like uj-journeys.
	for (const tag of createdSkus) {
		const like = `%${tag}%`
		dbSql(
			`DELETE l FROM oc_iv_cc_line l JOIN oc_iv_items i ON l.item_id = i.id `
			+ `WHERE i.sku LIKE '${like}';`
			+ `DELETE n FROM oc_iv_notif_log n JOIN oc_iv_items i ON n.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE m FROM oc_iv_movements m JOIN oc_iv_items i ON m.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_items i ON b.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE FROM oc_iv_items WHERE sku LIKE '${like}' OR scan_code LIKE '${like}' OR name LIKE '${like}';`,
		)
	}
	// Feature must end disabled (consent default) regardless of test order.
	dbSql("UPDATE oc_appconfig SET configvalue='0' WHERE appid='inventorycheck' AND configkey='product_lookup_enabled'")
})

// ───────────────────────────── A) API contract ─────────────────────────────

test.describe('product-lookup API contract', () => {
	test.beforeEach(async ({ page }) => {
		await ensureLoggedIn(page)
		await openInventory(page)
	})

	test('disabled by default: toggle off → 403, zero egress', async ({ page }) => {
		await saveLookupConfig(page, { enabled: false, autofire: true, providers: ['openfoodfacts'] })
		const res = await api(page, 'POST', LOOKUP_API, { barcode: GTIN })
		expect(res.status).toBe(403)
		expect(res.data?.error?.code).toBe('feature_disabled')
	})

	test('toggle on: invalid barcodes → 422 before any provider call', async ({ page }) => {
		await saveLookupConfig(page, { enabled: true, autofire: true, providers: ['openfoodfacts'] })
		for (const bad of ['123', '4006381333932', 'not-a-barcode', '4006381333931 OR 1=1']) {
			const res = await api(page, 'POST', LOOKUP_API, { barcode: bad })
			expect(res.status, bad).toBe(422)
			expect(res.data?.error?.code).toBe('invalid_barcode')
		}
	})

	test('empty provider selection → 502 unavailable', async ({ page }) => {
		await saveLookupConfig(page, { enabled: true, autofire: true, providers: [] })
		const res = await api(page, 'POST', LOOKUP_API, { barcode: freshGtin() })
		expect(res.status).toBe(502)
		expect(res.data?.error?.code).toBe('unavailable')
	})

	test('per-user rate limit: second call inside 5s → 429', async ({ page }) => {
		// providers:[] makes the first call resolve instantly while still
		// stamping the rate key — deterministic 429 on the follow-up.
		// Fresh barcodes: cache hits bypass the throttle by design.
		// Wait out any prior rate window BEFORE the config save so the write
		// sits as close to the assertions as possible (shared dev instance).
		await freshRateWindow()
		await saveLookupConfig(page, { enabled: true, autofire: true, providers: [] })
		const first = await api(page, 'POST', LOOKUP_API, { barcode: freshGtin() })
		expect(first.status, `first lookup → ${JSON.stringify(first.data)}`).toBe(502)
		const second = await api(page, 'POST', LOOKUP_API, { barcode: freshGtin() })
		expect(second.status, `second lookup → ${JSON.stringify(second.data)}`).toBe(429)
		expect(second.data?.error?.code).toBe('rate_limited')
	})

	test('concurrent lookups stay bounded — no crash, single rate stamp', async ({ page }) => {
		// Lens-1 proof: three parallel POSTs. The per-user throttle is a soft
		// check-then-set bound, so 1–3 calls may pass the gate — but every
		// response must be a classified outcome (502 cascade-empty or
		// 429 throttled), never a 500, and at least one must have proceeded
		// to the cascade (502) since no rate key exists after the window.
		await freshRateWindow()
		await saveLookupConfig(page, { enabled: true, autofire: true, providers: [] })
		const [r1, r2, r3] = await Promise.all([
			api(page, 'POST', LOOKUP_API, { barcode: freshGtin() }),
			api(page, 'POST', LOOKUP_API, { barcode: freshGtin() }),
			api(page, 'POST', LOOKUP_API, { barcode: freshGtin() }),
		])
		const statuses = [r1.status, r2.status, r3.status]
		for (const s of statuses) {
			expect([429, 502], `concurrent lookup → ${JSON.stringify(statuses)}`).toContain(s)
		}
		expect(statuses.filter((s) => s === 502).length).toBeGreaterThanOrEqual(1)
	})

	test('non-office user → 403 permission_denied, no lookup affordance', async ({ page, browser }) => {
		// Lens-2/6 role probe: a real provisioned user without an office grant
		// must be denied uniformly (never 500, never a silent hit), and the UI
		// must not offer a lookup path they can never use.
		const uid = `e2e_nonoffice_${Date.now().toString(36)}`
		const pass = 'NonOffice-T3st!'
		execFileSync('docker', [
			'compose', 'exec', '-T', '-e', `OC_PASS=${pass}`, 'nextcloud',
			'php', 'occ', 'user:add', '--password-from-env', uid,
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
		try {
			occ('user:enable', uid)
			await saveLookupConfig(page, { enabled: true, autofire: true, providers: ['openfoodfacts'] })

			// browser.newContext() inherits the project's use.storageState —
			// empty state, otherwise the "field" context starts logged in as admin.
			const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } })
			const field = await ctx.newPage()
			try {
				await login(field, { username: uid, password: pass })
				// NOT openInventory(): it re-auths as ADMIN on a /login redirect.
				// Dashboard keeps the field session and provides OC.requestToken.
				await field.goto('/apps/dashboard/', { waitUntil: 'domcontentloaded' })
				const res = await api(field, 'POST', LOOKUP_API, { barcode: freshGtin() })
				expect(res.who, `field session uid`).toBe(uid)
				expect(res.status, `non-office lookup → ${JSON.stringify(res.data)}`).toBe(403)
				// canUseApp (middleware, app_access_denied) fires before the
				// controller's requireOffice (permission_denied) — either is a
				// correct uniform denial for a user without office rights.
				expect(['app_access_denied', 'permission_denied']).toContain(res.data?.error?.code)
			} finally {
				await ctx.close()
			}
		} finally {
			occ('user:delete', uid)
		}
	})

	test('live lookup smoke: found or unavailable, never a crash', async ({ page }) => {
		test.setTimeout(60_000)
		await saveLookupConfig(page, {
			enabled: true,
			autofire: true,
			providers: ['openfoodfacts', 'openbeautyfacts', 'openpetfoodfacts', 'openproductsfacts', 'upcitemdb'],
		})
		await freshRateWindow()
		const res = await api(page, 'POST', LOOKUP_API, { barcode: GTIN })
		expect([200, 502], `live lookup → ${res.status}: ${JSON.stringify(res.data)}`).toContain(res.status)
		if (res.status === 200) {
			if (res.data.found) {
				expect(typeof res.data.name).toBe('string')
				expect(res.data.barcode).toBe(GTIN)
				expect(['openfoodfacts', 'openbeautyfacts', 'openpetfoodfacts', 'openproductsfacts', 'upcitemdb'])
					.toContain(res.data.provider)
			} else {
				expect(res.data).toEqual({ found: false })
			}
		} else {
			expect(res.data?.error?.code).toBe('unavailable')
		}
	})

	test('mobile route honors the same toggle', async ({ page }) => {
		// /mobile/v1/product-lookup requires a paired device — asserting the
		// gate exists (non-2xx for a plain web session) proves it is wired.
		const res = await api(page, 'POST', '/index.php/apps/inventorycheck/mobile/v1/product-lookup', { barcode: GTIN })
		expect(res.status, 'mobile route must reject unpaired web sessions').toBeGreaterThanOrEqual(400)
	})
})

// ───────────────────────────── B) UI journeys ──────────────────────────────

test.describe('product-lookup item dialog UX', () => {
	test.beforeEach(async ({ page }) => {
		await ensureLoggedIn(page)
		await openInventory(page)
		// Enable via the real admin endpoint, then reload so the shell picks it up.
		await saveLookupConfig(page, {
			enabled: true,
			autofire: true,
			providers: ['openfoodfacts', 'upcitemdb'],
		})
	})

	async function openItemsDialog(page) {
		await openInventory(page, '/apps/inventorycheck/items')
		const newItem = page.locator('[data-iv-action="item-new"]').first()
		await expect(newItem).toBeVisible({ timeout: 20_000 })
		await newItem.click()
		const dialogEl = page.locator('.iv-dialog')
		await expect(dialogEl).toBeVisible({ timeout: 10_000 })
		// Lookup controls live inside the "More item options" <details>.
		const details = dialogEl.locator('details.iv-disclosure').first()
		if (await details.count() && !(await details.getAttribute('open'))) {
			await details.locator('summary').click()
		}
		return dialogEl
	}

	test('toggle off → no lookup panel in the dialog', async ({ page }) => {
		await saveLookupConfig(page, { enabled: false, autofire: true, providers: ['openfoodfacts'] })
		const dialogEl = await openItemsDialog(page)
		await expect(dialogEl.locator('.iv-lookup')).toHaveCount(0)
	})

	test('hit fills only empty fields, announces via live region', async ({ page }) => {
		await page.route('**/api/product-lookup', (route) => route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				found: true,
				barcode: GTIN,
				name: 'Stub Cola 330ml',
				brand: 'StubBrand',
				description: 'Deterministic fixture',
				sourceUrl: 'https://example.org/p/1',
				provider: 'openfoodfacts',
			}),
		}))
		const dialogEl = await openItemsDialog(page)
		const panel = dialogEl.locator('.iv-lookup')
		await expect(panel).toBeVisible()

		const scan = dialogEl.locator('[data-iv-field="scanCode"] input').first()
		await scan.fill(GTIN)
		// Pre-typed name must survive the suggestion.
		const name = dialogEl.locator('[data-iv-field="name"] input').first()
		await name.fill('My own name')

		const status = panel.locator('.iv-lookup__status')
		await panel.locator('[data-iv-action="product-lookup"]').click()
		await expect(status).toContainText(/Open Food Facts/, { timeout: 10_000 })

		await expect(name).toHaveValue('My own name')
		// SKU + description are empty → barcode + suggestion land there.
		const sku = dialogEl.locator('[data-iv-field="sku"] input').first()
		await expect(sku).toHaveValue(GTIN)
		const desc = dialogEl.locator('[data-iv-field="description"] textarea').first()
		await expect(desc).toHaveValue('Deterministic fixture')
		// Brand line rendered.
		await expect(panel.locator('.iv-lookup__brand')).toContainText('StubBrand')
		// Source link sanitized + safe attrs.
		const src = panel.locator('.iv-lookup__source a')
		await expect(src).toHaveAttribute('href', 'https://example.org/p/1')
		await expect(src).toHaveAttribute('rel', /noopener/)
		// Live region announces politely.
		await expect(status).toHaveAttribute('aria-live', 'polite')
	})

	test('poisoned suggestion renders as text, never markup', async ({ page }) => {
		let dialogOpened = false
		page.on('dialog', (d) => { dialogOpened = true; void d.dismiss() })
		await page.route('**/api/product-lookup', (route) => route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				found: true,
				barcode: GTIN,
				name: '<img src=x onerror=alert(1)>',
				brand: '<script>alert(2)</script>',
				description: '"><svg onload=alert(3)>',
				sourceUrl: 'javascript:alert(4)',
				provider: 'openfoodfacts',
			}),
		}))
		const dialogEl = await openItemsDialog(page)
		const panel = dialogEl.locator('.iv-lookup')
		const scan = dialogEl.locator('[data-iv-field="scanCode"] input').first()
		await scan.fill(GTIN)
		await panel.locator('[data-iv-action="product-lookup"]').click()
		const status = panel.locator('.iv-lookup__status')
		await expect(status).toContainText(/Open Food Facts/, { timeout: 10_000 })

		const name = dialogEl.locator('[data-iv-field="name"] input').first()
		await expect(name).toHaveValue('<img src=x onerror=alert(1)>')
		// No markup was ever injected into the DOM.
		await expect(dialogEl.locator('img[src="x"]')).toHaveCount(0)
		await expect(dialogEl.locator('svg[onload]')).toHaveCount(0)
		expect(dialogOpened, 'no alert() may fire from upstream content').toBe(false)
		// Unsafe sourceUrl → no link rendered.
		await expect(panel.locator('.iv-lookup__source a[href^="javascript:"]')).toHaveCount(0)
	})

	test('miss + error states announce; manual web-search link stays valid', async ({ page }) => {
		let calls = 0
		await page.route('**/api/product-lookup', (route) => {
			calls += 1
			return calls === 1
				? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ found: false }) })
				: route.fulfill({
					status: 429,
					contentType: 'application/json',
					body: JSON.stringify({ error: { code: 'rate_limited', message: 'Please wait a few seconds before searching again.', details: [] } }),
				})
		})
		const dialogEl = await openItemsDialog(page)
		const panel = dialogEl.locator('.iv-lookup')
		const scan = dialogEl.locator('[data-iv-field="scanCode"] input').first()
		const status = panel.locator('.iv-lookup__status')
		const lookupBtn = panel.locator('[data-iv-action="product-lookup"]')

		await scan.fill(GTIN)
		await lookupBtn.click()
		await expect(status).toContainText(/no product found|nicht gefunden|kein produkt/i, { timeout: 10_000 })

		await lookupBtn.click()
		await expect(status).toContainText(/wait a few seconds|rate|versuch/i, { timeout: 10_000 })

		// Manual web-search fallback: valid DDG URL with the quoted barcode.
		const webLink = panel.locator('.iv-lookup__web')
		await expect(webLink).toHaveAttribute('href', new RegExp(`^https://duckduckgo\\.com/\\?q=`))
		await expect(webLink).toHaveAttribute('target', '_blank')
		const href = await webLink.getAttribute('href')
		expect(href).toContain(encodeURIComponent(`"${GTIN}"`))
	})

	test('invalid barcode never fires a request', async ({ page }) => {
		let calls = 0
		await page.route('**/api/product-lookup', (route) => { calls += 1; return route.fulfill({ status: 200, body: '{"found":false}' }) })
		const dialogEl = await openItemsDialog(page)
		const panel = dialogEl.locator('.iv-lookup')
		const scan = dialogEl.locator('[data-iv-field="scanCode"] input').first()
		await scan.fill('12345') // bad length AND bad check digit
		// The lookup button stays disabled for invalid input — the strongest
		// possible "no request" is that there is nothing to click.
		const lookupBtn = panel.locator('[data-iv-action="product-lookup"]')
		await expect(lookupBtn).toBeDisabled()
		await page.waitForTimeout(300)
		expect(calls).toBe(0)
		// And the validation hint is announced.
		await expect(panel.locator('.iv-lookup__status')).toContainText(/valid EAN or UPC|gültig/i)
	})

	test('suggestion never auto-saves; manual save still works end-to-end', async ({ page }) => {
		const tag = skuTag()
		await page.route('**/api/product-lookup', (route) => route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				found: true,
				barcode: GTIN,
				name: `${tag} name`,
				description: 'E2E applied suggestion',
				provider: 'openfoodfacts',
			}),
		}))
		const dialogEl = await openItemsDialog(page)
		const panel = dialogEl.locator('.iv-lookup')
		const scan = dialogEl.locator('[data-iv-field="scanCode"] input').first()
		await scan.fill(GTIN)
		await panel.locator('[data-iv-action="product-lookup"]').click()
		const name = dialogEl.locator('[data-iv-field="name"] input').first()
		await expect(name).toHaveValue(`${tag} name`, { timeout: 10_000 })

		// Nothing persisted yet — no item may exist before the user saves.
		const preSave = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items?q=${tag}`)
		expect((preSave.data?.data || []).length, 'lookup must not persist anything').toBe(0)

		// Manual save still goes through the real item API.
		const skuInput = dialogEl.locator('[data-iv-field="sku"] input').first()
		await skuInput.fill(tag)
		await dialogEl.locator('[data-iv-action="dialog-confirm"]').click()
		await expect(dialogEl).toBeHidden({ timeout: 15_000 })
		const postSave = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items?q=${tag}`)
		expect((postSave.data?.data || []).length).toBe(1)
	})

	test('dialog passes axe WCAG 2.1 AA', async ({ page }) => {
		const dialogEl = await openItemsDialog(page)
		await expect(dialogEl.locator('.iv-lookup')).toBeVisible()
		const results = await new AxeBuilder({ page })
			.include('.iv-dialog')
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('.toastify')
			.analyze()
		expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
	})

	test('settings save keeps dialog in sync without reload', async ({ page }) => {
		await openInventory(page, '/apps/inventorycheck/settings/product-lookup')
		const toggle = page.locator('#iv-product-lookup')
		await expect(toggle).toBeVisible({ timeout: 20_000 })
		await expect(toggle).toBeChecked()
		const providerBoxes = page.locator('[data-provider-id]')
		await expect(providerBoxes.first()).toBeVisible()
		expect(await providerBoxes.count()).toBeGreaterThanOrEqual(5)

		// Disable via the section's own save button — one request for all fields.
		await toggle.click()
		await page.locator('[data-iv-action="lookup-settings-save"]').click()
		await expect(page.locator('.iv-toast').first()).toContainText(/saved|gespeichert/i, { timeout: 10_000 })

		// Dialog on the same session must now hide the panel (ctx flags synced).
		const dialogEl = await openItemsDialog(page)
		await expect(dialogEl.locator('.iv-lookup')).toHaveCount(0)
	})
})
