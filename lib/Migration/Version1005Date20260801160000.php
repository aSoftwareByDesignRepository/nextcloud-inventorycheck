<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Wave D: target_stock, default_location_id, reason_code on movements.
 *
 * No new tables — reason taxonomy is a fixed PHP catalog (ReasonCodes).
 * Config keys require_adjust_reason / require_location_scan live in appconfig.
 */
class Version1005Date20260801160000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('iv_items')) {
			$items = $schema->getTable('iv_items');
			if (!$items->hasColumn('target_stock')) {
				$items->addColumn('target_stock', 'integer', [
					'notnull' => false,
					'default' => null,
				]);
			}
			if (!$items->hasColumn('default_location_id')) {
				$items->addColumn('default_location_id', 'bigint', [
					'notnull' => false,
					'default' => null,
				]);
				$items->addIndex(['default_location_id'], 'iv_item_defloc_idx');
			}
		}

		if ($schema->hasTable('iv_movements')) {
			$mov = $schema->getTable('iv_movements');
			if (!$mov->hasColumn('reason_code')) {
				$mov->addColumn('reason_code', 'string', [
					'notnull' => false,
					'length' => 32,
					'default' => null,
				]);
				$mov->addIndex(['reason_code', 'created_at'], 'iv_mov_reason_idx');
			}
		}

		return $schema;
	}
}
