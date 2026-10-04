// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * Theme + viewport + WCAG 2.1 AA gauntlet for InventoryCheck.
 * Skips when neither storage-state nor NC_* / E2E_* credentials are available.
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const BASE = (process.env.E2E_BASE || process.env.BASE_URL || process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')

const a11yRoutes = [
	{ path: '/apps/inventorycheck/', ready: '#iv-page-title, .iv-section, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/items', ready: '#iv-page-title, #iv-items-filter-panel, .iv-filter-panel, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/locations', ready: '#iv-page-title, #iv-loc-filter-panel, .iv-filter-panel, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/movements', ready: '#iv-page-title, .iv-filter-panel, .iv-filterbar, .iv-empty', creds: 'E2E' },
	{ path: '/apps/inventorycheck/stocktake', ready: '#iv-page-title, .iv-section, .iv-empty, .iv-row', creds: 'E2E' },
	{ path: '/apps/inventorycheck/settings/access', ready: '#iv-page-title, #iv-access-title, .iv-section', creds: 'ADMIN' },
]

const viewports = [
	{ name: 'mobile-320', width: 320, height: 720 },
	{ name: 'mobile-375', width: 375, height: 812 },
	{ name: 'tablet-768', width: 768, height: 1024 },
	{ name: 'desktop-1024', width: 1024, height: 768 },
	{ name: 'desktop-1440', width: 1440, height: 900 },
	{ name: 'ultrawide-2560', width: 2560, height: 1440 },
]

/** @typedef {'light'|'dark'|'dark-highcontrast'|'light-highcontrast'} ThemeId */

/** @type {{ id: ThemeId }[]} */
const themes = [
	{ id: 'light' },
	{ id: 'dark' },
	{ id: 'dark-highcontrast' },
	{ id: 'light-highcontrast' },
]

function hasAnyCreds() {
	return !!(
		process.env.NC_ADMIN_USER
		|| process.env.NC_E2E_USER
		|| process.env.E2E_USER
		|| fs.existsSync(path.join(__dirname, '.auth', 'user.json'))
	)
}

/**
 * Persist a theme server-side via the OCS theming API, then verify the
 * *rendered* body attributes after a real navigation.
 *
 * Atlas learned class: client-side class/attribute fakes are forbidden —
 * a theme must survive a full document round-trip so the capture proves the
 * server-persisted user preference, not DOM cosmetics.
 *
 * @param {import('@playwright/test').Page} page
 * @param {ThemeId} themeId
 */
async function applyTheme(page, themeId) {
	const all = themes.map((t) => t.id)
	const failures = await page.evaluate(async ({ target, ids }) => {
		const token =
			(typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' }
		const problems = []
		for (const id of ids.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
				method: 'DELETE', credentials: 'same-origin', headers,
			})
			if (!res.ok && res.status !== 400) problems.push(`disable ${id}: HTTP ${res.status}`)
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, {
			method: 'PUT', credentials: 'same-origin', headers,
		})
		if (!res.ok && res.status !== 400) problems.push(`enable ${target}: HTTP ${res.status}`)
		return problems
	}, { target: themeId, ids: all })
	if (failures.length) {
		throw new Error(`OCS theme persist failed for ${themeId}: ${failures.join(';')}`)
	}
	// Re-navigate so the page re-renders from the persisted preference, then
	// assert the attribute the server actually emitted.
	await page.reload({ waitUntil: 'domcontentloaded' })
	const expectedAttr = themeId === 'light' ? 'data-theme-light' : `data-theme-${themeId}`
	const rendered = await page.evaluate((attr) => ({
		hasAttr: document.body.hasAttribute(attr),
		dataThemes: document.body.getAttribute('data-themes') || '',
	}), expectedAttr)
	expect(
		rendered.hasAttr || rendered.dataThemes.split(/\s+/).includes(themeId)
			|| (themeId === 'light' && /default|light/.test(rendered.dataThemes)),
		`post-navigation body theme attribute missing for ${themeId}: ${JSON.stringify(rendered)}`,
	).toBeTruthy()
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertNoHorizontalOverflow(page) {
	const overflow = await page.evaluate(() => {
		const root = document.querySelector('#app-content.iv-app') || document.body
		const main = document.getElementById('iv-main-content')
		const shell = document.querySelector('#app-content-wrapper.iv-shell, .iv-shell')
		const rootOverflow = root.scrollWidth > root.clientWidth + 2
		const mainOverflow = main ? main.scrollWidth > main.clientWidth + 2 : false
		const shellOverflow = shell ? shell.scrollWidth > shell.clientWidth + 2 : false
		const docOverflow = document.documentElement.scrollWidth > window.innerWidth + 2
		return {
			rootOverflow,
			mainOverflow,
			shellOverflow,
			docOverflow,
			scrollWidth: root.scrollWidth,
			clientWidth: root.clientWidth,
			shellScroll: shell ? shell.scrollWidth : null,
			shellClient: shell ? shell.clientWidth : null,
			innerWidth: window.innerWidth,
		}
	})
	expect(overflow, JSON.stringify(overflow)).toMatchObject({
		rootOverflow: false,
		mainOverflow: false,
		shellOverflow: false,
		docOverflow: false,
	})
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertThemeTokensResolved(page) {
	const tokens = await page.evaluate(() => {
		const el = document.querySelector('#app-content.iv-app') || document.body
		const cs = getComputedStyle(el)
		return {
			bg: cs.getPropertyValue('--iv-bg-card').trim() || cs.getPropertyValue('--color-main-background').trim(),
			text: cs.getPropertyValue('--iv-text').trim() || cs.getPropertyValue('--color-main-text').trim(),
			primary: cs.getPropertyValue('--color-primary-element').trim(),
			muted: cs.getPropertyValue('--iv-muted').trim() || cs.getPropertyValue('--color-text-maxcontrast').trim(),
			tintInfo: cs.getPropertyValue('--iv-tint-info').trim(),
			scrim: cs.getPropertyValue('--iv-scrim').trim(),
			touch: cs.getPropertyValue('--iv-touch').trim(),
			accent: cs.getPropertyValue('--iv-accent').trim(),
		}
	})
	expect(tokens.bg, 'theme background token').not.toEqual('')
	expect(tokens.text, 'theme text token').not.toEqual('')
	expect(tokens.primary, 'primary element token').not.toEqual('')
	expect(tokens.tintInfo, 'tint-info must resolve (mixed into main-background)').not.toEqual('')
	expect(
		tokens.tintInfo.includes('transparent') && /,\s*0%\)\s*$/.test(tokens.tintInfo),
		'tint must not be fully transparent',
	).toBeFalsy()
	expect(tokens.scrim, 'scrim token').not.toEqual('')
	expect(tokens.accent, 'accent alias').not.toEqual('')
	expect(tokens.touch === '44px' || parseFloat(tokens.touch) >= 44, 'touch target token').toBeTruthy()
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertTouchTargets(page) {
	const result = await page.evaluate(() => {
		const nodes = [
			...document.querySelectorAll(
				'#app-content.iv-app .iv-page-header__actions .iv-btn, #app-content.iv-app .iv-page-header__actions .button, #app-content.iv-app .iv-toolbar .iv-btn, #app-content.iv-app .iv-toolbar .button, #app-navigation .iv-nav__link, #app-navigation a.app-navigation-entry-link',
			),
		].slice(0, 40)
		const undersized = []
		for (const el of nodes) {
			const style = getComputedStyle(el)
			if (style.display === 'none' || style.visibility === 'hidden') {
				continue
			}
			const rect = el.getBoundingClientRect()
			if (rect.width === 0 && rect.height === 0) {
				continue
			}
			const minH = Math.max(rect.height, parseFloat(style.minHeight) || 0)
			const minW = Math.max(rect.width, parseFloat(style.minWidth) || 0)
			const isBar = rect.width >= 120
			const heightOk = minH >= 44
			const widthOk = isBar || minW >= 40
			if (!heightOk || !widthOk) {
				undersized.push({
					tag: el.tagName,
					cls: String(el.className).slice(0, 80),
					w: Math.round(minW),
					h: Math.round(minH),
				})
			}
		}
		return { ok: undersized.length === 0, undersized }
	})
	expect(result.ok, JSON.stringify(result.undersized)).toBeTruthy()
}

test.describe('InventoryCheck theme × a11y matrix', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!hasAnyCreds(), 'Requires NC_* / E2E_* credentials or .auth/user.json')
		await ensureLoggedIn(page, 'E2E')
	})

	for (const theme of themes) {
		test(`axe WCAG 2.1 AA on dashboard @ ${theme.id}`, async ({ page }) => {
			await openInventory(page, '/apps/inventorycheck/')
			await applyTheme(page, theme.id)
			await assertThemeTokensResolved(page)
			await page.locator('#iv-toast-region .iv-toast, .toastify').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
			const results = await new AxeBuilder({ page })
				.include('#app-content.iv-app')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.exclude('#iv-toast-region')
				.exclude('.toastify')
				.analyze()
			expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
		})
	}

	// Learned class: pixel-identical "dark" captures once passed a farm —
	// byte-compare server-persisted theme renders so identical output fails.
	test('pixel-diff: light and dark renders differ on dashboard', async ({ page }) => {
		const { createHash } = await import('node:crypto')
		const shots = {}
		for (const themeId of ['light', 'dark']) {
			await openInventory(page, '/apps/inventorycheck/')
			await applyTheme(page, themeId)
			shots[themeId] = await page.locator('#app-content.iv-app').screenshot()
		}
		const sha = (b) => createHash('sha256').update(b).digest('hex').slice(0, 16)
		expect(
			sha(shots.light),
			`light and dark captures are pixel-identical (sha ${sha(shots.light)}) — theme not applied`,
		).not.toEqual(sha(shots.dark))
		// And the dark render must not be the light render with a filter only:
		// resolved main background must actually be dark in the dark theme.
		const bg = await page.evaluate(() => {
			const el = document.querySelector('#app-content.iv-app') || document.body
			return getComputedStyle(el).backgroundColor
		})
		const m = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
		expect(m, `unparseable background ${bg}`).toBeTruthy()
		const lum = 0.2126 * Number(m[1]) + 0.7152 * Number(m[2]) + 0.0722 * Number(m[3])
		expect(lum, `dark theme background luminance ${lum} (bg ${bg})`).toBeLessThan(128)
	})
})

