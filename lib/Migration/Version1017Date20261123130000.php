<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * IV-COMPLIANCE-PACK (MH-01): hash-chained append-only journal.
 *
 * One row per committed movement; `entry_hash` chains
 * sha256(prev_hash_raw ‖ canonical(payload)). App code INSERTs only —
 * there is deliberately no update/delete path (NN-01): erasing the
 * evidence is the attack this table exists to make visible.
 */
class Version1017Date20261123130000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('iv_journal')) {
			$t = $schema->createTable('iv_journal');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'iv']);
			$t->addColumn('seq', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('ts', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('actor', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('kind', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('movement_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('payload', Types::TEXT, ['notnull' => true]);
			$t->addColumn('prev_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('entry_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->setPrimaryKey(['id'], 'iv_jrn_pk');
			// seq backstop: catches writers that raced the named lock.
			$t->addUniqueIndex(['site_id', 'seq'], 'iv_jrn_seq_uq');
			$t->addIndex(['movement_id'], 'iv_jrn_mov_idx');
		}

		return $schema;
	}
}
