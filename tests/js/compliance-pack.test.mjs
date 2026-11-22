import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const appJs = readFileSync(new URL('../../js/app.js', import.meta.url), 'utf8');
const journalPhp = readFileSync(new URL('../../lib/Service/JournalService.php', import.meta.url), 'utf8');
const mapperPhp = readFileSync(new URL('../../lib/Db/JournalEntryMapper.php', import.meta.url), 'utf8');
const movementPhp = readFileSync(new URL('../../lib/Service/MovementService.php', import.meta.url), 'utf8');

test('IV-COMPLIANCE-PACK: journal append rides inside the movement transaction', () => {
	assert.match(movementPhp, /\$this->journal->append\(\[/);
	// Append is inside insertMovement — the single commit choke point,
	// so every kind (incl. future loan kinds) chains for free (AC-1).
	assert.match(movementPhp, /private function insertMovement\([\s\S]*\$this->journal->append/);
});

test('IV-COMPLIANCE-PACK: journal mapper is insert-only (NN-01)', () => {
	assert.doesNotMatch(mapperPhp, /function (update|delete|deleteAll|remove)\(/);
	assert.match(journalPhp, /canonicalPayload/);
	assert.match(journalPhp, /hash_equals/);
	assert.match(journalPhp, /tail_missing/);
});

test('IV-COMPLIANCE-PACK: honest tamper-evident copy — no audit-proof claims (NN-02)', () => {
	const l10nFiles = ['en', 'de'].map((l) =>
		readFileSync(new URL(`../../l10n/${l}.json`, import.meta.url), 'utf8'));
	for (const cat of l10nFiles) {
		const keys = Object.keys(JSON.parse(cat).translations);
		const journalKeys = keys.filter((k) =>
			/journal|chain|tamper|integrit|stock list/i.test(k));
		for (const k of journalKeys) {
			assert.doesNotMatch(k, /audit-proof|GoBD|certified|zertifiziert/i, `banned claim in: ${k}`);
		}
	}
});

test('IV-COMPLIANCE-PACK: settings section renders status, verify, downloads', () => {
	assert.match(appJs, /section === 'journal'/);
	assert.match(appJs, /renderJournalSettings/);
	assert.match(appJs, /api\('POST', ctx\.urls\.api\.journalVerify\)/);
	assert.match(appJs, /aria-live/);
	assert.match(appJs, /stockListOds/);
	assert.match(appJs, /stockListPdf/);
	// Suspect-tamper path uses the error badge variant, not a hand-rolled red.
	assert.match(appJs, /iv-badge--critical/);
});

test('IV-COMPLIANCE-PACK: movement journal maps direction correctly', () => {
	// transfer_out → from=own location, to=counterparty; transfer_in mirrored.
	assert.match(movementPhp, /if \(\$kind === 'transfer_out'\) \{\s*\$fromLoc = \$locationId;\s*\$toLoc = \$counterpartyLocId;/);
	assert.match(movementPhp, /\$kind === 'transfer_in'\) \{\s*\$fromLoc = \$counterpartyLocId;\s*\$toLoc = \$locationId;/);
	// Non-transfer signs follow qty delta.
	assert.match(movementPhp, /\$qtyDelta < 0[\s\S]*\$fromLoc = \$locationId/);
});
