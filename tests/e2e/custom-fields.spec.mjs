// @ts-check
import { test, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ensureLoggedIn, credsFromEnv, openInventory } from './helpers/auth.mjs'

/**
 * IV-CUSTOM-FIELDS — admin journey: define a field, write a value through
 * the item API, see it in search + detail, and prove the CSV round-trip.
 */

async function api(page, method, path, body) {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token =
				(typeof window.OC !== 'undefined' && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
				|| ''
			const res = await fetch(path, {
				method,
				credentials: 'same-origin',
				headers: {
					'requesttoken': token,
					'OCS-APIREQUEST': 'true',
					...(body ? { 'Content-Type': 'application/json' } : {}),
				},
				body: body ? JSON.stringify(body) : undefined,
			})
			let data = null
			try { data = await res.json() } catch { /* csv/text */ }
			return { status: res.status, data, text: data === null ? await res.text() : null }
		},
		{ method, path, body },
	)
}

function expectOk(result, label = 'API') {
	expect(
		[200, 201, 204].includes(result.status),
		`${label} → ${result.status}: ${JSON.stringify(result.data)}`,
	).toBeTruthy()
}

const createdTags = []
function marker() {
	const tag = `cf${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
	createdTags.push(tag)
	return tag
}

const COMPOSE_CWD = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

function dbSql(sql) {
	try {
		return execFileSync('docker', [
			'compose', 'exec', '-T', 'mariadb', 'mariadb',
			'-u', 'nextcloud', '-pnextcloud_password', 'nextcloud', '-N', '-B', '-e', sql,
		], { cwd: COMPOSE_CWD, encoding: 'utf8', timeout: 60_000 })
	} catch { return '' }
}

test.afterAll(() => {
	for (const tag of createdTags) {
		const like = `%${tag}%`
		dbSql(
			`DELETE v FROM oc_iv_item_field_values v JOIN oc_iv_items i ON v.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE v FROM oc_iv_item_field_values v JOIN oc_iv_item_fields f ON v.field_id = f.id WHERE f.name LIKE '${like}';`
			+ `DELETE m FROM oc_iv_movements m JOIN oc_iv_items i ON m.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE b FROM oc_iv_balances b JOIN oc_iv_items i ON b.item_id = i.id WHERE i.sku LIKE '${like}';`
			+ `DELETE FROM oc_iv_items WHERE sku LIKE '${like}';`
			+ `DELETE FROM oc_iv_item_fields WHERE name LIKE '${like}';`,
		)
	}
})

test('IV-CUSTOM-FIELDS: def CRUD → item value → search → CSV round-trip', async ({ page }) => {
	test.skip(!credsFromEnv('ADMIN') && !credsFromEnv('E2E'), 'Requires NC_ADMIN_* or NC_E2E_*')

	await ensureLoggedIn(page, 'ADMIN')
	await openInventory(page)

	const tag = marker()
	const fieldName = `CF Note ${tag}`
	const def = await api(page, 'POST', '/index.php/apps/inventorycheck/api/fields', {
		name: fieldName, type: 'text',
	})
	expectOk(def, 'create field')
	const fieldId = def.data.id

	// Duplicate active name must conflict.
	const dup = await api(page, 'POST', '/index.php/apps/inventorycheck/api/fields', {
		name: fieldName.toUpperCase(), type: 'text',
	})
	expect([409, 422].includes(dup.status)).toBeTruthy()

	const sku = `CF-I-${tag}`.slice(0, 64)
	const value = `cfval-${tag}`
	const item = await api(page, 'POST', '/index.php/apps/inventorycheck/api/items', {
		sku,
		name: `CF Item ${tag}`,
		customValues: { [fieldId]: value },
	})
	expectOk(item, 'create item')

	// Search finds the text value (AC-9).
	const search = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items?q=${encodeURIComponent(value)}&limit=10`)
	expectOk(search, 'search')
	expect(search.data.data.length).toBe(1)

	// Detail carries the merged def+value.
	const detail = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items/${item.data.id}`)
	expectOk(detail, 'get item')
	const cf = detail.data.customFields.find((c) => c.id === fieldId)
	expect(cf).toBeTruthy()
	expect(cf.value).toBe(value)

	// Type is immutable (NN-01).
	const typeChange = await api(page, 'PUT', `/index.php/apps/inventorycheck/api/fields/${fieldId}`, {
		type: 'number',
	})
	expect(typeChange.status).toBe(422)

	// CSV export carries the field column; import maps it back.
	const exp = await page.evaluate(async () => {
		const res = await fetch('/index.php/apps/inventorycheck/api/export?kind=items&lang=en', {
			credentials: 'same-origin',
		})
		return { status: res.status, text: await res.text() }
	})
	expect(exp.status).toBe(200)
	expect(exp.text).toContain(fieldName)
	expect(exp.text).toContain(value)

	// Mutate the value, then reimport — round-trip restores it.
	const wipe = await api(page, 'PUT', `/index.php/apps/inventorycheck/api/items/${item.data.id}`, {
		customValues: { [fieldId]: 'changed' },
	})
	expectOk(wipe, 'clear value')
	const dry = await api(page, 'POST', '/index.php/apps/inventorycheck/api/import/dry-run', {
		csv: exp.text, lang: 'en',
	})
	expectOk(dry, 'import dry-run')
	expect(dry.data.errors.length).toBe(0)
	const commit = await api(page, 'POST', '/index.php/apps/inventorycheck/api/import/commit', {
		csv: exp.text, lang: 'en',
	})
	expectOk(commit, 'import commit')
	const after = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items/${item.data.id}`)
	expect(after.data.customFields.find((c) => c.id === fieldId).value).toBe(value)

	// Item detail page renders the section.
	await page.goto(`/index.php/apps/inventorycheck/items/${item.data.id}`)
	await page.waitForSelector('[data-iv-custom-fields]', { timeout: 15_000 })
	await expect(page.locator('[data-iv-custom-fields]')).toContainText(fieldName)
	await expect(page.locator('[data-iv-custom-fields]')).toContainText(value)

	// Dialog journey: edit the item, the custom-field control is under its
	// disclosure, and saving a new value sticks.
	await page.goto('/index.php/apps/inventorycheck/items')
	await page.getByRole('button', { name: 'Edit' }).first().waitFor({ timeout: 15_000 })
	// Find our row's edit affordance by the item name cell.
	const row = page.locator('tr', { hasText: `CF Item ${tag}` }).first()
	await row.getByRole('button', { name: 'Edit' }).click()
	const dlg = page.locator('.iv-dialog-overlay')
	await dlg.waitFor({ timeout: 10_000 })
	const cfDisclosure = dlg.locator('details.iv-item-cf')
	await expect(cfDisclosure).toBeVisible()
	const cfInput = dlg.locator(`[data-iv-field="cf_${fieldId}"] input`)
	await cfInput.fill(`dialog-${value}`)
	await dlg.getByRole('button', { name: 'Save' }).click()
	await page.waitForSelector('.iv-dialog-overlay', { state: 'detached', timeout: 10_000 })
	const finalGet = await api(page, 'GET', `/index.php/apps/inventorycheck/api/items/${item.data.id}`)
	expect(finalGet.data.customFields.find((c) => c.id === fieldId).value).toBe(`dialog-${value}`)
})
