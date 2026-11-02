<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\ItemMapper;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Db\OrderSheet;
use OCA\InventoryCheck\Db\OrderSheetLine;
use OCA\InventoryCheck\Db\OrderSheetLineMapper;
use OCA\InventoryCheck\Db\OrderSheetMapper;
use OCA\InventoryCheck\Db\SupplierMapper;
use OCA\InventoryCheck\Exception\ApprovalRequiredException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * F-13/F-15/F-16 order sheets: draft → sent → received|cancelled.
 * Receiving an item decrements matching open lines inside the movement TX
 * (called from MovementService post-commit hooks with the sheet lock).
 */
class OrderSheetService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly OrderSheetMapper $sheets,
		private readonly OrderSheetLineMapper $lines,
		private readonly ItemMapper $items,
		private readonly SupplierMapper $suppliers,
		private readonly AccessControlService $access,
		private readonly RestockConfig $restock,
		private readonly Clock $clock,
		private readonly IConfig $config,
		private readonly ?IMailer $mailer = null,
		private readonly ?IUserManager $userManager = null,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?MovementMapper $movements = null,
	) {
	}

	/** @return array{data: list<array<string, mixed>>} */
	public function list(string $actorUid, ?string $status, int $limit, int $offset): array
	{
		$this->access->requireOffice($actorUid);
		return ['data' => array_map(
			static fn (OrderSheet $s) => $s->toApi(),
			$this->sheets->search($status, $limit, $offset),
		)];
	}

	/** @return array{sheet: array<string, mixed>, lines: list<array<string, mixed>>} */
	public function show(string $actorUid, int $id): array
	{
		$this->access->requireOffice($actorUid);
		$sheet = $this->sheets->findById($id);
		$lines = $this->lines->findBySheet($id);
		$names = [];
		foreach ($lines as $l) {
			$names[$l->getItemId()] = null;
		}
		$out = [];
		foreach ($lines as $l) {
			$api = $l->toApi();
			if (!isset($names[$l->getItemId()])) {
				$item = $this->items->findById($l->getItemId());
				$names[$l->getItemId()] = ['sku' => $item->getSku(), 'name' => $item->getName()];
			}
			$api['sku'] = $names[$l->getItemId()]['sku'];
			$api['name'] = $names[$l->getItemId()]['name'];
			$out[] = $api;
		}
		return ['sheet' => $sheet->toApi(), 'lines' => $out];
	}

	/**
	 * Create a draft sheet from a set of low-stock rows (office+).
	 *
	 * @param list<array{itemId:int, qty:int}> $linesIn storage units
	 */
	public function create(string $actorUid, ?int $supplierId, ?string $supplierName, ?string $note, array $linesIn): array
	{
		$this->access->requireOffice($actorUid);
		if (count($linesIn) === 0 || count($linesIn) > 200) {
			throw new ValidationException('validation_failed', '', [['field' => 'lines', 'code' => 'validation_failed']]);
		}
		if ($supplierId !== null) {
			$s = $this->suppliers->findById($supplierId);
			$supplierName = $s->getName();
		}
		$now = $this->clock->now();
		$factor = QtyScale::factor($this->config);
		$sheetId = 0;
		$this->db->beginTransaction();
		try {
			$sheet = new OrderSheet();
			$sheet->setSupplierId($supplierId);
			$sheet->setSupplierName($supplierName !== null ? mb_substr($supplierName, 0, 200) : null);
			$sheet->setStatus(OrderSheet::STATUS_DRAFT);
			$sheet->setNote($note !== null ? mb_substr($note, 0, 512) : null);
			$sheet->setCreatedBy($actorUid);
			$sheet->setCreatedAt($now);
			$sheet = $this->sheets->insert($sheet);
			$sheetId = (int)$sheet->getId();
			$total = 0;
			$seen = [];
			foreach ($linesIn as $l) {
				$itemId = (int)($l['itemId'] ?? 0);
				$qty = (int)($l['qty'] ?? 0);
				if ($itemId <= 0 || isset($seen[$itemId])) {
					continue;
				}
				$item = $this->items->findById($itemId);
				$max = QtyScale::maxStorage($this->config);
				if ($qty < 1 || $qty > $max) {
					throw new ValidationException('validation_failed', '', [['field' => 'qty', 'code' => 'validation_failed']]);
				}
				$est = RestockEstimate::lineMinor($qty, $item->getOrderPackQty(), $item->getOrderPackPriceMinor(), $item->getLastPriceMinor(), $factor);
				$total += $est ?? 0;
				$line = new OrderSheetLine();
				$line->setSheetId($sheetId);
				$line->setItemId($itemId);
				$line->setQty($qty);
				$line->setLineEstMinor($est);
				$this->lines->insert($line);
				$seen[$itemId] = true;
			}
			$sheet->setTotalMinor($total);
			$this->sheets->update($sheet);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $this->show($actorUid, $sheetId);
	}

	/**
	 * F-16: send requires app-admin when total exceeds the approval threshold.
	 */
	public function send(string $actorUid, int $id): void
	{
		$this->access->requireOffice($actorUid);
		$this->db->beginTransaction();
		try {
			$sheet = $this->sheets->findById($id);
			if ($sheet->getStatus() !== OrderSheet::STATUS_DRAFT) {
				throw new ValidationException('order_sheet_not_draft');
			}
			$threshold = $this->restock->approvalThresholdMinor();
			if ($threshold !== null && ($sheet->getTotalMinor() ?? 0) > $threshold
				&& !$this->access->isAppAdmin($actorUid)) {
				throw new ApprovalRequiredException();
			}
			$this->enforceBudgetCycle($actorUid, $sheet);
			$this->sheets->setStatus($id, OrderSheet::STATUS_SENT, $this->clock->now());
			$this->db->commit();
			$this->emailSupplier($sheet, $actorUid);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Budget-cycle enforcement (Reynolds ruling): a draft may only leave
	 * when its per-category cost fits what the monthly budget has left.
	 * Overage needs the owner (app-admin); beyond the configured emergency
	 * ceiling nobody sends. Informational budget states elsewhere stay
	 * untouched — this is the one place the ruling has teeth.
	 */
	private function enforceBudgetCycle(string $actorUid, OrderSheet $sheet): void
	{
		if ($this->movements === null) {
			return;
		}
		$budgets = $this->restock->budgets();
		$lines = $this->lines->findBySheet($sheet->getId());
		if ($budgets === [] || $lines === []) {
			return;
		}
		$factor = QtyScale::factor($this->config);
		$estByCat = [];
		$unpricedCapped = false;
		foreach ($lines as $line) {
			$item = $this->items->findById($line->getItemId());
			$cat = $item->getCategory() ?? RestockConfig::UNCATEGORIZED;
			$est = RestockEstimate::lineMinor(
				$line->getQty(),
				$item->getOrderPackQty(),
				$item->getOrderPackPriceMinor(),
				$item->getLastPriceMinor(),
				$factor,
			);
			if ($est === null) {
				if (($budgets[$cat] ?? 0) > 0) {
					$unpricedCapped = true;
				}
				continue;
			}
			$estByCat[$cat] = ($estByCat[$cat] ?? 0) + $est;
		}
		$mtdByCat = $this->movements->receivedSpendByCategorySince(
			(new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0)->getTimestamp(),
		);
		if (isset($mtdByCat[''])) {
			$mtdByCat[RestockConfig::UNCATEGORIZED] = ($mtdByCat[RestockConfig::UNCATEGORIZED] ?? 0) + $mtdByCat[''];
			unset($mtdByCat['']);
		}
		$emergency = $this->restock->emergencyBudgetMinor() ?? 0;
		$totalOverage = 0;
		foreach ($estByCat as $cat => $est) {
			$budget = $budgets[$cat] ?? null;
			if ($budget === null || $budget <= 0) {
				continue;
			}
			$remaining = max(0, $budget - ($mtdByCat[$cat] ?? 0));
			$totalOverage += max(0, $est - $remaining);
		}
		if ($emergency > 0 && $totalOverage > $emergency) {
			throw new ValidationException('budget_exceeded', '', [
				['field' => 'totalMinor', 'code' => 'budget_exceeded'],
			]);
		}
		if (($totalOverage > 0 || $unpricedCapped) && !$this->access->isAppAdmin($actorUid)) {
			throw new ApprovalRequiredException();
		}
	}

	public function cancel(string $actorUid, int $id): void
	{
		$this->access->requireOffice($actorUid);
		$sheet = $this->sheets->findById($id);
		if ($sheet->getStatus() === OrderSheet::STATUS_RECEIVED || $sheet->getStatus() === OrderSheet::STATUS_CANCELLED) {
			throw new ValidationException('order_sheet_closed');
		}
		$this->sheets->setStatus($id, OrderSheet::STATUS_CANCELLED, $this->clock->now());
	}

	/**
	 * F-13 auto-progress: called inside the movement TX after balance locks.
	 * Covers open sent-sheet lines for the item oldest-first; sheet → received
	 * when every line is covered.
	 */
	public function applyReceive(int $itemId, int $qty): void
	{
		$now = $this->clock->now();
		foreach ($this->sheets->openSheetIdsForItem($itemId) as $sheetId) {
			$remaining = $qty;
			foreach ($this->lines->openLinesForItem($itemId, $sheetId) as $line) {
				if ($remaining <= 0) {
					break;
				}
				$cover = min($remaining, $line['qty'] - $line['received_qty']);
				$this->lines->decrementLine($line['id'], $cover);
				$remaining -= $cover;
			}
			if ($this->lines->allLinesReceived($sheetId)) {
				$this->sheets->setStatus($sheetId, OrderSheet::STATUS_RECEIVED, $now);
			}
		}
	}

	/** F-15: email the sheet to the supplier — best-effort, never fails send. */
	private function emailSupplier(OrderSheet $sheet, string $actorUid): void
	{
		if ($this->mailer === null || $sheet->getSupplierId() === null || $this->logger === null) {
			return;
		}
		try {
			$supplier = $this->suppliers->findById($sheet->getSupplierId());
			$email = $supplier->getEmail();
			if ($email === null || $email === '') {
				return;
			}
			$body = $this->renderText((int)$sheet->getId());
			$msg = $this->mailer->createMessage();
			$msg->setTo([$email => $supplier->getName()]);
			$msg->setSubject('Order sheet #' . $sheet->getId());
			$msg->setBodyPlain($body);
			$this->mailer->send($msg);
		} catch (\Throwable $e) {
			$this->logger->warning('InventoryCheck order sheet email failed', [
				'app' => \OCA\InventoryCheck\AppInfo\Application::APP_ID,
				'sheetId' => $sheet->getId(),
				'message' => $e->getMessage(),
			]);
		}
	}

	/** Plain-text sheet for email/print (F-15). */
	public function renderText(int $id): string
	{
		$sheet = $this->sheets->findById($id);
		$lines = $this->lines->findBySheet($id);
		$out = 'InventoryCheck order sheet #' . $id . "\n";
		$out .= 'Supplier: ' . ($sheet->getSupplierName() ?? '—') . "\n\n";
		foreach ($lines as $l) {
			$item = $this->items->findById($l->getItemId());
			$out .= sprintf("%s\t%s\t%d\n", $item->getSku(), $item->getName(), $l->getQty());
		}
		return $out;
	}
}
