// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — assert the *rendered* truth of every
 * InventoryCheck page surface (list markers, select centring, icon boxes, no
 * inherited centering on form controls). DOM-level specs pass on visually
 * broken pages; this contract does not.
 *
 * Role-gated surfaces (settings for non-admins) degrade to the honest
 * access-denied shell — that surface is asserted too.
 */
import { createRequire } from 'module'
import { test } from '@playwright/test'
import { ensureLoggedIn } from './helpers/auth.mjs'

const require = createRequire(import.meta.url)
const { assertAtlasRenderedSurface } = require('../../../_shared/e2e/atlas-rendered-surface-contract.js')

const CONTENT = '#app-content'
// sr-only controls are 1px clipped by design — exempt from geometry checks.
const NAV = '#app-navigation, nav, #header, .iv-sr-only'
// Intentionally marker-less component lists (chips, pickers, icon-marked
// quickstart steps, menus, breadcrumbs) — NOT prose lists.
const LIST_ALLOW = '.iv-nav__list, .iv-quickstart, .iv-chips, '
	+ '.iv-picker__results, .iv-picker__empty-chip, .iv-actions-more__menu, '
	+ '.iv-nav-footer__menu, .user-picker__list, .iv-breadcrumb__list'

// Every shipped page route (app mounts into #iv-main-content inside
// #app-content; denied surfaces render .iv-access-denied instead).
const PAGES = [
	{ name: 'dashboard', url: '/apps/inventorycheck/' },
	{ name: 'items', url: '/apps/inventorycheck/items' },
	{ name: 'item-detail', url: '/apps/inventorycheck/items/1' },
	{ name: 'locations', url: '/apps/inventorycheck/locations' },
	{ name: 'location-detail', url: '/apps/inventorycheck/locations/1' },
	{ name: 'movements', url: '/apps/inventorycheck/movements' },
	{ name: 'stocktake', url: '/apps/inventorycheck/stocktake' },
	{ name: 'stocktake-new', url: '/apps/inventorycheck/stocktake/create' },
	{ name: 'settings-access', url: '/apps/inventorycheck/settings/access' },
	{ name: 'settings-office', url: '/apps/inventorycheck/settings/office' },
	{ name: 'settings-notifications', url: '/apps/inventorycheck/settings/notifications' },
	{ name: 'settings-quantities', url: '/apps/inventorycheck/settings/quantities' },
	{ name: 'settings-location-access', url: '/apps/inventorycheck/settings/location-access' },
	{ name: 'settings-connections', url: '/apps/inventorycheck/settings/connections' },
	{ name: 'settings-policies', url: '/apps/inventorycheck/settings/policies' },
	{ name: 'settings-license', url: '/apps/inventorycheck/settings/license' },
	{ name: 'settings-support', url: '/apps/inventorycheck/settings/support' },
]

test.describe('ATLAS_RENDERED_SURFACE_CONTRACT', () => {
	test.skip(!process.env.NC_ADMIN_USER, 'Requires NC_ADMIN_USER / NC_ADMIN_PASS')

	for (const p of PAGES) {
		test(`rendered surface: ${p.name}`, async ({ page }) => {
			await ensureLoggedIn(page, 'ADMIN')
			await page.goto(p.url, { waitUntil: 'domcontentloaded' })
			// Either the mounted app main or the honest access-denied shell.
			await page
				.locator('#iv-main-content, .iv-access-denied, main')
				.first()
				.waitFor({ state: 'attached', timeout: 30000 })
			await assertAtlasRenderedSurface(page, {
				content: CONTENT,
				navExclude: NAV,
				listAllow: LIST_ALLOW,
			})
		})
	}
})
