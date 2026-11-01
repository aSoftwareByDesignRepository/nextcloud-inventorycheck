<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Restock W2 / F-07: lot registry. Expiry is a lot *attribute* (recall-grade),
 * not movement history — one row per (item, lot_code), upserted inside the
 * receive transaction with a fail-closed conflict rule.
 */
class Version1011Date20261101100000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_lots')) {
			$t = $schema->createTable('iv_lots');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('lot_code', Types::STRING, ['length' => 200, 'notnull' => true]);
			$t->addColumn('expires_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$t->setPrimaryKey(['id'], 'iv_lots_pk');
			$t->addUniqueIndex(['item_id', 'lot_code'], 'iv_lots_uq');
			$t->addIndex(['expires_at'], 'iv_lots_exp_idx');
		}

		return $schema;
	}
}
