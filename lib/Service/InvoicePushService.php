<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\InvoicePush;
use OCA\InventoryCheck\Db\InvoicePushMapper;
use OCP\App\IAppManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * F8 caller side — pushes one outbox row's material lines to
 * InvoiceCheck's `MaterialsLineAppendFacade`.
 *
 * Fail-soft everywhere: the stock issue is already committed, so a
 * missing/disabled InvoiceCheck or a rejected push never rolls anything
 * back — the row stays flagged (pending/failed) and the retry job or the
 * settings retry action replays it. InvoiceCheck dedupes per line on
 * ext_ref, so replay is always safe.
 */
class InvoicePushService
{
	private const FACADE = 'OCA\InvoiceCheck\Facade\MaterialsLineAppendFacade';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly InvoicePushMapper $pushes,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
	) {
	}

	public function facadeAvailable(): bool
	{
		try {
			return $this->appManager->isEnabledForUser('invoicecheck')
				&& class_exists(self::FACADE)
				&& method_exists(self::FACADE, 'append')
				&& ((self::FACADE)::FACADE_VERSION ?? 0) >= 1;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Mint the deterministic outbox issue ref from the committed issue:
	 * the smallest movement id anchors the bundle, so a replayed issue
	 * (idempotent_replay) resolves to the same ref.
	 *
	 * @param list<array<string, mixed>> $movements
	 */
	public static function issueRefFor(int $projectId, array $movements): string
	{
		$min = PHP_INT_MAX;
		foreach ($movements as $m) {
			$min = min($min, (int) ($m['id'] ?? 0));
		}
		return sprintf('iv:project:%d:%d', $projectId, $min === PHP_INT_MAX ? 0 : $min);
	}

	/**
	 * Stage one issue into the outbox and attempt the push once. Returns
	 * the invoice-sync verdict for the API surface.
	 *
	 * @param list<array{sku:string,text:string,qty:int,unit:string}> $lines
	 */
	public function stageAndPush(string $actorUid, int $projectId, array $movements, array $lines): string
	{
		$issueRef = self::issueRefFor($projectId, $movements);
		$now = $this->clock->now();
		try {
			$row = $this->pushes->insertPending($issueRef, $projectId, $actorUid, json_encode($lines), $now);
		} catch (\Throwable $e) {
			$this->logger->warning('inventorycheck invoice-push staging failed', ['exception' => $e->getMessage()]);
			return 'failed';
		}
		if ($row->getStatus() === InvoicePush::STATUS_DONE) {
			return 'ok'; // replay of an already-pushed issue
		}
		return $this->pushRow($row, $actorUid);
	}

	/**
	 * One push attempt for an outbox row; updates status + attempts.
	 * `$actorUid` overrides the stored issuing actor (manual retry).
	 */
	public function pushRow(InvoicePush $row, ?string $actorUid = null): string
	{
		$actorUid = $actorUid ?? $row->getActorUid();
		$this->pushes->bumpAttempt($row, $this->clock->now());
		if (!$this->facadeAvailable()) {
			$this->pushes->markFailed($row, 'sibling_unavailable', $this->clock->now());
			return 'failed';
		}
		$lines = json_decode($row->getPayloadJson(), true);
		if (!is_array($lines)) {
			$this->pushes->markFailed($row, 'bad_payload', $this->clock->now());
			return 'failed';
		}
		try {
			$result = Server::get(self::FACADE)->append(
				$actorUid,
				$row->getProjectId(),
				$row->getIssueRef(),
				$lines,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('inventorycheck invoice-push threw', ['exception' => $e->getMessage()]);
			$this->pushes->markFailed($row, 'sibling_error', $this->clock->now());
			return 'failed';
		}
		if (!is_array($result)) {
			$this->pushes->markFailed($row, 'rejected', $this->clock->now());
			return 'failed';
		}
		$this->pushes->markDone($row, $this->clock->now());
		return 'ok';
	}

	/** Cron/manual drain — bounded batch, oldest first. */
	public function drain(?string $actorUid = null, int $limit = 20): int
	{
		$done = 0;
		foreach ($this->pushes->findRetryable($limit) as $row) {
			if ($this->pushRow($row, $actorUid) === 'ok') {
				$done++;
			}
		}
		return $done;
	}

	public function pendingCount(): int
	{
		return $this->pushes->countRetryable();
	}
}
