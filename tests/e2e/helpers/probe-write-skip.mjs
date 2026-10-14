import { chromium } from 'playwright'
import { execFileSync } from 'node:child_process'
const BASE = 'http://localhost:8081'
const sql = (s) => execFileSync('docker', ['compose','exec','-T','mariadb','mariadb','-u','nextcloud','-pnextcloud_password','nextcloud','-N','-B','-e',s], { cwd: '/home/alex/Development/nextcloud-dev/nextcloud', encoding:'utf8' }).trim()

const browser = await chromium.launch()
const ctx = await browser.newContext()
const page = await ctx.newPage()
// real login
await page.goto(BASE + '/index.php/login')
await page.fill('#user', process.env.NC_E2E_USER || 'admin')
await page.fill('#password', process.env.NC_E2E_PASS || 'E2eAdminT3st!')
await page.click('button[type=submit]')
await page.waitForURL(/apps|dashboard/, { timeout: 15000 })

// 1) ensure enabled=1 committed, then warm the appconfig blob via real page load
sql("INSERT INTO oc_appconfig (appid,configkey,configvalue) VALUES ('inventorycheck','product_lookup_enabled','1') ON DUPLICATE KEY UPDATE configvalue='1'")
await page.goto(BASE + '/index.php/apps/inventorycheck/')
const flag1 = await page.locator('body').getAttribute('data-iv-product-lookup')
console.log('warm flag =', flag1)

// 2) DB flips to 0 out-of-band (stale blob still says 1)
sql("UPDATE oc_appconfig SET configvalue='0' WHERE appid='inventorycheck' AND configkey='product_lookup_enabled'")
console.log('db after flip =', sql("SELECT configvalue FROM oc_appconfig WHERE appid='inventorycheck' AND configkey='product_lookup_enabled'"))

// 3) admin saves enabled:true through the real endpoint — must land in DB
const res = await page.evaluate(async () => {
	const r = await fetch('/index.php/apps/inventorycheck/api/config/product-lookup', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
		body: JSON.stringify({ enabled: true }),
	})
	return { status: r.status, data: await r.json() }
})
console.log('save status =', res.status, 'flag =', res.data?.productLookupEnabled)
console.log('db after save =', sql("SELECT configvalue FROM oc_appconfig WHERE appid='inventorycheck' AND configkey='product_lookup_enabled'"))

// 4) mirror: disable while blob could claim already-off
const res2 = await page.evaluate(async () => {
	const r = await fetch('/index.php/apps/inventorycheck/api/config/product-lookup', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
		body: JSON.stringify({ enabled: false }),
	})
	return { status: r.status, data: await r.json() }
})
console.log('disable status =', res2.status, 'flag =', res2.data?.productLookupEnabled)
console.log('db after disable =', sql("SELECT configvalue FROM oc_appconfig WHERE appid='inventorycheck' AND configkey='product_lookup_enabled'"))
await browser.close()
