// @ts-check
/**
 * ATLAS_DEAD_TOKEN_REFS — learned-regression probe for
 * dead_token_orphan_refs_post_token_sync (ivc vis-r31 must_fix
 * ivc-r31-danger-hover-dead + ivc-r31-badge-info-ink-orphan).
 *
 * The 2026-11-21 check-shell tokens sync removed --iv-danger-fill-hover,
 * --iv-info-ink, --iv-danger-border, --iv-press and --iv-fs-base while
 * consumers kept `var(--dead, fallback)` first-arg refs. The hover fallback
 * resolved to the identical resting fill → ZERO hover feedback on every
 * destructive CTA in light themes and a hover-BRIGHTENING on dark (inverse
 * of the family darken convention). The scheduled-badge ink fell to raw
 * --color-primary-element (~4.3:1 on --iv-tint-info dark → sub-AA).
 *
 * Probes are injected, same pattern as atlas-btn-variant-armor — real
 * .iv-btn--danger emitters are scarce and scheduled badges need movement
 * fixtures. Injection sits inside #iv-main-content (the #app-content.iv-app
 * token scope) so themed token legs resolve exactly like production use.
 *
 *   - light + dark: danger hover → computed filter == brightness(0.92) and
 *     the element bitmap changes vs rest (donor mechanism; core sets no
 *     filter so it survives the !important armor)
 *   - light + dark: .iv-badge--scheduled ink on --iv-tint-info → WCAG ratio
 *     >= 4.5:1 (dark carried the sub-AA regression)
 *   - dark-theme craft PNGs → .cursor/atlas-farm-v3/craft/web/ (skipped
 *     silently outside the farm checkout)
 */
import { test, expect } from '@playwright/test'
import { existsSync, mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, openInventory } from './helpers/auth.mjs'

const THEMES = ['light', 'dark', 'dark-highcontrast', 'light-highcontrast']
const CRAFT_DIR = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../../../../.cursor/atlas-farm-v3/craft/web',
)

/** Server-pinned OCS theme switch (same mechanism as the armor spec — a
 *  client flip fakes HC readings). Restores `light` at test end. */
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

async function injectProbes(page) {
	await page.evaluate(() => {
		document.querySelectorAll('.iv-deadref-probe').forEach((el) => el.remove())
		const host = document.getElementById('iv-main-content')
			|| document.getElementById('app-content')
		if (!host) throw new Error('no probe host')
		const btn = document.createElement('button')
		btn.type = 'button'
		btn.className = 'iv-btn iv-btn--danger iv-deadref-probe iv-deadref-probe--danger'
		btn.textContent = 'probe'
		host.appendChild(btn)
		const badge = document.createElement('span')
		badge.className = 'iv-badge iv-badge--scheduled iv-deadref-probe iv-deadref-probe--badge'
		badge.textContent = 'probe'
		host.appendChild(badge)
	})
}

/** WCAG 2.1 contrast ratio of the badge's computed ink vs its computed fill. */
async function badgeContrast(page) {
	return page.locator('.iv-deadref-probe--badge').evaluate((el) => {
		const toRgb = (c) => {
			const m = String(c).match(/[\d.]+/g)
			if (!m || m.length < 3) return null
			const s = m.map(parseFloat)
			return String(c).startsWith('color(') && s.every((v) => v <= 1)
				? s.slice(0, 3).map((v) => v * 255) : s.slice(0, 3)
		}
		const lum = (rgb) => {
			const f = (v) => {
				v /= 255
				return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
			}
			return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2])
		}
		const cs = getComputedStyle(el)
		const ink = toRgb(cs.color)
		const bg = toRgb(cs.backgroundColor)
		if (!ink || !bg) return { error: `unparsed color=${cs.color} bg=${cs.backgroundColor}` }
		const l1 = lum(ink); const l2 = lum(bg)
		return {
			ink: cs.color, bg: cs.backgroundColor,
			ratio: (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05),
		}
	})
}

async function shot(target, name) {
	if (!existsSync(CRAFT_DIR)) return
	mkdirSync(CRAFT_DIR, { recursive: true })
	await target.screenshot({ path: resolve(CRAFT_DIR, name) })
}

test.describe('Dead token refs — hover + badge ink regression', () => {
	test('ATLAS_DEAD_TOKEN_REFS danger hover darkens; scheduled badge ink >= 4.5:1', async ({ page }) => {
		test.setTimeout(240_000)
		await ensureLoggedIn(page, 'E2E')
		try {
			for (const theme of ['light', 'dark']) {
				await openInventory(page, '/apps/inventorycheck/items')
				await setUserTheme(page, theme)
				await openInventory(page, '/apps/inventorycheck/items')
				await page.waitForSelector('#app-content.iv-app', { timeout: 30_000 })
				await injectProbes(page)

				// ── Danger hover: donor filter mechanism, bitmap must change ──
				const probe = page.locator('.iv-deadref-probe--danger')
				await page.mouse.move(4, 4)
				const restFilter = await probe.evaluate((el) => getComputedStyle(el).filter)
				const restShot = await probe.screenshot()
				await probe.hover()
				await page.waitForTimeout(300) // outlast .iv-btn transition (.15s)
				const hovFilter = await probe.evaluate((el) => getComputedStyle(el).filter)
				const hovShot = await probe.screenshot()
				expect(restFilter, `${theme}: resting danger button must carry no filter`).toBe('none')
				expect(hovFilter, `${theme}: danger hover must darken via filter: brightness(0.92)`)
					.toBe('brightness(0.92)')
				expect(Buffer.compare(restShot, hovShot) !== 0,
					`${theme}: danger hover must repaint (bitmap differs from rest)`).toBe(true)

				// ── Scheduled badge ink on tint ≥ 4.5:1 (dark was sub-AA) ──
				const badge = await badgeContrast(page)
				expect(badge.error, `${theme}: badge colors must parse`).toBeUndefined()
				expect(badge.ratio,
					`${theme}: .iv-badge--scheduled ink ${badge.ink} on ${badge.bg} must be ≥4.5:1`)
					.toBeGreaterThanOrEqual(4.5)

				if (theme === 'dark') {
					await shot(probe, 'ivc-r31-deadref-danger-hover-dark.png')
					await shot(page.locator('.iv-deadref-probe--badge'), 'ivc-r31-deadref-badge-scheduled-dark.png')
				}
			}
		} finally {
			await setUserTheme(page, 'light').catch(() => {})
		}
	})
})