test.describe('InventoryCheck route a11y smoke', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!hasAnyCreds(), 'Requires NC_* / E2E_* credentials or .auth/user.json')
	})

	for (const route of a11yRoutes) {
		test(`a11y smoke: ${route.path}`, async ({ page }) => {
			test.skip(!credsFromEnv(route.creds) && !credsFromEnv('E2E'), `Requires NC_${route.creds}_*`)
			await ensureLoggedIn(page, route.creds)
			await openInventory(page, route.path)
			await expect(page.locator(route.ready).first()).toBeVisible({ timeout: 30_000 })
			await page.locator('#iv-toast-region .iv-toast, .toastify').evaluateAll((nodes) => nodes.forEach((n) => n.remove())).catch(() => {})
			const results = await new AxeBuilder({ page })
				.include('#app-content.iv-app')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.exclude('#iv-toast-region')
				.exclude('.toastify')
				.analyze()
			expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([])
		})
	}
})

const overflowRoutes = [
	'/apps/inventorycheck/',
	'/apps/inventorycheck/items',
	'/apps/inventorycheck/movements',
]

test.describe('InventoryCheck responsive overflow matrix', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!hasAnyCreds(), 'Requires NC_* / E2E_* credentials or .auth/user.json')
		await ensureLoggedIn(page, 'E2E')
	})

	for (const vp of viewports) {
		for (const route of overflowRoutes) {
			test(`no horizontal overflow @ ${vp.name} ${route}`, async ({ page }) => {
				await page.setViewportSize({ width: vp.width, height: vp.height })
				await openInventory(page, route)
				await expect(page.locator('.iv-page-header').first()).toBeVisible()
				await expect(page.locator('a.iv-skip-link')).toBeAttached()
				await assertNoHorizontalOverflow(page)
				await assertTouchTargets(page)
			})
		}
	}
})

