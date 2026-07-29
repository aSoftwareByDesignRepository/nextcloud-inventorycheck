<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * FC-IV-ISSUE race hardening: unique (ref_type, ref_id, item_id) for flange posts.
 *
 * Manual UI movements keep NULL refs — MySQL/MariaDB/Postgres allow multiple NULLs
 * in a unique index, so non-flange rows are unaffected.
 */
class Version1004Date20260726180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('iv_movements')) {
			$mov = $schema->getTable('iv_movements');
			if (!$mov->hasIndex('iv_mov_ref_uq')) {
				$mov->addUniqueIndex(['ref_type', 'ref_id', 'item_id'], 'iv_mov_ref_uq');
			}
		}

		return $schema;
	}
}
