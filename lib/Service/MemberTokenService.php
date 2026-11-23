<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\MemberToken;
use OCA\InventoryCheck\Db\MemberTokenMapper;
use OCA\InventoryCheck\Db\Person;
use OCA\InventoryCheck\Db\PersonMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\PermissionDeniedException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Member-portal token lifecycle (Wave C / PORTAL-ABUSE-SPEC):
 * raw token = 'ivm_' + base64url(random_bytes(24)) — 192-bit, prefixed;
 * only sha256(raw) is stored. Issue/rotate/revoke are office-only.
 */
class MemberTokenService
{
	private const PREFIX = 'ivm_';

	public function __construct(
		private readonly MemberTokenMapper $tokens,
		private readonly PersonMapper $persons,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
	) {
	}

	public static function hash(string $rawToken): string
	{
		return hash('sha256', $rawToken);
	}

	private function mint(): string
	{
		return self::PREFIX . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
	}

	/**
	 * Issue (or rotate) the live token for a person — revokes any prior
	 * live token in the same call so at most one link works at a time.
	 * @return array{token: string, personId: int} the raw token (show once)
	 */
	public function issue(string $actorUid, int $personId): array
	{
		$this->access->requireOffice($actorUid);
		$person = $this->findPerson($personId);
		$now = $this->clock->now();
		$this->tokens->revokeForPerson($personId, $now);
		$t = new MemberToken();
		$t->setPersonId($personId);
		$raw = $this->mint();
		$t->setTokenHash(self::hash($raw));
		$t->setCreatedAt($now);
		$this->tokens->insert($t);
		return ['token' => $raw, 'personId' => $personId];
	}

	public function revoke(string $actorUid, int $personId): void
	{
		$this->access->requireOffice($actorUid);
		$this->tokens->revokeForPerson($personId, $this->clock->now());
	}

	/** Whether the person currently has a live link (office badge). */
	public function hasLiveToken(int $personId): bool
	{
		return $this->tokens->liveForPerson($personId) !== null;
	}

	/**
	 * Portal auth: raw token → [person, token] or null. Never distinguishes
	 * bad-hash vs revoked — callers render a uniform 404.
	 * @return array{0: Person, 1: MemberToken}|null
	 */
	public function resolve(string $rawToken): ?array
	{
		$rawToken = trim($rawToken);
		if ($rawToken === '' || strlen($rawToken) > 128) {
			return null;
		}
		$token = $this->tokens->findActiveByHash(self::hash($rawToken));
		if ($token === null) {
			return null;
		}
		try {
			$person = $this->persons->findById($token->getPersonId());
		} catch (DoesNotExistException) {
			return null;
		}
		if (!$person->getActive() || $person->getAnonymized()) {
			return null;
		}
		$this->tokens->touchLastUsed($token->getId(), $this->clock->now());
		return [$person, $token];
	}

	/** Purge every token when the person is anonymised/deleted. */
	public function purgeForPerson(int $personId): void
	{
		$this->tokens->revokeForPerson($personId, $this->clock->now());
	}

	private function findPerson(int $personId): Person
	{
		try {
			return $this->persons->findById($personId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('unknown_person');
		}
	}
}
