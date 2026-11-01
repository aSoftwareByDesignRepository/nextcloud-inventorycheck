<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * F-13 order sheet. States: draft → sent → received|cancelled.
 *
 * @method string|null getSupplierName()
 * @method void setSupplierName(?string $v)
 * @method int|null getSupplierId()
 * @method void setSupplierId(?int $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method int|null getTotalMinor()
 * @method void setTotalMinor(?int $v)
 * @method string|null getNote()
 * @method void setNote(?string $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int|null getSentAt()
 * @method void setSentAt(?int $v)
 * @method int|null getReceivedAt()
 * @method void setReceivedAt(?int $v)
 * @method int|null getCancelledAt()
 * @method void setCancelledAt(?int $v)
 */
class OrderSheet extends Entity
{
	public const STATUS_DRAFT = 'draft';
	public const STATUS_SENT = 'sent';
	public const STATUS_RECEIVED = 'received';
	public const STATUS_CANCELLED = 'cancelled';

	protected ?string $supplierName = null;
	protected ?int $supplierId = null;
	protected string $status = self::STATUS_DRAFT;
	protected ?int $totalMinor = null;
	protected ?string $note = null;
	protected string $createdBy = '';
	protected int $createdAt = 0;
	protected ?int $sentAt = null;
	protected ?int $receivedAt = null;
	protected ?int $cancelledAt = null;

	public function __construct()
	{
		$this->addType('supplierName', 'string');
		$this->addType('supplierId', 'integer');
		$this->addType('status', 'string');
		$this->addType('totalMinor', 'integer');
		$this->addType('note', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('sentAt', 'integer');
		$this->addType('receivedAt', 'integer');
		$this->addType('cancelledAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'supplierName' => $this->supplierName,
			'supplierId' => $this->supplierId,
			'status' => $this->status,
			'totalMinor' => $this->totalMinor,
			'note' => $this->note,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt,
			'sentAt' => $this->sentAt,
			'receivedAt' => $this->receivedAt,
			'cancelledAt' => $this->cancelledAt,
		];
	}
}
