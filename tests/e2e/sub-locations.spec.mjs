// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'
import AxeBuilder from '@axe-core/playwright'
import { applyTheme } from './helpers/theme.mjs'
import { tabUntil, activateByKeyboard } from './helpers/keyboard.mjs'

/**
 * IV-SUB-LOCATIONS — the audit journey: a warehouse + shelf child get a
 * server-computed pathLabel on the API and in the rendered list, the
 * depth cap rejects a grandchild at 422, the parent refuses delete with
 * location_has_children (409), and reparenting re-labels the path.
 */

async function api(page, method, path, body) {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token = (document.querySelector('input[name="requesttoken"]'))
				?.getAttribute('value')
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

const createdTags = []
const COMPOSE_CWD = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

test.afterAll(() => {
	for (const tag of createdTags) {
		try {
			execFileSync('docker', [
				'compose', 'exec', '-T', 'mariadb', 'mariadb',
				'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-B', '-e',
				`DELETE FROM oc_iv_locations WHERE code LIKE '%${tag}%' OR name LIKE '%${tag}%';`,
			], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
		} catch {
			// best-effort cleanup
		}
	}
})

test('IV-SUB-LOCATIONS: pathLabel, depth cap, delete refusal, reparent', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = `sl${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`
	createdTags.push(tag)

	const parent = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `SL-P-${tag}`, name: `Wh ${tag}`, kind: 'warehouse',
	})
	expect([200, 201]).toContain(parent.status)
	const parentId = parent.data.id
	expect(parent.data.parentId).toBeNull()
	expect(parent.data.pathLabel).toBe(`Wh ${tag}`)

	const child = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `SL-C-${tag}`, name: `Shelf ${tag}`, kind: 'shelf', parentId,
	})
	expect([200, 201]).toContain(child.status)
	const childId = child.data.id
	expect(child.data.parentId).toBe(parentId)
	expect(child.data.pathLabel).toBe(`Wh ${tag} / Shelf ${tag}`)

	// NN-01 depth cap: a child cannot have a child.
	const grandchild = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `SL-G-${tag}`, name: `Bin ${tag}`, kind: 'shelf', parentId: childId,
	})
	expect(grandchild.status).toBe(422)
	expect(grandchild.data?.error?.code ?? grandchild.data?.error).toMatch(/validation_failed/)

	// NN-04: parent with children refuses delete.
	const del = await api(page, 'DELETE', `/index.php/apps/inventorycheck/api/locations/${parentId}`)
	expect(del.status).toBe(409)
	expect(del.data?.error?.code ?? del.data?.error).toMatch(/location_has_children/)

	// Roots picker: parent present, child excluded.
	const roots = await api(page, 'GET', '/index.php/apps/inventorycheck/api/locations?roots=1')
	expect(roots.status).toBe(200)
	const rootIds = (roots.data.data ?? []).map((r) => r.id)
	expect(rootIds).toContain(parentId)
	expect(rootIds).not.toContain(childId)

	// Reparent: child moves to a second root, pathLabel recomputes.
	const other = await api(page, 'POST', '/index.php/apps/inventorycheck/api/locations', {
		code: `SL-O-${tag}`, name: `Annex ${tag}`, kind: 'warehouse',
	})
	const reparented = await api(page, 'PUT', `/index.php/apps/inventorycheck/api/locations/${childId}`, {
		parentId: other.data.id,
	})
	expect(reparented.status).toBe(200)
	expect(reparented.data.pathLabel).toBe(`Annex ${tag} / Shelf ${tag}`)

	// Rendered list shows the parent prefix for the child row.
	await openInventory(page, '/apps/inventorycheck/locations')
	const childRow = page.locator(`text=${`Wh ${tag} / Shelf ${tag}`}`).or(
		page.locator(`text=${`Annex ${tag} / Shelf ${tag}`}`),
	)
	await expect(childRow.first()).toBeVisible({ timeout: 10_000 })
})

