<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\Loan;
use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\DB\Exception as DbException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * IV-CUSTODY Wave B: signature + damage-photo blobs on a loan. Private
 * AppData storage (NN-07 — never a public URL, never serialized). The
 * DTO exposes hasSignature/hasDamagePhoto flags only.
 *
 * Signature arrives base64 inside the loans POST (tourcheck precedent);
 * the damage photo is a post-checkin multipart attach (C-2 precedent).
 * Both writes are post-commit best-effort — a failed artifact never
 * rolls back the booking.
 */
class LoanEvidenceService
{
	public const PHOTO_MAX_BYTES = 10_485_760;
	public const SIG_MAX_BYTES = 262_144; // signature canvas PNG — tiny
	public const FOLDER = 'loan_evidence';
	public const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppData $appData,
		private readonly LoanMapper $loans,
		private readonly AccessControlService $access,
		private readonly LocationAclService $locationAcl,
		private readonly LoggerInterface $logger,
	) {
	}

	/** Store a signature PNG (base64) on the loan; replaces any prior blob. */
	public function storeSignature(string $actorUid, int $loanId, string $b64): Loan
	{
		$raw = base64_decode(preg_replace('/^data:image\/[a-z0-9.+-]+;base64,/i', '', trim($b64)) ?? '', true);
		if ($raw === false || $raw === '' || strlen($raw) > self::SIG_MAX_BYTES) {
			throw new ValidationException('signature_invalid', '', [['field' => 'signaturePng', 'code' => 'signature_invalid']]);
		}
		$info = @getimagesizefromstring($raw);
		if ($info === false || (string)$info['mime'] !== 'image/png' || $info[0] < 16 || $info[1] < 16) {
			throw new ValidationException('signature_invalid', '', [['field' => 'signaturePng', 'code' => 'signature_invalid']]);
		}
		return $this->store($actorUid, $loanId, $raw, 'sig', 'png', 'signature');
	}

	/**
	 * Store a damage photo (PHP upload array) on the loan. Office (web) or
	 * any actor whose location ACL covers the loan's location (seat/device).
	 * @param array<string, mixed> $file
	 */
	public function storeDamagePhoto(string $actorUid, int $loanId, array $file): Loan
	{
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			|| !is_string($file['tmp_name'] ?? null)
			|| !is_uploaded_file($file['tmp_name'])) {
			throw new ValidationException('upload_failed', '', [['field' => 'file', 'code' => 'upload_failed']]);
		}
		$size = (int)($file['size'] ?? 0);
		if ($size <= 0 || $size > self::PHOTO_MAX_BYTES) {
			throw new ValidationException('photo_too_large', '', [['field' => 'file', 'code' => 'photo_too_large']]);
		}
		$raw = file_get_contents($file['tmp_name']);
		$info = $raw === false ? false : @getimagesizefromstring($raw);
		if ($info === false || !isset(self::ALLOWED[(string)$info['mime']])
			|| $info[0] < 16 || $info[1] < 16 || $info[0] > 12000 || $info[1] > 12000) {
			throw new ValidationException('photo_type_invalid', '', [['field' => 'file', 'code' => 'photo_type_invalid']]);
		}
		return $this->store($actorUid, $loanId, $raw, 'dmg', self::ALLOWED[(string)$info['mime']], 'damage');
	}

	/** @return array{content: string, mime: string} */
	public function read(string $actorUid, int $loanId, string $which): array
	{
		$loan = $this->findAndAssert($actorUid, $loanId);
		$name = $which === 'signature' ? $loan->getSignaturePath() : $loan->getDamagePhotoPath();
		if ($name === null || $name === '') {
			throw new NotFoundException('loan_evidence_not_found');
		}
		try {
			$content = $this->folder()->getFile($name)->getContent();
		} catch (FilesNotFoundException) {
			throw new NotFoundException('loan_evidence_not_found');
		}
		$mime = $which === 'signature' ? 'image/png' : (array_search(pathinfo($name, PATHINFO_EXTENSION), self::ALLOWED, true) ?: 'application/octet-stream');
		return ['content' => $content, 'mime' => $mime];
	}

	private function store(string $actorUid, int $loanId, string $raw, string $tag, string $ext, string $prop): Loan
	{
		$fileName = sprintf('loan-%d-%s-%s.%s', $loanId, $tag, bin2hex(random_bytes(8)), $ext);
		$folder = $this->folder();
		$this->db->beginTransaction();
		try {
			$loan = $this->loans->findByIdForUpdate($loanId);
			$this->assertVisible($actorUid, $loan);
			$old = $prop === 'signature' ? $loan->getSignaturePath() : $loan->getDamagePhotoPath();
			$folder->newFile($fileName)->putContent($raw);
			if ($prop === 'signature') {
				$loan->setSignaturePath($fileName);
			} else {
				$loan->setDamagePhotoPath($fileName);
			}
			$this->loans->update($loan);
			$this->db->commit();
			if ($old !== null && $old !== '') {
				$this->tryDeleteFile($old);
			}
			return $loan;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			$this->tryDeleteFile($fileName);
			throw $e;
		}
	}

	private function findAndAssert(string $actorUid, int $loanId): Loan
	{
		try {
			$loan = $this->loans->findById($loanId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			throw new NotFoundException('unknown_loan');
		}
		$this->assertVisible($actorUid, $loan);
		return $loan;
	}

	private function assertVisible(string $actorUid, Loan $loan): void
	{
		if (!$this->locationAcl->canAccessLocation($actorUid, $loan->getLocationId())) {
			throw new NotFoundException('unknown_loan');
		}
	}

	private function folder(): \OCP\Files\SimpleFS\ISimpleFolder
	{
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (FilesNotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}

	private function tryDeleteFile(string $fileName): void
	{
		try {
			$this->folder()->getFile($fileName)->delete();
		} catch (\Throwable $e) {
			$this->logger->warning('loan evidence cleanup failed', ['file' => $fileName, 'error' => $e->getMessage()]);
		}
	}
}
