<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * IV-RECEIVING-EVIDENCE (C-2): movement-level delivery-note path.
 * A Lieferschein documents ONE delivery event — it belongs on the
 * receive movement, not the item (item.delivery_note_path stays as
 * the legacy display field, unmigrated per AS-03).
 */
class Version1020Date20261123170000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('iv_movements')) {
			$t = $schema->getTable('iv_movements');
			if (!$t->hasColumn('delivery_note_path')) {
				$t->addColumn('delivery_note_path', Types::STRING, [
					'length' => 512, 'notnull' => false, 'default' => null,
				]);
			}
		}

		return $schema;
	}
}
