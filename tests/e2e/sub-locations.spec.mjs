// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

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