test.describe('InventoryCheck visual shell metrics', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!hasAnyCreds(), 'Requires NC_* / E2E_* credentials or .auth/user.json')
		await ensureLoggedIn(page, 'E2E')
	})

	const snapViewports = [
		{ name: 'mobile-375', width: 375, height: 812 },
		{ name: 'tablet-768', width: 768, height: 1024 },
		{ name: 'desktop-1280', width: 1280, height: 800 },
	]

	for (const theme of [{ id: /** @type {ThemeId} */ ('light') }, { id: /** @type {ThemeId} */ ('dark') }]) {
		for (const vp of snapViewports) {
			test(`shell metrics @ ${theme.id} ${vp.name}`, async ({ page }) => {
				await page.setViewportSize({ width: vp.width, height: vp.height })
				await openInventory(page, '/apps/inventorycheck/')
				await applyTheme(page, theme.id)
				await assertThemeTokensResolved(page)
				await assertNoHorizontalOverflow(page)

				const metrics = await page.evaluate(() => {
					const header = document.querySelector('.iv-page-header')
					const main = document.getElementById('iv-main-content')
					const hRect = header ? header.getBoundingClientRect() : null
					const mRect = main ? main.getBoundingClientRect() : null
					return {
						headerVisible: !!(hRect && hRect.height > 0),
						mainVisible: !!(mRect && mRect.height >= 0),
						headerWidth: hRect ? Math.round(hRect.width) : 0,
						viewport: window.innerWidth,
						headerFits: hRect ? hRect.width <= window.innerWidth + 1 : false,
					}
				})
				expect(metrics.headerVisible).toBeTruthy()
				expect(metrics.mainVisible).toBeTruthy()
				expect(metrics.headerFits, JSON.stringify(metrics)).toBeTruthy()
			})
		}
	}
})

