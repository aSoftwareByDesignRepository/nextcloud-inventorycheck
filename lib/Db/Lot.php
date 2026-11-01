<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Restock F-07: one row per (item, lot_code); expiry is a lot attribute.
 *
 * @method int getItemId()
 * @method void setItemId(int $v)
 * @method string getLotCode()
 * @method void setLotCode(string $v)
 * @method int|null getExpiresAt()
 * @method void setExpiresAt(?int $v)
 */
class Lot extends Entity
{
	protected int $itemId = 0;
	protected string $lotCode = '';
	protected ?int $expiresAt = null;

	public function __construct()
	{
		$this->addType('itemId', 'integer');
		$this->addType('lotCode', 'string');
		$this->addType('expiresAt', 'integer');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'itemId' => $this->itemId,
			'lotCode' => $this->lotCode,
			'expiresAt' => $this->expiresAt,
		];
	}
}
