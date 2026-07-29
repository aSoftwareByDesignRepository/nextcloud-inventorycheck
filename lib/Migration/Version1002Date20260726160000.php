<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Wave A–B (+ C4): photos, notify dedupe, cycle-count, location favourites,
 * supplier/price columns on items.
 */
class Version1002Date20260726160000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('iv_items')) {
			$items = $schema->getTable('iv_items');
			if (!$items->hasColumn('photo_name')) {
				$items->addColumn('photo_name', Types::STRING, [
					'notnull' => false,
					'length' => 128,
					'default' => null,
				]);
			}
			if (!$items->hasColumn('photo_mime')) {
				$items->addColumn('photo_mime', Types::STRING, [
					'notnull' => false,
					'length' => 64,
					'default' => null,
				]);
			}
			if (!$items->hasColumn('supplier_note')) {
				$items->addColumn('supplier_note', Types::STRING, [
					'notnull' => false,
					'length' => 255,
					'default' => null,
				]);
			}
			if (!$items->hasColumn('last_price_minor')) {
				$items->addColumn('last_price_minor', Types::BIGINT, [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		if (!$schema->hasTable('iv_notif_log')) {
			$t = $schema->createTable('iv_notif_log');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('dedupe_key', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_nlog_pk');
			$t->addUniqueIndex(['dedupe_key'], 'iv_nlog_key_uq');
			$t->addIndex(['item_id', 'created_at'], 'iv_nlog_item_idx');
		}

		if (!$schema->hasTable('iv_cc_camp')) {
			$t = $schema->createTable('iv_cc_camp');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('closed_at', Types::INTEGER, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'iv_ccc_pk');
			$t->addIndex(['location_id', 'status'], 'iv_ccc_loc_st_idx');
		}

		if (!$schema->hasTable('iv_cc_line')) {
			$t = $schema->createTable('iv_cc_line');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('campaign_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('system_qty', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('qty_counted', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('posted_mov_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_ccl_pk');
			$t->addUniqueIndex(['campaign_id', 'item_id'], 'iv_ccl_camp_item_uq');
			$t->addIndex(['campaign_id'], 'iv_ccl_camp_idx');
		}

		if (!$schema->hasTable('iv_loc_fav')) {
			$t = $schema->createTable('iv_loc_fav');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_lfav_pk');
			$t->addUniqueIndex(['user_id', 'location_id'], 'iv_lfav_uq');
			$t->addIndex(['user_id'], 'iv_lfav_user_idx');
		}

		return $schema;
	}
}
