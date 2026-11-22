<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * IV-CUSTOM-FIELDS: admin-defined item attribute definition.
 * Exactly four types (NN-01); options_json only for select.
 *
 * @method string getName()
 * @method void setName(string $v)
 * @method string getType()
 * @method void setType(string $v)
 * @method string|null getOptionsJson()
 * @method void setOptionsJson(?string $v)
 * @method bool getRequired()
 * @method void setRequired(bool $v)
 * @method int getPosition()
 * @method void setPosition(int $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class ItemField extends Entity
{
	public const TYPES = ['text', 'number', 'date', 'select'];
	public const MAX_VALUE_CHARS = 1000;

	protected string $name = '';
	protected string $type = 'text';
	protected ?string $optionsJson = null;
	protected bool $required = false;
	protected int $position = 0;
	protected bool $active = true;
	protected int $createdAt = 0;

	public function __construct()
	{
		$this->addType('name', 'string');
		$this->addType('type', 'string');
		$this->addType('optionsJson', 'string');
		$this->addType('required', 'boolean');
		$this->addType('position', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('createdAt', 'integer');
	}

	/** @return list<string> */
	public function options(): array
	{
		if ($this->optionsJson === null || $this->optionsJson === '') {
			return [];
		}
		$decoded = json_decode($this->optionsJson, true);
		return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'name' => $this->name,
			'type' => $this->type,
			'options' => $this->options(),
			'required' => $this->required,
			'position' => $this->position,
			'active' => $this->active,
		];
	}
}
