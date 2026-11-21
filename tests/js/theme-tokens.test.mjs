/**
 * Design-system / theme-token contracts for InventoryCheck CSS (source assertions).
 * Complements PHP AccessibilityContractTest + Playwright theme gauntlet.
 */
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const cssRoot = path.join(appRoot, 'css')

function read(rel) {
	return readFileSync(path.join(appRoot, rel), 'utf8')
}

function collectFeatureCss() {
	const files = []
	const walk = (dir) => {
		for (const name of readdirSync(dir, { withFileTypes: true })) {
			const full = path.join(dir, name.name)
			if (name.isDirectory()) {
				if (name.name === 'vendor') continue
				walk(full)
				continue
			}
			if (name.name.endsWith('.css')) files.push(full)
		}
	}
	walk(cssRoot)
	return files
}

test('tokens map to Nextcloud theme variables with scrim/touch/qr canvas', () => {
	const tokens = read('css/common/tokens.css')
	assert.ok(tokens.includes('--iv-touch: 44px'))
	assert.ok(tokens.includes('--iv-qr-canvas:'))
	assert.ok(tokens.includes('--iv-scrim:'))
	assert.ok(tokens.includes('--iv-scrim-strong:'))
	assert.ok(tokens.includes('--iv-mono:'))
	assert.ok(tokens.includes('--iv-muted-on-tint:'))
	assert.ok(tokens.includes('--iv-shadow-lg:'))
	assert.ok(tokens.includes('#app-content.iv-app'))
	assert.ok(tokens.includes('.iv-toast-region'))
	assert.ok(/--iv-tint-info:\s*color-mix\([^;]*var\(--color-main-background\)/.test(tokens))
	assert.ok(!/--iv-tint-info:\s*color-mix\([^;]*,\s*transparent\)/.test(tokens))
	assert.ok(tokens.includes('--iv-danger-fill:'))
	assert.ok(tokens.includes('--iv-danger-on-fill:'))
	assert.ok(tokens.includes('--iv-danger-ink:'))
	assert.ok(tokens.includes('--iv-accent-ink:'))
	assert.ok(tokens.includes('--inventorycheck-color-primary: var(--color-primary-element)'))
	assert.ok(tokens.includes('--inventorycheck-color-modal-background:'))
	assert.ok(tokens.includes('--inventorycheck-color-error:'))
})

test('app.css imports tokens and uses theme-safe surfaces', () => {
	const appCss = read('css/app.css')
	const a11y = read('css/common/accessibility.css')
	assert.ok(appCss.includes("@import url('common/tokens.css')"))
	assert.ok(appCss.includes("@import url('common/accessibility.css')"))
	assert.ok(/min-height:\s*(?:var\(--iv-touch,\s*)?44px/.test(appCss))
	assert.ok(appCss.includes('background: var(--iv-qr-canvas)'))
	assert.ok(!appCss.includes('background: #ffffff'))
	assert.ok(!appCss.includes('background: #fff;'))
	assert.ok(appCss.includes('env(safe-area-inset-top') || read('css/common/shell-chrome.css').includes('env(safe-area-inset-top'))
	assert.ok(a11y.includes('forced-colors: active') || appCss.includes('forced-colors: active'))
	assert.ok(a11y.includes('prefers-contrast: more') || appCss.includes('prefers-contrast: more'))
	assert.ok(appCss.includes('.iv-badge::before'))
	assert.ok(appCss.includes('var(--iv-scrim'))
	assert.ok(!appCss.includes('rgba(0, 0, 0'))
	assert.ok(!appCss.includes('rgba(0,0,0'))
	assert.ok(a11y.includes('#content.app-inventorycheck'))
	assert.ok(!/^\s*\*:focus-visible/m.test(appCss), 'global *:focus-visible must not restyle NC chrome')
	assert.ok(appCss.includes('minmax(min(100%'))
	assert.ok(appCss.includes('--iv-overlay-top'))
})

test('feature CSS has no hardcoded black overlays or bare hex fills', () => {
	const featureBundle = collectFeatureCss().map((f) => readFileSync(f, 'utf8')).join('\n')
	assert.ok(!featureBundle.includes('rgba(0, 0, 0'), 'feature CSS must not use rgba(0,0,0) overlays')
	assert.ok(!featureBundle.includes('rgba(0,0,0'), 'feature CSS must not use rgba(0,0,0) overlays')

	// Bare hex fills outside var() fallbacks / QR token are forbidden in feature CSS.
	const stripped = featureBundle
		.replace(/--iv-qr-canvas:\s*#[0-9a-fA-F]+;/g, '')
		.replace(/var\([^)]*#[0-9a-fA-F]+[^)]*\)/g, 'var(--ok)')
		.replace(/\/\*[\s\S]*?\*\//g, '')
	const bareHex = stripped.match(/(?:^|[\s:{;,])(#[0-9a-fA-F]{3,8})\b/g) || []
	assert.equal(bareHex.length, 0, `Bare hex colours in feature CSS: ${bareHex.slice(0, 10).join(', ')}`)
})

test('shell chrome uses safe-area padding and 44px touch targets', () => {
	const shell = read('css/common/shell-chrome.css')
	assert.ok(shell.includes('env(safe-area-inset-top'))
	assert.ok(shell.includes('env(safe-area-inset-bottom'))
	assert.ok(/min-height:\s*var\(--iv-touch,\s*44px\)/.test(shell) || shell.includes('min-height: 44px') || shell.includes('min-height: 2.75rem'))
	assert.ok(!shell.includes('min-height: 36px'), 'iv-btn--sm must stay ≥44px touch target')
})

test('no dead first-arg app-scoped token refs in shipped css (dead_token_orphan_refs_post_token_sync)', () => {
	// learned regression (ivc vis-r31): a tokens.css sync removed --iv-* names
	// while consumers kept var(--dead, fallback) — the fallback repainted
	// identically to the base fill and killed every danger-button hover, and
	// dropped scheduled-badge ink to a sub-AA raw primary on dark themes.
	const files = collectFeatureCss()
	const defined = new Set()
	const srcs = files.map((f) => ({
		rel: path.relative(appRoot, f),
		src: readFileSync(f, 'utf8').replace(/\/\*[\s\S]*?\*\//g, ''),
	}))
	for (const { src } of srcs) {
		for (const m of src.matchAll(/--([\w-]+)\s*:/g)) defined.add(m[1])
	}
	const dead = []
	for (const { rel, src } of srcs) {
		for (const m of src.matchAll(/var\(\s*--([\w-]+)/g)) {
			const name = m[1]
			if (!(name.startsWith('iv-') || name.startsWith('inventorycheck-'))) continue
			if (!defined.has(name)) dead.push(`${rel} var(--${name}…)`)
		}
	}
	assert.deepEqual(dead, [], `dead token refs (fallback does not excuse): ${dead.join(', ')}`)
})

test('danger hover darkens via filter on the live fill; scheduled badge inks accent-ink', () => {
	const appCss = read('css/app.css')
	const shell = read('css/common/shell-chrome.css')
	assert.ok(/\.iv-btn--danger:hover[^{]*\{[^}]*filter:\s*brightness\(0\.92\)/.test(appCss),
		'danger hover must darken via filter — core hover repaint is !important-blocked')
	assert.ok(/:hover[^{]*\{[^}]*filter:\s*brightness\(0\.92\)/.test(shell),
		'shell-chrome danger armor hover must darken via filter')
	assert.ok(/\.iv-badge--scheduled\s*\{[^}]*var\(--iv-accent-ink/.test(appCss),
		'scheduled badge must ink the accent-ink→text composition (hue-preserving, >=4.5:1 on tint-info every theme)')
})

test('picker options and dialog close meet 44px touch targets', () => {
	const appCss = read('css/app.css')
	const dialogs = read('css/common/dialogs.css')
	assert.ok(appCss.includes(".iv-picker__results li[role='option']"))
	assert.ok(/li\[role='option'\][\s\S]*?min-height:\s*var\(--iv-touch,\s*44px\)/.test(appCss))
	assert.ok(/\.modal-close[\s\S]*?min-height:\s*var\(--iv-touch,\s*44px\)/.test(dialogs))
})
