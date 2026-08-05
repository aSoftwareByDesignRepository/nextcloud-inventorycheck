import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const appJs = readFileSync(join(root, 'js/app.js'), 'utf8');
const appCss = readFileSync(join(root, 'css/app.css'), 'utf8');

test('Bachus: loadErrorPanel + Try again on blank-page failures', () => {
	assert.match(appJs, /function loadErrorPanel/);
	assert.match(appJs, /tr\('Try again'\)/);
	assert.match(appJs, /renderSettings\(ctx\)/);
	assert.match(appJs, /listHost\.appendChild\(loadErrorPanel/);
});

test('Bachus: reverse-adjust honesty (fixed delta, no qty lie)', () => {
	assert.match(appJs, /isDeltaReverse/);
	assert.match(appJs, /data-iv-delta-reverse/);
	assert.match(appJs, /This reverses the earlier adjustment by \{qty\}/);
});

test('Bachus: lot fields progressive; reason note adjust-only', () => {
	assert.match(appJs, /data-iv-lot-fields/);
	assert.match(appJs, /syncLotVisibility/);
	assert.match(appJs, /if \(opts\.adjust\) \{\s*fields\.push\(field\(tr\('Reason note \(optional\)'\)/);
});

test('Bachus: disclosure for variance; settings are multipage sections', () => {
	assert.match(appJs, /function disclosureSection/);
	assert.match(appJs, /iv-variance-disclosure/);
	assert.match(appJs, /iv-settings-page/);
	assert.match(appJs, /ctx\.settingsSection/);
	assert.doesNotMatch(appJs, /iv-settings-disclosure/);
	assert.match(appCss, /\.iv-disclosure__summary/);
	assert.match(appCss, /min-height:\s*var\(--iv-touch/);
	assert.match(appCss, /\.iv-settings-nav/);
});

test('Bachus: stocktake autosave + blind count toggle', () => {
	assert.match(appJs, /data-iv-autosave/);
	assert.match(appJs, /Blind count — hide system quantities/);
	assert.match(appJs, /renderCountTable/);
	assert.doesNotMatch(
		appJs,
		/btn\(tr\('Save'\),\s*\{\s*onclick:\s*function\s*\(\)\s*\{\s*if \(input\.value\.trim\(\) === ''\)/,
	);
});

test('Bachus: live search + dashboard one-tap receive/issue/adjust', () => {
	assert.match(appJs, /function bindLiveSearch/);
	assert.match(appJs, /data-iv-low-stock-actions/);
	assert.match(appJs, /data-iv-neg-actions/);
	assert.match(appJs, /Filters update as you choose\./);
	assert.doesNotMatch(appJs, /then click Apply/);
});

test('Bachus: confirm-only booking + responsive tables + auto-start stocktake', () => {
	assert.match(appJs, /data-iv-quick-confirm/);
	assert.match(appJs, /Looking good — confirm this booking\./);
	assert.match(appJs, /data-iv-quick-change/);
	assert.match(appJs, /Change item or location/);
	assert.match(appJs, /forceFullForm/);
	assert.doesNotMatch(appJs, /Close and open Receive \/ Issue from the toolbar/);
	assert.match(appJs, /Set to zero/);
	assert.match(appJs, /Set each line to zero to clear it/);
	assert.doesNotMatch(appJs, /Adjust or receive to clear these/);
	assert.match(appJs, /className: 'table iv-table iv-table--responsive'/);
	assert.match(appJs, /Stocktake ready to count\./);
	assert.match(appJs, /wrap\.id = 'iv-app-admins'/);
	assert.match(appJs, /licenseSection\.id = 'iv-license'/);
	assert.match(appJs, /iv-actions-more/);
	assert.match(appJs, /function actionsMoreMenu\s*\(/);
	assert.match(appJs, /resolveCodePaste/);
	assert.match(appCss, /\.iv-quick-confirm/);
	assert.match(appCss, /\.iv-actions-more/);
	assert.match(appCss, /#content\.app-inventorycheck #app-content\.iv-app \.iv-actions-more__body[\s\S]{0,120}?position:\s*absolute\s*!important/);
});

test('Bachus: empty balances and per-location shortfalls offer Receive', () => {
	assert.match(appJs, /Receive stock at a location to start tracking this item\./);
	assert.match(appJs, /Receive stock here, or transfer from another location\./);
	assert.match(appJs, /data-iv-perloc-actions/);
	assert.match(appJs, /Add active items first, then start a new stocktake/);
});

test('Bachus: item create progressive + ACL chips + inline pairing + CSV auto-check', () => {
	assert.match(appJs, /More item options/);
	assert.match(appJs, /iv-item-more/);
	assert.match(appJs, /function createLocalOptionPicker/);
	assert.match(appJs, /Add locations as chips/);
	assert.doesNotMatch(appJs, /Hold Ctrl \(or Cmd on Mac\)/);
	assert.match(appJs, /iv-pair-code-panel/);
	assert.match(appJs, /We check your file automatically/);
	assert.match(appJs, /iv-import-columns/);
	assert.match(appJs, /scheduleDryRun/);
	assert.match(appCss, /\.iv-pair-code-panel/);
});

test('Bachus: scoped settings saves + clean stocktake close + auto filters', () => {
	assert.match(appJs, /tr\('Save access'\)/);
	assert.match(appJs, /tr\('Save office settings'\)/);
	assert.match(appJs, /tr\('Save notification settings'\)/);
	assert.match(appJs, /function withSaveBusy/);
	assert.doesNotMatch(appJs, /Save these settings/);
	assert.doesNotMatch(appJs, /function saveCoreSettings/);
	assert.match(appJs, /function closeStocktake/);
	assert.match(appJs, /tryAutoApplyFilters/);
	assert.match(appJs, /Filters update as you choose\./);
	assert.match(appJs, /iv-movements-results/);
	assert.match(appJs, /_reuseShell/);
	assert.match(appJs, /iv-btn--sr-fallback/);
	assert.match(appJs, /data-iv-access-allowlists/);
	assert.match(appJs, /Or browse items/);
	assert.doesNotMatch(appJs, /Check for errors/);
});

test('Bachus pass 5: auto-start open stocktake + confirm-is-consent + less chrome', () => {
	assert.match(appJs, /stocktakeAutoStarting/);
	assert.match(appJs, /Starting count…/);
	assert.match(appJs, /Accept conflicts and close/);
	assert.match(appJs, /Close and leave uncounted/);
	assert.match(appJs, /Confirming means you reviewed the conflicts/);
	assert.doesNotMatch(appJs, /iv-ack-conflicts/);
	assert.doesNotMatch(appJs, /iv-abandon-uncounted/);
	assert.doesNotMatch(appJs, /function syncFilterActive/);
	assert.match(appJs, /Showing transfer group/);
	assert.match(appJs, /Export DATEV-style CSV/);
	assert.match(appJs, /iv-actions-more/);
	assert.match(appJs, /perLoc\.data && perLoc\.data\.length > 0/);
	assert.match(appJs, /disclosureSection\(tr\('Mobile seats'/);
	assert.match(appJs, /disclosureSection\(tr\('Scanner device slots'/);
});

test('Bachus pass 6: transfer confirm-only + stocktake location-first + import skip consent', () => {
	assert.match(appJs, /\(!opts\.transfer \|\| !!toSelect\.value\)/);
	assert.match(appJs, /iv-stocktake-more/);
	assert.match(appJs, /More stocktake options/);
	assert.match(appJs, /iv-stocktake-options/);
	assert.match(appJs, /Counting options/);
	assert.match(appJs, /Import will skip rows with errors and keep the good ones/);
	assert.match(appJs, /Nothing to import — fix the errors first/);
	assert.doesNotMatch(appJs, /Skip rows with errors instead of stopping/);
	assert.match(appJs, /Add favourite/);
	assert.match(appJs, /Remove favourite/);
	assert.doesNotMatch(appJs, /★ Remove from favourites/);
	assert.match(appJs, /primary: !\(ctx\.isOffice \|\| ctx\.isAppAdmin\)/);
	assert.match(appJs, /locs\.length === 1/);
	assert.match(appJs, /aria-pressed/);
	assert.match(appJs, /function createLocationChooser/);
	assert.match(appJs, /function renderStocktakeNewPage/);
	assert.match(appJs, /stocktakeNew/);
	assert.match(appJs, /Where are you counting/);
	assert.match(appJs, /data-iv-loc-chooser/);
	assert.match(appJs, /iv-stocktake-new/);
	assert.doesNotMatch(appJs, /fillSelect\(locSelect, locs/);
	assert.match(appJs, /active=1&q=' \+ encodeURIComponent\(term\)/);
});

test('Aristoteles: DutyCheck-parity nav hints CSS + Access lockout tip', () => {
	assert.match(appCss, /\.iv-nav__hint/);
	assert.match(appCss, /\.iv-empty--quickstart/);
	assert.match(appCss, /\.iv-quickstart/);
	assert.match(appCss, /\.iv-hint-dismiss/);
	assert.match(appCss, /\.iv-tip__title/);
	assert.match(appCss, /\.iv-form-actions/);
	assert.match(appCss, /\.iv-switch-field/);
	assert.match(appJs, /function accessQuickstartCard/);
	assert.match(appJs, /function wireDismissibleHint/);
	assert.match(appJs, /settings_access_quickstart_v1/);
	assert.match(appJs, /This list controls the door, not the stock/);
	assert.match(appJs, /Add allowlist entries before turning restriction on/);
	assert.match(appJs, /Otherwise you can lock yourself out until a system admin re-opens the app/);
	assert.match(appJs, /iv:hint:/);
	assert.match(appJs, /function settingsSrTitle/);
	assert.match(appJs, /function settingsSwitchField/);
	assert.match(appJs, /function settingsFormActions/);
	assert.match(appJs, /function settingsPageSection/);
	assert.match(appJs, /People who may change settings \(in addition to system admins\)/);
	assert.doesNotMatch(appJs, /1\. Choose the audience/);
	assert.doesNotMatch(appJs, /3\. Delegate app-admin powers carefully/);
	assert.doesNotMatch(appJs, /tr\('Quick start'\)/);
	assert.doesNotMatch(appJs, /Adding users or groups here only lets them open InventoryCheck/);
});

test('Bachus: DutyCheck-style stocktake how-to cards', () => {
	assert.match(appJs, /function stocktakeHowToCard/);
	assert.match(appJs, /function howToCard/);
	assert.match(appJs, /function pageHowToCard/);
	assert.match(appJs, /dashboard_howto_v1/);
	assert.match(appJs, /items_howto_v1/);
	assert.match(appJs, /locations_howto_v1/);
	assert.match(appJs, /movements_howto_v1/);
	assert.match(appJs, /item_detail_howto_v1/);
	assert.match(appJs, /location_detail_howto_v1/);
	assert.match(appJs, /settings_office_howto_v1/);
	assert.match(appJs, /settings_license_howto_v1/);
	assert.match(appJs, /function withSettingsHowTo/);
	assert.match(appJs, /stocktake_detail_howto_v1/);
	assert.match(appJs, /How booking works/);
	assert.match(appJs, /How items work/);
	assert.match(appJs, /How locations work/);
	assert.match(appJs, /How movements work/);
	assert.match(appJs, /How this item page works/);
	assert.match(appJs, /How office rights work/);
	assert.match(appJs, /text: tr\('Hide tips'\)/);
	assert.match(appCss, /\.iv-howto/);
	assert.match(appCss, /\.iv-howto[\s\S]{0,120}?display:\s*flex/);
	assert.match(appCss, /\.iv-howto[\s\S]{0,200}?flex-direction:\s*column/);
	assert.match(appCss, /\.iv-stocktake-howto \.iv-quickstart|\.iv-howto \.iv-quickstart/);
	assert.match(appCss, /container-type:\s*inline-size/);
	assert.match(appCss, /@container iv-howto \(max-width: 519px\)/);
	assert.doesNotMatch(appJs, /iv-card iv-empty iv-empty--quickstart iv-howto/);
	assert.match(appJs, /Never use \.iv-empty here/);
	assert.match(appCss, /\.iv-quickstart__cta/);
	assert.match(appCss, /@media \(min-width: 520px\)/);
	assert.match(appCss, /@media \(min-width: 900px\)/);
	assert.match(appCss, /justify-content:\s*center/);
});

test('Bachus pass 7: 1-click stocktake + page chooser + quieter filters', () => {
	assert.match(appJs, /function createAndStartStocktake/);
	assert.match(appJs, /locs\.length === 1/);
	assert.match(appJs, /skip the chooser entirely/);
	assert.match(appJs, /Add a location first, then start a stocktake/);
	assert.match(appJs, /function createLocationChooser/);
	assert.match(appJs, /function renderStocktakeNewPage/);
	assert.match(appJs, /stocktakeNew/);
	assert.match(appJs, /iv-stocktake-new/);
	assert.match(appJs, /Where are you counting/);
	assert.match(appJs, /labelSrOnly: true/);
	assert.match(appJs, /Office access required/);
	assert.match(appJs, /Only office users and app admins can start a stocktake/);
	assert.match(appJs, /if \(!locId \|\| locId < 1 \|\| locId !== Math\.floor\(locId\)\)/);
	assert.match(appJs, /Arm busy before the async create path/);
	assert.match(appJs, /setBusy\(true\);\s*opts\.onPick\(loc\)/);
	assert.doesNotMatch(appJs, /iv-dialog--stocktake-chooser/);
	assert.match(appCss, /\.iv-loc-chooser__item/);
	assert.match(appCss, /\.iv-loc-chooser__list/);
	assert.match(appCss, /\.iv-stocktake-new/);
	assert.match(appJs, /iv-sr-only.*Filter movements|className: 'iv-sr-only', text: tr\('Filter movements'\)/);
	assert.doesNotMatch(appJs, /QR and Code 128 encode the scan code/);
	assert.doesNotMatch(appJs, /By default every logged-in user can open InventoryCheck/);
	assert.match(appJs, /Administrators always keep access/);
	// Deactivate tucked under More on item/location detail.
	assert.match(appJs, /Download SVG/);
	assert.match(appJs, /iv-actions-more/);
});

test('Aristoteles: dialog/Change races + movements catch + stocktake freshness + ACL', () => {
	assert.match(appJs, /DIALOG_INERT_IDS/);
	assert.match(appJs, /iv-page-actions/);
	assert.match(appJs, /return \{ close: close, overlay: overlay, dialogEl: dialogEl, bodyEl: bodyEl \}/);
	assert.match(appJs, /dlg\.close\(\)/);
	assert.doesNotMatch(appJs, /querySelector\('\.iv-dialog-overlay \.iv-dialog__close'\)/);
	assert.match(appJs, /if \(nextPrefill\.mode === 'delta'\)/);
	assert.match(appJs, /if \(loadSeq !== mount\._ivMovLoadSeq\)/);
	assert.match(appJs, /reuseShell && resultsHost/);
	assert.match(appJs, /_ivPendingSaves/);
	assert.match(appJs, /Still saving counts/);
	assert.match(appJs, /function stocktakeUncounted/);
	assert.match(appJs, /campaign\.linesUncounted != null/);
	assert.doesNotMatch(appJs, /campaign\.lines && campaign\.lines\.length\)\s*\?\s*campaign\.lines\.filter/);
	assert.match(appJs, /subjectId !== ''/);
	assert.match(appJs, /payload\.subjectId = subjectId/);
	assert.match(appJs, /dryRunSeq/);
	assert.match(appJs, /lastDry\.csv === text/);
	assert.match(appJs, /movementDialogOpening/);
	assert.match(appJs, /stocktakeCreating/);
	assert.match(appJs, /stocktakeOpenBusy/);
	assert.match(appJs, /setBusy\(listHost, false\)/);
	assert.match(appJs, /r\.itemName \|\| r\.sku/);
});

test('Bachus pass 9: inventur speed + balance-row booking + quieter chrome', () => {
	assert.match(appJs, /Count this page as system/);
	assert.match(appJs, /data-iv-count-as-system/);
	assert.match(appJs, /function countRemainingAsSystem/);
	assert.match(appJs, /ev\.key !== 'Enter'/);
	assert.match(appJs, /iv-stocktake-count-input/);
	assert.match(appJs, /data-iv-balance-actions/);
	assert.match(appJs, /iv-label-preview-disclosure/);
	assert.match(appJs, /className: 'iv-sr-only', text: tr\('OK'\)/);
	assert.doesNotMatch(appJs, /iv-items-filter-active/);
	assert.doesNotMatch(appJs, /iv-loc-filter-active/);
	// Movements: book first, export under More (not primary).
	assert.match(appJs, /Export DATEV-style CSV/);
	assert.doesNotMatch(appJs, /className: 'button primary',\s*\n\s*text: tr\('Export CSV'\)/);
});

test('Bachus pass 10: qty-only balance booking + quieter lists', () => {
	assert.match(appJs, /var mastersKnown =/);
	assert.match(appJs, /Enter the quantity, then confirm\./);
	assert.doesNotMatch(appJs, /&& hasQtyPref\n\t\t\t\t&& !\(opts\.adjust/);
	assert.match(appJs, /conflictCount > 0/);
	assert.match(appJs, /iv-filter-panel__intro iv-sr-only/);
	assert.match(appJs, /clearBtn\.hidden = !\(q\.value/);
	assert.match(appJs, /iv-name-with-status/);
	assert.match(appJs, /tr\('Inactive'\)/);
	assert.doesNotMatch(appJs, /label: tr\('Active'\), render: function \(r\) \{ return r\.active \? tr\('Yes'\)/);
});

test('Bachus pass 11: ACL revoke 1-click + no coming-soon scanner chrome', () => {
	assert.match(appJs, /data-iv-acl-clear/);
	assert.match(appJs, /Clear grants/);
	assert.match(appJs, /Location grants cleared\./);
	assert.match(appJs, /Pair shared scanners in the InventoryCheck mobile app/);
	assert.doesNotMatch(appJs, /Native scanner app: coming soon/);
	assert.match(appJs, /Add locations as chips\. Use Clear grants below/);
});
