<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getDisplayName()
 * @method void setDisplayName(string $v)
 * @method ?string getMemberNo()
 * @method void setMemberNo(?string $v)
 * @method ?string getNote()
 * @method void setNote(?string $v)
 * @method ?string getNcUserId()
 * @method void setNcUserId(?string $v)
 * @method int getActive()
 * @method void setActive(int $v)
 * @method int getAnonymized()
 * @method void setAnonymized(int $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 */
class Person extends Entity
{
	protected string $displayName = '';
	protected ?string $memberNo = null;
	protected ?string $note = null;
	protected ?string $ncUserId = null;
	protected int $active = 1;
	protected int $anonymized = 0;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function toApi(): array
	{
		return [
			'id' => $this->getId(),
			'displayName' => $this->getDisplayName(),
			'memberNo' => $this->getMemberNo(),
			'note' => $this->getNote(),
			'ncUserId' => $this->getNcUserId(),
			'active' => $this->getActive() === 1,
			'anonymized' => $this->getAnonymized() === 1,
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
