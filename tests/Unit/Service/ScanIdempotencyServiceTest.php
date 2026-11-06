<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\Clock;
use OCA\InventoryCheck\Service\ScanIdempotencyService;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ScanIdempotencyServiceTest extends TestCase
{
	public function testNormalizeRejectsGarbage(): void
	{
		$svc = new ScanIdempotencyService(
			$this->createMock(IDBConnection::class),
			new Clock(),
		);
		self::assertNull($svc->normalizeClientRequestId(''));
		self::assertNull($svc->normalizeClientRequestId('has space'));
		self::assertNull($svc->normalizeClientRequestId(str_repeat('a', 65)));
		self::assertNull($svc->normalizeClientRequestId(['x']));
		self::assertSame('ob-1-abc', $svc->normalizeClientRequestId('ob-1-abc'));
		self::assertSame('cf:42_ok', $svc->normalizeClientRequestId('cf:42_ok'));
	}

	public function testLookupReturnsDonePayload(): void
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn([
			'status' => 'done',
			'response_json' => '{"movements":[],"balances":[]}',
		]);
		$result->method('closeCursor');

		$expr = new class {
			public function eq(mixed ...$args): string
			{
				return 'eq';
			}
		};

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new ScanIdempotencyService($db, new Clock());
		$look = $svc->lookup('device:1', 'ob-1');
		self::assertSame('done', $look['status']);
		self::assertSame(['movements' => [], 'balances' => []], $look['response']);
		self::assertNull($look['payloadHash']);
	}

	public function testFingerprintChangesWhenQtyChanges(): void
	{
		$svc = new ScanIdempotencyService(
			$this->createMock(IDBConnection::class),
			new Clock(),
		);
		$a = $svc->fingerprintFromParams([
			'code' => 'SKU-1',
			'kind' => 'issue',
			'locationId' => 3,
			'qty' => '10',
		]);
		$b = $svc->fingerprintFromParams([
			'code' => 'SKU-1',
			'kind' => 'issue',
			'locationId' => 3,
			'qty' => '1',
		]);
		self::assertNotSame($a, $b);
		self::assertTrue($svc->payloadMatches($a, $a));
		self::assertFalse($svc->payloadMatches($a, $b));
		// Legacy rows without a stored hash still replay (upgrade safety).
		self::assertTrue($svc->payloadMatches(null, $b));
	}

	public function testFingerprintChangesWhenSpendChanges(): void
	{
		// F-06: an edited paid-total on a retried receive must collide as
		// idempotency_payload_mismatch, never silently apply the new amount.
		$svc = new ScanIdempotencyService(
			$this->createMock(IDBConnection::class),
			new Clock(),
		);
		$base = [
			'code' => 'SKU-1',
			'kind' => 'receive',
			'locationId' => 3,
			'clientRequestId' => 'crid-1',
			'spendMinor' => 12840,
		];
		$a = $svc->fingerprintFromParams($base);
		$b = $svc->fingerprintFromParams(array_merge($base, ['spendMinor' => 9999]));
		$c = $svc->fingerprintFromParams(array_merge($base, ['spendMinor' => null]));
		self::assertNotSame($a, $b);
		self::assertNotSame($a, $c);
		self::assertFalse($svc->payloadMatches($a, $b));
		// Same amount re-encoded as digit string on retry must replay, not
		// mismatch — the canonical form normalises both to int.
		$d = $svc->fingerprintFromParams(array_merge($base, ['spendMinor' => '12840']));
		self::assertSame($a, $d);
		// Garbage input stays distinct from a real zero.
		$e = $svc->fingerprintFromParams(array_merge($base, ['spendMinor' => 'abc']));
		self::assertNotSame($a, $e);
	}

	public function testFingerprintChangesWhenExpiresAtChanges(): void
	{
		// Lot expiry rides the same scan payload — an edited date on retry must
		// collide, not silently replay the stale response.
		$svc = new ScanIdempotencyService(
			$this->createMock(IDBConnection::class),
			new Clock(),
		);
		$base = ['code' => 'SKU-1', 'kind' => 'receive', 'locationId' => 3];
		$a = $svc->fingerprintFromParams($base + ['expiresAt' => 1735689599]);
		$b = $svc->fingerprintFromParams($base + ['expiresAt' => 1767225599]);
		$c = $svc->fingerprintFromParams($base);
		self::assertNotSame($a, $b);
		self::assertNotSame($a, $c);
	}

	public function testTryClaimReturnsFalseOnUniqueViolation(): void
	{
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeStatement')->willThrowException(
			new DbException('Duplicate entry', 23000),
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = new ScanIdempotencyService($db, new Clock());
		self::assertFalse($svc->tryClaim('alice', 'req-1', 'abc'));
	}
}
