<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCP\Server;
use Test\TestCase;

/**
 * @group DB
 */
final class ScanIdempotencyIntegrationTest extends TestCase
{
	private ScanIdempotencyService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->svc = Server::get(ScanIdempotencyService::class);
	}

	public function testClaimCompleteReplayAndRelease(): void
	{
		$actor = 'device:idem-' . bin2hex(random_bytes(3));
		$req = 'ob-test-' . bin2hex(random_bytes(4));
		$hash = $this->svc->fingerprintFromParams(['code' => 'A', 'kind' => 'issue', 'locationId' => 1, 'qty' => '2']);
		self::assertTrue($this->svc->tryClaim($actor, $req, $hash));
		self::assertFalse($this->svc->tryClaim($actor, $req, $hash));
		$pending = $this->svc->lookup($actor, $req);
		self::assertSame(ScanIdempotencyService::STATUS_PENDING, $pending['status']);
		self::assertSame($hash, $pending['payloadHash']);

		$payload = ['movements' => [['id' => 1]], 'balances' => [['qty' => 9]]];
		$this->svc->complete($actor, $req, $payload);
		$done = $this->svc->lookup($actor, $req);
		self::assertSame(ScanIdempotencyService::STATUS_DONE, $done['status']);
		self::assertSame($payload, $done['response']);
		self::assertSame($hash, $done['payloadHash']);
		self::assertTrue($this->svc->payloadMatches($done['payloadHash'], $hash));
		$other = $this->svc->fingerprintFromParams(['code' => 'A', 'kind' => 'issue', 'locationId' => 1, 'qty' => '99']);
		self::assertFalse($this->svc->payloadMatches($done['payloadHash'], $other));

		$req2 = 'ob-test-' . bin2hex(random_bytes(4));
		self::assertTrue($this->svc->tryClaim($actor, $req2));
		$this->svc->release($actor, $req2);
		$missing = $this->svc->lookup($actor, $req2);
		self::assertSame('missing', $missing['status']);
		self::assertTrue($this->svc->tryClaim($actor, $req2));
	}

	public function testNormalizeRejectsUnsafeIds(): void
	{
		self::assertNull($this->svc->normalizeClientRequestId('bad id'));
		self::assertSame('ok-1', $this->svc->normalizeClientRequestId('ok-1'));
	}
}
