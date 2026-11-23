<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Db\Movement;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * C-2 IV-RECEIVING-EVIDENCE: one Lieferschein photo per receive movement.
 *
 * Stored as a private AppData file (never a public URL — NN-01); the API
 * surface exposes only `hasDeliveryNote` on movement DTOs plus an
 * authenticated binary GET. Receive-kind-only by design (NN-04).
 */
class MovementEvidenceService
{
	public const MAX_BYTES = 10_485_760; // 10 MB — delivery-note scans/photos
	public const FOLDER = 'delivery_notes';
	/** @var array<string, string> */
	public const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppData $appData,
		private readonly MovementMapper $movements,
		private readonly AccessControlService $access,
		private readonly LocationAclService $locationAcl,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Upload or replace the delivery note on a receive movement.
	 * Upload authorization: office (web) OR any actor whose location ACL
	 * covers the movement's location (mobile seat). The web route gates
	 * office separately; seat devices are location-scoped by identity.
	 *
	 * @param array<string, mixed> $file PHP upload array
	 */
	public function upload(string $actorUid, int $movementId, array $file): Movement
	{
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			|| !is_string($file['tmp_name'] ?? null)
			|| !is_uploaded_file($file['tmp_name'])) {
			throw new ValidationException('upload_failed', '', [['field' => 'file', 'code' => 'upload_failed']]);
		}
		$size = (int)($file['size'] ?? 0);
		if ($size <= 0 || $size > self::MAX_BYTES) {
			throw new ValidationException('photo_too_large', '', [['field' => 'file', 'code' => 'photo_too_large']]);
		}
		$raw = file_get_contents($file['tmp_name']);
		if ($raw === false || $raw === '') {
			throw new ValidationException('upload_failed', '', [['field' => 'file', 'code' => 'upload_failed']]);
		}
		$info = @getimagesizefromstring($raw);
		if ($info === false || !isset(self::ALLOWED[(string)$info['mime']])) {
			throw new ValidationException('photo_type_invalid', '', [['field' => 'file', 'code' => 'photo_type_invalid']]);
		}
		if ($info[0] < 16 || $info[1] < 16 || $info[0] > 12000 || $info[1] > 12000) {
			throw new ValidationException('photo_type_invalid', '', [['field' => 'file', 'code' => 'photo_type_invalid']]);
		}
		$ext = self::ALLOWED[(string)$info['mime']];
		$fileName = sprintf('mov-%d-%s.%s', $movementId, bin2hex(random_bytes(8)), $ext);
		$folder = $this->folder();

		$this->db->beginTransaction();
		try {
			$mov = $this->movements->lockById($movementId, true);
			$this->assertReceiveAndVisible($actorUid, $mov);
			$old = $mov->getDeliveryNotePath();
			$folder->newFile($fileName)->putContent($raw);
			$mov->setDeliveryNotePath($fileName);
			$this->movements->update($mov);
			$this->db->commit();
			// Replace deletes the old blob in the same op — no orphan growth (AC-10).
			if ($old !== null && $old !== '') {
				$this->tryDeleteFile($old);
			}
			return $mov;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			$this->tryDeleteFile($fileName);
			throw $e;
		}
	}

	/** Office-only removal (spec MH-02); mobile has no DELETE route. */
	public function delete(string $actorUid, int $movementId): Movement
	{
		$this->access->requireOffice($actorUid);
		$this->db->beginTransaction();
		try {
			$mov = $this->movements->lockById($movementId, true);
			$this->assertReceiveAndVisible($actorUid, $mov);
			$old = $mov->getDeliveryNotePath();
			$mov->setDeliveryNotePath(null);
			$this->movements->update($mov);
			$this->db->commit();
			if ($old !== null && $old !== '') {
				$this->tryDeleteFile($old);
			}
			return $mov;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @return array{content: string, mime: string} */
	public function read(string $actorUid, int $movementId): array
	{
		$mov = $this->movements->findById($movementId);
		$this->assertReceiveAndVisible($actorUid, $mov);
		$name = $mov->getDeliveryNotePath();
		if ($name === null || $name === '') {
			throw new NotFoundException('delivery_note_not_found');
		}
		try {
			$content = $this->folder()->getFile($name)->getContent();
		} catch (FilesNotFoundException) {
			throw new NotFoundException('delivery_note_not_found');
		}
		$ext = pathinfo($name, PATHINFO_EXTENSION);
		$mime = array_search($ext, self::ALLOWED, true);
		return ['content' => $content, 'mime' => $mime === false ? 'application/octet-stream' : $mime];
	}

	/** Location visibility first (uniform 404), then receive-kind fence (NN-04). */
	private function assertReceiveAndVisible(string $actorUid, Movement $mov): void
	{
		$visible = $this->locationAcl->visibleLocationIds($actorUid);
		if ($visible !== null && !in_array($mov->getLocationId(), $visible, true)) {
			throw new NotFoundException('unknown_movement');
		}
		if ($mov->getKind() !== 'receive') {
			throw new ConflictException('invalid_kind');
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
			// best-effort: secondary cleanup — row is already committed, a
			// failed delete only leaves an orphan blob; log so a failing
			// backend stops hiding leaks.
			$this->logger->warning('InventoryCheck: could not delete delivery-note file', [
				'file' => $fileName,
				'exception' => $e,
			]);
		}
	}
}
