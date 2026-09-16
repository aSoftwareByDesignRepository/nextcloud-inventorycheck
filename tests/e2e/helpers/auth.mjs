import { expect } from '@playwright/test'

/**
 * Nextcloud login via shared cookie jar + form POST.
 * Prefer suite storageState (global-setup); this is the fallback when a
 * session expired or a test needs a different user.
 */
export async function login(page, { username, password }) {
	const base = (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
	let lastError = 'login_failed'

	for (let attempt = 1; attempt <= 3; attempt++) {
		// UI form login (not API+follow-redirects): NC may set overwritehost to
		// 10.0.2.2 for emulator reachability; following that Location from the
		// host hangs. Stay on localhost baseURL after cookies are set.
		await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded', timeout: 45_000 })
		const html = await page.content()
		if (/maintenance mode|update is in progress|needs to be updated/i.test(html)) {
			throw new Error('Nextcloud is in maintenance/upgrade mode — finish `occ upgrade` before E2E')
		}
		if (/Too many tries|try again in|Account locked/i.test(html)) {
			lastError = 'bruteforce_throttled'
			await page.waitForTimeout(2000 * attempt)
			continue
		}

		if (!page.url().includes('/login')) {
			// Already authenticated storage/cookies
			await page.goto(`${base}/apps/dashboard/`, { waitUntil: 'domcontentloaded', timeout: 45_000 })
			if (!page.url().includes('/login')) {
				return
			}
		}

		const user = page.locator('input[name="user"], #user')
		const pass = page.locator('input[name="password"], #password')
		await expect(user.first()).toBeVisible({ timeout: 30_000 })
		await user.first().fill(username)
		await pass.first().fill(password)

		const wrong = page.getByText(/Wrong login or password|Falscher Benutzername oder Passwort/i)
		await page.locator('button[type="submit"], input[type="submit"], #submit-form').first().click()

		// Do not follow overwritehost (10.0.2.2) redirects — they hang from the host.
		// Give NC a moment to set session cookies, then re-enter via localhost baseURL.
		await page.waitForTimeout(1500)
		if (await wrong.first().isVisible({ timeout: 500 }).catch(() => false)) {
			lastError = 'bad_credentials'
			await page.waitForTimeout(500 * attempt)
			continue
		}

		await page.goto(`${base}/apps/dashboard/`, { waitUntil: 'domcontentloaded', timeout: 45_000 })
		if (page.url().includes('/login')) {
			lastError = 'session_not_established'
			await page.waitForTimeout(500 * attempt)
			continue
		}
		const forbidden = page.getByText(/CSRF check failed|Access forbidden/i)
		if (await forbidden.first().isVisible({ timeout: 1000 }).catch(() => false)) {
			lastError = 'dashboard_csrf'
			continue
		}
		return
	}

	throw new Error(`Login failed for ${username}: ${lastError}`)
}

export function credsFromEnv(prefix = 'E2E') {
	const username = process.env[`NC_${prefix}_USER`] || process.env.NC_ADMIN_USER
	const password = process.env[`NC_${prefix}_PASS`] || process.env.NC_ADMIN_PASS
	if (!username || !password) {
		return null
	}
	return { username, password }
}

/**
 * Ensure an authenticated session. Uses storageState when present; otherwise
 * logs in. Re-auths if Nextcloud bounced us to /login.
 * Retries transient connection drops (Apache recycle during concurrent suites).
 */
export async function ensureLoggedIn(page, prefix = 'ADMIN') {
	let lastErr = null
	for (let attempt = 1; attempt <= 3; attempt++) {
		try {
			await page.goto('/apps/dashboard/', { waitUntil: 'domcontentloaded', timeout: 45_000 })
			if (!page.url().includes('/login')) {
				return
			}
			const creds = credsFromEnv(prefix) || credsFromEnv('E2E') || credsFromEnv('ADMIN')
			if (!creds) {
				throw new Error('Not logged in and no NC_* credentials available')
			}
			await login(page, creds)
			return
		} catch (err) {
			lastErr = err
			const msg = String(err && err.message ? err.message : err)
			if (!/ERR_CONNECTION_|ERR_SOCKET_|Timeout|net::/.test(msg) || attempt === 3) {
				throw err
			}
			await page.waitForTimeout(1500 * attempt)
		}
	}
	throw lastErr
}

export async function openInventory(page, path = '/apps/inventorycheck/') {
	let lastErr = null
	for (let attempt = 1; attempt <= 3; attempt++) {
		try {
			await page.goto(path, { waitUntil: 'domcontentloaded', timeout: 45_000 })
			if (page.url().includes('/login')) {
				await ensureLoggedIn(page, 'ADMIN')
				await page.goto(path, { waitUntil: 'domcontentloaded', timeout: 45_000 })
			}
			const denied = page.locator('.iv-app--denied, #iv-denied-title')
			if (await denied.first().isVisible({ timeout: 2_000 }).catch(() => false)) {
				throw new Error('InventoryCheck access denied for this user — check allow-lists')
			}
			const notFound = page.getByRole('heading', { name: /Page not found|Seite nicht gefunden/i })
			if (await notFound.first().isVisible({ timeout: 1_000 }).catch(() => false)) {
				lastErr = new Error(`InventoryCheck 404 for ${path} (attempt ${attempt})`)
				await page.waitForTimeout(1000 * attempt)
				continue
			}
			// Shell landmark: title is always painted; #iv-main-content can be empty
			// (zero-height) until JS fills it — Playwright then reports it as "hidden".
			const shell = page.locator('#app-content.iv-app')
			await expect(shell, 'expected InventoryCheck shell (#app-content.iv-app)').toBeVisible({
				timeout: 30_000,
			})
			await expect(page.locator('#iv-page-title')).toBeVisible({ timeout: 30_000 })
			await expect(page.locator('#iv-main-content')).toBeAttached({ timeout: 30_000 })
			return shell
		} catch (err) {
			lastErr = err
			const msg = String(err && err.message ? err.message : err)
			if (!/ERR_CONNECTION_|ERR_SOCKET_|Timeout|net::|404|Page not found/i.test(msg) || attempt === 3) {
				throw err
			}
			await page.waitForTimeout(1500 * attempt)
		}
	}
	throw lastErr
}
