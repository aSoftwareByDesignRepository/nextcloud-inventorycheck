<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Mobile scan idempotency keys — prevent double-book when the companion
 * retries after a lost HTTP 200 (outbox flush / bag commit ACK loss).
 */
class Version1007Date20260830210000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_scan_idem')) {
			$t = $schema->createTable('iv_scan_idem');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('request_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
			$t->addColumn('response_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('completed_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->setPrimaryKey(['id'], 'iv_scan_idem_pk');
			$t->addUniqueIndex(['actor_uid', 'request_id'], 'iv_scan_idem_uq');
			$output->info('Created iv_scan_idem');
		}

		return $schema;
	}
}
