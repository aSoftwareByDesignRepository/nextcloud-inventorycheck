<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getSubjectType()
 * @method void setSubjectType(string $v)
 * @method string getSubjectId()
 * @method void setSubjectId(string $v)
 * @method int getLocationId()
 * @method void setLocationId(int $v)
 */
class LocationAcl extends Entity
{
	protected string $subjectType = '';
	protected string $subjectId = '';
	protected int $locationId = 0;

	public function __construct()
	{
		$this->addType('subjectType', 'string');
		$this->addType('subjectId', 'string');
		$this->addType('locationId', 'integer');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'subjectType' => $this->subjectType,
			'subjectId' => $this->subjectId,
			'locationId' => $this->locationId,
		];
	}
}
