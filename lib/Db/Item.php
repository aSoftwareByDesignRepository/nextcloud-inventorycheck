<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getSku()
 * @method void setSku(string $v)
 * @method string getScanCode()
 * @method void setScanCode(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string|null getDescription()
 * @method void setDescription(?string $v)
 * @method string getUom()
 * @method void setUom(string $v)
 * @method int getReorderLevel()
 * @method void setReorderLevel(int $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class Item extends Entity
{
	protected string $sku = '';
	protected string $scanCode = '';
	protected string $name = '';
	protected ?string $description = null;
	protected string $uom = 'pcs';
	protected int $reorderLevel = 0;
	protected bool $active = true;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected string $createdBy = '';

	public function __construct()
	{
		$this->addType('sku', 'string');
		$this->addType('scanCode', 'string');
		$this->addType('name', 'string');
		$this->addType('description', 'string');
		$this->addType('uom', 'string');
		$this->addType('reorderLevel', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('createdBy', 'string');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'sku' => $this->sku,
			'scanCode' => $this->scanCode,
			'name' => $this->name,
			'description' => $this->description,
			'uom' => $this->uom,
			'reorderLevel' => $this->reorderLevel,
			'active' => $this->active,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
