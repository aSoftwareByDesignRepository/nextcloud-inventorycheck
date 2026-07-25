/**
 * InventoryCheck front-end contracts — CSRF, pickers, XSS hygiene, family CSS.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const appJs = readFileSync(path.join(appRoot, 'js/app.js'), 'utf8');
const appCss = readFileSync(path.join(appRoot, 'css/app.css'), 'utf8');

test('app.js never assigns innerHTML (XSS hygiene)', () => {
	assert.equal(/\.innerHTML\s*=/.test(appJs), false, 'must not assign innerHTML');
	assert.equal(/\bhtml\s*:/.test(appJs), false, 'el() must not accept html: shortcut');
});

test('app.js sends requesttoken on mutating fetches', () => {
	assert.match(appJs, /['"]requesttoken['"]\s*:\s*requestToken\(\)/);
	assert.match(appJs, /OC\.requestToken/);
	assert.match(appJs, /data-requesttoken/);
	assert.match(appJs, /meta\[name=["']requesttoken["']\]/);
});

test('requestToken prefers OC.requestToken then head data-requesttoken (NC34)', () => {
	const fn = appJs.match(/function requestToken\(\)\s*\{[\s\S]*?\n\t\}/);
	assert.ok(fn, 'requestToken function present');
	const body = fn[0];
	assert.match(body, /window\.OC/);
	assert.match(body, /OC\.requestToken/);
	assert.ok(
		body.indexOf('OC.requestToken') < body.indexOf('data-requesttoken'),
		'OC.requestToken must be checked before data-requesttoken',
	);
	assert.ok(
		body.indexOf('data-requesttoken') < body.indexOf('meta[name'),
		'data-requesttoken must be checked before legacy meta',
	);
});

test('movement dialogs use named pickers not raw numeric IDs', () => {
	assert.match(appJs, /openMovementDialog/);
	assert.match(appJs, /Paste SKU or scan code/);
	assert.match(appJs, /Choose an item/);
	assert.match(appJs, /byCodeUrl|itemByCode/);
	assert.equal(/Item ID/.test(appJs), false, 'raw Item ID labels removed');
	assert.equal(/Location ID/.test(appJs), false, 'raw Location ID labels removed');
});

test('field users get issue and transfer; office gets receive and adjust', () => {
	assert.match(appJs, /openReceiveDialog/);
	assert.match(appJs, /openIssueDialog/);
	assert.match(appJs, /openTransferDialog/);
	assert.match(appJs, /openAdjustDialog/);
	assert.match(appJs, /isOffice \|\| ctx\.isAppAdmin/);
});

test('transfer posts fromLocationId for SPEC contract', () => {
	assert.match(appJs, /fromLocationId:\s*locationId/);
	assert.match(appJs, /toLocationId:\s*toLocationId/);
});

test('CSS uses iv- prefix only (no mc-/mn- family leaks)', () => {
	assert.match(appCss, /\.iv-app\b/);
	assert.match(appCss, /\.iv-dialog\b/);
	assert.match(appCss, /@media/);
	assert.equal(/(?:^|[^a-z0-9_-])mc-/.test(appCss), false);
	assert.equal(/(?:^|[^a-z0-9_-])mn-/.test(appCss), false);
	assert.equal(/(?:^|[^a-z0-9_-])dc-/.test(appCss), false);
	assert.equal(/\.(?:mc|mn|dc)-/.test(appCss), false);
});

test('app.js dialogs wire to iv-dialog styles', () => {
	assert.match(appJs, /iv-dialog-overlay/);
	assert.match(appJs, /iv-dialog__title/);
	assert.equal(/iv-modal/.test(appJs), false);
});

test('dialogs implement focus trap Esc and return focus (A6)', () => {
	assert.match(appJs, /previouslyFocused/);
	assert.match(appJs, /ev\.key === 'Escape'/);
	assert.match(appJs, /ev\.key !== 'Tab'/);
	assert.match(appJs, /ev\.shiftKey/);
	assert.match(appJs, /previouslyFocused\.focus/);
});

test('dialogs disable confirm while pending to prevent double-submit', () => {
	assert.match(appJs, /confirmBtn\.disabled = true/);
	assert.match(appJs, /cancelBtn\.disabled = true/);
	assert.match(appJs, /aria-busy/);
	assert.match(appJs, /if \(confirmBtn\.disabled\)/);
});

test('CSS enforces focus-visible and touch-friendly targets', () => {
	assert.match(appCss, /:focus-visible/);
	assert.match(appCss, /min-height:\s*44px|min-width:\s*44px|44px/);
});

test('lists resolve item/location names via labelFromMap not raw ids', () => {
	assert.match(appJs, /labelFromMap\(itemMap,\s*r\.itemId/);
	assert.match(appJs, /labelFromMap\(locMap,\s*r\.locationId/);
	assert.equal(/key:\s*'itemId'/.test(appJs), false);
	assert.equal(/key:\s*'locationId'/.test(appJs), false);
});

test('N4 seed fixture script is present', () => {
	const seed = path.join(appRoot, 'tests/fixtures/seed-reference-dataset.php');
	const src = readFileSync(seed, 'utf8');
	assert.match(src, /--locations=50/);
	assert.match(src, /--items=2000/);
	assert.match(src, /--movements=20000/);
});

test('Track L settings manage seats and device slots with one-time pair code', () => {
	assert.match(appJs, /licenseSeats/);
	assert.match(appJs, /licenseDevices/);
	assert.match(appJs, /Assign seat/);
	assert.match(appJs, /Create device slot/);
	assert.match(appJs, /One-time pairing code/);
	assert.match(appJs, /Copy this code now/);
	assert.match(appJs, /Regenerate code/);
	assert.match(appJs, /pair-code/);
	assert.match(appJs, /withinLimit/);
	assert.match(appJs, /Over seat limit/);
	assert.equal(/Device pairing: coming soon/.test(appJs), false);
});

test('settings edit allow and office groups', () => {
	assert.match(appJs, /Allowed groups \(one per line\)/);
	assert.match(appJs, /Office groups \(one per line\)/);
	assert.match(appJs, /allowedGroups:\s*lines\(allowedGroups\)/);
	assert.match(appJs, /officeGroups:\s*lines\(officeGroups\)/);
});

test('settings expose delegated app administrators \(L0 writable\)', () => {
	assert.match(appJs, /Delegated app administrators/);
	assert.match(appJs, /iv-app-admins/);
	assert.match(appJs, /canEditAppAdmins/);
	assert.match(appJs, /isSystemAdmin/);
	assert.match(appJs, /payload\.appAdmins = lines\(appAdmins\)/);
	assert.match(appJs, /Only Nextcloud system administrators can change the app administrator list/);
});

test('settings surface unknown user and group field errors', () => {
	assert.match(appJs, /unknown_user/);
	assert.match(appJs, /unknown_group/);
	assert.match(appJs, /This Nextcloud user does not exist/);
	assert.match(appJs, /This Nextcloud group does not exist/);
	assert.match(appJs, /applyFieldErrors\(mount,/);
	assert.match(appJs, /clearFieldErrors\(mount\)/);
});

test('movements transfer group is clickable filter', () => {
	assert.match(appJs, /Filter by transfer group/);
	assert.match(appJs, /transferGroup=/);
	assert.match(appJs, /Clear filter/);
});

test('movements page exposes kind item location date filters and pagination', () => {
	assert.match(appJs, /movementListQuery/);
	assert.match(appJs, /Apply filters/);
	assert.match(appJs, /Clear filters/);
	assert.match(appJs, /Filter movements/);
	assert.match(appJs, /iv-filterbar/);
	assert.match(appJs, /paginationBar/);
	assert.match(appJs, /iv-pagination/);
	assert.match(appJs, /From date/);
	assert.match(appJs, /To date/);
	assert.match(appJs, /All kinds/);
});

test('dashboard flags negative balances when negatives are disallowed', () => {
	assert.match(appJs, /negative=1/);
	assert.match(appJs, /Negative balances/);
	assert.match(appJs, /allowNegative/);
});

test('transfer Reverse is offered only on transfer_out leg', () => {
	assert.match(appJs, /canReverseMovement/);
	assert.match(appJs, /transfer_in[\s\S]{0,80}return false/);
});

test('Reverse compensates immutable movements with reversal reason', () => {
	assert.match(appJs, /openReverseFromMovement/);
	assert.match(appJs, /reversal of #/);
	assert.match(appJs, /canReverseMovement/);
	assert.match(appJs, /mode:\s*'delta'/);
	assert.match(appJs, /qtyDelta:\s*-Number\(row\.qtyDelta\)/);
	assert.equal(/qtyAfter\)\s*-\s*Number\(row\.qtyDelta\)/.test(appJs), false, 'adjust reverse must not set historical absolute qty');
});

test('dialogs map 422 details to aria-invalid field errors', () => {
	assert.match(appJs, /applyFieldErrors/);
	assert.match(appJs, /aria-invalid/);
	assert.match(appJs, /iv-field__error/);
});

test('empty states offer role-aware CTAs', () => {
	assert.match(appJs, /Receive stock to start the ledger/);
	assert.match(appJs, /New item/);
	assert.match(appJs, /New location/);
});

test('masters expose Edit Deactivate Reactivate without hardcoded app paths', () => {
	assert.match(appJs, /tr\('Edit'\)/);
	assert.match(appJs, /tr\('Deactivate'\)/);
	assert.match(appJs, /tr\('Reactivate'\)/);
	assert.match(appJs, /refreshAfterMutation/);
	assert.match(appJs, /announceBalances|data-iv-balance/);
	assert.match(appJs, /labelPrintUrl|itemLabelPrint/);
	assert.match(appJs, /movementApi\(/);
	assert.equal(/['"`]\/apps\/inventorycheck/.test(appJs), false, 'must use ctx.urls not hardcoded /apps/inventorycheck');
});

test('CSS flashes balance cells after stock mutations', () => {
	assert.match(appCss, /\.iv-flash\b/);
	assert.match(appCss, /iv-flash-pulse|@keyframes iv-flash/);
	assert.match(appJs, /classList\.add\('iv-flash'\)/);
});

test('CSS print stylesheet keeps label code readable \(A12\)', () => {
	assert.match(appCss, /@media print/);
	assert.match(appCss, /#iv-label-code|iv-label-code/);
	assert.match(appCss, /12pt/);
});

test('helpers still export validation helpers', async () => {
	const { createRequire } = await import('node:module');
	const require = createRequire(import.meta.url);
	const IvApp = require('../../js/app.js');
	assert.equal(IvApp.isValidCode('FILTER-42', 64), true);
	assert.equal(IvApp.isValidCode('BAD CODE', 64), false);
	assert.ok(IvApp.kindMeta('transfer_out').label.length > 0);
});
