<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Db\Person;
use OCA\InventoryCheck\Db\PersonMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IUser;
use OCP\IUserManager;

/**
 * F2 person registry: minimal PII (name + member number + optional
 * Nextcloud user link). Office-managed, no hard delete — erasure is
 * anonymisation which preserves loan history (SPEC §9).
 */
final class PersonService
{
	public const SEARCH_LIMIT = 20;

	public function __construct(
		private readonly PersonMapper $persons,
		private readonly LoanMapper $loans,
		private readonly IUserManager $userManager,
		private readonly AccessControlService $access,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Register listing (distinct from picker search): paged, name-ordered.
	 * @return array{persons:list<array>, truncated:bool}
	 */
	public function list(string $actorUid, int $limit = 50, int $offset = 0): array
	{
		$this->access->requireOffice($actorUid);
		$limit = max(1, min($limit, 200));
		$rows = $this->persons->list($limit + 1, max(0, $offset));
		$truncated = count($rows) > $limit;
		return [
			'persons' => array_map(
				static fn (Person $p): array => $p->toApi(),
				array_slice($rows, 0, $limit),
			),
			'truncated' => $truncated,
		];
	}

	/**
	 * Person picker source: app persons matching name/member_no, merged
	 * with matching Nextcloud users (link candidates, no auto-claim).
	 * Office+ only — the picker serves checkout, never field users.
	 *
	 * @return array{results:list<array>, truncated:bool}
	 */
	public function search(string $actorUid, string $term): array
	{
		$this->access->requireOffice($actorUid);
		return $this->searchCore($term);
	}

	/**
	 * Mobile Wave B: seat/device person picker — person rows only, never
	 * the NC user directory (a device must not enumerate NC accounts).
	 * @return array{results:list<array>, truncated:bool}
	 */
	public function searchForSeat(string $actorUid, string $term): array
	{
		$term = trim($term);
		if (mb_strlen($term) > 128) {
			$term = mb_substr($term, 0, 128);
		}
		if ($term === '') {
			return ['results' => [], 'truncated' => false];
		}
		$persons = $this->persons->search($term, self::SEARCH_LIMIT + 1);
		$truncated = count($persons) > self::SEARCH_LIMIT;
		return [
			'results' => array_map(static fn (Person $p): array => [
				'type' => 'person',
				'person' => $p->toApi(),
			], array_slice($persons, 0, self::SEARCH_LIMIT)),
			'truncated' => $truncated,
		];
	}

	private function searchCore(string $term): array
	{
		$term = trim($term);
		if (mb_strlen($term) > 128) {
			$term = mb_substr($term, 0, 128);
		}
		if ($term === '') {
			return ['results' => [], 'truncated' => false];
		}
		// Fetch limit+1 to expose an honest `truncated` flag.
		$persons = $this->persons->search($term, self::SEARCH_LIMIT + 1);
		$truncated = count($persons) > self::SEARCH_LIMIT;
		$persons = array_slice($persons, 0, self::SEARCH_LIMIT);

		$linked = [];
		foreach ($persons as $p) {
			if ($p->getNcUserId() !== null) {
				$linked[$p->getNcUserId()] = true;
			}
		}
		$results = array_map(static fn (Person $p): array => [
			'type' => 'person',
			'person' => $p->toApi(),
		], $persons);
		// Merge uid search with display-name search — on uid-opaque
		// backends (LDAP/SAML) search() alone misses "type a name"
		// matches (people-search-picker contract).
		$seen = $linked;
		foreach ([$this->userManager->search($term, self::SEARCH_LIMIT),
			$this->userManager->searchDisplayName($term, self::SEARCH_LIMIT)] as $users) {
			if (!is_iterable($users)) {
				continue;
			}
			foreach ($users as $user) {
				assert($user instanceof IUser);
				$uid = $user->getUID();
				if (isset($seen[$uid])) {
					continue; // already represented (person row or earlier hit)
				}
				$seen[$uid] = true;
				$results[] = [
					'type' => 'ncUser',
					'ncUser' => [
						'uid' => $uid,
						'displayName' => $user->getDisplayName() ?: $uid,
					],
				];
			}
		}
		return ['results' => $results, 'truncated' => $truncated];
	}

	public function create(string $actorUid, string $displayName, ?string $memberNo, ?string $note, ?string $ncUserId): Person
	{
		$this->access->requireOffice($actorUid);
		[$displayName, $memberNo, $note] = $this->normalizeFields($displayName, $memberNo, $note);
		$ncUserId = $this->normalizeNcUser($ncUserId);
		$now = $this->clock->now();
		$person = new Person();
		$person->setDisplayName($displayName);
		$person->setMemberNo($memberNo);
		$person->setNote($note);
		$person->setNcUserId($ncUserId);
		$person->setActive(1);
		$person->setAnonymized(0);
		$person->setCreatedAt($now);
		$person->setUpdatedAt($now);
		return $this->persons->insert($person);
	}

	public function get(string $actorUid, int $personId): Person
	{
		$this->access->requireOffice($actorUid);
		return $this->findOr404($personId);
	}

	public function update(
		string $actorUid,
		int $personId,
		string $displayName,
		?string $memberNo,
		?string $note,
		?string $ncUserId,
		bool $active,
	): Person {
		$this->access->requireOffice($actorUid);
		$person = $this->findOr404($personId);
		if ($person->getAnonymized()) {
			throw new ConflictException('person_anonymized');
		}
		[$displayName, $memberNo, $note] = $this->normalizeFields($displayName, $memberNo, $note);
		$person->setDisplayName($displayName);
		$person->setMemberNo($memberNo);
		$person->setNote($note);
		$person->setNcUserId($this->normalizeNcUser($ncUserId));
		$person->setActive($active ? 1 : 0);
		$person->setUpdatedAt($this->clock->now());
		return $this->persons->update($person);
	}

	/**
	 * GDPR erasure: block while open loans exist (SPEC AC-13 stable error
	 * 'person_has_open_loans'); anonymisation keeps loan rows linked but
	 * strips PII — display name becomes 'deleted person #id'.
	 */
	public function anonymize(string $actorUid, int $personId): Person
	{
		$this->access->requireOffice($actorUid);
		$person = $this->findOr404($personId);
		if ($person->getAnonymized()) {
			return $person;
		}
		if ($this->loans->countOpenForPerson($personId) > 0) {
			throw new ConflictException('person_has_open_loans');
		}
		$person->setDisplayName('deleted person #' . $personId);
		$person->setMemberNo(null);
		$person->setNote(null);
		$person->setNcUserId(null);
		$person->setActive(0);
		$person->setAnonymized(1);
		$person->setUpdatedAt($this->clock->now());
		return $this->persons->update($person);
	}

	/** UserDeletedEvent listener hook — clear nc_user_id, keep history. */
	public function unlinkNcUser(string $uid): int
	{
		return $this->persons->unlinkNcUser($uid);
	}

	private function findOr404(int $personId): Person
	{
		try {
			return $this->persons->findById($personId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new NotFoundException('unknown_person');
		}
	}

	/** @return array{string, ?string, ?string} */
	private function normalizeFields(string $displayName, ?string $memberNo, ?string $note): array
	{
		$displayName = trim($displayName);
		if ($displayName === '' || mb_strlen($displayName) > 128) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'displayName', 'code' => 'validation_failed'],
			]);
		}
		$memberNo = $memberNo !== null ? trim($memberNo) : null;
		if ($memberNo !== null && ($memberNo === '' || mb_strlen($memberNo) > 64)) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'memberNo', 'code' => 'validation_failed'],
			]);
		}
		$note = $note !== null ? trim($note) : null;
		if ($note !== null && mb_strlen($note) > 512) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'note', 'code' => 'validation_failed'],
			]);
		}
		return [$displayName, $memberNo ?: null, $note ?: null];
	}

	private function normalizeNcUser(?string $ncUserId): ?string
	{
		$ncUserId = $ncUserId !== null ? trim($ncUserId) : null;
		if ($ncUserId === null || $ncUserId === '') {
			return null;
		}
		if (mb_strlen($ncUserId) > 64 || !$this->userManager->userExists($ncUserId)) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'ncUserId', 'code' => 'validation_failed'],
			]);
		}
		return $ncUserId;
	}
}
