<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** F-17 procedure kits: a kit issues its lines atomically in one TX. */
class Version1013Date20261101120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_kits')) {
			$t = $schema->createTable('iv_kits');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 200, 'notnull' => true]);
			$t->addColumn('note', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->setPrimaryKey(['id'], 'iv_kit_pk');
		}

		if (!$schema->hasTable('iv_kit_lines')) {
			$t = $schema->createTable('iv_kit_lines');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('kit_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('qty', Types::INTEGER, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_kitline_pk');
			$t->addIndex(['kit_id'], 'iv_kitline_kit_idx');
		}

		return $schema;
	}
}
