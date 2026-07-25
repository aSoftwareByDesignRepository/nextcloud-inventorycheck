const { defineConfig, devices } = require('@playwright/test')
const { existsSync, readFileSync } = require('fs')
const { resolve } = require('path')

const configDir = __dirname
const envFile = resolve(configDir, 'tests/e2e/.env')
if (existsSync(envFile)) {
	for (const line of readFileSync(envFile, 'utf8').split('\n')) {
		const trimmed = line.trim()
		if (!trimmed || trimmed.startsWith('#')) continue
		const eq = trimmed.indexOf('=')
		if (eq <= 0) continue
		const key = trimmed.slice(0, eq).trim()
		let value = trimmed.slice(eq + 1).trim()
		if (
			(value.startsWith('"') && value.endsWith('"'))
			|| (value.startsWith("'") && value.endsWith("'"))
		) {
			value = value.slice(1, -1)
		}
		// File wins: sibling app suites often export conflicting NC_* into the shell.
		process.env[key] = value
	}
}

const baseURL = process.env.NC_BASE_URL || 'http://localhost:8081'
const authFile = resolve(configDir, 'tests/e2e/.auth/user.json')

module.exports = defineConfig({
	testDir: 'tests/e2e',
	timeout: 90_000,
	expect: { timeout: 20_000 },
	fullyParallel: false,
	workers: 1,
	globalSetup: resolve(configDir, 'tests/e2e/global-setup.mjs'),
	use: {
		baseURL,
		storageState: authFile,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'chromium-1280', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
		{ name: 'chromium-320', use: { ...devices['Desktop Chrome'], viewport: { width: 320, height: 640 } } },
	],
})
