<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Oracle portability: boolean columns must be nullable (Oracle stores bools as int, false under NOT NULL fails). */
class Version1014Date20261102210000 extends SimpleMigrationStep
{
	/** @var array<string, list<array{0: string, 1: bool}>> */
	private const BOOL_COLS = [
		'iv_suppliers' => [['active', true]],
		'iv_item_suppliers' => [['preferred', false]],
		'iv_kits' => [['active', true]],
	];

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		foreach (self::BOOL_COLS as $tableName => $cols) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$t = $schema->getTable($tableName);
			foreach ($cols as [$col, $default]) {
				if ($t->hasColumn($col)) {
					$t->changeColumn($col, [
						'notnull' => false,
						'default' => $default,
					]);
				}
			}
		}

		return $schema;
	}
}
