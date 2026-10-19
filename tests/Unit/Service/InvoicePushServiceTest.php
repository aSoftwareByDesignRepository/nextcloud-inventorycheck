<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Db\InvoicePush;
use OCA\InventoryCheck\Db\InvoicePushMapper;
use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\InvoicePushService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F8 outbox caller — unit env has no InvoiceCheck container, so the live
 * facade hop runs in-container; here we prove staging, ref stability,
 * fail-soft status mapping, and retryable-set accounting.
 */
final class InvoicePushServiceTest extends TestCase
{
	private function svc(InvoicePushMapper $pushes, bool $ivEnabled = false): InvoicePushService
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($ivEnabled);
		return new InvoicePushService($apps, $pushes, new Clock(), $this->createMock(LoggerInterface::class));
	}

	public function testIssueRefIsDeterministicFromMinMovementId(): void
	{
		$ref = InvoicePushService::issueRefFor(7, [
			['id' => 55], ['id' => 57], ['id' => 56],
		]);
		$this->assertSame('iv:project:7:55', $ref);
		// Replay with the same movement ids resolves the same ref.
		$this->assertSame($ref, InvoicePushService::issueRefFor(7, [['id' => 55]]));
	}

	public function testStageAndPushStagesAndFailsSoftWithoutFacade(): void
	{
		$stored = new InvoicePush();
		$stored->setIssueRef('iv:project:7:55');
		$stored->setStatus(InvoicePush::STATUS_PENDING);
		$pushes = $this->createMock(InvoicePushMapper::class);
		$pushes->expects(self::once())->method('insertPending')
			->with('iv:project:7:55', 7, 'u1', self::anything(), self::anything())
			->willReturn($stored);
		$pushes->expects(self::once())->method('bumpAttempt');
		$pushes->expects(self::once())->method('markFailed')
			->with($stored, 'sibling_unavailable', self::anything());
		$r = $this->svc($pushes, false)->stageAndPush('u1', 7, [['id' => 55]], [['sku' => 'S', 'text' => 'T', 'qty' => 1, 'unit' => 'items']]);
		$this->assertSame('failed', $r);
	}

	public function testStageAndPushShortCircuitsDoneRow(): void
	{
		$done = new InvoicePush();
		$done->setStatus(InvoicePush::STATUS_DONE);
		$pushes = $this->createMock(InvoicePushMapper::class);
		$pushes->method('insertPending')->willReturn($done);
		$pushes->expects(self::never())->method('bumpAttempt');
		$this->assertSame('ok', $this->svc($pushes, false)->stageAndPush('u1', 7, [['id' => 55]], []));
	}

	public function testStagingFailureReturnsFailedWithoutThrowing(): void
	{
		$pushes = $this->createMock(InvoicePushMapper::class);
		$pushes->method('insertPending')->willThrowException(new \RuntimeException('db gone'));
		$this->assertSame('failed', $this->svc($pushes, false)->stageAndPush('u1', 7, [['id' => 55]], []));
	}

	public function testBadPayloadMarksFailed(): void
	{
		$row = new InvoicePush();
		$row->setPayloadJson('{invalid');
		$pushes = $this->createMock(InvoicePushMapper::class);
		$pushes->expects(self::once())->method('markFailed')->with($row, 'bad_payload', self::anything());
		$svc = $this->svc($pushes, true);
		$this->assertSame('failed', $svc->pushRow($row, 'u1'));
	}
}
