<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getName()
 * @method void setName(string $v)
 * @method string|null getEmail()
 * @method void setEmail(?string $v)
 * @method string|null getNote()
 * @method void setNote(?string $v)
 * @method bool getActive()
 * @method void setActive(bool $v)
 */
class Supplier extends Entity
{
	protected string $name = '';
	protected ?string $email = null;
	protected ?string $note = null;
	protected bool $active = true;

	public function __construct()
	{
		$this->addType('name', 'string');
		$this->addType('email', 'string');
		$this->addType('note', 'string');
		$this->addType('active', 'boolean');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'id' => (int)$this->getId(),
			'name' => $this->name,
			'email' => $this->email,
			'note' => $this->note,
			'active' => $this->active,
		];
	}
}
