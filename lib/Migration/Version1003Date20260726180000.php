<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Wave C: track_mode / lot_code + per-location ACL.
 */
class Version1003Date20260726180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('iv_items')) {
			$items = $schema->getTable('iv_items');
			if (!$items->hasColumn('track_mode')) {
				$items->addColumn('track_mode', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'none',
				]);
			}
		}

		if ($schema->hasTable('iv_movements')) {
			$mov = $schema->getTable('iv_movements');
			if (!$mov->hasColumn('lot_code')) {
				$mov->addColumn('lot_code', Types::STRING, [
					'notnull' => false,
					'length' => 64,
					'default' => null,
				]);
			}
			if (!$mov->hasIndex('iv_mov_lot_idx')) {
				$mov->addIndex(['item_id', 'lot_code'], 'iv_mov_lot_idx');
			}
		}

		if (!$schema->hasTable('iv_loc_acl')) {
			$t = $schema->createTable('iv_loc_acl');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('subject_type', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('subject_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('location_id', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_lacl_pk');
			$t->addUniqueIndex(['subject_type', 'subject_id', 'location_id'], 'iv_lacl_uq');
			$t->addIndex(['location_id'], 'iv_lacl_loc_idx');
		}

		return $schema;
	}
}
