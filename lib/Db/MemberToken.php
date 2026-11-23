<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getPersonId()
 * @method void setPersonId(int $v)
 * @method string getTokenHash()
 * @method void setTokenHash(string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method ?int getRevokedAt()
 * @method void setRevokedAt(?int $v)
 * @method ?int getLastUsedAt()
 * @method void setLastUsedAt(?int $v)
 */
class MemberToken extends Entity
{
	protected ?int $personId = null;
	protected string $tokenHash = '';
	protected int $createdAt = 0;
	protected ?int $revokedAt = null;
	protected ?int $lastUsedAt = null;

	public function isLive(): bool
	{
		return $this->revokedAt === null;
	}
}
