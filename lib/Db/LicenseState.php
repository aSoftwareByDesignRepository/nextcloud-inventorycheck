<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getCustomerId()
 * @method void setCustomerId(string $v)
 * @method string getIssuedAt()
 * @method void setIssuedAt(string $v)
 * @method string getValidUntil()
 * @method void setValidUntil(string $v)
 * @method int getMobileSeats()
 * @method void setMobileSeats(int $v)
 * @method int getScanDevices()
 * @method void setScanDevices(int $v)
 * @method bool getBundle()
 * @method void setBundle(bool $v)
 * @method string getPayloadB64()
 * @method void setPayloadB64(string $v)
 * @method string getSignatureB64()
 * @method void setSignatureB64(string $v)
 * @method int getAppliedAt()
 * @method void setAppliedAt(int $v)
 * @method string getAppliedBy()
 * @method void setAppliedBy(string $v)
 */
class LicenseState extends Entity
{
	protected string $customerId = '';
	protected string $issuedAt = '';
	protected string $validUntil = '';
	protected int $mobileSeats = 0;
	protected int $scanDevices = 0;
	protected bool $bundle = false;
	protected string $payloadB64 = '';
	protected string $signatureB64 = '';
	protected int $appliedAt = 0;
	protected string $appliedBy = '';

	public function __construct()
	{
		$this->addType('customerId', 'string');
		$this->addType('issuedAt', 'string');
		$this->addType('validUntil', 'string');
		$this->addType('mobileSeats', 'integer');
		$this->addType('scanDevices', 'integer');
		$this->addType('bundle', 'boolean');
		$this->addType('payloadB64', 'string');
		$this->addType('signatureB64', 'string');
		$this->addType('appliedAt', 'integer');
		$this->addType('appliedBy', 'string');
	}

	/** @return array<string, mixed> */
	public function toApi(): array
	{
		return [
			'customerId' => $this->customerId,
			'issuedAt' => $this->issuedAt,
			'validUntil' => $this->validUntil,
			'mobileSeats' => $this->mobileSeats,
			'scanDevices' => $this->scanDevices,
			'bundle' => $this->bundle,
			'appliedAt' => $this->appliedAt,
			'appliedBy' => $this->appliedBy,
		];
	}
}
