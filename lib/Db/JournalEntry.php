<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * IV-COMPLIANCE-PACK: one chained journal row per committed movement.
 * Insert-only by construction — JournalEntryMapper exposes no
 * update/delete (NN-01).
 *
 * @method string getSiteId()
 * @method void setSiteId(string $v)
 * @method int getSeq()
 * @method void setSeq(int $v)
 * @method int getTs()
 * @method void setTs(int $v)
 * @method string getActor()
 * @method void setActor(string $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method int getMovementId()
 * @method void setMovementId(int $v)
 * @method string getPayload()
 * @method void setPayload(string $v)
 * @method string|null getPrevHash()
 * @method void setPrevHash(?string $v)
 * @method string getEntryHash()
 * @method void setEntryHash(string $v)
 */
class JournalEntry extends Entity
{
	protected string $siteId = 'iv';
	protected int $seq = 0;
	protected int $ts = 0;
	protected string $actor = '';
	protected string $kind = '';
	protected int $movementId = 0;
	protected string $payload = '';
	protected ?string $prevHash = null;
	protected string $entryHash = '';

	public function __construct()
	{
		$this->addType('siteId', 'string');
		$this->addType('seq', 'integer');
		$this->addType('ts', 'integer');
		$this->addType('actor', 'string');
		$this->addType('kind', 'string');
		$this->addType('movementId', 'integer');
		$this->addType('payload', 'string');
		$this->addType('prevHash', 'string');
		$this->addType('entryHash', 'string');
	}
}
