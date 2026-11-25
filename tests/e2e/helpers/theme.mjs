// @ts-check
import { expect } from '@playwright/test'

/**
 * Server-persisted Nextcloud theme switching for axe/screenshot matrices.
 * Extracted from theme-responsive-a11y.spec.mjs so feature specs can reuse
 * the exact same persist-and-prove mechanism.
 */

/** @typedef {'light'|'dark'|'dark-highcontrast'|'light-highcontrast'} ThemeId */

/** @type {{ id: ThemeId }[]} */
export const THEMES = [
	{ id: 'light' },
	{ id: 'dark' },
	{ id: 'dark-highcontrast' },
	{ id: 'light-highcontrast' },
]

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
export async function applyTheme(page, themeId) {
	const all = THEMES.map((t) => t.id)
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
