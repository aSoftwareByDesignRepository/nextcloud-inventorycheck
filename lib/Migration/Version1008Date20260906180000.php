<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Bind scan idempotency keys to a payload fingerprint so a lost ACK cannot
 * silently replay a *different* qty/location under the same clientRequestId.
 */
class Version1008Date20260906180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('iv_scan_idem')) {
			$t = $schema->getTable('iv_scan_idem');
			if (!$t->hasColumn('payload_hash')) {
				$t->addColumn('payload_hash', Types::STRING, [
					'length' => 64,
					'notnull' => false,
					'default' => null,
				]);
				$output->info('Added iv_scan_idem.payload_hash');
			}
		}

		return $schema;
	}
}
