<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getItemId()
 * @method void setItemId(int $v)
 * @method int getLocationId()
 * @method void setLocationId(int $v)
 * @method ?int getPersonId()
 * @method void setPersonId(?int $v)
 * @method ?string getEventLabel()
 * @method void setEventLabel(?string $v)
 * @method int getQty()
 * @method void setQty(int $v)
 * @method int getFromTs()
 * @method void setFromTs(int $v)
 * @method int getToTs()
 * @method void setToTs(int $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method ?int getConvertedLoanId()
 * @method void setConvertedLoanId(?int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class Reservation extends Entity
{
	public const STATUSES = ['reserved', 'fulfilled', 'cancelled', 'expired'];

	protected ?int $itemId = null;
	protected ?int $locationId = null;
	protected ?int $personId = null;
	protected ?string $eventLabel = null;
	protected int $qty = 0;
	protected int $fromTs = 0;
	protected int $toTs = 0;
	protected string $status = 'reserved';
	protected ?int $convertedLoanId = null;
	protected string $createdBy = '';
	protected int $createdAt = 0;

	public function isOpen(): bool
	{
		return $this->status === 'reserved';
	}

	public function toApi(): array
	{
		return [
			'id' => $this->getId(),
			'itemId' => $this->getItemId(),
			'locationId' => $this->getLocationId(),
			'personId' => $this->getPersonId(),
			'eventLabel' => $this->getEventLabel(),
			'qty' => $this->getQty(),
			'fromTs' => $this->getFromTs(),
			'toTs' => $this->getToTs(),
			'status' => $this->getStatus(),
			'convertedLoanId' => $this->getConvertedLoanId(),
			'createdBy' => $this->getCreatedBy(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
