<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\Loan;
use OCA\InventoryCheck\Db\LoanMapper;
use OCA\InventoryCheck\Exception\NotFoundException;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\LoanEvidenceService;
use OCA\InventoryCheck\Service\LocationAclService;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LoanEvidenceServiceTest extends TestCase
{
	private function loan(?string $sig = null, ?string $dmg = null): Loan
	{
		$l = new Loan();
		$l->setItemId(5);
		$l->setPersonId(3);
		$l->setLocationId(7);
		$l->setQty(2);
		$l->setSignaturePath($sig);
		$l->setDamagePhotoPath($dmg);
		return $l;
	}

	/** @param array<int, \PHPUnit\Framework\MockObject\MockObject> $mocks */
	private function svc(array $mocks): LoanEvidenceService
	{
		return new LoanEvidenceService(
			$mocks['db'],
			$mocks['appData'],
			$mocks['loans'],
			$mocks['access'],
			$mocks['locationAcl'],
			new NullLogger(),
		);
	}

	private function mocks(Loan $loan): array
	{
		$db = $this->createMock(IDBConnection::class);
		$appData = $this->createMock(IAppData::class);
		$loans = $this->createMock(LoanMapper::class);
		$loans->method('findById')->willReturn($loan);
		$loans->method('findByIdForUpdate')->willReturn($loan);
		$access = $this->createMock(AccessControlService::class);
		$locationAcl = $this->createMock(LocationAclService::class);
		return compact('db', 'appData', 'loans', 'access', 'locationAcl');
	}

	/** Tiny valid PNG (24x24 — above the 16px floor) for signature tests. */
	private function png(): string
	{
		$png = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAABgAAAAYCAYAAADgdz34AAAAIklEQVR4nGP4T2PAMGrBqAWjFoxaMGrBqAWjFoxaMDQsAACAo/d5ePaf+gAAAABJRU5ErkJggg=='
		);
		return $png === false ? '' : $png;
	}

	public function testStoreSignatureRejectsBadBase64(): void
	{
		$mocks = $this->mocks($this->loan());
		$this->expectException(ValidationException::class);
		$this->svc($mocks)->storeSignature('office', 1, '!!!not-b64!!!');
	}

	public function testStoreSignatureRejectsNonPng(): void
	{
		$mocks = $this->mocks($this->loan());
		$this->expectException(ValidationException::class);
		$this->svc($mocks)->storeSignature('office', 1, base64_encode('definitely-text'));
	}

	public function testStoreSignatureRejectsOversize(): void
	{
		$mocks = $this->mocks($this->loan());
		$this->expectException(ValidationException::class);
		$this->svc($mocks)->storeSignature('office', 1, base64_encode(random_bytes(LoanEvidenceService::SIG_MAX_BYTES + 1)));
	}

	public function testStoreSignatureStripsDataUrlPrefixAndStores(): void
	{
		$loan = $this->loan('loan-1-sig-old.png');
		$mocks = $this->mocks($loan);
		$folder = $this->createMock(ISimpleFolder::class);
		$newFile = $this->createMock(ISimpleFile::class);
		$oldFile = $this->createMock(ISimpleFile::class);
		$folder->method('newFile')->willReturn($newFile);
		$folder->method('getFile')->willReturn($oldFile);
		$mocks['appData']->method('getFolder')->willReturn($folder);
		$mocks['locationAcl']->method('canAccessLocation')->willReturn(true);
		$newFile->expects($this->once())->method('putContent');
		$oldFile->expects($this->once())->method('delete'); // orphan-free replace
		$out = $this->svc($mocks)->storeSignature('office', 1, 'data:image/png;base64,' . base64_encode($this->png()));
		$this->assertNotNull($out->getSignaturePath());
		$this->assertStringStartsWith('loan-1-sig-', $out->getSignaturePath());
	}

	public function testStoreSignatureHiddenLocationIsUniform404(): void
	{
		$mocks = $this->mocks($this->loan());
		$mocks['locationAcl']->method('canAccessLocation')->willReturn(false);
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('unknown_loan');
		$this->svc($mocks)->storeSignature('device:3', 1, base64_encode($this->png()));
	}

	public function testStoreDamagePhotoRejectsMissingUpload(): void
	{
		$mocks = $this->mocks($this->loan());
		$this->expectException(ValidationException::class);
		$this->svc($mocks)->storeDamagePhoto('office', 1, ['error' => UPLOAD_ERR_NO_FILE]);
	}

	public function testStoreDamagePhotoRejectsOversize(): void
	{
		$mocks = $this->mocks($this->loan());
		$this->expectException(ValidationException::class);
		$this->svc($mocks)->storeDamagePhoto('office', 1, [
			'error' => UPLOAD_ERR_OK,
			'tmp_name' => '/tmp/x',
			'size' => LoanEvidenceService::PHOTO_MAX_BYTES + 1,
		]);
	}

	public function testReadThrows404WhenNoBlob(): void
	{
		$mocks = $this->mocks($this->loan());
		$mocks['locationAcl']->method('canAccessLocation')->willReturn(true);
		$this->expectException(NotFoundException::class);
		$this->svc($mocks)->read('office', 1, 'signature');
	}

	public function testReadServesStoredSignature(): void
	{
		$loan = $this->loan('loan-1-sig-abc.png');
		$mocks = $this->mocks($loan);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('PNGDATA');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturn($file);
		$mocks['appData']->method('getFolder')->willReturn($folder);
		$mocks['locationAcl']->method('canAccessLocation')->willReturn(true);
		$out = $this->svc($mocks)->read('office', 1, 'signature');
		$this->assertSame('PNGDATA', $out['content']);
		$this->assertSame('image/png', $out['mime']);
	}

	public function testLoanDtoExposesFlagsNotPaths(): void
	{
		$loan = $this->loan('loan-1-sig-x.png', 'loan-1-dmg-y.jpg');
		$api = $loan->toApi();
		$this->assertTrue($api['hasSignature']);
		$this->assertTrue($api['hasDamagePhoto']);
		$this->assertArrayNotHasKey('signaturePath', $api);
		$this->assertArrayNotHasKey('damagePhotoPath', $api);
		$this->assertArrayNotHasKey('signature_path', $api);
		$this->assertArrayNotHasKey('damage_photo_path', $api);
	}
}
