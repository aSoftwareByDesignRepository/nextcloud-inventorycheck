<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AdjustSemantics;
use PHPUnit\Framework\TestCase;

final class AdjustSemanticsTest extends TestCase
{
	public function testSetComputesDelta(): void
	{
		$result = AdjustSemantics::compute('set', 14, 12, null, false);
		$this->assertSame(-2, $result['delta']);
		$this->assertSame(12, $result['qtyAfter']);
	}

	public function testSetNoopRejected(): void
	{
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('noop');
		AdjustSemantics::compute('set', 12, 12, null, false);
	}

	public function testSetNegativeTargetBlocked(): void
	{
		$this->expectException(ValidationException::class);
		AdjustSemantics::compute('set', 5, -1, null, false);
	}

	public function testSetNegativeTargetAllowedWhenPolicyOn(): void
	{
		$result = AdjustSemantics::compute('set', 5, -1, null, true);
		$this->assertSame(-6, $result['delta']);
		$this->assertSame(-1, $result['qtyAfter']);
	}

	public function testDeltaMode(): void
	{
		$result = AdjustSemantics::compute('delta', 10, null, -3, false);
		$this->assertSame(-3, $result['delta']);
		$this->assertSame(7, $result['qtyAfter']);
	}

	public function testDeltaZeroRejected(): void
	{
		$this->expectException(ValidationException::class);
		AdjustSemantics::compute('delta', 10, null, 0, false);
	}

	public function testDeltaWouldGoNegative(): void
	{
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('would_go_negative');
		AdjustSemantics::compute('delta', 2, null, -5, false);
	}

	public function testDeltaOutOfMovementQtyBounds(): void
	{
		$this->expectException(ValidationException::class);
		AdjustSemantics::compute('delta', 0, null, 1_000_001, true);
	}

	public function testSetTargetAboveMovementMaxRejected(): void
	{
		$this->expectException(ValidationException::class);
		AdjustSemantics::compute('set', 0, 1_000_001, null, true);
	}

	public function testSetTargetAtMovementMaxAllowed(): void
	{
		$result = AdjustSemantics::compute('set', 0, 1_000_000, null, true);
		$this->assertSame(1_000_000, $result['delta']);
		$this->assertSame(1_000_000, $result['qtyAfter']);
	}

	public function testInvalidMode(): void
	{
		$this->expectException(ValidationException::class);
		AdjustSemantics::compute('weird', 1, 1, null, false);
	}
}
