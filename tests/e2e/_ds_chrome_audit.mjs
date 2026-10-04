// @ts-check
/**
 * ds_chrome lane probe — Atlas Farm 3.5.14 (fresh artifacts).
 *
 * Usage: DS_PROBE_PASS=<secret> node tests/e2e/_ds_chrome_audit.mjs <phase>
 *   sweep    routes × themes × viewports: http status, overflow, touch, axe,
 *            PNG+sha256 (server-persisted OCS themes, post-navigation
 *            body[data-theme-*] asserted, per-route themed sha distinctness)
 *   dialogs  role/aria-modal/focus-trap/Escape/focus-restore + validation
 *            negatives (aria-invalid + inline .iv-field__error)
 *   states   anon→login, denied surface, fetch-error+retry, empty states
 *   theatre  dark-island hunt: surfaces that keep light bg in dark theme
 *            outside the documented --iv-qr-canvas exception
 *
 * Evidence root: FARM_OUT (default .cursor/atlas-farm-v3/artifacts/inventorycheck/probes/ds_chrome)
 * Probe users: iv_ds_probe (app-admin+office+allow-listed) / iv_ds_denied.
 * Probe user lang pinned `en` via occ for the whole run.
 */
import { chromium } from 'playwright'
import AxeBuilder from '@axe-core/playwright'
import { createHash } from 'node:crypto'
import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { login } from './helpers/auth.mjs'

const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'
const PASS = process.env.DS_PROBE_PASS || 'DsProbe!2026'
const OUT = process.env.FARM_OUT
	|| '/home/alex/Development/nextcloud-dev/.cursor/atlas-farm-v3/artifacts/inventorycheck/probes/ds_chrome'
const PHASE = process.argv[2] || 'sweep'
mkdirSync(OUT, { recursive: true })

const USERS = {
	probe: { username: 'iv_ds_probe', password: PASS },
	denied: { username: 'iv_ds_denied', password: PASS },
}

const THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']
const VIEWPORTS = [
	{ w: 320, h: 640 },
	{ w: 768, h: 1024 },
	{ w: 1024, h: 768 },
	{ w: 1440, h: 900 },
]

const APP_ROUTES = [
	{ id: 'dashboard', path: '/apps/inventorycheck/' },
	{ id: 'items', path: '/apps/inventorycheck/items' },
	{ id: 'item-detail', path: '/apps/inventorycheck/items/1' },
	{ id: 'locations', path: '/apps/inventorycheck/locations' },
	{ id: 'location-detail', path: '/apps/inventorycheck/locations/1' },
	{ id: 'movements', path: '/apps/inventorycheck/movements' },
	{ id: 'stocktake', path: '/apps/inventorycheck/stocktake' },
	{ id: 'stocktake-new', path: '/apps/inventorycheck/stocktake/create' },
	{ id: 'stocktake-campaign', path: '/apps/inventorycheck/stocktake/1' },
]
const SETTINGS_SECTIONS = [
	'access', 'office', 'notifications', 'quantities', 'location-access',
	'connections', 'policies', 'license', 'support',
]
const DENIED_ROUTES = [
	{ id: 'denied-dashboard', path: '/apps/inventorycheck/' },
	{ id: 'denied-items', path: '/apps/inventorycheck/items' },
	{ id: 'denied-settings', path: '/apps/inventorycheck/settings/access' },
]

const results = { phase: PHASE, startedAt: new Date().toISOString(), cells: [], defects: [], captures: {}, themeProof: {} }

function sha256(buf) {
	return createHash('sha256').update(buf).digest('hex')
}

async function snap(page, name, opts = {}) {
	const buf = await page.screenshot({ fullPage: Boolean(opts.fullPage) })
	const file = `${name}.png`
	writeFileSync(join(OUT, file), buf)
	const hash = sha256(buf)
	results.captures[name] = { file, sha256: hash, bytes: buf.length }
	return { file, sha256: hash, bytes: buf.length }
}

async function settle(page) {
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	try { await page.waitForLoadState('networkidle', { timeout: 5000 }) } catch { /* long-polls */ }
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
}