test.describe('InventoryCheck keyboard chrome', () => {
	test('skip link lands on main', async ({ page }) => {
		test.skip(!hasAnyCreds(), 'Requires NC_* / E2E_* credentials or .auth/user.json')
		await ensureLoggedIn(page, 'E2E')
		await page.setViewportSize({ width: 1280, height: 800 })
		await openInventory(page, '/apps/inventorycheck/')
		await page.locator('a.iv-skip-link').focus()
		await page.keyboard.press('Enter')
		const focused = await page.evaluate(() => document.activeElement && document.activeElement.id)
		expect(focused).toBe('iv-main-content')
	})
})

test.describe('InventoryCheck custom accent adaptation', () => {
	test('primary token follows custom accent override', async ({ page }) => {
		test.skip(!hasAnyCreds(), 'Requires NC_* / E2E_* credentials or .auth/user.json')
		await ensureLoggedIn(page, 'E2E')
		await openInventory(page, '/apps/inventorycheck/')
		await page.evaluate(() => {
			document.body.style.setProperty('--color-primary-element', '#c45c26')
			document.body.style.setProperty('--color-primary-element-text', '#ffffff')
			document.body.style.setProperty('--color-primary-element-hover', '#a34b1f')
		})
		await page.waitForTimeout(100)
		const resolved = await page.evaluate(() => {
			const el = document.querySelector('#app-content.iv-app')
			const cs = getComputedStyle(el || document.body)
			return {
				primary: cs.getPropertyValue('--color-primary-element').trim(),
				accent: cs.getPropertyValue('--iv-accent').trim(),
				tint: cs.getPropertyValue('--iv-tint-info').trim(),
			}
		})
		expect(resolved.primary.toLowerCase()).toContain('c45c26')
		expect(resolved.accent.toLowerCase()).toContain('c45c26')
		expect(resolved.tint, 'tint-info recomputes from accent').not.toEqual('')
	})
})
