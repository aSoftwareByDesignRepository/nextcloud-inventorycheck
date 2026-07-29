<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getDedupeKey()
 * @method void setDedupeKey(string $v)
 * @method int getItemId()
 * @method void setItemId(int $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class NotifyLog extends Entity
{
	protected string $dedupeKey = '';
	protected int $itemId = 0;
	protected int $createdAt = 0;

	public function __construct()
	{
		$this->addType('dedupeKey', 'string');
		$this->addType('itemId', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
