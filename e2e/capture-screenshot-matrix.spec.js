// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate) —
 * F2 custody wave delta scope.
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/inventorycheck/craft/web/. The companion
 * gate .cursor/atlas-farm-v3/scripts/check-screenshot-matrix.py verifies
 * completeness — a declared cell without a real PNG fails the craft stage.
 *
 * Beyond the matrix cells this spec executes the ds_chrome mechanical legs
 * for the new surfaces (results + probe PNGs land under
 * artifacts/inventorycheck/probes/ds_chrome/):
 *   - themed sweep  : new routes × light/dark/light-highcontrast/
 *                     dark-highcontrast @1440 (axe + overflow + theme attr +
 *                     per-route themed-sha distinctness), plus a light
 *                     viewport sweep at 320/768/1024/1440 with touch-targets
 *   - dialogs       : role/aria-modal/aria-labelledby/focus-in/trap/Escape/
 *                     focus-restore/Cancel lifecycle for checkout, checkin,
 *                     person-create, mark-lost, anonymize, delivery-note
 *   - states        : fetch-error + retry CTA on people + person-detail,
 *                     anon redirect for /people, denied /people (L2 door),
 *                     people empty-state copy + CTA
 *   - theatre       : dark-island hunt on the new routes in dark theme
 *
 * States:
 *   populated — real fixtures seeded via the app API in 'seeds custody
 *               fixtures' (person + open loan + overdue loan + returned loan
 *               + a delivery-note photo on a receive movement), so list and
 *               detail cells carry honest rows.
 *   empty     — honest zero-row render through the people surface's own
 *               search field (never a mocked response).
 *   current   — state-invariant chrome (denied surface as iv_ds_denied).
 *   *-dialog  — modal states driven through the real flow before capture;
 *               dialogs are dismissed via Escape immediately after the shot.
 *
 * Run (from the app dir):
 *   E2E_STORAGE_STATE=tests/e2e/.auth/ivc-dschrome-<pid>.json \
 *     npx playwright test --config=e2e/playwright.config.mjs
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { execFileSync } from 'node:child_process'

// tests/e2e/helpers/auth.mjs is ESM — a CJS-transformed .js spec cannot
// require() it, so bind the helpers lazily in beforeAll.
let login = null
let credsFromEnv = null

const MANIFEST = JSON.parse(readFileSync(join(__dirname, 'screenshot-matrix.json'), 'utf8'))
const FARM_ROOT = resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/inventorycheck')
const OUT = resolve(FARM_ROOT, MANIFEST.outDir)
const PROBE_OUT = resolve(FARM_ROOT, 'probes/ds_chrome')
const APP = '/apps/inventorycheck'
// Unique per worker AND per run — parallel lanes share this instance.
const MARKER = `ivmx-${Date.now().toString(36)}-${process.pid.toString(36)}`
const COMPOSE_CWD = resolve(__dirname, '../../..')

const THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']
const VISIBLE_DIALOG = '.iv-dialog-overlay:not([hidden]) [role="dialog"], .iv-dialog-overlay:not([hidden]) .iv-dialog, dialog[open]'

const results = { startedAt: new Date().toISOString(), cells: [], captures: {}, themeProof: {} }
const capturedFiles = []
const fixtures = { personId: 0, itemId: 0, itemId2: 0, locationId: 0, loanId: 0, loanId2: 0, returnedLoanId: 0, receiveMovId: 0 }

function record(cell) {
	results.cells.push(cell)
	const tag = cell.status === 'fail' ? 'FAIL' : cell.status === 'warn' ? 'warn' : 'ok'
	console.log(`[${tag}] ${cell.id} :: ${JSON.stringify(cell.checks).slice(0, 280)}`)
}

