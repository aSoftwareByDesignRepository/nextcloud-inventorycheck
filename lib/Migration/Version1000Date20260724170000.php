<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial InventoryCheck schema (iv_*). Logical names ≤ 27 chars; explicit PK/index ≤ 30.
 */
class Version1000Date20260724170000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_locations')) {
			$t = $schema->createTable('iv_locations');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('kind', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'other']);
			$t->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_loc_pk');
			$t->addUniqueIndex(['code'], 'iv_loc_code_uq');
			$t->addIndex(['kind'], 'iv_loc_kind_idx');
		}

		if (!$schema->hasTable('iv_items')) {
			$t = $schema->createTable('iv_items');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('sku', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('scan_code', Types::STRING, ['length' => 128, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('uom', Types::STRING, ['length' => 32, 'notnull' => true, 'default' => 'pcs']);
			$t->addColumn('reorder_level', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_item_pk');
			$t->addUniqueIndex(['sku'], 'iv_item_sku_uq');
			$t->addUniqueIndex(['scan_code'], 'iv_item_scan_uq');
			$t->addIndex(['name'], 'iv_item_name_idx');
		}

		if (!$schema->hasTable('iv_balances')) {
			$t = $schema->createTable('iv_balances');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('qty', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'iv_bal_pk');
			$t->addUniqueIndex(['item_id', 'location_id'], 'iv_bal_item_loc_uq');
			$t->addIndex(['location_id'], 'iv_bal_loc_idx');
		}

		if (!$schema->hasTable('iv_movements')) {
			$t = $schema->createTable('iv_movements');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('qty_delta', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('qty_after', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('transfer_group', Types::STRING, ['length' => 36, 'notnull' => false]);
			$t->addColumn('counterparty_loc_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('reason', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('ref_type', Types::STRING, ['length' => 32, 'notnull' => false]);
			$t->addColumn('ref_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_mov_pk');
			$t->addIndex(['item_id', 'created_at'], 'iv_mov_item_idx');
			$t->addIndex(['location_id', 'created_at'], 'iv_mov_loc_idx');
			$t->addIndex(['transfer_group'], 'iv_mov_grp_idx');
			$t->addIndex(['kind'], 'iv_mov_kind_idx');
		}

		if (!$schema->hasTable('iv_license_state')) {
			$t = $schema->createTable('iv_license_state');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('customer_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('issued_at', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('valid_until', Types::STRING, ['length' => 10, 'notnull' => true]);
			$t->addColumn('mobile_seats', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('scan_devices', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('bundle', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('payload_b64', Types::TEXT, ['notnull' => true]);
			$t->addColumn('signature_b64', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('applied_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('applied_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_lic_pk');
		}

		if (!$schema->hasTable('iv_mobile_seats')) {
			$t = $schema->createTable('iv_mobile_seats');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('assigned_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('assigned_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_seat_pk');
			$t->addUniqueIndex(['uid'], 'iv_seat_uid_uq');
		}

		if (!$schema->hasTable('iv_scan_devices')) {
			$t = $schema->createTable('iv_scan_devices');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('pair_code_hash', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('pair_code_expires', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('token_hash', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('paired_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('last_seen_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_dev_pk');
		}

		return $schema;
	}
}
