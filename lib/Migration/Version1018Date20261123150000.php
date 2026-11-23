<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * IV-CUSTODY-LENDING Wave A: person registry + loan ledger.
 * Custody movements keep using iv_movements with ref_type='loan' +
 * ref_id=<loan_id> — no new movement column (spec §9).
 */
class Version1018Date20261123150000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_persons')) {
			$t = $schema->createTable('iv_persons');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('display_name', Types::STRING, ['length' => 128, 'notnull' => true]);
			$t->addColumn('member_no', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('nc_user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('anonymized', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'iv_per_pk');
			$t->addIndex(['nc_user_id'], 'iv_per_user_idx');
			$t->addIndex(['display_name'], 'iv_per_name_idx');
		}

		// F2: loan legs need several movements per (ref_type='loan', ref_id,
		// item_id) — checkout + N partial checkins. The 1004 unique index
		// (ref_type, ref_id, item_id) guards flange posts, so it keeps its
		// backstop via a leg discriminator: flange rows stay at ref_leg=0,
		// loan movements carry sequential leg numbers.
		if ($schema->hasTable('iv_movements')) {
			$mov = $schema->getTable('iv_movements');
			if (!$mov->hasColumn('ref_leg')) {
				$mov->addColumn('ref_leg', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			}
			if ($mov->hasIndex('iv_mov_ref_uq')) {
				$mov->dropIndex('iv_mov_ref_uq');
			}
			if (!$mov->hasIndex('iv_mov_ref_uq2')) {
				$mov->addUniqueIndex(['ref_type', 'ref_id', 'item_id', 'ref_leg'], 'iv_mov_ref_uq2');
			}
		}

		if (!$schema->hasTable('iv_loans')) {
			$t = $schema->createTable('iv_loans');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('person_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('qty', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('lot_code', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('qty_returned', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'open']);
			$t->addColumn('due_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('checked_out_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('checked_out_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('returned_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('checked_in_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('condition_out', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'ok']);
			$t->addColumn('condition_in', Types::STRING, ['length' => 16, 'notnull' => false]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('damage_note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('signature_path', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('request_id', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'iv_loan_pk');
			$t->addIndex(['person_id', 'status'], 'iv_loan_per_idx');
			$t->addIndex(['item_id', 'status'], 'iv_loan_item_idx');
			$t->addIndex(['status', 'due_at'], 'iv_loan_due_idx');
		}

		return $schema;
	}
}
