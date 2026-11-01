<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Restock W4: order sheets (F-13/F-15/F-16) and supplier registry (F-14).
 * Sheets: draft → sent → received|cancelled; lines track qty + received_qty
 * so a receive can auto-progress the sheet inside the movement TX.
 */
class Version1012Date20261101110000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_suppliers')) {
			$t = $schema->createTable('iv_suppliers');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 200, 'notnull' => true]);
			$t->addColumn('email', Types::STRING, ['length' => 320, 'notnull' => false]);
			$t->addColumn('note', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$t->setPrimaryKey(['id'], 'iv_sup_pk');
		}

		if (!$schema->hasTable('iv_item_suppliers')) {
			$t = $schema->createTable('iv_item_suppliers');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('supplier_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('supplier_sku', Types::STRING, ['length' => 200, 'notnull' => false]);
			$t->addColumn('price_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('pack_qty', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('lead_days', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('preferred', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$t->setPrimaryKey(['id'], 'iv_isup_pk');
			$t->addUniqueIndex(['item_id', 'supplier_id'], 'iv_isup_uq');
		}

		if (!$schema->hasTable('iv_order_sheets')) {
			$t = $schema->createTable('iv_order_sheets');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('supplier_name', Types::STRING, ['length' => 200, 'notnull' => false]);
			$t->addColumn('supplier_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'draft']);
			$t->addColumn('total_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('note', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('sent_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('received_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('cancelled_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'iv_osheet_pk');
		}

		if (!$schema->hasTable('iv_order_sheet_lines')) {
			$t = $schema->createTable('iv_order_sheet_lines');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('sheet_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('qty', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('received_qty', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('line_est_minor', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'iv_osline_pk');
			$t->addIndex(['sheet_id'], 'iv_osline_sheet_idx');
			$t->addIndex(['item_id'], 'iv_osline_item_idx');
		}

		return $schema;
	}
}
