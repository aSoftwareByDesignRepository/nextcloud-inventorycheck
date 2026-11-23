<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\Movement;
use OCA\InventoryCheck\Db\MovementMapper;
use OCA\InventoryCheck\Exception\ConflictException;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCA\InventoryCheck\Service\MovementEvidenceService;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MovementEvidenceServiceTest extends TestCase
{
	private function mov(string $kind = 'receive', int $locationId = 7, ?string $note = 'mov-9-x.jpg'): Movement
	{
		$m = new Movement();
		$m->setItemId(5);
		$m->setLocationId($locationId);
		$m->setKind($kind);
		$m->setQtyDelta(2);
		$m->setQtyAfter(2);
		$m->setCreatedBy('office');
		$m->setDeliveryNotePath($note);
		return $m;
	}

	/** @param array<int, \PHPUnit\Framework\MockObject\MockObject> $mocks */
	private function svc(array $mocks): MovementEvidenceService
	{
		return new MovementEvidenceService(
			$mocks['db'],
			$mocks['appData'],
			$mocks['movements'],
			$mocks['access'],
			$mocks['locationAcl'],
			new NullLogger(),
		);
	}

	private function mocks(Movement $mov): array
	{
		$db = $this->createMock(IDBConnection::class);
		$appData = $this->createMock(IAppData::class);
		$movements = $this->createMock(MovementMapper::class);
		$movements->method('findById')->willReturn($mov);
		$movements->method('lockById')->willReturn($mov);
		$access = $this->createMock(AccessControlService::class);
		$locationAcl = $this->createMock(LocationAclService::class);
		return compact('db', 'appData', 'movements', 'access', 'locationAcl');
	}

	public function testConstantsWhitelistAndCap(): void
	{
		self::assertSame(10_485_760, MovementEvidenceService::MAX_BYTES);
		self::assertSame(
			['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
			MovementEvidenceService::ALLOWED,
		);
		self::assertArrayNotHasKey('image/svg+xml', MovementEvidenceService::ALLOWED);
	}

	public function testReadReturnsContentAndMime(): void
	{
		$mov = $this->mov();
		$m = $this->mocks($mov);
		$m['locationAcl']->method('visibleLocationIds')->willReturn(null);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('PNGDATA');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->with('mov-9-x.jpg')->willReturn($file);
		$m['appData']->method('getFolder')->willReturn($folder);
		$out = $this->svc($m)->read('field-user', 9);
		self::assertSame('PNGDATA', $out['content']);
		self::assertSame('image/jpeg', $out['mime']);
	}

	public function testReadInvisibleLocationIsUniform404(): void
	{
		$m = $this->mocks($this->mov('receive', 7));
		$m['locationAcl']->method('visibleLocationIds')->willReturn([3, 4]);
		$this->expectException(NotFoundException::class);
		$this->svc($m)->read('field-user', 9);
	}

	public function testReadNonReceiveKindIsConflict(): void
	{
		$m = $this->mocks($this->mov('issue', 7));
		$m['locationAcl']->method('visibleLocationIds')->willReturn(null);
		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('invalid_kind');
		$this->svc($m)->read('office', 9);
	}

	public function testInvisibleLocation404BeatsKindConflict(): void
	{
		// Non-receive row at an invisible location must 404, not 409 — the
		// conflict would leak the row's existence (uniform-404 rule).
		$m = $this->mocks($this->mov('issue', 7));
		$m['locationAcl']->method('visibleLocationIds')->willReturn([3]);
		$this->expectException(NotFoundException::class);
		$this->svc($m)->read('field-user', 9);
	}

	public function testReadWithoutNoteIs404(): void
	{
		$m = $this->mocks($this->mov('receive', 7, null));
		$m['locationAcl']->method('visibleLocationIds')->willReturn(null);
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('delivery_note_not_found');
		$this->svc($m)->read('office', 9);
	}

	public function testUploadRejectsInvalidFileArray(): void
	{
		$m = $this->mocks($this->mov());
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('upload_failed');
		$this->svc($m)->upload('office', 9, ['error' => UPLOAD_ERR_PARTIAL]);
	}

	public function testDeleteOnNonReceiveKindIsConflict(): void
	{
		$m = $this->mocks($this->mov('issue'));
		$m['locationAcl']->method('visibleLocationIds')->willReturn(null);
		$this->expectException(ConflictException::class);
		$this->svc($m)->delete('office', 9);
	}

	public function testHasDeliveryNoteFlagIsBooleanOnly(): void
	{
		$api = $this->mov()->toApi();
		self::assertTrue($api['hasDeliveryNote']);
		self::assertArrayNotHasKey('deliveryNotePath', $api);
		self::assertArrayNotHasKey('delivery_note_path', $api);
		$empty = $this->mov('receive', 7, null)->toApi();
		self::assertFalse($empty['hasDeliveryNote']);
		$blank = $this->mov('receive', 7, '')->toApi();
		self::assertFalse($blank['hasDeliveryNote']);
	}

	public function testReadWithEmptyStringNoteIs404(): void
	{
		$m = $this->mocks($this->mov('receive', 7, ''));
		$m['locationAcl']->method('visibleLocationIds')->willReturn(null);
		$this->expectException(NotFoundException::class);
		$this->svc($m)->read('office', 9);
	}
}