function record(cell) {
	results.cells.push(cell)
	const tag = cell.status === 'fail' ? 'FAIL' : cell.status === 'warn' ? 'warn' : 'ok'
	console.log(`[${tag}] ${cell.id} :: ${JSON.stringify(cell.checks).slice(0, 300)}`)
}

async function loginState(browser, role) {
	const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	await login(page, USERS[role])
	const state = await ctx.storageState()
	await ctx.close()
	return state
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
			attr,
			present: document.body.hasAttribute(attr),
		}
	}, themeId)
}

async function checkOverflow(page) {
	return page.evaluate(() => {
		const doc = document.documentElement
		const app = document.querySelector('#app-content')
		const main = document.getElementById('iv-main-content') || document.getElementById('iv-denied-main')
		const probe = (el) => (el ? el.scrollWidth - el.clientWidth : 0)
		return {
			doc: probe(doc),
			app: probe(app),
			main: probe(main),
		}
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
					// checkbox/radio native glyphs draw small by design; their
					// wrapping <label> provides the hit area — measure the label.
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

const ALL_ROUTES = [
	...APP_ROUTES,
	...SETTINGS_SECTIONS.map((s) => ({ id: `settings-${s}`, path: `/apps/inventorycheck/settings/${s}` })),
]

/* ───────────────────────────── sweep ───────────────────────────── */
async function phaseSweep(browser) {
	const probeState = await loginState(browser, 'probe')
	const deniedState = await loginState(browser, 'denied')

	const ctx = await browser.newContext({ baseURL: BASE, storageState: probeState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))

	// Theme matrix at 1440 — server-persisted themes; per-route the light vs
	// dark capture must differ (pixel-identical themed renders hard-fail).
	for (const theme of THEMES) {
		await page.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, theme)
		for (const route of ALL_ROUTES) {
			const cell = { id: `${route.id}@${theme}@1440`, role: 'probe', theme, viewport: 1440, checks: {} }
			const resp = await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
			await settle(page)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			cell.checks.finalUrl = page.url().replace(BASE, '')
			cell.checks.hasMain = await page.locator('#iv-main-content, #iv-denied-main, .iv-app--denied, main').first().isVisible().catch(() => false)
			const ov = await checkOverflow(page)
			cell.checks.overflow = ov
			cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
			const themeRender = await assertThemeRendered(page, theme)
			cell.checks.themeApplied = themeRender
			cell.checks.axe = await runAxe(page)
			cell.checks.axeViolations = cell.checks.axe.length
			const shot = await snap(page, `sweep__${route.id}__${theme}__1440`)
			cell.proof = shot.sha256.slice(0, 16)
			const failReasons = []
			if (typeof cell.checks.http !== 'number' || cell.checks.http >= 400) {
				failReasons.push(`http ${cell.checks.http}`)
			}
			if (!themeRender.ok) failReasons.push(`theme attr missing: ${JSON.stringify(themeRender)}`)
			if (!cell.checks.overflowOk) failReasons.push(`overflow ${JSON.stringify(ov)}`)
			if (cell.checks.axeViolations > 0) failReasons.push(`axe ${cell.checks.axeViolations}: ${JSON.stringify(cell.checks.axe).slice(0, 300)}`)
			cell.status = failReasons.length ? 'fail' : 'ok'
			if (failReasons.length) cell.failReasons = failReasons
			record(cell)
			const tk = `${route.id}`
			results.themeProof[tk] = results.themeProof[tk] || {}
			results.themeProof[tk][theme] = shot.sha256
		}
	}

	// Per-route themed distinctness: light vs dark MUST differ pixel-wise;
	// fewer than 4 distinct shas across the theme set is a warn.
	for (const route of ALL_ROUTES) {
		const proof = results.themeProof[route.id] || {}
		const shas = Object.values(proof)
		const distinct = new Set(shas)
		const cell = { id: `themedistinct__${route.id}`, checks: { distinct: distinct.size, themes: proof }, status: 'ok' }
		const fails = []
		if (proof.light && proof.dark && proof.light === proof.dark) {
			fails.push('light and dark captures pixel-identical — theme not applied')
		}
		if (distinct.size < 2) fails.push(`only ${distinct.size} distinct themed sha`)
		if (fails.length) { cell.status = 'fail'; cell.failReasons = fails }
		else if (distinct.size < 4) { cell.status = 'warn'; cell.warns = [`${distinct.size}/4 distinct themed shas (light/dark differ)`] }
		record(cell)
	}

	// Viewport matrix on light for key routes (overflow + touch + capture).
	const KEY_ROUTES = [
		...APP_ROUTES,
		{ id: 'settings-access', path: '/apps/inventorycheck/settings/access' },
		{ id: 'settings-license', path: '/apps/inventorycheck/settings/license' },
	]
	await page.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
	await setUserTheme(page, 'light')
	for (const vp of VIEWPORTS) {
		await page.setViewportSize({ width: vp.w, height: vp.h })
		for (const route of KEY_ROUTES) {
			const cell = { id: `${route.id}@light@${vp.w}`, role: 'probe', theme: 'light', viewport: vp.w, checks: {} }
			const resp = await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
			await settle(page)
			cell.checks.http = resp ? resp.status() : 'nav-fail'
			const ov = await checkOverflow(page)
			cell.checks.overflow = ov
			cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
			cell.checks.touchOffenders = await checkTouchTargets(page)
			const shot = await snap(page, `sweep__${route.id}__light__${vp.w}`)
			cell.proof = shot.sha256.slice(0, 16)
			const failReasons = []
			if (typeof cell.checks.http !== 'number' || cell.checks.http >= 400) {
				failReasons.push(`http ${cell.checks.http}`)
			}
			if (!cell.checks.overflowOk) failReasons.push(`overflow ${JSON.stringify(ov)}`)
			if (cell.checks.touchOffenders.length) failReasons.push(`touch<44: ${JSON.stringify(cell.checks.touchOffenders.slice(0, 6))}`)
			cell.status = failReasons.length ? 'fail' : 'ok'
			if (failReasons.length) cell.failReasons = failReasons
			record(cell)
		}
	}
	await ctx.close()

	// Denied user — L2 gate: every page must 403 with the denied surface.
	const dctx = await browser.newContext({ baseURL: BASE, storageState: deniedState, viewport: { width: 1440, height: 900 } })
	const dp = await dctx.newPage()
	for (const route of DENIED_ROUTES) {
		const cell = { id: `${route.id}@light@1440`, role: 'denied', theme: 'light', viewport: 1440, checks: {} }
		const resp = await dp.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded' }).catch(() => null)
		await settle(dp)
		cell.checks.http = resp ? resp.status() : 'nav-fail'
		cell.checks.deniedShown = await dp.locator('.iv-app--denied, #iv-denied-main, #iv-denied-title').first().isVisible().catch(() => false)
		cell.checks.hasRecoveryCta = await dp.locator('.iv-app--denied a.button, .iv-app--denied .iv-btn').first().isVisible().catch(() => false)
		cell.checks.axe = await runAxe(dp)
		cell.checks.axeViolations = cell.checks.axe.length
		const ov = await checkOverflow(dp)
		cell.checks.overflowOk = Math.max(ov.doc, ov.app, ov.main) <= 1
		const shot = await snap(dp, `sweep__${route.id}__light__1440`)
		cell.proof = shot.sha256.slice(0, 16)
		const failReasons = []
		if (cell.checks.http !== 403 && cell.checks.http !== 401) failReasons.push(`expected 403, got http ${cell.checks.http}`)
		if (!cell.checks.deniedShown) failReasons.push('denied surface not rendered')
		if (!cell.checks.hasRecoveryCta) failReasons.push('no recovery CTA on denied surface')
		if (!cell.checks.overflowOk) failReasons.push('overflow')
		if (cell.checks.axeViolations) failReasons.push(`axe ${cell.checks.axeViolations}`)
		cell.status = failReasons.length ? 'fail' : 'ok'
		if (failReasons.length) cell.failReasons = failReasons
		record(cell)
	}
	await dctx.close()
}

/* ───────────────────────────── dialogs ───────────────────────────── */
async function probeDialogLifecycle(page, name, openFn, opts = {}) {
	const r = { id: name, checks: {}, status: 'ok', fails: [] }
	const fail = (m) => { r.fails.push(m); r.status = 'fail' }

	let trigger = null
	try {
		trigger = await openFn()
	} catch (err) {
		fail(`trigger threw: ${String(err).slice(0, 160)}`)
		record(r)
		return null
	}
	if (!trigger) { fail('no trigger found'); record(r); return null }
	const VISIBLE_DIALOG = '.iv-dialog-overlay:not([hidden]) [role="dialog"], .iv-dialog-overlay:not([hidden]) .iv-dialog, dialog[open]'
	await page.waitForFunction(
		(sel) => [...document.querySelectorAll(sel)]
			.some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
		VISIBLE_DIALOG, { timeout: 10000 },
	).catch(() => {})
	await settle(page)

	const dialogInfo = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || d.tagName === 'DIALOG')
		if (!dlg) return null
		const labelId = dlg.getAttribute('aria-labelledby')
		return {
			tag: dlg.tagName.toLowerCase(),
			role: dlg.getAttribute('role'),
			ariaModal: dlg.getAttribute('aria-modal'),
			nativeOpen: dlg.hasAttribute('open'),
			labelId,
			labelText: labelId ? (document.getElementById(labelId)?.textContent || '') : '',
		}
	}, VISIBLE_DIALOG)
	if (!dialogInfo) { fail('dialog not found after trigger'); record(r); return null }
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
			.filter((n) => n.offsetParent !== null || n === document.activeElement)
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
	await snap(page, `dialog__${name}__open`)

	// Validation negative (optional): submit the confirm with required fields
	// empty → server must NOT write; field errors must render aria-invalid +
	// inline .iv-field__error (WCAG 3.3.1/3.3.3).
	if (opts.validationNegative) {
		const neg = await opts.validationNegative(page, VISIBLE_DIALOG)
		r.checks.validationNegative = neg
		if (neg && neg.ok === false) fail(`validation negative: ${neg.reason}`)
	}

	// Escape closes
	await page.keyboard.press('Escape')
	await page.waitForTimeout(350)
	const stillOpen = await page.evaluate((sel) => [...document.querySelectorAll(sel)].some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)), VISIBLE_DIALOG)
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

	// Cancel path: reopen → Cancel/X → closed + focus off <body>
	try {
		await openFn()
		// Dialogs may mount asynchronously (loadMasters fetch behind
		// busyTriggerUntil) — poll for visibility, never one-shot.
		await page.waitForFunction(
			(sel) => [...document.querySelectorAll(sel)].some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
			VISIBLE_DIALOG, { timeout: 12000 },
		).catch(() => {})
		await settle(page)
		const reopened = await page.evaluate((sel) => [...document.querySelectorAll(sel)].some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)), VISIBLE_DIALOG)
		if (!reopened) { fail('dialog did not reopen for cancel path'); record(r); return r }
		// Descendant selectors must be applied per comma-arm of VISIBLE_DIALOG —
		// `${sel} .x` otherwise scopes .x to the last arm only and .first()
		// resolves the dialog element itself (learned blind spot).
		const insideDlg = (sel) => VISIBLE_DIALOG.split(',').map((p) => `${p.trim()} ${sel}`).join(',')
		const cancelBtn = page.locator(insideDlg('button'))
			.filter({ hasText: /Cancel|Abbrechen|Close|Schließen|Back|Zurück|Keep|Behalten/ }).first()
		if (await cancelBtn.count()) {
			await cancelBtn.click({ timeout: 5000 })
		} else {
			await page.locator(insideDlg('.iv-dialog__close')).first().click({ timeout: 5000 })
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

async function phaseDialogs(browser) {
	const probeState = await loginState(browser, 'probe')
	const ctx = await browser.newContext({ baseURL: BASE, storageState: probeState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	page.on('pageerror', (e) => console.log('PAGEEXC:', String(e).slice(0, 200)))

	// 1) dashboard → Receive stock (primary page action; async masters load)
	await page.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await page.waitForSelector('#iv-page-actions button.primary', { timeout: 30000 }).catch(() => {})
	await probeDialogLifecycle(page, 'dashboard-receive-dialog', async () => {
		const btnEl = page.locator('#iv-page-actions button.primary').first()
		if (!(await btnEl.count()) || !(await btnEl.isVisible().catch(() => false))) return null
		await btnEl.click()
		return true
	})

	// 2) items → New item + validation negative (empty required fields)
	await page.goto(`${BASE}/apps/inventorycheck/items`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await page.waitForSelector('#iv-page-actions button.primary', { timeout: 30000 }).catch(() => {})
	await probeDialogLifecycle(page, 'item-create-dialog', async () => {
		const btnEl = page.locator('#iv-page-actions button.primary').first()
		if (!(await btnEl.count()) || !(await btnEl.isVisible().catch(() => false))) return null
		await btnEl.click()
		return true
	}, {
		validationNegative: async (pg, dlgSel) => {
			// Clear required fields then confirm — expect aria-invalid + inline
			// .iv-field__error, dialog stays open, no POST to /api/items.
			const inside = (sel) => dlgSel.split(',').map((p) => `${p.trim()} ${sel}`).join(',')
			let postStatus = null
			const respListener = (res) => {
				const req = res.request()
				if (req.method() === 'POST' && /\/api\/items$/.test(req.url())) postStatus = res.status()
			}
			pg.on('response', respListener)
			try {
				const required = pg.locator(inside('input[required]'))
				const n = await required.count()
				for (let i = 0; i < n; i++) await required.nth(i).fill('')
				const confirmBtn = pg.locator(inside('.iv-dialog__actions button.primary, .iv-dialog__actions button')).last()
				await confirmBtn.click({ timeout: 5000 })
				await pg.waitForTimeout(1500)
				const res = await pg.evaluate((sel) => {
					const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null)
					const scope = dlg || document
					const invalid = [...scope.querySelectorAll('[aria-invalid="true"]')]
					const errors = [...scope.querySelectorAll('.iv-field__error')]
						.filter((e) => !e.hidden && (e.textContent || '').trim().length > 0)
						.map((e) => (e.textContent || '').trim().slice(0, 80))
					return { open: !!dlg, invalidCount: invalid.length, errors }
				}, dlgSel)
				// A 2xx write with empty required fields is the defect; a 4xx +
				// rendered inline field errors is the designed server-validation path.
				if (postStatus !== null && postStatus < 400) {
					return { ok: false, reason: `POST /api/items returned ${postStatus} with empty required fields` }
				}
				if (!res.open) return { ok: false, reason: 'dialog closed on invalid submit' }
				if (!res.invalidCount) return { ok: false, reason: `no aria-invalid on invalid submit (postStatus=${postStatus})` }
				if (!res.errors.length) return { ok: false, reason: 'no inline .iv-field__error rendered' }
				return { ok: true, postStatus, ...res }
			} finally {
				pg.off('response', respListener)
			}
		},
	})

	// 3) locations → New location
	await page.goto(`${BASE}/apps/inventorycheck/locations`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await page.waitForSelector('#iv-page-actions button.primary', { timeout: 30000 }).catch(() => {})
	await probeDialogLifecycle(page, 'location-create-dialog', async () => {
		const btnEl = page.locator('#iv-page-actions button.primary').first()
		if (!(await btnEl.count()) || !(await btnEl.isVisible().catch(() => false))) return null
		await btnEl.click()
		return true
	})

	// 4) item detail → Edit
	await page.goto(`${BASE}/apps/inventorycheck/items/1`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	await page.waitForSelector('#iv-page-actions button', { timeout: 30000 }).catch(() => {})
	await probeDialogLifecycle(page, 'item-edit-dialog', async () => {
		const btnEl = page.locator('#iv-page-actions button').first()
		if (!(await btnEl.count()) || !(await btnEl.isVisible().catch(() => false))) return null
		await btnEl.click()
		return true
	})

	// 5) stocktake campaign → Close stocktake confirm dialog. Resolve a
	// 'counting'/'open' campaign via the in-page API (dialog only opens when
	// the campaign has uncounted or conflict lines — 'counting' seed rows do).
	await page.goto(`${BASE}/apps/inventorycheck/stocktake`, { waitUntil: 'domcontentloaded' })
	await settle(page)
	const campaignId = await page.evaluate(async () => {
		try {
			const urls = JSON.parse(document.getElementById('app-content')?.getAttribute('data-iv-urls') || '{}')
			const base = urls?.api?.cycleCounts
			if (!base) return null
			const token = (window.OC && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') || ''
			const res = await fetch(`${base}?limit=100`, { credentials: 'same-origin', headers: { requesttoken: token } })
			const data = await res.json()
			const rows = data?.data || []
			const counting = rows.find((r) => r.status === 'counting') || rows.find((r) => r.status === 'open')
			return counting ? counting.id : null
		} catch { return null }
	})
	if (campaignId) {
		await page.goto(`${BASE}/apps/inventorycheck/stocktake/${campaignId}`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		// 'open' auto-starts counting — allow re-render to land the Close action.
		await page.waitForSelector('#iv-page-actions button.primary', { timeout: 20000 }).catch(() => {})
		await probeDialogLifecycle(page, 'stocktake-close-dialog', async () => {
			const btnEl = page.locator('#iv-page-actions button.primary').first()
			if (!(await btnEl.count()) || !(await btnEl.isVisible().catch(() => false))) return null
			await btnEl.click()
			return true
		})
	} else {
		record({ id: 'stocktake-close-dialog', status: 'warn', checks: { note: 'no counting/open campaign found — could not probe' } })
	}

	// 6) settings → quantities fractional enable → window.confirm native dialog.
	// The irreversible switch is a primary button (not a checkbox); cancel must
	// leave qtyScale unchanged (no POST).
	{
		const r = { id: 'quantities-window-confirm', checks: {}, status: 'ok', fails: [] }
		await page.goto(`${BASE}/apps/inventorycheck/settings/quantities`, { waitUntil: 'domcontentloaded' })
		await settle(page)
		const alreadyOn = await page.evaluate(() => document.getElementById('app-content')?.getAttribute('data-iv-qty-scale') === '3')
		const enableBtn = page.locator('.iv-settings-page button.primary, #iv-main-content button.primary').first()
		let dialogSeen = null
		let posted = 0
		const respListener = (res) => { if (res.request().method() === 'POST' && /config|fractional/i.test(res.request().url())) posted++ }
		page.on('response', respListener)
		page.once('dialog', async (d) => { dialogSeen = d.type(); await d.dismiss() })
		if (alreadyOn) {
			r.status = 'warn'
			r.checks.note = 'qtyScale already 3 — irreversible toggle not reachable'
		} else if (await enableBtn.count()) {
			await enableBtn.click()
			await page.waitForTimeout(600)
			r.checks.nativeConfirmFired = dialogSeen === 'confirm'
			r.checks.noPostOnDismiss = posted === 0
			if (!r.checks.nativeConfirmFired) { r.status = 'fail'; r.fails.push('irreversible toggle did not raise window.confirm') }
			if (!r.checks.noPostOnDismiss) { r.status = 'fail'; r.fails.push('POST fired despite confirm dismissed') }
		} else {
			r.status = 'warn'; r.checks.note = 'no enable button found'
		}
		page.off('response', respListener)
		record(r)
	}
	await ctx.close()
}

/* ───────────────────────────── states ───────────────────────────── */
async function phaseStates(browser) {
	// anon → login redirect
	const anon = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const ap = await anon.newPage()
	const resp = await ap.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
	await settle(ap)
	const anonCell = { id: 'anon-index-redirect', checks: { finalUrl: ap.url(), http: resp?.status() }, status: 'ok' }
	anonCell.checks.onLogin = /\/login/.test(ap.url())
	if (!anonCell.checks.onLogin) { anonCell.status = 'fail'; anonCell.fails = ['anon did not land on /login'] }
	await snap(ap, 'state__anon-redirect')
	record(anonCell)
	await anon.close()

	// denied → every app page renders the 403 denied surface with hint + CTA
	const deniedState = await loginState(browser, 'denied')
	const dctx = await browser.newContext({ baseURL: BASE, storageState: deniedState, viewport: { width: 1440, height: 900 } })
	const dp = await dctx.newPage()
	const dresp = await dp.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
	await settle(dp)
	const denyCell = { id: 'denied-index', checks: { http: dresp?.status() }, status: 'ok' }
	denyCell.checks.deniedShown = await dp.locator('.iv-app--denied, #iv-denied-title').first().isVisible().catch(() => false)
	denyCell.checks.hintText = (await dp.locator('.iv-empty__hint').first().textContent().catch(() => ''))?.trim().slice(0, 160)
	denyCell.checks.recoveryCta = await dp.locator('.iv-app--denied a.button.primary, .iv-app--denied .iv-btn--primary').first().isVisible().catch(() => false)
	if (!denyCell.checks.deniedShown) { denyCell.status = 'fail'; denyCell.fails = ['no denied surface'] }
	if (!denyCell.checks.hintText) { denyCell.status = 'fail'; (denyCell.fails = denyCell.fails || []).push('no actionable hint on denied surface') }
	if (!denyCell.checks.recoveryCta) { denyCell.status = 'fail'; (denyCell.fails = denyCell.fails || []).push('no recovery CTA') }
	await snap(dp, 'state__denied-index')
	record(denyCell)
	await dctx.close()

	const probeState = await loginState(browser, 'probe')
	const pctx = await browser.newContext({ baseURL: BASE, storageState: probeState, viewport: { width: 1440, height: 900 } })
	const pp = await pctx.newPage()

	// fetch-error: abort items API → error panel + retry CTA, no raw codes
	await pp.route('**/apps/inventorycheck/api/items**', (route) => route.abort('failed'))
	await pp.goto(`${BASE}/apps/inventorycheck/items`, { waitUntil: 'domcontentloaded' })
	await settle(pp)
	const errCell = { id: 'items-fetch-error', checks: {}, status: 'ok' }
	errCell.checks.errorPanel = await pp.locator('.iv-empty, .iv-empty-state, [role="alert"]').first().isVisible().catch(() => false)
	errCell.checks.retryBtn = await pp.locator('#iv-main-content button.primary, #iv-main-content .iv-btn--touch').first().isVisible().catch(() => false)
	errCell.checks.errorToast = await pp.locator('.iv-toast--error, .toast--error').first().isVisible().catch(() => false)
	errCell.checks.rawCodeText = await pp.evaluate(() => {
		const main = document.getElementById('iv-main-content')
		const txt = main ? main.textContent || '' : ''
		return /ERR_|ECONNREFUSED|TypeError|\b500\b|\b503\b|stack trace/i.test(txt) ? txt.slice(0, 200) : null
	})
	// The state panel renders below the fold inside the inner #iv-main-content
	// scroller (document itself is 900px) — scroll it into frame or the capture
	// shows only the identical how-to card (learned: identical PNGs ≠ evidence).
	await pp.locator('.iv-empty-state, .iv-empty').first().scrollIntoViewIfNeeded().catch(() => {})
	await snap(pp, 'state__items-fetch-error', { fullPage: true })
	if (!errCell.checks.errorPanel) { errCell.status = 'fail'; errCell.fails = ['no error surface on aborted fetch'] }
	if (!errCell.checks.retryBtn) { errCell.status = 'fail'; (errCell.fails = errCell.fails || []).push('no retry CTA') }
	if (errCell.checks.rawCodeText) { errCell.status = 'fail'; (errCell.fails = errCell.fails || []).push(`raw error code visible: ${errCell.checks.rawCodeText}`) }

	// Toast dedup live probe: click retry repeatedly — identical error toast
	// must not stack (kind+text dedup with timer reset).
	if (errCell.checks.retryBtn) {
		for (let i = 0; i < 3; i++) {
			await pp.locator('#iv-main-content button.primary, #iv-main-content .iv-btn--touch').first().click().catch(() => {})
			await pp.waitForTimeout(700)
		}
		const toastCount = await pp.locator('.iv-toast').count()
		errCell.checks.toastDedupCount = toastCount
		if (toastCount > 1) {
			errCell.status = 'fail'
			;(errCell.fails = errCell.fails || []).push(`${toastCount} identical error toasts stacked — dedup missing`)
		}
	}
	record(errCell)

	// empty state: fulfill empty items → empty copy + create CTA
	await pp.unroute('**/apps/inventorycheck/api/items**')
	await pp.route('**/apps/inventorycheck/api/items**', (route) => {
		if (route.request().method() === 'GET' && !/\/api\/items\/\d/.test(route.request().url())) {
			return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], total: 0 }) })
		}
		return route.continue()
	})
	await pp.goto(`${BASE}/apps/inventorycheck/items`, { waitUntil: 'domcontentloaded' })
	await settle(pp)
	const emptyCell = { id: 'items-empty-state', checks: {}, status: 'ok' }
	emptyCell.checks.emptyVisible = await pp.locator('.iv-empty, .iv-empty-state').first().isVisible().catch(() => false)
	emptyCell.checks.cta = await pp.locator('.iv-empty button.primary, .iv-empty-state button.primary, .iv-empty .iv-btn').first().isVisible().catch(() => false)
	emptyCell.checks.copy = await pp.evaluate(() => {
		const main = document.getElementById('iv-main-content')
		const txt = main ? (main.textContent || '').replace(/\s+/g, ' ').trim() : ''
		return /No items|keine/i.test(txt) ? txt.slice(0, 200) : null
	})
	await pp.locator('.iv-empty-state, .iv-empty').first().scrollIntoViewIfNeeded().catch(() => {})
	await snap(pp, 'state__items-empty', { fullPage: true })
	if (!emptyCell.checks.emptyVisible || !emptyCell.checks.copy) {
		emptyCell.status = 'fail'; emptyCell.fails = ['no empty-state copy on empty list']
	}
	if (!emptyCell.checks.cta) {
		emptyCell.status = 'fail'; (emptyCell.fails = emptyCell.fails || []).push('no first-action CTA in empty state')
	}
	record(emptyCell)
	await pctx.close()
}

/* ───────────────────────────── theatre ─────────────────────────────
 * Dark-island hunt: in dark theme, flag app-surface elements whose computed
 * background stays near-white outside the documented --iv-qr-canvas
 * exception (label/QR preview must stay light for scannability).
 */
async function phaseTheatre(browser) {
	const probeState = await loginState(browser, 'probe')
	const ctx = await browser.newContext({ baseURL: BASE, storageState: probeState, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	await page.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
	await setUserTheme(page, 'dark')
	const theatreRoutes = [
		'/apps/inventorycheck/', '/apps/inventorycheck/items', '/apps/inventorycheck/items/1',
		'/apps/inventorycheck/locations', '/apps/inventorycheck/movements',
		'/apps/inventorycheck/stocktake', '/apps/inventorycheck/settings/license',
	]
	for (const path of theatreRoutes) {
		const cell = { id: `theatre__${path.split('/').pop() || 'index'}`, checks: { path }, status: 'ok' }
		await page.goto(`${BASE}${path}`, { waitUntil: 'domcontentloaded' })
		await settle(page)
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
				if (el.closest('.iv-label-preview, .iv-label-print__sheet')) continue // documented QR canvas
				const cs = getComputedStyle(el)
				if (cs.display === 'none' || cs.visibility === 'hidden') continue
				const r = el.getBoundingClientRect()
				if (r.width < 40 || r.height < 24) continue
				const L = lum(cs.backgroundColor)
				if (L !== null && L > 200 && parseFloat(cs.backgroundColor.match(/rgba?\([^)]*\)/)?.[0].split(',')[3] || '1') !== 0) {
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
		}
		record(cell)
	}
	// Restore probe user to light for subsequent lanes.
	await page.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
	await setUserTheme(page, 'light')
	await ctx.close()
}

/* ───────────────────────────── main ───────────────────────────── */
const browser = await chromium.launch({ headless: true })
try {
	if (PHASE === 'sweep') await phaseSweep(browser)
	else if (PHASE === 'dialogs') await phaseDialogs(browser)
	else if (PHASE === 'states') await phaseStates(browser)
	else if (PHASE === 'theatre') await phaseTheatre(browser)
	else if (PHASE === 'all') { await phaseSweep(browser); await phaseDialogs(browser); await phaseStates(browser); await phaseTheatre(browser) }
} finally {
	results.finishedAt = new Date().toISOString()
	writeFileSync(join(OUT, `results-${PHASE}.json`), JSON.stringify(results, null, 1))
	await browser.close()
}
const fails = results.cells.filter((c) => c.status === 'fail')
const warns = results.cells.filter((c) => c.status === 'warn')
console.log(`\n== ${PHASE} done: ${results.cells.length} cells, ${fails.length} FAIL, ${warns.length} warn ==`)
for (const f of fails) console.log('FAIL:', f.id, JSON.stringify(f.failReasons || f.fails))
process.exit(fails.length ? 2 : 0)
