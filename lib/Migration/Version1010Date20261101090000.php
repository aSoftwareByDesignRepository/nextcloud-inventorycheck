<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Restock & procurement W1 (SPEC restock-procurement §9):
 * iv_items gains order-pack, supplier, on-order marker, lead-time and
 * category columns; iv_movements gains the immutable receive spend total.
 * All additive/nullable — no semantic change to existing columns.
 */
class Version1010Date20261101090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('iv_items')) {
			$t = $schema->getTable('iv_items');
			if (!$t->hasColumn('order_pack_qty')) {
				$t->addColumn('order_pack_qty', Types::INTEGER, ['notnull' => false, 'default' => null]);
			}
			if (!$t->hasColumn('order_pack_price_minor')) {
				$t->addColumn('order_pack_price_minor', Types::BIGINT, ['notnull' => false, 'default' => null]);
			}
			if (!$t->hasColumn('supplier_name')) {
				$t->addColumn('supplier_name', Types::STRING, ['length' => 200, 'notnull' => false, 'default' => null]);
			}
			if (!$t->hasColumn('supplier_sku')) {
				$t->addColumn('supplier_sku', Types::STRING, ['length' => 200, 'notnull' => false, 'default' => null]);
			}
			if (!$t->hasColumn('supplier_lead_days')) {
				$t->addColumn('supplier_lead_days', Types::INTEGER, ['notnull' => false, 'default' => null]);
			}
			if (!$t->hasColumn('on_order_qty')) {
				$t->addColumn('on_order_qty', Types::INTEGER, ['notnull' => false, 'default' => null]);
			}
			if (!$t->hasColumn('on_order_at')) {
				$t->addColumn('on_order_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			}
			if (!$t->hasColumn('on_order_by')) {
				$t->addColumn('on_order_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			}
			if (!$t->hasColumn('category')) {
				$t->addColumn('category', Types::STRING, ['length' => 100, 'notnull' => false, 'default' => null]);
			}
			if (!$t->hasColumn('criticality')) {
				$t->addColumn('criticality', Types::STRING, ['length' => 10, 'notnull' => true, 'default' => 'none']);
			}
			if (!$t->hasColumn('delivery_note_path')) {
				$t->addColumn('delivery_note_path', Types::STRING, ['length' => 512, 'notnull' => false, 'default' => null]);
			}
		}

		if ($schema->hasTable('iv_movements')) {
			$t = $schema->getTable('iv_movements');
			if (!$t->hasColumn('spend_minor')) {
				$t->addColumn('spend_minor', Types::BIGINT, ['notnull' => false, 'default' => null]);
			}
			if (!$t->hasIndex('iv_mov_kind_created_idx')) {
				$t->addIndex(['kind', 'created_at'], 'iv_mov_kind_created_idx');
			}
		}

		return $schema;
	}
}
