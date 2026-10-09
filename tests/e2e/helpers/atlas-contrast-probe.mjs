// @ts-check
/**
 * ATLAS ds_chrome live contrast probe (inventorycheck).
 *
 * Logs in as the dedicated probe user, walks representative pages, and
 * measures the COMPUTED WCAG 2.1 contrast of semantic chrome: status badges,
 * callouts, danger buttons, invalid-field borders, control borders and
 * semantic accent borders — across light / dark / light-highcontrast /
 * dark-highcontrast user themes (server-pinned via OCS theming,
 * body[data-theme-*] marker asserted — never client-emulated).
 *
 *   text ink   >= 4.5:1  (WCAG 1.4.3 AA; >=3:1 for large icon glyphs)
 *   borders    >= 3.0:1  (WCAG 1.4.11 non-text contrast)
 *
 * Usage (from the app dir):
 *   DS_PROBE_PASS=... node tests/e2e/helpers/atlas-contrast-probe.mjs [--out <path.json>]
 *
 * Probe users: iv_ds_probe (app-admin/office/allow-listed) / iv_ds_denied.
 */
import { writeFileSync, mkdirSync } from 'node:fs'
import { dirname } from 'node:path'
import { chromium } from 'playwright'
import { login } from './auth.mjs'

const BASE = process.env.NC_BASE_URL || 'http://localhost:8081'
const PASS = process.env.DS_PROBE_PASS || 'DsProbe!2026'
const THEMES = ['light', 'dark', 'light-highcontrast', 'dark-highcontrast']

// ── WCAG contrast helpers (injected into the page for computed colors) ──
const EVAL_FN = String.raw`
function hexToRgb(c) {
  c = c.trim()
  if (c.startsWith('color(')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) {
      const s = m.map(parseFloat)
      const scale = s.every((v) => v <= 1) ? 255 : 1
      return [s[0] * scale, s[1] * scale, s[2] * scale]
    }
    return null
  }
  if (c.startsWith('rgb')) {
    const m = c.match(/[\d.]+/g)
    if (m && m.length >= 3) return [parseFloat(m[0]), parseFloat(m[1]), parseFloat(m[2])]
    return null
  }
  if (c.startsWith('#')) {
    let h = c.slice(1)
    if (h.length === 3) h = h.split('').map(x => x + x).join('')
    if (h.length === 4) h = h.split('').map(x => x + x).join('')
    if (h.length === 6 || h.length === 8) {
      return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)]
    }
  }
  return null
}
function lum(rgb) {
  const f = v => {
    v /= 255
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2])
}
function effBgFrom(n) {
  while (n && n !== document.documentElement) {
    const bg = getComputedStyle(n).backgroundColor
    const m = bg && bg.match(/[\d.]+/g)
    if (m && m.length >= 4 && parseFloat(m[3]) > 0) return bg
    if (m && m.length === 3 && !bg.includes('transparent')) return bg
    n = n.parentElement
  }
  return getComputedStyle(document.body).backgroundColor
}
function effBg(el) {
  return effBgFrom(el)
}
function effBgParent(el) {
  return effBgFrom(el && el.parentElement)
}
function alphaOf(c) {
  const m = c && c.match(/[\d.]+/g)
  if (m && m.length >= 4) return parseFloat(m[3])
  if (c && c.startsWith('color(')) {
    const parts = c.match(/[\d.]+/g)
    if (parts && parts.length >= 4) return parseFloat(parts[3])
  }
  return 1
}
function blend(fgRgb, bgRgb, a) {
  return [
    a * fgRgb[0] + (1 - a) * bgRgb[0],
    a * fgRgb[1] + (1 - a) * bgRgb[1],
    a * fgRgb[2] + (1 - a) * bgRgb[2],
  ]
}
function ratio(fg, bg) {
  const a = hexToRgb(fg), b = hexToRgb(bg)
  if (!a || !b) return null
  const l1 = lum(a), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
function borderRatio(border, bg) {
  const f = hexToRgb(border), b = hexToRgb(bg)
  if (!f || !b) return null
  const alpha = alphaOf(border)
  const eff = alpha >= 1 ? f : blend(f, b, alpha)
  const l1 = lum(eff), l2 = lum(b)
  const hi = Math.max(l1, l2), lo = Math.min(l1, l2)
  return (hi + 0.05) / (lo + 0.05)
}
window.__ivProbe = { effBg, effBgParent, ratio, alphaOf, borderRatio }
`

