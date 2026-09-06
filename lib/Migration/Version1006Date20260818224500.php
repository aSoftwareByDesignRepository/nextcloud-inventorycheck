<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Companion tables from Version1000 were skipped on some installs (migrate marked
 * complete without createTable). User-delete then fatals on oc_iv_mobile_seats.
 */
class Version1006Date20260818224500 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_mobile_seats')) {
			$t = $schema->createTable('iv_mobile_seats');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('assigned_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('assigned_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_seat_pk');
			$t->addUniqueIndex(['uid'], 'iv_seat_uid_uq');
			$output->info('Created missing iv_mobile_seats');
		}

		if (!$schema->hasTable('iv_scan_devices')) {
			$t = $schema->createTable('iv_scan_devices');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => true]);
			$t->addColumn('pair_code_hash', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('pair_code_expires', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('token_hash', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('paired_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('last_seen_at', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_dev_pk');
			$output->info('Created missing iv_scan_devices');
		}

		return $schema;
	}
}
