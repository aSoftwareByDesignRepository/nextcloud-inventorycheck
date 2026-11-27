// @ts-check
/**
 * Atlas POLICY ≥3.5.10 — executed click-path legs for the agent-auth
 * settings surface (/settings/agent). Closes ivc-r57-mf2/mf3
 * (rendered_surface_contract_gap + dialog_matrix_proof_gap +
 * ui_matrix_surface_inventory_gap): the pack ships three native <dialog>
 * surfaces (mint / secret / shared revoke-confirm incl. webhook rows) that
 * had zero rendered interaction proof, and a JS-driven webhook-endpoints
 * table with no row coverage.
 *
 * Legs: surface render + webhook section, mint open→cancel→Escape (zero
 * POST), mint validation field-errors, choice-card chrome, mint→201→secret
 * dialog→rebuildTable row injection, rotate durable postcondition, revoke
 * open→cancel→confirm durable postcondition, self-mint policy toggle
 * persistence, empty-state + first-mint pendingReload (hold-table surgery —
 * foreign rows snapshotted, emptied, restored verbatim), webhook create→
 * Test→Revoke row lifecycle, 320px card transform.
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'
import { mkdirSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { credsFromEnv, login } from './helpers/auth.mjs'

const AGENT_URL = '/apps/inventorycheck/settings/agent'
const APP = 'inventorycheck'
const CRAFT_DIR = resolve(
	dirname(fileURLToPath(import.meta.url)),
	'../../../../../.cursor/atlas-farm-v3/artifacts/inventorycheck/craft/web',
)
// Unique label per run — parallel lanes must never share a fixture row.
const RUN = `atlas-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`
const HOLD_TABLE = 'oc_iv_agent_tokens_atlashold'
const SEAT_TAG = 'ivagent-spec'

function craftStamp() {
	return new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z')
}

/** @param {import('@playwright/test').Page} page @param {string} slug */
async function craftShot(page, slug) {
	mkdirSync(CRAFT_DIR, { recursive: true })
	const dest = resolve(CRAFT_DIR, `iv-agent-${slug}-${craftStamp()}.png`)
	await page.screenshot({ path: dest, fullPage: false })
	console.log(`craft: ${dest}`)
	return dest
}

function db(sql) {
	return execSync(
		`docker exec nextcloud-mariadb mariadb -u nextcloud -pnextcloud_password nextcloud -N -e "${sql}"`,
		{ encoding: 'utf8' },
	).trim()
}

function occ(...args) {
	return execSync(
		`docker exec -u www-data nextcloud-app php occ ${args.join(' ')}`,
		{ encoding: 'utf8' },
	).trim()
}

/** occ get on a possibly-unset key — absence is '' not a throw. */
function occGet(...args) {
	try {
		return occ(...args)
	} catch {
		return ''
	}
}

/** POST helper bound to the page's session requesttoken. */
async function agentApi(page, method, path, body) {
	const { rt, url } = await page.evaluate((p) => ({
		rt: window.OC?.requestToken || '',
		url: window.OC?.generateUrl ? window.OC.generateUrl(`/apps/${'inventorycheck'}${p}`) : `/index.php/apps/inventorycheck${p}`,
	}), path)
	const res = await page.request.fetch(url, {
		method,
		headers: { 'requesttoken': rt, 'Content-Type': 'application/json' },
		data: body,
	})
	return { status: res.status(), json: await res.json().catch(() => ({})) }
}

async function indexTokens(page) {
	const res = await agentApi(page, 'GET', '/agent/v1/tokens')
	return res.json
}