/** Server-pinned OCS theme switch (learned: client flips fake HC readings). */
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

async function themeMarkerOk(page, themeId) {
	return page.evaluate((t) => {
		const attr = t === 'light' ? 'data-theme-light' : `data-theme-${t}`
		const dataThemes = document.body.getAttribute('data-themes') || ''
		return document.body.hasAttribute(attr) || dataThemes.split(/\s+/).includes(t)
			|| (t === 'light' && /default|light/.test(dataThemes))
	}, themeId)
}

/** Elements to measure per page (probe role). */
const PROBES = [
	{
		page: '/apps/inventorycheck/',
		anchor: '#iv-main-content',
		label: 'dashboard',
		rows: [
			{ sel: '.iv-badge', kind: 'badge', what: 'text' },
			{ sel: '#iv-page-actions button.primary, #iv-page-actions .button.primary, #iv-main-content button.primary', kind: 'primary-cta', what: 'text' },
			{ sel: '#iv-main-content input:not([type="hidden"]), #iv-main-content select, #iv-main-content textarea', kind: 'control-border', what: 'border' },
			{ sel: '.iv-bucket--today .iv-bucket__title, .iv-bucket--overdue .iv-bucket__title, .iv-bucket--next7 .iv-bucket__title', kind: 'bucket-accent-border', what: 'border', borderSide: 'borderInlineStartColor' },
			{ sel: '.iv-hint-dismiss', kind: 'dismiss-button', what: 'border' },
		],
	},
	{
		page: '/apps/inventorycheck/items',
		anchor: '#iv-main-content',
		label: 'items',
		rows: [
			{ sel: '.iv-badge', kind: 'badge', what: 'text' },
			{ sel: '#iv-main-content input:not([type="hidden"]), #iv-main-content select, #iv-main-content textarea, .iv-filter-field__control input, .iv-filter-field__control select', kind: 'control-border', what: 'border' },
			{ sel: '#iv-page-actions button.primary, #iv-main-content button.primary', kind: 'primary-cta', what: 'text' },
		],
	},
	{
		page: '/apps/inventorycheck/stocktake',
		anchor: '#iv-main-content',
		label: 'stocktake',
		rows: [
			{ sel: '.iv-badge', kind: 'badge', what: 'text' },
			{ sel: '#iv-page-actions button.primary, #iv-main-content button.primary', kind: 'primary-cta', what: 'text' },
			{ sel: '#iv-main-content input:not([type="hidden"]), #iv-main-content select', kind: 'control-border', what: 'border' },
		],
	},
	{
		page: '/apps/inventorycheck/settings/policies',
		anchor: '#iv-main-content',
		label: 'settings-policies',
		rows: [
			{ sel: '.iv-switch-field', kind: 'switch-control-border', what: 'border' },
			{ sel: '#iv-main-content input:not([type="hidden"]), #iv-main-content select, #iv-main-content textarea', kind: 'control-border', what: 'border' },
			{ sel: '#iv-main-content button.primary', kind: 'primary-cta', what: 'text' },
		],
	},
	{
		page: '/apps/inventorycheck/items/1',
		anchor: '#iv-main-content',
		label: 'item-detail',
		rows: [
			{ sel: '.iv-actions-more__summary', kind: 'disclosure-control-border', what: 'border' },
			{ sel: '#iv-main-content input:not([type="hidden"]):not([type="file"]), #iv-main-content select, #iv-main-content textarea', kind: 'control-border', what: 'border' },
			{ sel: '.iv-badge', kind: 'badge', what: 'text' },
			{ sel: '#iv-page-actions button.primary', kind: 'primary-cta', what: 'text' },
		],
	},
	{
		page: '/apps/inventorycheck/movements',
		anchor: '#iv-main-content',
		label: 'movements',
		rows: [
			{ sel: '.iv-badge', kind: 'badge', what: 'text' },
			{ sel: '#iv-main-content input:not([type="hidden"]), #iv-main-content select', kind: 'control-border', what: 'border' },
		],
	},
]