test.describe('IV-SUB-LOCATIONS UX proof', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')
		await ensureLoggedIn(page, 'ADMIN')
	})

	for (const themeId of ['light', 'dark', 'dark-highcontrast']) {
		test(`axe WCAG 2.1 AA on locations + create dialog @ ${themeId}`, async ({ page }) => {
			await openInventory(page, '/apps/inventorycheck/locations')
			await applyTheme(page, /** @type {'light'|'dark'|'dark-highcontrast'} */ (themeId))
			await expect(page.locator('#iv-page-title, .iv-table, .iv-empty').first()).toBeVisible({ timeout: 15_000 })
			const pageResults = await new AxeBuilder({ page })
				.include('#app-content.iv-app')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.exclude('.toastify')
				.analyze()
			expect(pageResults.violations, `page @ ${themeId}: ${JSON.stringify(pageResults.violations, null, 2)}`).toEqual([])

			const newBtn = page.locator('[data-iv-action="location-new"]').first()
			await expect(newBtn).toBeVisible({ timeout: 15_000 })
			await activateByKeyboard(newBtn, page)
			const dlg = page.locator('.iv-dialog')
			await expect(dlg).toBeVisible({ timeout: 10_000 })
			const dlgResults = await new AxeBuilder({ page })
				.include('.iv-dialog')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.exclude('.toastify')
				.analyze()
			expect(dlgResults.violations, `dialog @ ${themeId}: ${JSON.stringify(dlgResults.violations, null, 2)}`).toEqual([])
			await page.keyboard.press('Escape')
			await expect(dlg).toBeHidden()
		})
	}

	test('keyboard-only create-parent+child journey (Tab/Enter only)', async ({ page }) => {
		await openInventory(page)
		const tag = `kb${Date.now().toString(36)}`
		createdTags.push(tag)

		await openInventory(page, '/apps/inventorycheck/locations')
		const newBtn = page.locator('[data-iv-action="location-new"]').first()
		await expect(newBtn).toBeVisible({ timeout: 15_000 })
		await activateByKeyboard(newBtn, page)
		const dlg = page.locator('.iv-dialog')
		await expect(dlg).toBeVisible({ timeout: 10_000 })

		// Parent: code + name via typing, parent select left at "None".
		await tabUntil(page, { sel: '[data-iv-field="code"] input' })
		await page.keyboard.type(`SLKBP-${tag}`)
		await tabUntil(page, { sel: '[data-iv-field="name"] input' })
		await page.keyboard.type(`KBP ${tag}`)
		await tabUntil(page, { sel: '[data-iv-action="dialog-confirm"]' })
		await page.keyboard.press('Enter')
		await expect(dlg).toBeHidden({ timeout: 15_000 })

		const parent = await api(page, 'GET', `/index.php/apps/inventorycheck/api/locations?roots=1`)
		const parentRow = (parent.data.data || []).find((r) => r.code === `SLKBP-${tag}`)
		expect(parentRow, `parent row after keyboard create: ${JSON.stringify(parent.data).slice(0, 400)}`).toBeTruthy()
		const parentId = parentRow.id

		// Child: same keyboard path, parent select driven by ArrowDown.
		await activateByKeyboard(newBtn, page)
		const dlg2 = page.locator('.iv-dialog')
		await expect(dlg2).toBeVisible({ timeout: 10_000 })
		await tabUntil(page, { sel: '[data-iv-field="code"] input' })
		await page.keyboard.type(`SLKBC-${tag}`)
		await tabUntil(page, { sel: '[data-iv-field="name"] input' })
		await page.keyboard.type(`KBC ${tag}`)

		await tabUntil(page, { sel: '[data-iv-field="parentId"] select' })
		// The roots list loads async — wait for the parent option to exist.
		const parentSel = page.locator('[data-iv-field="parentId"] select')
		await expect(parentSel.locator('option').filter({ hasText: tag }).first()).toBeAttached({ timeout: 10_000 })
		// Native select typeahead/arrows are the keyboard path — no pointer.
		for (let i = 0; i < 80; i++) {
			const selText = await page.evaluate(() => {
				const s = document.activeElement
				return s && s.tagName === 'SELECT' ? (s.options[s.selectedIndex]?.textContent || '') : ''
			})
			if (selText.includes(tag)) break
			await page.keyboard.press('ArrowDown')
		}
		const chosen = await parentSel.evaluate((s) => s.options[s.selectedIndex]?.textContent || '')
		expect(chosen, 'parent select never reached the tagged option via ArrowDown').toContain(tag)

		await tabUntil(page, { sel: '[data-iv-action="dialog-confirm"]' })
		await page.keyboard.press('Enter')
		await expect(dlg2).toBeHidden({ timeout: 15_000 })

		// Server proof: child persisted with the parent link + pathLabel.
		const child = await api(page, 'GET', `/index.php/apps/inventorycheck/api/locations?q=${encodeURIComponent(`SLKBC-${tag}`)}`)
		const childRow = (child.data.data || []).find((r) => r.code === `SLKBC-${tag}`)
		expect(childRow, `child row after keyboard create`).toBeTruthy()
		expect(childRow.parentId).toBe(parentId)
		expect(childRow.pathLabel).toBe(`KBP ${tag} / KBC ${tag}`)
	})

	test('desktop ≥1024 layout identity: flat table, no card reflow, no overflow', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-1280', 'desktop leg runs at 1280')
		await openInventory(page, '/apps/inventorycheck/locations')
		await expect(page.locator('#iv-page-title, .iv-table, .iv-empty').first()).toBeVisible({ timeout: 15_000 })
		await page.setViewportSize({ width: 1280, height: 800 })

		// The desktop layout is sacred — sub-locations may only indent the name
		// cell, never reflow the list into cards or add columns. Structural
		// assertion: the standard .iv-table renders (not --cards), headers stay
		// the pre-feature column set (name/code/kind + actions).
		const table = page.locator('#app-content.iv-app table.iv-table').first()
		await expect(table).toBeVisible({ timeout: 15_000 })
		await expect(table).not.toHaveClass(/iv-table--cards/)
		const headerCount = await table.locator('thead th').count()
		expect(headerCount, `desktop column count drifted: ${headerCount}`).toBeLessThanOrEqual(4)

		// No horizontal scroll at the desktop widths the spec protects.
		for (const w of [1024, 1280]) {
			await page.setViewportSize({ width: w, height: 800 })
			const overflow = await page.evaluate(() => {
				const root = document.querySelector('#app-content.iv-app') || document.body
				return root.scrollWidth > root.clientWidth + 2
					|| document.documentElement.scrollWidth > window.innerWidth + 2
			})
			expect(overflow, `horizontal overflow at ${w}`).toBe(false)
		}
		await page.setViewportSize({ width: 1280, height: 800 })
	})
})
