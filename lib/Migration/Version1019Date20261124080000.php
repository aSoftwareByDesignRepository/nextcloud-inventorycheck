<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1019Date20261124080000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_loan_requests')) {
			$t = $schema->createTable('iv_loan_requests');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->addColumn('person_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->addColumn('requester_uid', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$t->addColumn('qty', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('purpose', Types::TEXT, ['notnull' => false, 'default' => null]);
			$t->addColumn('wanted_from', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->addColumn('wanted_to', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
			$t->addColumn('decided_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$t->addColumn('decided_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->addColumn('loan_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false, 'default' => null]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['status', 'created_at'], 'iv_lr_status_idx');
			$t->addIndex(['person_id', 'status'], 'iv_lr_person_idx');
			$t->addIndex(['item_id', 'status'], 'iv_lr_item_idx');
		}

		if (!$schema->hasTable('iv_reservations')) {
			$t = $schema->createTable('iv_reservations');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('person_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->addColumn('event_label', Types::STRING, ['length' => 190, 'notnull' => false, 'default' => null]);
			$t->addColumn('qty', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('from_ts', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('to_ts', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'reserved']);
			$t->addColumn('converted_loan_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['item_id', 'location_id', 'status'], 'iv_res_item_idx');
			$t->addIndex(['status', 'to_ts'], 'iv_res_expiry_idx');
		}

		if (!$schema->hasTable('iv_member_tokens')) {
			$t = $schema->createTable('iv_member_tokens');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('person_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('token_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('revoked_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->addColumn('last_used_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['token_hash'], 'iv_mt_hash_uq');
			$t->addIndex(['person_id'], 'iv_mt_person_idx');
		}

		return $schema;
	}
}