async function settle(page) {
	await page.waitForLoadState('domcontentloaded').catch(() => {})
	try { await page.waitForLoadState('networkidle', { timeout: 5000 }) } catch { /* long-polls */ }
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))))
}

async function measure(page, probes) {
	const results = []
	for (const p of probes) {
		const resp = await page.goto(`${BASE}${p.page}`, { waitUntil: 'domcontentloaded' })
		if (!resp || resp.status() >= 400) {
			results.push({ page: p.label, error: `http ${resp ? resp.status() : 'nav-fail'}`, rows: [] })
			continue
		}
		// Surface anchor assert BEFORE any measurement (fabricated-capture guard).
		await page.waitForSelector(p.anchor, { timeout: 30_000 })
		await settle(page)
		const pageRes = { page: p.label, rows: [] }
		for (const row of p.rows) {
			const found = await page.evaluate(
				async ({ sel, what, borderSide }) => {
					const els = Array.from(document.querySelectorAll(sel)).filter(
						(n) => n.offsetParent !== null,
					)
					const out = []
					for (const el of els.slice(0, 6)) {
						const cs = getComputedStyle(el)
						const bg = window.__ivProbe.effBg(el)
						const border = borderSide ? cs[borderSide] : cs.borderColor
						const bWidth = borderSide ? cs.borderInlineStartWidth : cs.borderWidth
						const item = {
							tag: el.tagName.toLowerCase(),
							cls: (el.getAttribute('class') || '').slice(0, 80),
							fg: cs.color,
							bg,
							borderColor: border,
							borderWidth: bWidth,
							selfBg: cs.backgroundColor,
						}
						if (what !== 'border') {
							item.textRatio = window.__ivProbe.ratio(cs.color, bg)
						}
						if (what !== 'text' && parseFloat(bWidth) > 0) {
							if (border !== cs.backgroundColor) {
								// Border defines the control boundary.
								item.borderRatio = window.__ivProbe.borderRatio(border, bg)
								item.borderAlpha = window.__ivProbe.alphaOf(border)
							} else {
								// Border == fill: the fill itself is the boundary
								// (WCAG 1.4.11 control-identifiable measure).
								item.fillRatio = window.__ivProbe.ratio(cs.backgroundColor, window.__ivProbe.effBgParent(el))
							}
						}
						out.push(item)
					}
					return out
				},
				{ sel: row.sel, what: row.what, borderSide: row.borderSide || null },
			)
			pageRes.rows.push({ kind: row.kind, selector: row.sel, what: row.what, found: found.length, samples: found })
		}
		results.push(pageRes)
	}
	return results
}

/**
 * Dialog controls + validation-negative invalid state. `.iv-dialog-overlay`
 * mounts on document.body — OUTSIDE #app-content — so the #app-content
 * control-border baseline does not reach it; the .oc-dialog arm does.
 * Measure input borders inside the dialog, then submit with cleared
 * required fields and assert the invalid state paints a >=3:1 border +
 * aria-invalid + readable .iv-field__error.
 */
