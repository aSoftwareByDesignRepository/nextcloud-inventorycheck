<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getItemId()
 * @method void setItemId(int $v)
 * @method ?int getLocationId()
 * @method void setLocationId(?int $v)
 * @method ?int getPersonId()
 * @method void setPersonId(?int $v)
 * @method ?string getRequesterUid()
 * @method void setRequesterUid(?string $v)
 * @method int getQty()
 * @method void setQty(int $v)
 * @method ?string getPurpose()
 * @method void setPurpose(?string $v)
 * @method ?int getWantedFrom()
 * @method void setWantedFrom(?int $v)
 * @method ?int getWantedTo()
 * @method void setWantedTo(?int $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method ?string getDecidedBy()
 * @method void setDecidedBy(?string $v)
 * @method ?int getDecidedAt()
 * @method void setDecidedAt(?int $v)
 * @method ?int getLoanId()
 * @method void setLoanId(?int $v)
 * @method ?string getNote()
 * @method void setNote(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class LoanRequest extends Entity
{
	public const STATUSES = ['pending', 'approved', 'declined', 'fulfilled', 'cancelled'];
	public const OPEN = ['pending', 'approved'];

	protected ?int $itemId = null;
	protected ?int $locationId = null;
	protected ?int $personId = null;
	protected ?string $requesterUid = null;
	protected int $qty = 0;
	protected ?string $purpose = null;
	protected ?int $wantedFrom = null;
	protected ?int $wantedTo = null;
	protected string $status = 'pending';
	protected ?string $decidedBy = null;
	protected ?int $decidedAt = null;
	protected ?int $loanId = null;
	protected ?string $note = null;
	protected int $createdAt = 0;

	public function toApi(): array
	{
		return [
			'id' => $this->getId(),
			'itemId' => $this->getItemId(),
			'locationId' => $this->getLocationId(),
			'personId' => $this->getPersonId(),
			'requesterUid' => $this->getRequesterUid(),
			'qty' => $this->getQty(),
			'purpose' => $this->getPurpose(),
			'wantedFrom' => $this->getWantedFrom(),
			'wantedTo' => $this->getWantedTo(),
			'status' => $this->getStatus(),
			'decidedBy' => $this->getDecidedBy(),
			'decidedAt' => $this->getDecidedAt(),
			'loanId' => $this->getLoanId(),
			'note' => $this->getNote(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
