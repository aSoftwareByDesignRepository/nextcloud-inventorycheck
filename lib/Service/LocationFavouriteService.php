<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\LocationFavourite;
use OCA\InventoryCheck\Db\LocationFavouriteMapper;
use OCA\InventoryCheck\Db\LocationMapper;
use OCA\InventoryCheck\Db\UniqueViolation;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * Per-user favourite locations (Wave B4). Max 20.
 * Wave C3: favourites honour location ACL (no IDOR via favourite add/list).
 */
class LocationFavouriteService
{
	public const MAX = 20;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LocationFavouriteMapper $favourites,
		private readonly LocationMapper $locations,
		private readonly Clock $clock,
		private readonly LocationAclService $locationAcl,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list(string $userId): array
	{
		$ids = $this->favourites->locationIdsForUser($userId);
		$out = [];
		foreach ($ids as $id) {
			try {
				if (!$this->locationAcl->canAccessLocation($userId, $id)) {
					continue;
				}
				$loc = $this->locations->findById($id);
				if ($loc->getActive()) {
					$out[] = $loc->toApi();
				}
			} catch (\Throwable) {
				// Skip deleted locations.
			}
		}
		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function add(string $userId, int $locationId): array
	{
		if (!$this->locationAcl->canAccessLocation($userId, $locationId)) {
			throw new NotFoundException('unknown_location');
		}
		$loc = $this->locations->findById($locationId);
		if (!$loc->getActive()) {
			throw new ValidationException('inactive_location');
		}
		if ($this->favourites->findPair($userId, $locationId) !== null) {
			return $this->list($userId);
		}
		if ($this->favourites->countForUser($userId) >= self::MAX) {
			throw new ValidationException('favourite_limit', '', [
				['field' => 'locationId', 'code' => 'favourite_limit'],
			]);
		}
		$row = new LocationFavourite();
		$row->setUserId($userId);
		$row->setLocationId($locationId);
		$row->setCreatedAt($this->clock->now());
		try {
			$this->favourites->insert($row);
		} catch (\Throwable $e) {
			if (!UniqueViolation::is($e)) {
				throw $e;
			}
		}
		return $this->list($userId);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function remove(string $userId, int $locationId): array
	{
		$this->favourites->deletePair($userId, $locationId);
		return $this->list($userId);
	}
}
