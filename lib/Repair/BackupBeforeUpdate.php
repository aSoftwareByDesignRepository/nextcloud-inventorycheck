<?php

declare(strict_types=1);

/**
 * Pre-migration repair step: snapshot InventoryCheck data before schema migrations run.
 *
 * Registered under {@see info.xml} `<repair-steps><pre-migration>` so every
 * `occ app:update projectcheck` and app reinstall over an existing version creates
 * a recoverable backup before migrations mutate the database.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\InventoryCheck\Repair;

use OCA\InventoryCheck\Exception\UpgradeBackupException;
use OCA\InventoryCheck\Service\UpgradeBackupService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class BackupBeforeUpdate implements IRepairStep
{
	public function __construct(
		private readonly UpgradeBackupService $backupService,
	) {
	}

	public function getName(): string
	{
		return 'Back up InventoryCheck data before update migrations';
	}

	public function run(IOutput $output): void
	{
		if (!$this->backupService->hasDataToBackup()) {
			$output->info('InventoryCheck: no existing tables to back up (fresh install); skipping pre-update snapshot.');
			return;
		}

		try {
			$result = $this->backupService->createSnapshot('pre-migration');
		} catch (UpgradeBackupException $e) {
			$output->warning('InventoryCheck: pre-update backup failed: ' . $e->getMessage());
			// Oversized ledgers must not stall occ upgrade in maintenance mode —
			// operators still have DB dumps / snapshots outside this JSON path.
			if (str_contains($e->getMessage(), 'exceeds backup row limit')) {
				$output->warning(
					'InventoryCheck: continuing upgrade without a JSON snapshot. '
					. 'Take a database dump before updating large warehouses.',
				);
				return;
			}
			throw $e;
		}

		$tableCount = count($result['manifest']['tables'] ?? []);
		$output->info(sprintf(
			'InventoryCheck: pre-update backup created (%s, %d table(s)). '
			. 'Restore with `occ inventorycheck:upgrade-backup restore --latest --force` if needed.',
			$result['id'],
			$tableCount,
		));
	}
}