function dbSql(sql) {
	try {
		return execFileSync('docker', [
			'compose', 'exec', '-T', 'mariadb', 'mariadb',
			'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-B', '-e', sql,
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
	} catch { return '' }
}

function occ(args) {
	try {
		return execFileSync('docker', [
			'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ',
			...args.split(' '),
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000, stdio: ['ignore', 'pipe', 'pipe'] }).trim()
	} catch { return '' }
}

function sha256(buf) {
	return createHash('sha256').update(buf).digest('hex')
}

async function snap(page, name, outDir = PROBE_OUT, opts = {}) {
	const buf = await page.screenshot({ fullPage: Boolean(opts.fullPage) })
	const file = `${name}.png`
	writeFileSync(join(outDir, file), buf)
	const hash = sha256(buf)
	results.captures[name] = { file, sha256: hash, bytes: buf.length }
	return { file, sha256: hash, bytes: buf.length }
}

async function settle(page) {
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	try { await page.waitForLoadState('networkidle', { timeout: 5000 }) } catch { /* long-polls */ }
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
}

async function api(page, method, path, body) {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token = (window.OC && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
			const res = await fetch(path, {
				method, credentials: 'same-origin',
				headers: {
					requesttoken: token,
					'OCS-APIREQUEST': 'true',
					...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
				},
				body: body !== undefined ? JSON.stringify(body) : undefined,
			})
			let data = null
			try { data = await res.json() } catch { /* non-json */ }
			return { status: res.status, data }
		},
		{ method, path, body },
	)
}

function expectOk(result, label) {
	expect([200, 201, 204].includes(result.status), `${label}: ${JSON.stringify(result.data)}`).toBeTruthy()
}

function ivUrls(page) {
	return page.evaluate(() => JSON.parse(
		document.getElementById('app-content')?.getAttribute('data-iv-urls') || '{}',
	))
}

/** Multipart upload inside the page (requesttoken + session cookies). */
async function uploadDeliveryNote(page, url, movId) {
	return page.evaluate(async ({ url, movId }) => {
		const token = (window.OC && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
		// Server enforces a >=16x16 image floor — render a real PNG in-browser.
		const c = document.createElement('canvas')
		c.width = 96; c.height = 64
		const g = c.getContext('2d')
		g.fillStyle = '#f5f5f5'; g.fillRect(0, 0, 96, 64)
		g.strokeStyle = '#333'; g.strokeRect(2, 2, 92, 60)
		g.fillStyle = '#333'; g.font = 'bold 12px sans-serif'
		g.fillText(`DN-${movId}`, 8, 34)
		const blob = await new Promise((r) => c.toBlob(r, 'image/png'))
		const fd = new FormData()
		fd.append('file', new File([blob], `atlas-${movId}.png`, { type: 'image/png' }))
		const res = await fetch(url.replace('__ID__', String(movId)), {
			method: 'POST', credentials: 'same-origin',
			headers: { requesttoken: token }, body: fd,
		})
		let data = null
		try { data = await res.json() } catch { /* non-json */ }
		return { status: res.status, data }
	}, { url, movId })
}

/**
 * Learned class: theme switching persists server-side via the OCS theming
 * API. Callers must re-navigate/reload and assert the rendered attribute.
 */
async function setUserTheme(page, themeId) {
	const failures = await page.evaluate(async ({ target, all }) => {
		const token = (window.OC && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		const problems = []
		for (const id of all.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, { method: 'DELETE', credentials: 'same-origin', headers })
			if (!res.ok && res.status !== 400) problems.push(`disable ${id}: HTTP ${res.status}`)
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, { method: 'PUT', credentials: 'same-origin', headers })
		if (!res.ok && res.status !== 400) problems.push(`enable ${target}: HTTP ${res.status}`)
		return problems
	}, { target: themeId, all: THEMES })
	if (failures.length) throw new Error(`theme ${themeId}: ${failures.join(';')}`)
}

async function assertThemeRendered(page, themeId) {
	return page.evaluate((t) => {
		const attr = t === 'light' ? 'data-theme-light' : `data-theme-${t}`
		const dataThemes = document.body.getAttribute('data-themes') || ''
		return {
			ok: document.body.hasAttribute(attr) || dataThemes.split(/\s+/).includes(t)
				|| (t === 'light' && /default|light/.test(dataThemes)),
			dataThemes: dataThemes || null,
		}
	}, themeId)
}

async function checkOverflow(page) {
	return page.evaluate(() => {
		const doc = document.documentElement
		const app = document.querySelector('#app-content')
		const main = document.getElementById('iv-main-content') || document.getElementById('iv-denied-main')
		const probe = (el) => (el ? el.scrollWidth - el.clientWidth : 0)
		return { doc: probe(doc), app: probe(app), main: probe(main) }
	})
}

async function checkTouchTargets(page) {
	return page.evaluate(() => {
		const scopes = ['#app-content', '#app-navigation', '.iv-dialog-overlay', 'dialog[open]']
		const seen = new Set()
		const offenders = []
		const interactiveSel = [
			'button', 'a[href]', 'input:not([type="hidden"])', 'select', 'textarea',
			'[role="button"]', '[role="link"]', '[role="checkbox"]', '[role="tab"]',
			'[role="menuitem"]', '[role="switch"]', 'summary', '[tabindex]:not([tabindex="-1"])',
		].join(',')
		for (const scopeSel of scopes) {
			for (const scope of document.querySelectorAll(scopeSel)) {
				for (const el of scope.querySelectorAll(interactiveSel)) {
					if (seen.has(el)) continue
					seen.add(el)
					const r = el.getBoundingClientRect()
					const style = getComputedStyle(el)
					if (r.width <= 0 || r.height <= 0) continue
					if (style.visibility === 'hidden' || style.display === 'none') continue
					if (el.matches('input[type="checkbox"],input[type="radio"]')) {
						const lab = el.closest('label') || (el.id && document.querySelector(`label[for="${el.id}"]`))
						if (lab) {
							const lr = lab.getBoundingClientRect()
							if (lr.height >= 40) continue
						}
					}
					if (r.width < 44 || r.height < 44) {
						const label = (el.textContent || el.getAttribute('aria-label') || el.id || el.tagName)
							.trim().replace(/\s+/g, ' ').slice(0, 60)
						offenders.push({
							tag: el.tagName.toLowerCase(), cls: String(el.className).slice(0, 60),
							label, w: Math.round(r.width), h: Math.round(r.height),
						})
					}
				}
			}
		}
		return offenders.slice(0, 15)
	})
}

async function runAxe(page) {
	try {
		const res = await new AxeBuilder({ page })
			.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
			.exclude('#header').exclude('#contactsmenu').exclude('.notifications').exclude('#iv-toast-region')
			.analyze()
		return res.violations.map((v) => ({
			id: v.id, impact: v.impact,
			nodes: v.nodes.slice(0, 4).map((n) => String(n.target).slice(0, 120)),
			summary: String(v.help).slice(0, 140),
		}))
	} catch (err) {
		return [{ id: 'axe-error', impact: 'critical', nodes: [], summary: String(err).slice(0, 200) }]
	}
}

/** Per-surface "content landed" waits — the shell paints instantly, the list
 * arrives async. Ready = the surface's own marker, never a bare timeout. */
const READY = {
	'people': '#iv-page-root input[type="search"]',
	'person-detail': '#iv-page-root .iv-section:nth-of-type(2)',
	'item-detail-loans': '.iv-section:has(table .iv-btn--small) button.iv-btn--secondary',
	'dashboard-lending': '[data-iv-loans-card]',
	'movements-delivery-note': '[data-iv-action^="delivery-note"]',
	'people-denied': '.iv-app--denied, #iv-denied-main',
}

async function waitPeopleListSettled(page) {
	await page.waitForFunction(() => {
		const host = document.querySelector('#iv-page-root')
		return !!(host && (host.querySelector('table') || host.querySelector('.iv-empty, .iv-empty-state')))
	}, { timeout: 15_000 })
}

async function waitPersonLoansSettled(page) {
	await page.waitForFunction(() => {
		const host = document.querySelector('#iv-page-root')
		if (!host) return false
		if (host.getAttribute('aria-busy') === 'true') return false
		return host.querySelectorAll('.iv-section').length >= 2
	}, { timeout: 15_000 })
}

/* ---------- fixture seeding (real API, never DOM injection) ---------- */

// Sibling lanes reset shared oc_iv_* tables mid-run (external_suite_db_reset
// class): a person/item created seconds earlier can vanish before the loan
// POST. Retry the whole seed with fresh marker rows rather than borrowing
// shared fixtures.
async function seedFixtures(page) {
	let lastErr = null
	for (let attempt = 1; attempt <= 4; attempt++) {
		try {
			// Unique per-attempt tag — a half-seeded row from a wiped attempt
			// would otherwise collide on unique codes (location code_exists).
			await seedOnce(page, `${MARKER}A${attempt}`)
			return
		} catch (err) {
			lastErr = err
			record({ id: `seed-retry-${attempt}`, status: 'warn',
				checks: { transient: String(err && err.message ? err.message : err).slice(0, 200) } })
			await new Promise((r) => setTimeout(r, 1500 * attempt))
		}
	}
	throw lastErr
}

async function seedOnce(page, tag) {
	await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	const urls = await ivUrls(page)
	expect(urls.api?.persons, 'persons api url not injected').toBeTruthy()
	expect(urls.api?.loans, 'loans api url not injected').toBeTruthy()

	const person = await api(page, 'POST', urls.api.persons, {
		displayName: `Matrix Custody ${tag}`,
		memberNo: `MX-${tag}`.slice(0, 32),
		note: 'Atlas screenshot-matrix custody fixture',
	})
	expectOk(person, 'person create')
	fixtures.personId = person.data.person?.id ?? person.data.id
	expect(fixtures.personId).toBeGreaterThan(0)

	// Self-contained fixture chain — the shared instance is mutated by
	// sibling lanes mid-run, so seed our own location + item + receive
	// stock instead of borrowing shared rows (shared_fixture_race class).
	const loc = await api(page, 'POST', urls.api.locations, {
		code: `MX-LOC-${tag}`.slice(0, 32), name: `MX Loc ${tag}`,
	})
	expectOk(loc, 'location create')
	const locationId = loc.data.id ?? loc.data.location?.id
	fixtures.locationId = locationId
	expect(locationId).toBeTruthy()

	const item = await api(page, 'POST', urls.api.items, {
		sku: `MX-I-${tag}`.slice(0, 64), name: `MX Matrix Item ${tag}`,
	})
	expectOk(item, 'item create')
	fixtures.itemId = item.data.id ?? item.data.item?.id
	expect(fixtures.itemId).toBeGreaterThan(0)

	const item2 = await api(page, 'POST', urls.api.items, {
		sku: `MX-I2-${tag}`.slice(0, 64), name: `MX Matrix Item Two ${tag}`,
	})
	expectOk(item2, 'item2 create')
	fixtures.itemId2 = item2.data.id ?? item2.data.item?.id
	expect(fixtures.itemId2).toBeGreaterThan(0)

	const recv = await api(page, 'POST', urls.api.movementReceive, {
		itemId: fixtures.itemId, locationId, qty: 8,
	})
	expectOk(recv, 'receive stock item1')
	const recv2 = await api(page, 'POST', urls.api.movementReceive, {
		itemId: fixtures.itemId2, locationId, qty: 8,
	})
	expectOk(recv2, 'receive stock item2')
	// The receive movement is the delivery-note target — it is ours, so the
	// attached-state cell carries a real uploaded note.
	fixtures.receiveMovId = recv.data.movements?.[0]?.id ?? recv.data.id ?? null

	const in14 = Math.floor((Date.now() + 14 * 86400_000) / 1000)
	const loan1 = await api(page, 'POST', urls.api.loans, {
		itemId: fixtures.itemId, locationId, personId: fixtures.personId,
		qty: 1, dueAt: in14, note: 'Atlas matrix open loan',
	})
	expectOk(loan1, 'loan1 create')
	fixtures.loanId = loan1.data.loan?.id ?? loan1.data.id
	expect(fixtures.loanId).toBeGreaterThan(0)

	// Overdue loan: try past dueAt through the API first (the service may
	// accept historical due dates); fall back to a future due patched to the
	// past — the rendered badge must come from a real overdue row.
	const past = Math.floor((Date.now() - 3 * 86400_000) / 1000)
	const loan2 = await api(page, 'POST', urls.api.loans, {
		itemId: fixtures.itemId2, locationId, personId: fixtures.personId,
		qty: 2, dueAt: past, note: 'Atlas matrix overdue loan',
	})
	if (loan2.status < 400) {
		fixtures.loanId2 = loan2.data.loan?.id ?? loan2.data.id
	} else {
		const retry = await api(page, 'POST', urls.api.loans, {
			itemId: fixtures.itemId2, locationId, personId: fixtures.personId,
			qty: 2, dueAt: in14, note: 'Atlas matrix overdue loan',
		})
		expectOk(retry, 'loan2 create (future due)')
		fixtures.loanId2 = retry.data.loan?.id ?? retry.data.id
		dbSql(`UPDATE oc_iv_loans SET due_at = ${past} WHERE id = ${fixtures.loanId2};`)
	}
	expect(fixtures.loanId2).toBeGreaterThan(0)

	// Returned loan → person-detail history row.
	const loan3 = await api(page, 'POST', urls.api.loans, {
		itemId: fixtures.itemId2, locationId, personId: fixtures.personId,
		qty: 1, note: 'Atlas matrix returned loan',
	})
	expectOk(loan3, 'loan3 create')
	fixtures.returnedLoanId = loan3.data.loan?.id ?? loan3.data.id
	const checkin = await api(page, 'POST',
		urls.api.loanCheckin.replace('__ID__', String(fixtures.returnedLoanId)),
		{ qty: 1, conditionIn: 'ok' })
	expectOk(checkin, 'loan3 checkin')

	// Delivery note on OUR receive movement — the attached-image state cell
	// must carry a real uploaded note, not only the empty attach control.
	expect(fixtures.receiveMovId, 'receive movement id missing after seed').toBeTruthy()
	const up = await uploadDeliveryNote(page, urls.api.deliveryNoteUpload, fixtures.receiveMovId)
	expectOk(up, 'delivery-note upload')
}

async function cleanupFixtures(page) {
	try {
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
		const urls = await ivUrls(page)
		// Check in open loans so stock returns, then anonymize the fixture
		// person (persons are never hard-deleted — anonymize is the erasure path).
		for (const id of [fixtures.loanId, fixtures.loanId2]) {
			if (id) {
				await api(page, 'POST', urls.api.loanCheckin.replace('__ID__', String(id)),
					{ qty: 99, conditionIn: 'ok' }).catch(() => {})
			}
		}
		if (fixtures.receiveMovId && urls.api.deliveryNoteDelete) {
			await api(page, 'DELETE', urls.api.deliveryNoteDelete.replace('__ID__', String(fixtures.receiveMovId))).catch(() => {})
		}
		if (fixtures.personId && urls.api.personAnonymize) {
			await api(page, 'POST', urls.api.personAnonymize.replace('__ID__', String(fixtures.personId)), {}).catch(() => {})
		}
	} catch { /* best-effort teardown */ }
	// Hard-delete the seeded chain — marker-scoped so parallel lanes' rows
	// are never touched (shared_fixture_race class).
	const like = `%${MARKER}%`
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

// Fixture liveness — sibling lanes wipe shared oc_iv_* rows mid-run
// (external_suite_db_reset). Before each fixture-dependent surface verify
// the seeded person/items still resolve; reseed once when they were swept.
async function ensureFixturesAlive(page) {
	await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	const urls = await ivUrls(page)
	const [p, i1, i2] = await Promise.all([
		api(page, 'GET', urls.api.personById.replace('__ID__', String(fixtures.personId))),
		api(page, 'GET', `${urls.api.items}/${fixtures.itemId}`),
		api(page, 'GET', `${urls.api.items}/${fixtures.itemId2}`),
	])
	if (p.status !== 200 || i1.status !== 200 || i2.status !== 200) {
		record({ id: `fixture-reseed-${results.cells.length}`, status: 'warn',
			checks: { person: p.status, item1: i1.status, item2: i2.status } })
		await seedFixtures(page)
	}
}

/* ---------- state drivers ---------- */

async function driveEmpty(page, surface) {
	if (surface.emptyDriver !== 'searchNoMatch') {
		throw new Error(`surface ${surface.id}: unknown emptyDriver ${surface.emptyDriver}`)
	}
	const input = page.locator(surface.searchSelector)
	await expect(input).toBeVisible({ timeout: 15_000 })
	await input.fill(`zz-nomatch-${MARKER}`)
	await waitPeopleListSettled(page)
	await expect(page.locator('#iv-page-root .iv-empty, #iv-page-root .iv-empty-state').first())
		.toBeVisible({ timeout: 15_000 })
}

async function closeOpenDialog(page) {
	await page.keyboard.press('Escape')
	await page.waitForTimeout(300)
	await expect(page.locator(VISIBLE_DIALOG)).toHaveCount(0, { timeout: 10_000 }).catch(() => {})
}

const insideDlg = (sel) => VISIBLE_DIALOG.split(',').map((p) => `${p.trim()} ${sel}`).join(',')

const STATE_DRIVERS = {
	'people': {
		'empty': driveEmpty,
		'person-dialog': async (page) => {
			await waitPeopleListSettled(page)
			await page.locator('#iv-page-actions button.iv-btn--primary, #iv-page-actions button.primary').first().click()
			await expect(page.locator(insideDlg('[data-iv-action="dialog-confirm"]'))).toBeVisible({ timeout: 12_000 })
		},
	},
	'person-detail': {
		'checkin-dialog': async (page) => {
			await waitPersonLoansSettled(page)
			await page.locator('#iv-page-root table .iv-btn--small:not(.iv-btn--ghost)').first().click()
			await expect(page.locator(insideDlg('[data-iv-action="dialog-confirm"]'))).toBeVisible({ timeout: 12_000 })
		},
		'anonymize-dialog': async (page) => {
			await waitPersonLoansSettled(page)
			await page.locator('#iv-page-actions .iv-btn--ghost').first().click()
			await expect(page.locator(insideDlg('[data-iv-action="dialog-confirm"]'))).toBeVisible({ timeout: 12_000 })
		},
	},
	'item-detail-loans': {
		'checkout-dialog': async (page) => {
			// The Lent out section carries the loans table; its Check out button
			// is the secondary action inside that section.
			const lentSection = page.locator('.iv-section', { has: page.locator('table .iv-btn--small') })
			await lentSection.locator('button.iv-btn--secondary').first().click()
			await expect(page.locator(insideDlg('[data-iv-action="dialog-confirm"]'))).toBeVisible({ timeout: 12_000 })
		},
	},
	'movements-delivery-note': {
		'delivery-note-dialog': async (page) => {
			// Prefer the seeded attached row (view state); fall back to an
			// attach-control row when none carried over.
			const viewBtn = page.locator('[data-iv-action="delivery-note"]').first()
			if (await viewBtn.count()) {
				await viewBtn.click()
				await expect(page.locator(insideDlg('.iv-delivery-note__img'))).toBeVisible({ timeout: 12_000 })
			} else {
				await page.locator('[data-iv-action="delivery-note-add"]').first().click()
				await expect(page.locator(insideDlg('input[type="file"]'))).toBeVisible({ timeout: 12_000 })
			}
		},
	},
}

function surfacePath(surface) {
	return surface.path
		.replace('{personId}', String(fixtures.personId))
		.replace('{itemId}', String(fixtures.itemId))
}

function* matrixCells(theme) {
	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states) {
			for (const vp of MANIFEST.viewports) {
				if (theme === 'dark' && (!surface.dark || vp.name !== 'desktop' || state !== surface.states[0])) {
					continue
				}
				yield { surface, state, vp }
			}
		}
	}
}

function shotName(surface, state, vp, theme) {
	return `${MANIFEST.prefix}-${surface.id}-${state}-${vp.name}${theme === 'dark' ? '-dark' : ''}.png`
}

/* ---------- dialog lifecycle probe (locale-safe structural selectors) ---- */

async function waitDialog(page) {
	await page.waitForFunction(
		(sel) => [...document.querySelectorAll(sel)]
			.some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
		VISIBLE_DIALOG, { timeout: 12000 },
	).catch(() => {})
	await settle(page)
}

async function probeDialogLifecycle(page, name, openFn) {
	const r = { id: name, checks: {}, status: 'ok', fails: [] }
	const fail = (m) => { r.fails.push(m); r.status = 'fail' }
	try {
		const ok = await openFn()
		if (ok === false) { fail('no trigger found'); record(r); return r }
	} catch (err) {
		fail(`trigger threw: ${String(err).slice(0, 160)}`); record(r); return r
	}
	await waitDialog(page)
	const dialogInfo = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
		if (!dlg) return null
		const labelId = dlg.getAttribute('aria-labelledby')
		return {
			tag: dlg.tagName.toLowerCase(),
			role: dlg.getAttribute('role'),
			ariaModal: dlg.getAttribute('aria-modal'),
			labelText: labelId ? (document.getElementById(labelId)?.textContent || '') : '',
		}
	}, VISIBLE_DIALOG)
	if (!dialogInfo) { fail('dialog not found after trigger'); record(r); return r }
	r.checks.dialog = dialogInfo
	if (dialogInfo.tag !== 'dialog' && dialogInfo.role !== 'dialog') fail('missing role=dialog')
	if (dialogInfo.tag !== 'dialog' && dialogInfo.ariaModal !== 'true') fail('missing aria-modal')
	if (!dialogInfo.labelText.trim()) fail('aria-labelledby unresolved/empty')

	r.checks.focusInside = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
		const ae = document.activeElement
		return !!(dlg && ae && (dlg === ae || dlg.contains(ae)))
	}, VISIBLE_DIALOG)
	if (!r.checks.focusInside) fail('focus did not move inside dialog')

	// Focus trap: Tab on last wraps inside; Shift+Tab on first wraps inside.
	const trap = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
		if (!dlg) return null
		const list = [...dlg.querySelectorAll('a[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),button:not([disabled]),[tabindex]:not([tabindex="-1"])')]
			.filter((n) => n.offsetParent !== null)
		return { count: list.length }
	}, VISIBLE_DIALOG)
	r.checks.focusableCount = trap?.count
	if (trap && trap.count > 1) {
		await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			const list = [...dlg.querySelectorAll('button:not([disabled]),input:not([disabled]):not([type="hidden"]),select,textarea,a[href],[tabindex]:not([tabindex="-1"])')]
				.filter((n) => n.offsetParent !== null)
			list[list.length - 1]?.focus()
		}, VISIBLE_DIALOG)
		await page.keyboard.press('Tab')
		const wrappedForward = await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			return !!(dlg && dlg.contains(document.activeElement))
		}, VISIBLE_DIALOG)
		if (!wrappedForward) fail('Tab escaped dialog forward')
		await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			const list = [...dlg.querySelectorAll('button:not([disabled]),input:not([disabled]):not([type="hidden"]),select,textarea,a[href],[tabindex]:not([tabindex="-1"])')]
				.filter((n) => n.offsetParent !== null)
			list[0]?.focus()
		}, VISIBLE_DIALOG)
		await page.keyboard.press('Shift+Tab')
		const wrappedBack = await page.evaluate((sel) => {
			const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
			return !!(dlg && dlg.contains(document.activeElement))
		}, VISIBLE_DIALOG)
		if (!wrappedBack) fail('Shift+Tab escaped dialog backward')
	}
	await snap(page, `custody__dialog__${name}__open`)

	// Escape closes + focus restores off <body>.
	await page.keyboard.press('Escape')
	await page.waitForTimeout(350)
	const stillOpen = await page.evaluate((sel) => [...document.querySelectorAll(sel)]
		.some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)), VISIBLE_DIALOG)
	r.checks.escapeClosed = !stillOpen
	if (stillOpen) fail('Escape did not close dialog')
	const focusAfterEsc = await page.evaluate(() => ({
		active: document.activeElement?.tagName,
		activeId: document.activeElement?.id || '',
	}))
	r.checks.focusAfterEscape = focusAfterEsc
	if (focusAfterEsc.active === 'BODY' || focusAfterEsc.active === 'HTML') {
		fail(`focus restored to <${(focusAfterEsc.active || '?').toLowerCase()}> — trigger lost`)
	}

	// Cancel path: reopen → Cancel/X → closed.
	try {
		const ok2 = await openFn()
		if (ok2 === false) { fail('dialog did not reopen for cancel path'); record(r); return r }
		await waitDialog(page)
		const cancelBtn = page.locator(insideDlg('.iv-dialog__actions button:not(.primary)')).first()
		const closeX = page.locator(insideDlg('.iv-dialog__close')).first()
		if (await cancelBtn.count()) {
			await cancelBtn.click({ timeout: 5000 })
		} else if (await closeX.count()) {
			await closeX.click({ timeout: 5000 })
		} else {
			await page.keyboard.press('Escape')
		}
		await page.waitForTimeout(350)
		const afterCancel = await page.evaluate((sel) => ({
			stillOpen: [...document.querySelectorAll(sel)].some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
			active: document.activeElement?.tagName,
		}), VISIBLE_DIALOG)
		r.checks.cancelClosed = !afterCancel.stillOpen
		if (afterCancel.stillOpen) fail('Cancel/close did not dismiss dialog')
		if (afterCancel.active === 'BODY') fail('focus lost to <body> after cancel — no restore')
	} catch (err) {
		fail(`cancel path error: ${String(err).slice(0, 160)}`)
	}
	record(r)
	return r
}

