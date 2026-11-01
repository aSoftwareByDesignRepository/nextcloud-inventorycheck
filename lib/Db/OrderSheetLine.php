<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getSheetId()
 * @method void setSheetId(int $v)
 * @method int getItemId()
 * @method void setItemId(int $v)
 * @method int getQty()
 * @method void setQty(int $v)
 * @method int getReceivedQty()
 * @method void setReceivedQty(int $v)
 * @method int|null getLineEstMinor()
 * @method void setLineEstMinor(?int $v)
 */
class OrderSheetLine extends Entity
{
	protected int $sheetId = 0;
	protected int $itemId = 0;
	protected int $qty = 0;
	protected int $receivedQty = 0;
	protected ?int $lineEstMinor = null;

	public function __construct()
	{
		$this->addType('sheetId', 'integer');
		$this->addType('itemId', 'integer');
		$this->addType('qty', 'integer');
		$this->addType('receivedQty', 'integer');
		$this->addType('lineEstMinor', 'integer');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'sheetId' => $this->sheetId,
			'itemId' => $this->itemId,
			'qty' => $this->qty,
			'receivedQty' => $this->receivedQty,
			'lineEstMinor' => $this->lineEstMinor,
		];
	}
}
