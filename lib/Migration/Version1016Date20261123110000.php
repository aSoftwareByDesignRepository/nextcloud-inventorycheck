<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * IV-CUSTOM-FIELDS (MH-01): admin-defined item attributes — EAV-lite.
 *
 * `iv_item_fields` holds the definitions (name, exactly-4 types, select
 * options, required, position, active); `iv_item_field_values` holds one
 * coerced value per (item, field). Unique name *among active defs* is
 * enforced case-insensitively in the service layer — partial unique
 * indexes are not portable across MySQL/PostgreSQL/Oracle.
 */
class Version1016Date20261123110000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_item_fields')) {
			$t = $schema->createTable('iv_item_fields');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 64, 'notnull' => true]);
			// text|number|date|select — app-validated enum for portability.
			// Default must match ItemField::$type: Entity::setter skips
			// marking same-value assignments, so an unmarked insert relies
			// on the column default being identical to the property's.
			$t->addColumn('type', Types::STRING, ['length' => 10, 'notnull' => true, 'default' => 'text']);
			$t->addColumn('options_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('required', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'iv_ifld_pk');
			$t->addIndex(['active', 'position'], 'iv_ifld_act_idx');
			$t->addIndex(['name'], 'iv_ifld_name_idx');
		}

		if (!$schema->hasTable('iv_item_field_values')) {
			$t = $schema->createTable('iv_item_field_values');
			// Surrogate id PK — every iv_ table carries one (uninstall
			// watermark sweeps SELECT max(id)); (item_id, field_id)
			// uniqueness is enforced by iv_ifval_uq instead.
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('field_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('value', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'iv_ifval_pk');
			$t->addUniqueIndex(['item_id', 'field_id'], 'iv_ifval_uq');
			$t->addIndex(['field_id'], 'iv_ifval_fld_idx');
		}

		return $schema;
	}
}