/* ---------- the matrix test ---------- */

test.describe('SCREENSHOT_MATRIX capture — F2 custody delta', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(30 * 60_000)

	test.beforeAll(async () => {
		const auth = await import('../tests/e2e/helpers/auth.mjs')
		login = auth.login
		credsFromEnv = auth.credsFromEnv
	})

	test('seeds custody fixtures', async ({ page }) => {
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#iv-main-content')).toBeVisible({ timeout: 30_000 })
		await seedFixtures(page)
		expect(fixtures.personId).toBeGreaterThan(0)
		expect(fixtures.loanId).toBeGreaterThan(0)
	})

	test('captures every manifest cell', async ({ page }) => {
		test.skip(!fixtures.personId, 'fixture seed failed')
		mkdirSync(OUT, { recursive: true })
		mkdirSync(PROBE_OUT, { recursive: true })
		page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))
		const missing = []

		for (const theme of ['light', 'dark']) {
			for (const { surface, state, vp } of matrixCells(theme)) {
				const cellId = `${surface.id}__${state}__${vp.name}${theme === 'dark' ? '__dark' : ''}`
				const cell = { id: `matrix__${cellId}`, checks: { theme, viewport: vp.width }, status: 'ok', fails: [] }
				try {
					if (surface.user === 'denied') { cell.status = 'skip'; cell.checks.note = 'denied cells run in the dedicated denied leg'; record(cell); continue }
					if (theme === 'dark') {
						await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
						await setUserTheme(page, 'dark')
					}
					await page.setViewportSize({ width: vp.width, height: vp.height })
					if (surface.path.includes('{personId}') || surface.path.includes('{itemId}')) {
						await ensureFixturesAlive(page)
					}
					const resp = await page.goto(`${APP}${surfacePath(surface)}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
					await settle(page)
					await expect(page.locator(READY[surface.id]).first()).toBeVisible({ timeout: 30_000 })
					const driver = STATE_DRIVERS[surface.id]?.[state]
					if (driver) await driver(page, surface)
					else if (surface.id === 'people' && state === 'populated') await waitPeopleListSettled(page)
					else if (surface.id === 'person-detail') await waitPersonLoansSettled(page)
					cell.checks.http = resp ? resp.status() : 'nav-fail'
					const ov = await checkOverflow(page)
					cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
					cell.checks.axe = await runAxe(page)
					const themeRender = await assertThemeRendered(page, theme)
					cell.checks.themeApplied = themeRender.ok
					// #app-content scrolls internally — grow the viewport to content
					// height so the shot is reviewable, not fold-clipped.
					const contentH = await page.evaluate(() =>
						(document.querySelector('#app-content') || document.documentElement).scrollHeight)
					const shotH = Math.min(Math.max(contentH + 60, vp.height), 8192)
					if (shotH !== vp.height) await page.setViewportSize({ width: vp.width, height: shotH })
					const name = shotName(surface, state, vp, theme).replace(/\.png$/, '')
					const shot = await snap(page, name, OUT)
					capturedFiles.push(`${name}.png`)
					cell.proof = shot.sha256.slice(0, 16)
					if (driver) await closeOpenDialog(page)
					if (theme === 'dark') {
						const tk = surface.id
						results.themeProof[tk] = results.themeProof[tk] || {}
						results.themeProof[tk][theme] = shot.sha256
					}
					if (typeof cell.checks.http !== 'number' || cell.checks.http >= 400) cell.fails.push(`http ${cell.checks.http}`)
					if (!cell.checks.overflowOk) cell.fails.push(`overflow ${JSON.stringify(ov)}`)
					if (cell.checks.axe.length) cell.fails.push(`axe ${cell.checks.axe.length}: ${JSON.stringify(cell.checks.axe).slice(0, 300)}`)
					if (!themeRender.ok) cell.fails.push(`theme attr missing: ${JSON.stringify(themeRender)}`)
				} catch (err) {
					cell.fails.push(`cell error: ${String(err).slice(0, 200)}`)
					missing.push(cellId)
					try { await closeOpenDialog(page) } catch { /* */ }
				}
				if (cell.fails.length) cell.status = 'fail'
				record(cell)
				if (theme === 'dark') {
					await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
					await setUserTheme(page, 'light')
				}
			}
		}
		expect(missing, `failed cells: ${missing.join(', ')}`).toEqual([])
	})

	test('denied /people renders the L2 door', async ({ page, browser }) => {
		test.skip(!fixtures.personId, 'fixture seed failed')
		const r = { id: 'custody-denied-people', checks: {}, status: 'ok', fails: [] }
		const prevRestriction = occ('config:app:get inventorycheck access_restriction_enabled')
		const prevAllowed = occ('config:app:get inventorycheck access_allowed_user_ids')
		let ctx2 = null
		try {
			// Recreate the harness denied probe if a prior lane removed it,
			// then pin its password — sibling lanes may have rotated it
			// (admin_password_rotation class).
			if (!occ('user:info iv_ds_denied').includes('user_id')) {
				execFileSync('docker', [
					'compose', 'exec', '-T', '-u', 'www-data',
					'-e', 'OC_PASS=DsProbe!2026',
					'nextcloud', 'php', 'occ', 'user:add', '--password-from-env', 'iv_ds_denied',
				], { cwd: COMPOSE_CWD, stdio: ['ignore', 'pipe', 'pipe'] })
			}
			execFileSync('docker', [
				'compose', 'exec', '-T', '-u', 'www-data',
				'-e', 'OC_PASS=DsProbe!2026',
				'nextcloud', 'php', 'occ', 'user:resetpassword', '--password-from-env', 'iv_ds_denied',
			], { cwd: COMPOSE_CWD, stdio: ['ignore', 'pipe', 'pipe'] })
			occ('config:app:set inventorycheck access_restriction_enabled --value=1')
			occ(`config:app:set inventorycheck access_allowed_user_ids --value='["admin","iv_atlas"]'`)
			// Empty storageState: browser.newContext inside a test inherits
			// use.storageState (iv_atlas) — without this the "denied" context
			// stays authenticated as an allowed user.
			ctx2 = await browser.newContext({ viewport: { width: 1280, height: 800 }, storageState: { cookies: [], origins: [] } })
			const dp = await ctx2.newPage()
			await login(dp, { username: 'iv_ds_denied', password: 'DsProbe!2026' })
			const resp = await dp.goto(`${APP}/people`, { waitUntil: 'domcontentloaded' })
			await settle(dp)
			r.checks.http = resp ? resp.status() : 'nav-fail'
			r.checks.deniedShown = await dp.locator('.iv-app--denied, #iv-denied-main, #iv-denied-title').first().isVisible().catch(() => false)
			r.checks.recoveryCta = await dp.locator('.iv-app--denied a.button, .iv-app--denied .iv-btn, .iv-app--denied a').first().isVisible().catch(() => false)
			r.checks.axe = await runAxe(dp)
			const shot = await snap(dp, 'iv-people-denied-current-desktop', OUT)
			capturedFiles.push('iv-people-denied-current-desktop.png')
			for (const vpName of ['mobile', 'tablet']) {
				const vp = MANIFEST.viewports.find((v) => v.name === vpName)
				await dp.setViewportSize({ width: vp.width, height: vp.height })
				await dp.goto(`${APP}/people`, { waitUntil: 'domcontentloaded' })
				await settle(dp)
				const s2 = await snap(dp, `iv-people-denied-current-${vpName}`, OUT)
				capturedFiles.push(`iv-people-denied-current-${vpName}.png`)
			}
			if (r.checks.http !== 403 && r.checks.http !== 401) r.fails.push(`expected 403, got ${r.checks.http}`)
			if (!r.checks.deniedShown) r.fails.push('denied surface not rendered on /people')
			if (!r.checks.recoveryCta) r.fails.push('no recovery CTA on denied surface')
			if (r.checks.axe.length) r.fails.push(`axe ${r.checks.axe.length}`)
			r.proof = shot.sha256.slice(0, 16)
		} catch (err) {
			r.fails.push(`denied leg error: ${String(err).slice(0, 200)}`)
		} finally {
			if (ctx2) await ctx2.close().catch(() => {})
			occ(`config:app:set inventorycheck access_restriction_enabled --value=${prevRestriction === '1' ? '1' : '0'}`)
			occ(`config:app:set inventorycheck access_allowed_user_ids --value='${(prevAllowed || '[]').replace(/'/g, `'\\''`)}'`)
		}
		if (r.fails.length) r.status = 'fail'
		record(r)
	})

	test('custody dialog lifecycles', async ({ page }) => {
		test.skip(!fixtures.personId, 'fixture seed failed')
		page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))

		// person create dialog on /people
		await page.goto(`${APP}/people`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		await waitPeopleListSettled(page)
		await probeDialogLifecycle(page, 'person-create', async () => {
			const b = page.locator('#iv-page-actions button.iv-btn--primary, #iv-page-actions button.primary').first()
			if (!(await b.count())) return false
			await b.click()
			return true
		})

		// checkout dialog on item detail
		await page.goto(`${APP}/items/${fixtures.itemId}`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		await probeDialogLifecycle(page, 'checkout', async () => {
			const lentSection = page.locator('.iv-section', { has: page.locator('table .iv-btn--small') })
			const b = lentSection.locator('button.iv-btn--secondary').first()
			if (!(await b.count())) return false
			await b.click()
			return true
		})
		// Keyboard-only leg: person picker combobox fully by keyboard.
		{
			const kb = { id: 'checkout-keyboard-combobox', checks: {}, status: 'ok', fails: [] }
			const lentSection = page.locator('.iv-section', { has: page.locator('table .iv-btn--small') })
			await lentSection.locator('button.iv-btn--secondary').first().click()
			await waitDialog(page)
			const combo = page.locator(insideDlg('[role="combobox"], .iv-combobox input, .iv-picker input')).first()
			if (await combo.count()) {
				await combo.focus()
				await page.keyboard.type('Matrix', { delay: 30 })
				await page.waitForTimeout(600)
				kb.checks.options = await page.locator(insideDlg('[role="option"]')).count()
				await page.keyboard.press('ArrowDown')
				await page.keyboard.press('Enter')
				await page.waitForTimeout(300)
				kb.checks.selected = await page.evaluate((sel) => {
					const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null)
					return dlg ? (dlg.textContent || '').includes('Matrix') : false
				}, VISIBLE_DIALOG)
				if (!kb.checks.options) kb.fails.push('combobox offered no options for fixture name')
				if (!kb.checks.selected) kb.fails.push('ArrowDown+Enter did not pick the person')
				await snap(page, 'custody__dialog__checkout__keyboard-pick')
			} else {
				kb.fails.push('no combobox input in checkout dialog')
			}
			await closeOpenDialog(page)
			if (kb.fails.length) kb.status = 'fail'
			record(kb)
		}

		// checkin + mark-lost dialogs on person detail
		await page.goto(`${APP}/people/${fixtures.personId}`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		await waitPersonLoansSettled(page)
		await probeDialogLifecycle(page, 'checkin', async () => {
			const b = page.locator('#iv-page-root table .iv-btn--small:not(.iv-btn--ghost)').first()
			if (!(await b.count())) return false
			await b.click()
			return true
		})
		await probeDialogLifecycle(page, 'mark-lost', async () => {
			const b = page.locator('#iv-page-root table .iv-btn--small.iv-btn--ghost').first()
			if (!(await b.count())) return false
			await b.click()
			return true
		})
		await probeDialogLifecycle(page, 'anonymize', async () => {
			const b = page.locator('#iv-page-actions .iv-btn--ghost').first()
			if (!(await b.count())) return false
			await b.click()
			return true
		})

		// delivery-note dialog on the seeded receive movement row
		await page.goto(`${APP}/movements`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		await probeDialogLifecycle(page, 'delivery-note', async () => {
			const view = page.locator('[data-iv-action="delivery-note"]').first()
			const add = page.locator('[data-iv-action="delivery-note-add"]').first()
			const b = (await view.count()) ? view : add
			if (!(await b.count())) return false
			await b.click()
			return true
		})
	})

	test('custody themed + viewport sweep', async ({ page }) => {
		test.skip(!fixtures.personId, 'fixture seed failed')
		page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))
		const routes = [
			{ id: 'people', path: '/people' },
			{ id: 'person-detail', path: `/people/${fixtures.personId}` },
			{ id: 'item-detail', path: `/items/${fixtures.itemId}` },
			{ id: 'dashboard', path: '/' },
			{ id: 'movements', path: '/movements' },
		]
		const readyByRoute = {
			'people': '#iv-page-root input[type="search"]',
			'person-detail': '#iv-page-root .iv-section:nth-of-type(2)',
			'item-detail': '#iv-page-root .iv-section',
			'dashboard': '[data-iv-loans-card]',
			'movements': '[data-iv-action^="delivery-note"]',
		}
		for (const theme of THEMES) {
			await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			for (const route of routes) {
				const cell = { id: `sweep__${route.id}@${theme}@1440`, theme, viewport: 1440, checks: {}, status: 'ok' }
				const resp = await page.goto(`${APP}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
				await settle(page)
				await expect(page.locator(readyByRoute[route.id]).first()).toBeVisible({ timeout: 30_000 }).catch(() => {})
				if (route.id === 'people') await waitPeopleListSettled(page)
				if (route.id === 'person-detail') await waitPersonLoansSettled(page)
				cell.checks.http = resp ? resp.status() : 'nav-fail'
				const ov = await checkOverflow(page)
				cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
				const themeRender = await assertThemeRendered(page, theme)
				cell.checks.themeApplied = themeRender.ok
				cell.checks.axe = await runAxe(page)
				const shot = await snap(page, `custody__sweep__${route.id}__${theme}__1440`)
				cell.proof = shot.sha256.slice(0, 16)
				const fails = []
				if (typeof cell.checks.http !== 'number' || cell.checks.http >= 400) fails.push(`http ${cell.checks.http}`)
				if (!themeRender.ok) fails.push('theme attr missing')
				if (!cell.checks.overflowOk) fails.push(`overflow ${JSON.stringify(ov)}`)
				if (cell.checks.axe.length) fails.push(`axe ${cell.checks.axe.length}: ${JSON.stringify(cell.checks.axe).slice(0, 300)}`)
				if (fails.length) { cell.status = 'fail'; cell.fails = fails }
				record(cell)
				results.themeProof[route.id] = results.themeProof[route.id] || {}
				results.themeProof[route.id][theme] = shot.sha256
			}
		}
		for (const route of routes) {
			const proof = results.themeProof[route.id] || {}
			const distinct = new Set(Object.values(proof))
			const cell = { id: `themedistinct__${route.id}`, checks: { distinct: distinct.size }, status: 'ok' }
			const fails = []
			if (proof.light && proof.dark && proof.light === proof.dark) fails.push('light=dark pixel-identical — theme not applied')
			if (distinct.size < 2) fails.push(`only ${distinct.size} distinct themed sha`)
			if (fails.length) { cell.status = 'fail'; cell.fails = fails }
			else if (distinct.size < 4) { cell.status = 'warn'; cell.warns = [`${distinct.size}/4 distinct themed shas`] }
			record(cell)
		}
		// Viewport sweep on light.
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, 'light')
		for (const vp of [{ w: 320, h: 640 }, { w: 768, h: 1024 }, { w: 1024, h: 768 }, { w: 1440, h: 900 }]) {
			await page.setViewportSize({ width: vp.w, height: vp.h })
			for (const route of routes) {
				const cell = { id: `sweep__${route.id}@light@${vp.w}`, theme: 'light', viewport: vp.w, checks: {}, status: 'ok' }
				const resp = await page.goto(`${APP}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
				await settle(page)
				if (route.id === 'people') await waitPeopleListSettled(page)
				if (route.id === 'person-detail') await waitPersonLoansSettled(page)
				cell.checks.http = resp ? resp.status() : 'nav-fail'
				const ov = await checkOverflow(page)
				cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
				cell.checks.touchOffenders = await checkTouchTargets(page)
				const shot = await snap(page, `custody__sweep__${route.id}__light__${vp.w}`)
				cell.proof = shot.sha256.slice(0, 16)
				const fails = []
				if (typeof cell.checks.http !== 'number' || cell.checks.http >= 400) fails.push(`http ${cell.checks.http}`)
				if (!cell.checks.overflowOk) fails.push(`overflow ${JSON.stringify(ov)}`)
				if (cell.checks.touchOffenders.length) fails.push(`touch<44: ${JSON.stringify(cell.checks.touchOffenders.slice(0, 6))}`)
				if (fails.length) { cell.status = 'fail'; cell.fails = fails }
				record(cell)
			}
		}
	})

	test('custody error/anon/empty states', async ({ page, browser }) => {
		test.skip(!fixtures.personId, 'fixture seed failed')

		// people fetch-error: abort persons API → error panel + retry CTA, no raw codes.
		{
			const cell = { id: 'people-fetch-error', checks: {}, status: 'ok', fails: [] }
			await page.route('**/apps/inventorycheck/api/persons**', (route) => route.abort('failed'))
			await page.goto(`${APP}/people`, { waitUntil: 'domcontentloaded' })
			await settle(page)
			await page.waitForTimeout(1200)
			cell.checks.errorPanel = await page.locator('.iv-empty, .iv-empty-state, [role="alert"]').first().isVisible().catch(() => false)
			cell.checks.retryBtn = await page.locator('#iv-page-root button').first().isVisible().catch(() => false)
			cell.checks.rawCodeText = await page.evaluate(() => {
				const main = document.getElementById('iv-page-root')
				const txt = main ? main.textContent || '' : ''
				return /ERR_|ECONNREFUSED|TypeError|\b500\b|\b503\b|stack trace/i.test(txt) ? txt.slice(0, 200) : null
			})
			await snap(page, 'custody__state__people-fetch-error', PROBE_OUT, { fullPage: true })
			if (!cell.checks.errorPanel) cell.fails.push('no error surface on aborted persons fetch')
			if (!cell.checks.retryBtn) cell.fails.push('no retry CTA')
			if (cell.checks.rawCodeText) cell.fails.push(`raw error code visible: ${cell.checks.rawCodeText}`)
			await page.unroute('**/apps/inventorycheck/api/persons**')
			if (cell.fails.length) cell.status = 'fail'
			record(cell)
		}
		// person-detail fetch-error.
		{
			const cell = { id: 'person-detail-fetch-error', checks: {}, status: 'ok', fails: [] }
			await page.route('**/apps/inventorycheck/api/persons/**', (route) => route.abort('failed'))
			await page.goto(`${APP}/people/${fixtures.personId}`, { waitUntil: 'domcontentloaded' })
			await settle(page)
			await page.waitForTimeout(1200)
			cell.checks.errorPanel = await page.locator('.iv-empty, .iv-empty-state, [role="alert"], .iv-error').first().isVisible().catch(() => false)
			cell.checks.rawCodeText = await page.evaluate(() => {
				const main = document.getElementById('iv-page-root')
				const txt = main ? main.textContent || '' : ''
				return /ERR_|ECONNREFUSED|TypeError|\b500\b|\b503\b|stack trace/i.test(txt) ? txt.slice(0, 200) : null
			})
			await snap(page, 'custody__state__person-detail-fetch-error', PROBE_OUT, { fullPage: true })
			if (!cell.checks.errorPanel) cell.fails.push('no error surface on aborted person fetch')
			if (cell.checks.rawCodeText) cell.fails.push(`raw error code visible: ${cell.checks.rawCodeText}`)
			await page.unroute('**/apps/inventorycheck/api/persons/**')
			if (cell.fails.length) cell.status = 'fail'
			record(cell)
		}
		// anon /people → login redirect.
		{
			const cell = { id: 'anon-people-redirect', checks: {}, status: 'ok', fails: [] }
			// Empty storageState — see the denied leg: inherited use.storageState
			// would keep this context authenticated as iv_atlas.
			const anon = await browser.newContext({ viewport: { width: 1280, height: 800 }, storageState: { cookies: [], origins: [] } })
			const ap = await anon.newPage()
			const resp = await ap.goto(`${APP}/people`, { waitUntil: 'domcontentloaded' })
			await settle(ap)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			cell.checks.onLogin = /\/login/.test(ap.url())
			await snap(ap, 'custody__state__anon-people')
			if (!cell.checks.onLogin) cell.fails.push(`anon did not land on /login (${ap.url()})`)
			await anon.close()
			if (cell.fails.length) cell.status = 'fail'
			record(cell)
		}
		// people empty-state asserts (manifest cell renders it; assert copy+CTA here).
		{
			const cell = { id: 'people-empty-quality', checks: {}, status: 'ok', fails: [] }
			await page.goto(`${APP}/people`, { waitUntil: 'domcontentloaded' })
			await settle(page)
			const input = page.locator('#iv-page-root input[type="search"]')
			await input.fill(`zz-nomatch-${MARKER}`)
			await waitPeopleListSettled(page)
			const empty = page.locator('#iv-page-root .iv-empty, #iv-page-root .iv-empty-state').first()
			// Auto-wait for the debounced (300ms) search load to land the empty
			// state — a one-shot isVisible() races the debounce + fetch.
			try {
				await expect(empty).toBeVisible({ timeout: 15_000 })
				cell.checks.emptyVisible = true
			} catch {
				cell.checks.emptyVisible = false
			}
			cell.checks.emptyCopy = ((await empty.textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim().slice(0, 200)
			cell.checks.cta = await page.locator('#iv-page-actions button.iv-btn--primary, #iv-page-actions button.primary').first().isVisible().catch(() => false)
			if (!cell.checks.emptyVisible || !cell.checks.emptyCopy) cell.fails.push('no empty-state copy on empty list')
			if (!cell.checks.cta) cell.fails.push('no first-action CTA in empty state')
			if (cell.fails.length) cell.status = 'fail'
			record(cell)
		}
	})

	test('custody theatre — dark-island hunt', async ({ page }) => {
		test.skip(!fixtures.personId, 'fixture seed failed')
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, 'dark')
		const routes = [
			{ id: 'people', path: '/people' },
			{ id: 'person-detail', path: `/people/${fixtures.personId}` },
			{ id: 'item-detail', path: `/items/${fixtures.itemId}` },
			{ id: 'movements', path: '/movements' },
		]
		for (const route of routes) {
			const cell = { id: `theatre__custody-${route.id}`, checks: { path: route.path }, status: 'ok' }
			await page.goto(`${APP}${route.path}`, { waitUntil: 'domcontentloaded' })
			await settle(page)
			if (route.id === 'people') await waitPeopleListSettled(page)
			if (route.id === 'person-detail') await waitPersonLoansSettled(page)
			const islands = await page.evaluate(() => {
				const root = document.querySelector('#app-content.iv-app')
				if (!root) return { error: 'no app root' }
				const out = []
				const lum = (rgb) => {
					const m = String(rgb).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
					if (!m) return null
					return 0.2126 * Number(m[1]) + 0.7152 * Number(m[2]) + 0.0722 * Number(m[3])
				}
				for (const el of root.querySelectorAll('*')) {
					const cs = getComputedStyle(el)
					if (cs.display === 'none' || cs.visibility === 'hidden') continue
					if (el.closest('.iv-label-preview, .iv-label-print__sheet')) continue // documented --iv-qr-canvas exception
					const r = el.getBoundingClientRect()
					if (r.width < 40 || r.height < 24) continue
					const L = lum(cs.backgroundColor)
					if (L !== null && L > 200) {
						const alpha = cs.backgroundColor.startsWith('rgba')
							? parseFloat(cs.backgroundColor.split(',')[3]) : 1
						if (alpha > 0.5) {
							out.push(`${el.tagName.toLowerCase()}.${String(el.className).split(' ')[0]} bg=${cs.backgroundColor}`)
						}
					}
					if (out.length >= 10) break
				}
				return out
			})
			cell.checks.lightIslands = islands
			if (Array.isArray(islands) && islands.length) {
				cell.status = 'fail'
				cell.fails = [`dark-island surfaces: ${islands.join(' | ')}`]
				await snap(page, `custody__theatre__${route.id}`)
			}
			record(cell)
		}
		await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, 'light')
	})

	test.afterAll(async ({ browser }) => {
		try {
			const ctx = await browser.newContext()
			const page = await ctx.newPage()
			const creds = credsFromEnv('ADMIN') || credsFromEnv('E2E')
			if (creds) {
				await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' }).catch(() => {})
				if (page.url().includes('/login')) await login(page, creds).catch(() => {})
				await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' }).catch(() => {})
				await setUserTheme(page, 'light').catch(() => {})
				await cleanupFixtures(page)
			}
			await ctx.close()
		} finally {
			results.finishedAt = new Date().toISOString()
			mkdirSync(PROBE_OUT, { recursive: true })
			writeFileSync(join(PROBE_OUT, 'results-custody.json'), JSON.stringify(results, null, 1))
			mkdirSync(OUT, { recursive: true })
			writeFileSync(
				resolve(OUT, '_manifest-screenshot-matrix.json'),
				JSON.stringify({
					app: 'inventorycheck',
					lane: 'ds_chrome',
					wave: 'F2-custody',
					generated_by: 'e2e/capture-screenshot-matrix.spec.js',
					files: capturedFiles,
				}, null, 2),
			)
		}
	})
})
