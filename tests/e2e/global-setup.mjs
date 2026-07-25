import { chromium, expect } from '@playwright/test'
import { mkdirSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'
import { login, credsFromEnv } from './helpers/auth.mjs'

/**
 * One shared authenticated storageState for the suite — avoids hammering
 * /login (bruteforce) when sibling apps also run Playwright against the same NC.
 */
export default async function globalSetup() {
	const out = resolve(dirname(fileURLToPath(import.meta.url)), '.auth/user.json')
	mkdirSync(dirname(out), { recursive: true })
	const creds = credsFromEnv('ADMIN') || credsFromEnv('E2E')
	if (!creds) {
		throw new Error('global-setup requires NC_ADMIN_* or NC_E2E_* in tests/e2e/.env')
	}
	const browser = await chromium.launch()
	const context = await browser.newContext()
	const page = await context.newPage()
	await login(page, creds)
	await expect(page.locator('body')).toBeVisible()
	await context.storageState({ path: out })
	await browser.close()
	// eslint-disable-next-line no-console
	console.log('Wrote', out, 'for', creds.username)
}
