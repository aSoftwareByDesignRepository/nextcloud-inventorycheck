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
 * @method string|null getPhotoName()
 * @method void setPhotoName(?string $v)
 * @method string|null getPhotoMime()
 * @method void setPhotoMime(?string $v)
 * @method string|null getSupplierNote()
 * @method void setSupplierNote(?string $v)
 * @method int|null getLastPriceMinor()
 * @method void setLastPriceMinor(?int $v)
 * @method string getTrackMode()
 * @method void setTrackMode(string $v)
 * @method int|null getTargetStock()
 * @method void setTargetStock(?int $v)
 * @method int|null getDefaultLocationId()
 * @method void setDefaultLocationId(?int $v)
 * @method int|null getOrderPackQty()
 * @method void setOrderPackQty(?int $v)
 * @method int|null getOrderPackPriceMinor()
 * @method void setOrderPackPriceMinor(?int $v)
 * @method string|null getSupplierName()
 * @method void setSupplierName(?string $v)
 * @method string|null getSupplierSku()
 * @method void setSupplierSku(?string $v)
 * @method int|null getSupplierLeadDays()
 * @method void setSupplierLeadDays(?int $v)
 * @method int|null getOnOrderQty()
 * @method void setOnOrderQty(?int $v)
 * @method int|null getOnOrderAt()
 * @method void setOnOrderAt(?int $v)
 * @method string|null getOnOrderBy()
 * @method void setOnOrderBy(?string $v)
 * @method string|null getCategory()
 * @method void setCategory(?string $v)
 * @method string getCriticality()
 * @method void setCriticality(string $v)
 * @method string|null getDeliveryNotePath()
 * @method void setDeliveryNotePath(?string $v)
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
	protected ?string $photoName = null;
	protected ?string $photoMime = null;
	protected ?string $supplierNote = null;
	protected ?int $lastPriceMinor = null;
	/** Wave C2: none|lot|serial — see {@see \OCA\InventoryCheck\Service\CodeRules::TRACK_MODES}. */
	protected string $trackMode = 'none';
	/** Wave D4: optional order-up-to level (storage units). */
	protected ?int $targetStock = null;
	/** Wave D7: optional putaway / receive default location. */
	protected ?int $defaultLocationId = null;
	/** Restock F-01: storage units per order pack (e.g. 100 pcs per box). */
	protected ?int $orderPackQty = null;
	/** Restock F-01: price per order pack in minor units. */
	protected ?int $orderPackPriceMinor = null;
	/** Restock F-03: free-text supplier name (no supplier entity until W4). */
	protected ?string $supplierName = null;
	/** Restock F-03: supplier's own article number. */
	protected ?string $supplierSku = null;
	/** Restock F-19: supplier lead time in days (0..365). */
	protected ?int $supplierLeadDays = null;
	/** Restock F-04: on-order marker — written ONLY via targeted ItemMapper column updates (NN-09). */
	protected ?int $onOrderQty = null;
	protected ?int $onOrderAt = null;
	protected ?string $onOrderBy = null;
	/** Restock F-09: budget-bucket category (constrained to category_list). */
	protected ?string $category = null;
	/** Restock F-11: none|critical severity signal. */
	protected string $criticality = 'none';
	/** Restock F-27: Files path reference for a delivery note — never proxied. */
	protected ?string $deliveryNotePath = null;

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
		$this->addType('photoName', 'string');
		$this->addType('photoMime', 'string');
		$this->addType('supplierNote', 'string');
		$this->addType('lastPriceMinor', 'integer');
		$this->addType('trackMode', 'string');
		$this->addType('targetStock', 'integer');
		$this->addType('defaultLocationId', 'integer');
		$this->addType('orderPackQty', 'integer');
		$this->addType('orderPackPriceMinor', 'integer');
		$this->addType('supplierName', 'string');
		$this->addType('supplierSku', 'string');
		$this->addType('supplierLeadDays', 'integer');
		$this->addType('onOrderQty', 'integer');
		$this->addType('onOrderAt', 'integer');
		$this->addType('onOrderBy', 'string');
		$this->addType('category', 'string');
		$this->addType('criticality', 'string');
		$this->addType('deliveryNotePath', 'string');
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
			'targetStock' => $this->targetStock,
			'defaultLocationId' => $this->defaultLocationId,
			'active' => $this->active,
			'hasPhoto' => $this->photoName !== null && $this->photoName !== '',
			'photoMime' => $this->photoMime,
			'supplierNote' => $this->supplierNote,
			'lastPriceMinor' => $this->lastPriceMinor,
			'trackMode' => $this->trackMode,
			'orderPackQty' => $this->orderPackQty,
			'orderPackPriceMinor' => $this->orderPackPriceMinor,
			'supplierName' => $this->supplierName,
			'supplierSku' => $this->supplierSku,
			'supplierLeadDays' => $this->supplierLeadDays,
			'onOrderQty' => $this->onOrderQty,
			'onOrderAt' => $this->onOrderAt,
			'onOrderBy' => $this->onOrderBy,
			'category' => $this->category,
			'criticality' => $this->criticality,
			'deliveryNotePath' => $this->deliveryNotePath,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'createdBy' => $this->createdBy,
		];
	}
}