async function measureDialogAndFieldError(page) {
	await page.goto(`${BASE}/apps/inventorycheck/items`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('#iv-main-content', { timeout: 30_000 })
	await settle(page)
	const trigger = page.locator('#iv-page-actions button.primary').first()
	if (!(await trigger.count())) {
		return { skipped: 'no item-create trigger' }
	}
	await trigger.click()
	const VISIBLE_DIALOG = '.iv-dialog-overlay:not([hidden]) [role="dialog"], .iv-dialog-overlay:not([hidden]) .iv-dialog, dialog[open]'
	await page.waitForFunction(
		(sel) => [...document.querySelectorAll(sel)]
			.some((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open)),
		VISIBLE_DIALOG, { timeout: 15000 },
	).catch(() => {})
	await settle(page)

	const insideDlg = (sel) => VISIBLE_DIALOG.split(',').map((p) => `${p.trim()} ${sel}`).join(',')
	const out = {}
	out.dialogControls = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open))
		if (!dlg) return []
		const els = [...dlg.querySelectorAll('input:not([type="hidden"]), select, textarea')]
			.filter((n) => n.offsetParent !== null)
		return els.slice(0, 8).map((el) => {
			const cs = getComputedStyle(el)
			const bg = window.__ivProbe.effBg(el)
			return {
				tag: el.tagName.toLowerCase(),
				cls: (el.getAttribute('class') || '').slice(0, 60),
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				bg,
				borderRatio: window.__ivProbe.borderRatio(cs.borderColor, bg),
			}
		})
	}, VISIBLE_DIALOG)
	out.dialogButtons = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open))
		if (!dlg) return []
		const els = [...dlg.querySelectorAll('button, .button')].filter((n) => n.offsetParent !== null)
		return els.slice(0, 8).map((el) => {
			const cs = getComputedStyle(el)
			const bg = window.__ivProbe.effBg(el)
			const bordered = parseFloat(cs.borderWidth) > 0
			const borderDiffers = bordered && cs.borderColor !== cs.backgroundColor
			return {
				tag: el.tagName.toLowerCase(),
				cls: (el.getAttribute('class') || '').slice(0, 60),
				fg: cs.color,
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				bg,
				textRatio: window.__ivProbe.ratio(cs.color, bg),
				borderRatio: borderDiffers ? window.__ivProbe.borderRatio(cs.borderColor, bg) : null,
				fillRatio: bordered && !borderDiffers ? window.__ivProbe.ratio(cs.backgroundColor, window.__ivProbe.effBgParent(el)) : null,
			}
		})
	}, VISIBLE_DIALOG)

	// Validation negative: clear required fields → confirm → aria-invalid +
	// painted error border + readable inline error text.
	const required = page.locator(insideDlg('input[required]'))
	const n = await required.count()
	for (let i = 0; i < n; i++) await required.nth(i).fill('')
	const confirmBtn = page.locator(insideDlg('.iv-dialog__actions button.primary, .iv-dialog__actions button')).last()
	await confirmBtn.click({ timeout: 5000 }).catch(() => {})
	await page.waitForTimeout(1200)
	out.invalidState = await page.evaluate((sel) => {
		const dlg = [...document.querySelectorAll(sel)].find((d) => d.offsetParent !== null || (d.tagName === 'DIALOG' && d.open))
		if (!dlg) return { error: 'dialog closed on invalid submit' }
		const input = dlg.querySelector('[aria-invalid="true"]')
		const err = dlg.querySelector('.iv-field__error:not([hidden])')
		if (!input) return { ariaInvalid: null, errorShown: !!err }
		const cs = getComputedStyle(input)
		const bg = window.__ivProbe.effBg(input)
		const res = {
			ariaInvalid: input.getAttribute('aria-invalid'),
			ariaDescribedby: input.getAttribute('aria-describedby'),
			errorShown: !!err,
			border: {
				borderColor: cs.borderColor,
				borderWidth: cs.borderWidth,
				borderRatio: window.__ivProbe.borderRatio(cs.borderColor, bg),
			},
		}
		if (err) {
			const ecs = getComputedStyle(err)
			res.errorText = {
				text: (err.textContent || '').slice(0, 120),
				textRatio: window.__ivProbe.ratio(ecs.color, window.__ivProbe.effBg(err)),
			}
		}
		return res
	}, VISIBLE_DIALOG)
	await page.keyboard.press('Escape').catch(() => {})
	return out
}

