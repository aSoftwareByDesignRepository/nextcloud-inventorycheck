<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method int getLocationId()
 * @method void setLocationId(int $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class LocationFavourite extends Entity
{
	protected string $userId = '';
	protected int $locationId = 0;
	protected int $createdAt = 0;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('locationId', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
