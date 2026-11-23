<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getItemId()
 * @method void setItemId(int $v)
 * @method int getPersonId()
 * @method void setPersonId(int $v)
 * @method int getLocationId()
 * @method void setLocationId(int $v)
 * @method int getQty()
 * @method void setQty(int $v)
 * @method ?string getLotCode()
 * @method void setLotCode(?string $v)
 * @method int getQtyReturned()
 * @method void setQtyReturned(int $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method ?int getDueAt()
 * @method void setDueAt(?int $v)
 * @method int getCheckedOutAt()
 * @method void setCheckedOutAt(int $v)
 * @method string getCheckedOutBy()
 * @method void setCheckedOutBy(string $v)
 * @method ?int getReturnedAt()
 * @method void setReturnedAt(?int $v)
 * @method ?string getCheckedInBy()
 * @method void setCheckedInBy(?string $v)
 * @method string getConditionOut()
 * @method void setConditionOut(string $v)
 * @method ?string getConditionIn()
 * @method void setConditionIn(?string $v)
 * @method ?string getNote()
 * @method void setNote(?string $v)
 * @method ?string getDamageNote()
 * @method void setDamageNote(?string $v)
 * @method ?string getSignaturePath()
 * @method void setSignaturePath(?string $v)
 * @method ?int getRequestId()
 * @method void setRequestId(?int $v)
 */
class Loan extends Entity
{
	public const STATUS_OPEN = 'open';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_RETURNED = 'returned';
	public const STATUS_LOST = 'lost';

	protected int $itemId = 0;
	protected int $personId = 0;
	protected int $locationId = 0;
	protected int $qty = 0;
	protected ?string $lotCode = null;
	protected int $qtyReturned = 0;
	protected string $status = self::STATUS_OPEN;
	protected ?int $dueAt = null;
	protected int $checkedOutAt = 0;
	protected string $checkedOutBy = '';
	protected ?int $returnedAt = null;
	protected ?string $checkedInBy = null;
	protected string $conditionOut = 'ok';
	protected ?string $conditionIn = null;
	protected ?string $note = null;
	protected ?string $damageNote = null;
	protected ?string $signaturePath = null;
	protected ?int $requestId = null;

	public function outstanding(): int
	{
		return $this->qty - $this->qtyReturned;
	}

	public function isOpen(): bool
	{
		return $this->status === self::STATUS_OPEN || $this->status === self::STATUS_PARTIAL;
	}

	public function isOverdue(int $now): bool
	{
		return $this->isOpen() && $this->dueAt !== null && $this->dueAt < $now;
	}

	public function toApi(?Person $person = null, ?string $itemName = null, ?string $locationLabel = null, ?int $now = null): array
	{
		return [
			'id' => $this->getId(),
			'itemId' => $this->getItemId(),
			'personId' => $this->getPersonId(),
			'locationId' => $this->getLocationId(),
			'qty' => $this->getQty(),
			'qtyReturned' => $this->getQtyReturned(),
			'outstanding' => $this->outstanding(),
			'status' => $this->getStatus(),
			'dueAt' => $this->getDueAt(),
			'overdue' => $now !== null ? $this->isOverdue($now) : null,
			'lotCode' => $this->getLotCode(),
			'checkedOutAt' => $this->getCheckedOutAt(),
			'checkedOutBy' => $this->getCheckedOutBy(),
			'returnedAt' => $this->getReturnedAt(),
			'checkedInBy' => $this->getCheckedInBy(),
			'conditionOut' => $this->getConditionOut(),
			'conditionIn' => $this->getConditionIn(),
			'note' => $this->getNote(),
			'damageNote' => $this->getDamageNote(),
			'personName' => $person?->getDisplayName(),
			'personMemberNo' => $person?->getMemberNo(),
			'itemName' => $itemName,
			'locationLabel' => $locationLabel,
		];
	}
}
