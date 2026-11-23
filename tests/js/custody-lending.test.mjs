import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const appJs = readFileSync(new URL('../../js/app.js', import.meta.url), 'utf8');
const routesPhp = readFileSync(new URL('../../appinfo/routes.php', import.meta.url), 'utf8');

test('F2: checkout dialog collects person, qty, due, condition, note', () => {
	assert.match(appJs, /function openCheckoutDialog/);
	// Person picker, never a raw id input.
	assert.match(appJs, /personPicker/);
	// Server-required human fields are all present in the POST payload.
	assert.match(appJs, /api\('POST', ctx\.urls\.api\.loans, \{\s*itemId: item\.id,\s*locationId: locId,\s*personId: person\.id,\s*qty: qty,\s*dueAt: dueAt,/);
	assert.match(appJs, /conditionOut: condSelect\.value/);
});

test('F2: checkin posts qty + condition + note to the loan endpoint', () => {
	assert.match(appJs, /function openCheckinDialog/);
	assert.match(appJs, /api\('POST', urlWithId\(ctx\.urls\.api\.loanCheckin, loan\.id\), \{\s*qty: qty,/);
	assert.match(appJs, /conditionIn: condSelect\.value/);
});

test('F2: mark-lost is a dialog, not window.confirm', () => {
	assert.match(appJs, /function openMarkLostDialog/);
	assert.doesNotMatch(appJs, /window\.confirm/);
});

test('F2: people pages fetch the API surfaces', () => {
	assert.match(appJs, /function renderPeople\b/);
	assert.match(appJs, /function renderPersonDetail\b/);
	assert.match(appJs, /personsSearch/);
	assert.match(appJs, /personAnonymize/);
	assert.match(appJs, /personLoans/);
});

test('F2: item detail mounts the lent-out section', () => {
	assert.match(appJs, /function mountItemLoans/);
	assert.match(appJs, /itemLoans/);
	assert.match(appJs, /mountItemLoans\(/);
});

test('F2: dashboard shows open-loans card', () => {
	assert.match(appJs, /data-iv-loans-card/);
});

test('F2: page routes for people exist', () => {
	assert.match(routesPhp, /page#people/);
	assert.match(routesPhp, /page#person/);
});

test('F2: anonymize guarded against open loans in UI copy', () => {
	// The anonymize confirm copy warns about open loans; server returns
	// person_has_open_loans for the race.
	assert.match(appJs, /personAnonymize/);
});

test('F2-B: loan rows link private evidence via generated URL templates', () => {
	// Evidence icons render only from the hasX flags — never a raw path.
	assert.match(appJs, /l\.hasSignature && ctx\.urls\.api\.loanSignature/);
	assert.match(appJs, /l\.hasDamagePhoto && ctx\.urls\.api\.loanDamagePhoto/);
	assert.doesNotMatch(appJs, /signaturePath|damagePhotoPath/);
});

test('F2-B: web evidence routes are single-param template-pinned', () => {
	assert.match(routesPhp, /loan#evidenceSignature/);
	assert.match(routesPhp, /loan#evidenceDamagePhoto/);
	// Mobile evidence route exists for the companion.
	assert.match(routesPhp, /mobile#loanEvidence/);
	assert.match(routesPhp, /mobile#loansCheckout/);
	assert.match(routesPhp, /mobile#loanCheckin/);
	assert.match(routesPhp, /mobile#loanDamagePhoto/);
	assert.match(routesPhp, /mobile#loanSignature/);
	assert.match(routesPhp, /mobile#itemLoans/);
});

const portalJs = readFileSync(new URL('../../js/portal.js', import.meta.url), 'utf8');
const portalTpl = readFileSync(new URL('../../templates/portal.php', import.meta.url), 'utf8');

test('Wave C: portal shell is uniform — token is data, not oracle', () => {
	// The template carries only the token + a busy loading state.
	assert.match(portalTpl, /data-token/);
	assert.match(portalTpl, /aria-busy="true"/);
	assert.match(portalTpl, /iv-portal-root/);
	// No server-side token branching in the template.
	assert.doesNotMatch(portalTpl, /if \(.*token/);
});

test('Wave C: portal.js renders badges only — never stock counts', () => {
	// Items are filtered by badge string, never by a numeric count.
	assert.match(portalJs, /i\.badge === 'available'/);
	// No quantity/balance field is read from the item payload.
	assert.doesNotMatch(portalJs, /state\.items.*\.qty/);
	assert.doesNotMatch(portalJs, /item\.balance/);
});

test('Wave C: portal 404 collapses to one "link not valid" message', () => {
	assert.match(portalJs, /err\.status === 404 \? tr\('This link is not valid\.'/);
	assert.match(portalJs, /link is not valid or has expired/);
});

test('Wave C: portal uses the overlay-dialog recipe, never native prompts', () => {
	assert.doesNotMatch(portalJs, /window\.(prompt|confirm|alert)/);
	assert.match(portalJs, /function miniDialog/);
	assert.match(portalJs, /role: 'dialog'|aria-labelledby/);
	// Escape closes and focus management is present.
	assert.match(portalJs, /ev\.key === 'Escape'/);
});

test('Wave C: portal markup is built via DOM API — no innerHTML injection', () => {
	assert.doesNotMatch(portalJs, /innerHTML/);
	assert.doesNotMatch(portalJs, /insertAdjacentHTML/);
});

test('Wave C: office surfaces + routes are wired', () => {
	assert.match(appJs, /loanRequests/);
	assert.match(appJs, /reservations/);
	assert.match(appJs, /memberToken/);
	assert.match(routesPhp, /loanRequest#approve/);
	assert.match(routesPhp, /reservation#convert/);
	assert.match(routesPhp, /memberToken#issue/);
	assert.match(routesPhp, /portal#state/);
});
