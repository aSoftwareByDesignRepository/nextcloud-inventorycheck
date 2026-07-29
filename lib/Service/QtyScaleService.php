<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ConflictException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * C1: one-time, irreversible ×1000 rescale of every stored quantity column
 * so the ledger can represent up to 3 decimal places.
 *
 * Guarded by an exclusive lock plus a re-check of the persisted scale after
 * acquiring it (double-checked locking), so two concurrent enable calls —
 * or a retry after a slow request — can never multiply the ledger twice.
 * There is deliberately no disable path: once columns are rescaled,
 * reversing it would require re-deriving whether stored precision was real
 * or an artefact of the scale, which is not safely invertible.
 */
class QtyScaleService
{
	private const LOCK = 'inventorycheck/qty_scale';
	private const LOCK_ATTEMPTS = 40;
	private const LOCK_RETRY_US = 25_000;

	/** @var list<string> iv_balances columns rescaled on enable. */
	private const BALANCE_COLUMNS = ['qty'];
	/** @var list<string> iv_movements columns rescaled on enable. */
	private const MOVEMENT_COLUMNS = ['qty_delta', 'qty_after'];
	/** @var list<string> iv_items columns rescaled on enable. */
	private const ITEM_COLUMNS = ['reorder_level'];
	/** @var list<string> iv_cc_line columns rescaled on enable (NULL stays NULL — arithmetic on NULL is NULL). */
	private const CC_LINE_COLUMNS = ['system_qty', 'qty_counted'];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * @return array{qtyScale: int, changed: bool}
	 */
	public function enableFractional(): array
	{
		if (QtyScale::current($this->config) === QtyScale::SCALE_MILLI) {
			return ['qtyScale' => QtyScale::SCALE_MILLI, 'changed' => false];
		}

		$this->acquireLock();
		try {
			// Re-check inside the lock: a concurrent request may have
			// finished the migration while we were waiting for it — never
			// double-migrate the ledger.
			if (QtyScale::current($this->config) === QtyScale::SCALE_MILLI) {
				return ['qtyScale' => QtyScale::SCALE_MILLI, 'changed' => false];
			}

			$this->db->beginTransaction();
			try {
				$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
				$this->multiply($prefix, 'iv_balances', self::BALANCE_COLUMNS);
				$this->multiply($prefix, 'iv_movements', self::MOVEMENT_COLUMNS);
				$this->multiply($prefix, 'iv_items', self::ITEM_COLUMNS);
				if ($this->db->tableExists('iv_cc_line')) {
					$this->multiply($prefix, 'iv_cc_line', self::CC_LINE_COLUMNS);
				}
				$this->config->setAppValue(Application::APP_ID, QtyScale::KEY, (string)QtyScale::SCALE_MILLI);
				$this->db->commit();
			} catch (\Throwable $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			}

			return ['qtyScale' => QtyScale::SCALE_MILLI, 'changed' => true];
		} finally {
			$this->locking->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @param list<string> $columns */
	private function multiply(string $prefix, string $table, array $columns): void
	{
		$sets = implode(', ', array_map(
			static fn (string $c): string => $c . ' = ' . $c . ' * ' . QtyScale::FACTOR,
			$columns,
		));
		$this->db->executeStatement('UPDATE ' . $prefix . $table . ' SET ' . $sets);
	}

	private function acquireLock(): void
	{
		for ($i = 0; $i < self::LOCK_ATTEMPTS; $i++) {
			try {
				$this->locking->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
				return;
			} catch (LockedException) {
				usleep(self::LOCK_RETRY_US);
			}
		}
		throw new ConflictException('qty_scale_migration_in_progress');
	}
}