test.describe('agent-auth settings surface — executed dialog legs', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')
	test.setTimeout(120_000)

	let snapSelfMint = ''
	let snapDevHttp = ''
	let snapLocalRemote = ''

	test.beforeAll(() => {
		// Deterministic start for OUR rows only; foreign fixtures (e.g. the
		// handbook-minted root tokens) are never touched by cleanup.
		db("DELETE FROM oc_iv_agent_tokens WHERE label LIKE 'atlas-%'")
		// BQ-3 seat gate: session mint requires a tagged seat row.
		const seats = db("SELECT id FROM oc_iv_mobile_seats WHERE uid='iv_atlas'")
		if (!seats) {
			db(`INSERT INTO oc_iv_mobile_seats (uid,assigned_at,assigned_by) VALUES ('iv_atlas',UNIX_TIMESTAMP(),'${SEAT_TAG}')`)
		}
		snapSelfMint = occGet('config:app:get', APP, 'agent_self_mint')
		snapDevHttp = occGet('config:app:get', APP, 'agent_webhook_dev_http')
		snapLocalRemote = occGet('config:system:get', 'allow_local_remote_servers')
	})

	test.afterAll(() => {
		db("DELETE FROM oc_iv_agent_tokens WHERE label LIKE 'atlas-%'")
		db(`DELETE FROM oc_iv_mobile_seats WHERE assigned_by='${SEAT_TAG}'`)
		db("DELETE FROM oc_iv_agent_webhooks WHERE url LIKE '%atlas%'")
		db(`DROP TABLE IF EXISTS ${HOLD_TABLE}`)
		if (snapSelfMint === '') occ('config:app:delete', APP, 'agent_self_mint')
		else occ('config:app:set', APP, 'agent_self_mint', `--value=${snapSelfMint}`)
		if (snapDevHttp === '') occ('config:app:delete', APP, 'agent_webhook_dev_http')
		else occ('config:app:set', APP, 'agent_webhook_dev_http', `--value=${snapDevHttp}`)
		occ('config:system:set', 'allow_local_remote_servers',
			`--value=${snapLocalRemote === 'true' || snapLocalRemote === '1' ? 'true' : 'false'}`, '--type=boolean')
	})

	test('surface renders; all three dialogs closed and unpainted; webhook section present', async ({ page }) => {
		const consoleErrors = []
		page.on('pageerror', (e) => consoleErrors.push(String(e)))
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#iv-agent-access')).toBeVisible()
		for (const id of ['#iv-agent-mint-dialog', '#iv-agent-secret-dialog', '#iv-agent-revoke-dialog']) {
			await expect(page.locator(id)).toBeAttached()
			await expect(page.locator(id)).not.toBeVisible()
			await expect(page.locator(`${id}[open]`)).toHaveCount(0)
		}
		// Token surface: populated table (foreign rows may exist) or honest
		// empty state — whichever the account actually renders.
		await expect(
			page.locator('#iv-agent-table, #iv-agent-empty').first(),
		).toBeVisible()
		// Webhook-endpoints subsection (17:15Z wave): table + tbody mount.
		await expect(page.locator('#iv-agent-webhooks')).toBeVisible()
		await expect(page.locator('#iv-agent-webhook-table')).toBeAttached()
		// Admin-only self-mint toggle.
		await expect(page.locator('#iv-agent-selfmint-cb')).toBeAttached()
		await craftShot(page, 'settings-agent-closed')
		expect(consoleErrors).toEqual([])
	})

	test('mint open→cancel + Escape: zero mutation requests', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		const posts = []
		page.on('request', (r) => {
			if (r.method() === 'POST' && r.url().includes('/agent/v1/tokens')) posts.push(r.url())
		})
		const dlg = page.locator('#iv-agent-mint-dialog')
		await page.locator('#iv-agent-connect').first().click()
		await expect(dlg).toBeVisible()
		// armEscape heal: NC notification handlers can eat the global Escape
		// keydown — the dialog-level binding must still close it.
		await page.keyboard.press('Escape')
		await expect(dlg).not.toBeVisible()
		await page.locator('#iv-agent-connect').first().click()
		await expect(dlg).toBeVisible()
		await page.locator('#iv-agent-mint-cancel').click()
		await expect(dlg).not.toBeVisible()
		expect(posts, 'cancel/Escape legs must never mint').toEqual([])
	})

	test('mint dialog: choice-cards rendered as cards; validation field errors', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		await page.locator('#iv-agent-connect').first().click()
		const dlg = page.locator('#iv-agent-mint-dialog')
		await expect(dlg).toBeVisible()

		const cards = dlg.locator('.iv-choice-card')
		await expect(cards).toHaveCount(3)
		const cardMetrics = await cards.first().evaluate((el) => {
			const cs = getComputedStyle(el)
			const r = el.getBoundingClientRect()
			return { borderWidth: parseFloat(cs.borderWidth), height: r.height }
		})
		expect(cardMetrics.borderWidth, 'choice-card has card chrome (border)').toBeGreaterThanOrEqual(1)
		expect(cardMetrics.height, 'choice-card ≥48px target').toBeGreaterThanOrEqual(48)
		const checkedTint = await dlg.locator('.iv-choice-card:has(> input[type="radio"]:checked)').first()
			.evaluate((el) => getComputedStyle(el).borderColor)
		expect(checkedTint, 'checked card carries primary border').not.toBe('rgb(118, 118, 118)')
		const dlgMetrics = await dlg.locator('.iv-dialog__body').evaluate((el) => {
			const cs = getComputedStyle(el)
			return { padding: parseFloat(cs.paddingTop), maxH: cs.maxHeight }
		})
		expect(dlgMetrics.padding, 'dialog body padded').toBeGreaterThanOrEqual(12)
		await craftShot(page, 'mint-dialog-open')

		// empty label → inline field error + aria-invalid, zero requests
		await page.locator('#iv-agent-mint-submit').click()
		const labelErr = page.locator('#iv-agent-label-error')
		await expect(labelErr).toBeVisible()
		await expect(labelErr).not.toBeEmpty()
		await expect(page.locator('#iv-agent-label')).toHaveAttribute('aria-invalid', 'true')
		await craftShot(page, 'mint-validation-label')

		// custom preset → scopes wrap unhidden; valid label + no scope picked
		// → the scopes field error renders (label error would mask it first)
		await page.locator('#iv-agent-label').fill(`${RUN}-scopes`)
		await page.locator('input[name="preset"][value="custom"]').check()
		await expect(page.locator('#iv-agent-custom-wrap')).toBeVisible()
		await expect(page.locator('#iv-agent-chips .iv-agent-chip').first()).toBeVisible()
		await craftShot(page, 'mint-dialog-custom-scopes')
		await page.locator('#iv-agent-mint-submit').click()
		await expect(page.locator('#iv-agent-scopes-error')).toBeVisible()
		await page.locator('#iv-agent-mint-cancel').click()
	})

	test('mint on populated table: 201 → secret dialog → row injected without reload', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		// Own populated-table fixture so this leg never depends on test order.
		const seeded = await agentApi(page, 'POST', '/agent/v1/tokens', { label: `${RUN}-seed`, preset: 'read' })
		expect(seeded.status, 'seed fixture mints').toBe(201)
		await page.reload({ waitUntil: 'domcontentloaded' })
		await expect(page.locator('#iv-agent-tbody')).toBeVisible()

		await page.locator('#iv-agent-connect').first().click()
		await page.locator('#iv-agent-label').fill(`${RUN}-mint`)
		const resp = page.waitForResponse((r) => r.url().includes('/agent/v1/tokens') && r.request().method() === 'POST')
		await page.locator('#iv-agent-mint-submit').click()
		expect((await resp).status()).toBe(201)

		// secret dialog: token + config populated, reveal toggles type
		const secret = page.locator('#iv-agent-secret-dialog')
		await expect(secret).toBeVisible()
		const secretInput = page.locator('#iv-agent-secret')
		await expect(secretInput).toHaveValue(/^ckst_inventorycheck_/)
		await expect(secretInput).toHaveAttribute('type', 'password')
		await page.locator('#iv-agent-secret-reveal').click()
		await expect(secretInput).toHaveAttribute('type', 'text')
		await expect(page.locator('#iv-agent-secret-reveal')).toHaveAttribute('aria-pressed', 'true')
		const cfg = await page.locator('#iv-agent-config').inputValue()
		const parsed = JSON.parse(cfg)
		expect(parsed?.mcpServers?.checksuite?.command).toBe('python3')
		await craftShot(page, 'secret-dialog')

		// rebuildTable injects the row in place — no navigation
		await page.locator('#iv-agent-secret-close').click()
		await expect(page.locator('#iv-agent-tbody tr', { hasText: `${RUN}-mint` })).toHaveCount(1)
		expect(page.url()).toContain('/settings/agent')
		await craftShot(page, 'table-after-mint-populated')
	})

	test('rotate: open→cancel keeps active; confirm → 201 secret → old revoked + new live', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		const minted = await agentApi(page, 'POST', '/agent/v1/tokens', { label: `${RUN}-rot`, preset: 'read' })
		expect(minted.status, 'rotate fixture mints').toBe(201)
		const oldId = minted.json.token?.id
		expect(oldId).toBeTruthy()

		await page.reload({ waitUntil: 'domcontentloaded' })
		const row = page.locator('#iv-agent-tbody tr', { hasText: `${RUN}-rot` })
		await expect(row).toHaveCount(1)

		// open→cancel: shared confirm dialog carries the rotate copy, no mutation
		await row.locator('.iv-agent-rotate').click()
		const dlg = page.locator('#iv-agent-revoke-dialog')
		await expect(dlg).toBeVisible()
		await expect(dlg.locator('#iv-agent-revoke-text')).toContainText(`${RUN}-rot`)
		await expect(dlg.locator('#iv-agent-revoke-confirm')).toHaveText(/Rotate/)
		await craftShot(page, 'rotate-dialog')
		await page.locator('#iv-agent-revoke-cancel').click()
		await expect(dlg).not.toBeVisible()
		const still = await indexTokens(page)
		expect(still.tokens.find((t) => String(t.id) === String(oldId))?.state).toBe('active')

		// open→confirm: 201 + fresh secret dialog; durable postcondition =
		// API re-read (old id revoked, new row active) + DOM badges.
		await row.locator('.iv-agent-rotate').click()
		const resp = page.waitForResponse((r) => r.url().includes(`/agent/v1/tokens/${oldId}/rotate`) && r.request().method() === 'POST')
		await page.locator('#iv-agent-revoke-confirm').click()
		expect((await resp).status()).toBe(201)
		await expect(page.locator('#iv-agent-secret-dialog')).toBeVisible()
		await page.locator('#iv-agent-secret-close').click()

		const after = await indexTokens(page)
		expect(after.tokens.find((t) => String(t.id) === String(oldId))?.state).toBe('revoked')
		const alive = after.tokens.filter((t) => t.label === `${RUN}-rot` && t.state === 'active')
		expect(alive.length, 'rotation mints one live replacement').toBe(1)
		expect(String(alive[0].id)).not.toBe(String(oldId))
		await expect(page.locator('#iv-agent-tbody tr', { hasText: `${RUN}-rot` }).first()).toBeAttached()
		await craftShot(page, 'table-after-rotate')
	})

	test('revoke open→cancel keeps active row; confirm revokes durably', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		const minted = await agentApi(page, 'POST', '/agent/v1/tokens', { label: `${RUN}-rev`, preset: 'read' })
		expect(minted.status, 'revoke fixture mints').toBe(201)
		const tokenId = minted.json.token?.id
		expect(tokenId).toBeTruthy()

		await page.reload({ waitUntil: 'domcontentloaded' })
		const row = page.locator('#iv-agent-tbody tr', { hasText: `${RUN}-rev` })
		await expect(row).toHaveCount(1)

		// open→cancel: dialog names the token + destructive copy, nothing mutates
		await row.locator('.iv-agent-revoke').click()
		const dlg = page.locator('#iv-agent-revoke-dialog')
		await expect(dlg).toBeVisible()
		await expect(dlg.locator('#iv-agent-revoke-text')).toContainText(`${RUN}-rev`)
		await expect(dlg.locator('#iv-agent-revoke-confirm')).toHaveClass(/iv-btn--danger/)
		await craftShot(page, 'revoke-dialog')
		await page.locator('#iv-agent-revoke-cancel').click()
		await expect(dlg).not.toBeVisible()
		const stillActive = await indexTokens(page)
		expect(stillActive.tokens.find((t) => String(t.id) === String(tokenId))?.state).toBe('active')

		// open→confirm: durable postcondition — API re-read says revoked and
		// the DOM row loses its action buttons + gains the Revoked badge.
		await row.locator('.iv-agent-revoke').click()
		await expect(dlg).toBeVisible()
		const resp = page.waitForResponse((r) => r.url().includes(`/agent/v1/tokens/${tokenId}/revoke`) && r.request().method() === 'POST')
		await page.locator('#iv-agent-revoke-confirm').click()
		expect((await resp).status()).toBe(200)
		const after = await indexTokens(page)
		expect(after.tokens.find((t) => String(t.id) === String(tokenId))?.state).toBe('revoked')
		await expect(row).toHaveClass(/iv-agent-row--inactive/)
		await expect(row.locator('.iv-agent-revoke')).toHaveCount(0)
		await craftShot(page, 'table-after-revoke')
	})

	test('admin self-mint checkbox: toggle persists policy both ways', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		const cb = page.locator('#iv-agent-selfmint-cb')
		await expect(cb).toBeVisible()
		const before = await indexTokens(page)
		const initial = before.policy?.selfMintEnabled === true
		expect(await cb.isChecked()).toBe(initial)

		// Flip on: change → POST /agent/v1/policy → persisted on API re-read.
		const resp = page.waitForResponse((r) => r.url().includes('/agent/v1/policy') && r.request().method() === 'POST')
		await cb.click()
		expect((await resp).status()).toBe(200)
		const flipped = await indexTokens(page)
		expect(flipped.policy?.selfMintEnabled, 'policy flag persists after toggle').toBe(!initial)

		// Flip back — restores the pre-test value.
		const resp2 = page.waitForResponse((r) => r.url().includes('/agent/v1/policy') && r.request().method() === 'POST')
		await cb.click()
		expect((await resp2).status()).toBe(200)
		const restored = await indexTokens(page)
		expect(restored.policy?.selfMintEnabled, 'policy flag restored').toBe(initial)
	})

	test('empty state → first mint → pendingReload reload shows canonical row', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		// Hold-table surgery: the admin payload lists ALL tokens (incl. foreign
		// handbook fixtures) so the empty state is otherwise unreachable. Rows
		// are snapshotted verbatim and restored in finally — never destroyed.
		db(`DROP TABLE IF EXISTS ${HOLD_TABLE}`)
		db(`CREATE TABLE ${HOLD_TABLE} AS SELECT * FROM oc_iv_agent_tokens`)
		db('DELETE FROM oc_iv_agent_tokens')
		try {
			await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
			await expect(page.locator('#iv-agent-empty')).toBeVisible()
			await expect(page.locator('#iv-agent-connect')).toBeVisible()
			await craftShot(page, 'settings-agent-empty')

			await page.locator('#iv-agent-connect').click()
			await page.locator('#iv-agent-label').fill(`${RUN}-first`)
			const resp = page.waitForResponse((r) => r.url().includes('/agent/v1/tokens') && r.request().method() === 'POST')
			await page.locator('#iv-agent-mint-submit').click()
			expect((await resp).status()).toBe(201)
			await expect(page.locator('#iv-agent-secret-dialog')).toBeVisible()

			// pendingReload heal: no tbody existed → closing reloads the page
			// and the canonical server-rendered row appears.
			await page.locator('#iv-agent-secret-close').click()
			await expect(page.locator('#iv-agent-tbody tr', { hasText: `${RUN}-first` })).toHaveCount(1, { timeout: 20_000 })
			await craftShot(page, 'table-after-first-mint')
		} finally {
			db(`INSERT INTO oc_iv_agent_tokens SELECT * FROM ${HOLD_TABLE}`)
			db(`DROP TABLE ${HOLD_TABLE}`)
		}
	})

	test('webhook endpoints: API create → row → Test queued → Revoke dialog → revoked', async ({ page }) => {
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		// Dev flags open the http/private-target guard for a hermetic
		// localhost endpoint; restored in afterAll (snapshotted in beforeAll).
		occ('config:app:set', APP, 'agent_webhook_dev_http', '--value=1')
		occ('config:system:set', 'allow_local_remote_servers', '--value=true', '--type=boolean')
		const hookUrl = `http://localhost:8099/atlas-hook-${RUN}`
		const created = await agentApi(page, 'POST', '/agent/v1/webhooks', { url: hookUrl, events: ['*'] })
		expect(created.status, 'webhook fixture creates').toBe(201)
		const hookId = created.json.webhook?.id
		expect(hookId).toBeTruthy()

		await page.reload({ waitUntil: 'domcontentloaded' })
		const row = page.locator('#iv-agent-webhook-tbody tr', { hasText: 'atlas-hook' })
		await expect(row, 'created webhook renders its row').toHaveCount(1)
		await expect(row.locator('.iv-agent-webhook-test')).toBeVisible()
		await expect(row.locator('.iv-agent-webhook-revoke')).toBeVisible()

		// Test → POST /{id}/test → queued announce
		const testResp = page.waitForResponse((r) => r.url().includes(`/agent/v1/webhooks/${hookId}/test`) && r.request().method() === 'POST')
		await row.locator('.iv-agent-webhook-test').click()
		expect((await testResp).status()).toBe(200)

		// Revoke → shared confirm dialog carries the webhook copy → DELETE →
		// durable postcondition: API re-read revoked + row inert.
		await row.locator('.iv-agent-webhook-revoke').click()
		const dlg = page.locator('#iv-agent-revoke-dialog')
		await expect(dlg).toBeVisible()
		await expect(dlg.locator('#iv-agent-revoke-text')).toContainText('atlas-hook')
		await expect(dlg.locator('#iv-agent-revoke-confirm')).toHaveClass(/iv-btn--danger/)
		const delResp = page.waitForResponse((r) => r.url().includes(`/agent/v1/webhooks/${hookId}`) && r.request().method() === 'DELETE')
		await page.locator('#iv-agent-revoke-confirm').click()
		expect((await delResp).status()).toBe(200)

		const after = await agentApi(page, 'GET', '/agent/v1/webhooks')
		const entry = (after.json.webhooks || []).find((w) => String(w.id) === String(hookId))
		expect(entry?.state, 'revoked webhook persists revoked state').toBe('revoked')
		await expect(row).toHaveClass(/iv-agent-row--inactive/)
		await expect(row.locator('.iv-agent-webhook-revoke')).toHaveCount(0)
		await craftShot(page, 'webhook-table-after-revoke')
	})

	test('mint dialog at 320px: full-viewport card transform', async ({ page }) => {
		await page.setViewportSize({ width: 320, height: 720 })
		await login(page, credsFromEnv('ADMIN'))
		await page.goto(AGENT_URL, { waitUntil: 'domcontentloaded' })
		await page.locator('#iv-agent-connect').first().click()
		const dlg = page.locator('#iv-agent-mint-dialog')
		await expect(dlg).toBeVisible()
		const box = await dlg.boundingBox()
		expect(box.width, 'dialog fills the small viewport').toBeGreaterThanOrEqual(315)
		// cards collapse to a single column and stay ≥48px targets
		const cards = dlg.locator('.iv-choice-card')
		const heights = await cards.evaluateAll((els) => els.map((e) => e.getBoundingClientRect().height))
		for (const h of heights) expect(h).toBeGreaterThanOrEqual(48)
		await craftShot(page, 'mint-dialog-320')
	})
})