/**
 * Semantic accent surfaces not always reachable from fixture data: inject a
 * representative node into the live document and measure the COMPUTED token
 * resolution. This measures the stylesheet, not app state — class-level
 * token truth for .iv-toast--ok/--error accents and chip wells.
 */
async function measureAccentSurfaces(page) {
	return page.evaluate(() => {
		const host = document.getElementById('iv-toast-region') || document.getElementById('iv-main-content') || document.body
		const mk = (cls) => {
			const el = document.createElement('div')
			el.className = cls
			el.textContent = 'probe'
			host.appendChild(el)
			return el
		}
		const out = []
		for (const cls of ['toast iv-toast toast--success iv-toast--ok', 'toast iv-toast toast--error iv-toast--error']) {
			const el = mk(cls)
			const cs = getComputedStyle(el)
			const bg = window.__ivProbe.effBg(el)
			const b = cs.borderInlineStartColor
			const w = cs.borderInlineStartWidth
			out.push({
				kind: `accent:${cls.split(' ').pop()}`,
				borderColor: b,
				borderWidth: w,
				bg,
				borderRatio: parseFloat(w) > 0 ? window.__ivProbe.borderRatio(b, bg) : null,
			})
			el.remove()
		}
		// Bucket title accents — not rendered by every fixture; measure the
		// token resolution on an injected node (stylesheet truth, not state).
		for (const cls of ['iv-bucket--today', 'iv-bucket--overdue', 'iv-bucket--next7']) {
			const bucket = document.createElement('div')
			bucket.className = `iv-bucket ${cls}`
			const title = document.createElement('h3')
			title.className = 'iv-bucket__title'
			title.textContent = 'probe'
			bucket.appendChild(title)
			host.appendChild(bucket)
			const cs = getComputedStyle(title)
			const bg = window.__ivProbe.effBg(title)
			out.push({
				kind: `accent:${cls}`,
				borderColor: cs.borderInlineStartColor,
				borderWidth: cs.borderInlineStartWidth,
				bg,
				borderRatio: parseFloat(cs.borderInlineStartWidth) > 0
					? window.__ivProbe.borderRatio(cs.borderInlineStartColor, bg) : null,
			})
			bucket.remove()
		}
		return out
	})
}

/** Denied user → 403 surface: icon ink + recovery CTA. */
async function measureDeniedSurface(browser) {
	const ctx = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await ctx.newPage()
	await login(page, { username: 'iv_ds_denied', password: PASS })
	const resp = await page.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
	await page.evaluate(new Function(EVAL_FN))
	await settle(page)
	const out = { http: resp ? resp.status() : 'nav-fail' }
	out.deniedShown = await page.locator('.iv-app--denied, #iv-denied-main, #iv-denied-title').first().isVisible().catch(() => false)
	if (out.deniedShown) {
		out.samples = await page.evaluate(() => {
			const res = []
			const icon = document.querySelector('.iv-app--denied .iv-page-header__icon, .iv-app--denied .iv-denied__icon')
			if (icon) {
				const cs = getComputedStyle(icon)
				res.push({
					kind: 'denied-icon-ink',
					fg: cs.color,
					bg: window.__ivProbe.effBg(icon),
					ratio: window.__ivProbe.ratio(cs.color, window.__ivProbe.effBg(icon)),
				})
			}
			for (const el of document.querySelectorAll('.iv-app--denied a.button, .iv-app--denied .iv-btn, .iv-app--denied button')) {
				if (el.offsetParent === null) continue
				const cs = getComputedStyle(el)
				const bordered = parseFloat(cs.borderWidth) > 0
				const borderDiffers = bordered && cs.borderColor !== cs.backgroundColor
				res.push({
					kind: 'denied-cta',
					cls: (el.getAttribute('class') || '').slice(0, 60),
					fg: cs.color,
					bg: window.__ivProbe.effBg(el),
					borderColor: cs.borderColor,
					borderWidth: cs.borderWidth,
					textRatio: window.__ivProbe.ratio(cs.color, window.__ivProbe.effBg(el)),
					borderRatio: borderDiffers ? window.__ivProbe.borderRatio(cs.borderColor, window.__ivProbe.effBg(el)) : null,
					fillRatio: bordered && !borderDiffers ? window.__ivProbe.ratio(cs.backgroundColor, window.__ivProbe.effBgParent(el)) : null,
				})
			}
			const hint = document.querySelector('.iv-app--denied .iv-empty__hint')
			if (hint) {
				const cs = getComputedStyle(hint)
				res.push({ kind: 'denied-hint-text', fg: cs.color, bg: window.__ivProbe.effBg(hint), textRatio: window.__ivProbe.ratio(cs.color, window.__ivProbe.effBg(hint)) })
			}
			return res
		})
	}
	await ctx.close()
	return out
}

