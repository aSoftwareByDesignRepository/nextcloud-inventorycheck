<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Unique indexes on device auth material (AC-18 / N5).
 * Multiple NULLs remain allowed (unpaired / inactive slots).
 */
class Version1001Date20260724210000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('iv_scan_devices')) {
			return null;
		}
		$table = $schema->getTable('iv_scan_devices');
		if (!$table->hasIndex('iv_dev_tok_uq')) {
			$table->addUniqueIndex(['token_hash'], 'iv_dev_tok_uq');
		}
		if (!$table->hasIndex('iv_dev_pair_uq')) {
			$table->addUniqueIndex(['pair_code_hash'], 'iv_dev_pair_uq');
		}
		return $schema;
	}
}
