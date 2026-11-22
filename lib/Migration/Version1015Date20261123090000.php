<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * IV-SUB-LOCATIONS (MH-01): one-level location hierarchy.
 * parent_id nullable + index; depth cap enforced in the service layer
 * (parent must itself be a root), which keeps the schema portable and
 * every location_id consumer (balances, movements, ACL, favourites)
 * unchanged.
 */
class Version1015Date20261123090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_locations')) {
			return null;
		}
		$t = $schema->getTable('iv_locations');
		if (!$t->hasColumn('parent_id')) {
			$t->addColumn('parent_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
		}
		if (!$t->hasIndex('iv_loc_parent_idx')) {
			$t->addIndex(['parent_id'], 'iv_loc_parent_idx');
		}

		return $schema;
	}
}