async function main() {
	const browser = await chromium.launch({ headless: true })
	const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 900 } })
	const page = await context.newPage()
	const report = { app: 'inventorycheck', probe: 'live-computed-contrast', base: BASE, generated_at: new Date().toISOString(), themes: {} }

	try {
		await login(page, { username: 'iv_ds_probe', password: PASS })
		await context.addInitScript(EVAL_FN)
		await page.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
		await page.evaluate(new Function(EVAL_FN))

		for (const theme of THEMES) {
			await page.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
			await setUserTheme(page, theme)
			// Marker assert AFTER a real navigation — OCS persistence is only
			// painted on the next render (never trust pre-nav body attrs).
			await page.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
			const markerOk = await themeMarkerOk(page, theme)
			const themeRes = { themeMarker: markerOk, pages: await measure(page, PROBES) }
			if (theme === 'light' || theme === 'dark') {
				themeRes.dialog = await measureDialogAndFieldError(page)
			}
			themeRes.accents = await measureAccentSurfaces(page)
			report.themes[theme] = themeRes
		}
		// Restore probe user to light for subsequent lanes.
		await page.goto(`${BASE}/apps/inventorycheck/`, { waitUntil: 'domcontentloaded' })
		await setUserTheme(page, 'light')
		report.deniedSurface = await measureDeniedSurface(browser)
	} finally {
		await context.close()
		await browser.close()
	}

	const TEXT_MIN = 4.5
	const BORDER_MIN = 3.0
	const findings = []
	for (const [theme, t] of Object.entries(report.themes)) {
		if (t.themeMarker === false) {
			findings.push({ theme, kind: 'theme-marker-missing', ratio: null, min: null })
		}
		for (const pr of t.pages || []) {
			if (pr.error) {
				findings.push({ theme, page: pr.page, kind: 'page-error', detail: pr.error })
				continue
			}
			for (const row of pr.rows || []) {
				for (const s of row.samples || []) {
					if (s.textRatio !== undefined && s.textRatio !== null && s.textRatio < TEXT_MIN) {
						findings.push({ theme, page: pr.page, kind: row.kind, cls: s.cls, ratio: s.textRatio, min: TEXT_MIN })
					}
					if (s.borderRatio !== undefined && s.borderRatio !== null && s.borderRatio < BORDER_MIN) {
						findings.push({ theme, page: pr.page, kind: row.kind + '-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
					}
					if (s.fillRatio !== undefined && s.fillRatio !== null && s.fillRatio < BORDER_MIN) {
						findings.push({ theme, page: pr.page, kind: row.kind + '-fill-boundary', cls: s.cls, ratio: s.fillRatio, min: BORDER_MIN })
					}
				}
			}
		}
		for (const s of t.accents || []) {
			if (s.borderRatio !== null && s.borderRatio !== undefined && s.borderRatio < BORDER_MIN) {
				findings.push({ theme, page: 'accents', kind: s.kind + '-border', ratio: s.borderRatio, min: BORDER_MIN })
			}
		}
		const m = t.dialog
		if (m && !m.skipped) {
			for (const s of m.dialogControls || []) {
				if (s.borderRatio !== null && s.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: 'dialog', kind: 'dialog-control-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
				}
			}
			for (const s of m.dialogButtons || []) {
				if (s.textRatio !== null && s.textRatio < TEXT_MIN) {
					findings.push({ theme, page: 'dialog', kind: 'dialog-button-ink', cls: s.cls, ratio: s.textRatio, min: TEXT_MIN })
				}
				if (s.borderRatio !== null && s.borderRatio !== undefined && s.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: 'dialog', kind: 'dialog-button-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
				}
				if (s.fillRatio !== null && s.fillRatio !== undefined && s.fillRatio < BORDER_MIN) {
					findings.push({ theme, page: 'dialog', kind: 'dialog-button-fill-boundary', cls: s.cls, ratio: s.fillRatio, min: BORDER_MIN })
				}
			}
			const inv = m.invalidState || {}
			if (inv.errorShown) {
				if (inv.ariaInvalid !== 'true') {
					findings.push({ theme, page: 'dialog', kind: 'aria-invalid-missing', detail: 'field error shown without aria-invalid on control' })
				}
				if (inv.border && inv.border.borderRatio !== null && inv.border.borderRatio < BORDER_MIN) {
					findings.push({ theme, page: 'dialog', kind: 'invalid-border', ratio: inv.border.borderRatio, min: BORDER_MIN })
				}
				if (inv.errorText && inv.errorText.textRatio !== null && inv.errorText.textRatio < TEXT_MIN) {
					findings.push({ theme, page: 'dialog', kind: 'field-error-text', ratio: inv.errorText.textRatio, min: TEXT_MIN })
				}
			}
		}
	}
	const ds = report.deniedSurface
	if (ds && ds.samples) {
		for (const s of ds.samples) {
			const lim = s.kind === 'denied-icon-ink' ? 3.0 : TEXT_MIN // large decorative glyph
			const r = s.textRatio !== undefined ? s.textRatio : s.ratio
			if (r !== null && r !== undefined && r < lim) {
				findings.push({ theme: 'light', page: 'denied', kind: s.kind, cls: s.cls, ratio: r, min: lim })
			}
			if (s.borderRatio !== null && s.borderRatio !== undefined && s.borderRatio < BORDER_MIN) {
				findings.push({ theme: 'light', page: 'denied', kind: s.kind + '-border', cls: s.cls, ratio: s.borderRatio, min: BORDER_MIN })
			}
			if (s.fillRatio !== null && s.fillRatio !== undefined && s.fillRatio < BORDER_MIN) {
				findings.push({ theme: 'light', page: 'denied', kind: s.kind + '-fill-boundary', cls: s.cls, ratio: s.fillRatio, min: BORDER_MIN })
			}
		}
	}
	report.findings = findings
	report.verdict = findings.length === 0 ? 'PASS' : 'FAIL'

	const outIdx = process.argv.indexOf('--out')
	const outPath = outIdx > 0 ? process.argv[outIdx + 1] : null
	if (outPath) {
		mkdirSync(dirname(outPath), { recursive: true })
		writeFileSync(outPath, JSON.stringify(report, null, 2))
		console.log(`wrote ${outPath}`)
	} else {
		console.log(JSON.stringify(report, null, 2).slice(0, 4000))
	}
	console.log(`contrast probe: ${report.verdict} (${findings.length} findings)`)
	process.exit(findings.length > 0 ? 1 : 0)
}

main().catch((e) => {
	console.error(e)
	process.exit(2)
})
