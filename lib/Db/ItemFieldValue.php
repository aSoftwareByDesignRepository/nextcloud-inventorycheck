<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * IV-CUSTOM-FIELDS: one coerced value per (item, field). PK is the
 * (item_id, field_id) pair — no surrogate id column.
 *
 * @method int getItemId()
 * @method void setItemId(int $v)
 * @method int getFieldId()
 * @method void setFieldId(int $v)
 * @method string|null getValue()
 * @method void setValue(?string $v)
 */
class ItemFieldValue extends Entity
{
	protected int $itemId = 0;
	protected int $fieldId = 0;
	protected ?string $value = null;

	public function __construct()
	{
		$this->addType('itemId', 'integer');
		$this->addType('fieldId', 'integer');
		$this->addType('value', 'string');
	}
}
