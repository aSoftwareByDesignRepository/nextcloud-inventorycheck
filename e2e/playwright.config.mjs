// @ts-check
/**
 * Playwright config scoped to the screenshot-matrix capture spec.
 *
 * The main playwright.config.js has testDir tests/e2e; the DS §VISUAL gate
 * (check-screenshot-matrix.py) requires the manifest + spec under e2e/, so
 * this mini config exists solely for that spec:
 *
 *   E2E_STORAGE_STATE=tests/e2e/.auth/ivc-dschrome-<pid>.json \
 *     npx playwright test --config=e2e/playwright.config.mjs
 *
 * It keeps the farm shared-instance lock + user-state teardown identical to
 * the main config — the matrix run must queue behind sibling lanes like any
 * other Playwright suite.
 */
import { defineConfig, devices } from '@playwright/test'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const envFile = resolve(__dirname, '../tests/e2e/.env')
if (existsSync(envFile)) {
	for (const line of readFileSync(envFile, 'utf8').split('\n')) {
		const t = line.trim()
		if (!t || t.startsWith('#')) continue
		const eq = t.indexOf('=')
		if (eq <= 0) continue
		process.env[t.slice(0, eq).trim()] = t.slice(eq + 1).trim().replace(/^['"]|['"]$/g, '')
	}
}

const baseURL = process.env.NC_BASE_URL || 'http://localhost:8081'
const stateFile = process.env.E2E_STORAGE_STATE
	? resolve(__dirname, '..', process.env.E2E_STORAGE_STATE)
	: resolve(__dirname, '../tests/e2e/.auth/user.json')

export default defineConfig({
	testDir: '.',
	testMatch: 'capture-screenshot-matrix.spec.js',
	globalSetup: '../tests/e2e/global-setup.mjs',
	globalTeardown: '../tests/e2e/global-teardown.mjs',
	timeout: 1_800_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	use: {
		baseURL,
		storageState: stateFile,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
	],
})
