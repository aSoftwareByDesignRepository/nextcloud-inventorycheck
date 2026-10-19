<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Suite flange F8 — invoice-push outbox.
 *
 * A project-bound stock issue commits first (the issue is the business
 * fact); mirroring its material lines onto an InvoiceCheck draft is a
 * best-effort downstream write. `iv_inv_push` is the flag-and-retry
 * outbox: one row per issue, unique on `issue_ref` (deterministic
 * `iv:project:{projectId}:{firstMovementId}`), drained by the retry job
 * and the settings retry action.
 */
class Version1009Date20261019090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('iv_inv_push')) {
			$t = $schema->createTable('iv_inv_push');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('issue_ref', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('project_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('payload_json', Types::TEXT, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
			$t->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_error', Types::STRING, ['length' => 255, 'notnull' => false]);
			$t->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->addColumn('updated_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'iv_invpush_pk');
			$t->addUniqueIndex(['issue_ref'], 'iv_invpush_ref_uq');
			$t->addIndex(['status', 'attempts'], 'iv_invpush_retry_idx');
		}
		return $schema;
	}
}
