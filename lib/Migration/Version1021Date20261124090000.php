<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * IV-CUSTODY Wave B: damage-photo path on checkin. Like signature_path
 * it stores a private AppData blob name, never a public URL (NN-07).
 */
class Version1021Date20261124090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('iv_loans')) {
			$t = $schema->getTable('iv_loans');
			if (!$t->hasColumn('damage_photo_path')) {
				$t->addColumn('damage_photo_path', Types::STRING, [
					'length' => 512, 'notnull' => false, 'default' => null,
				]);
			}
		}

		return $schema;
	}
}
