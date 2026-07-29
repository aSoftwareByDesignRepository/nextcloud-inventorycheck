<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\MovementMath;
use PHPUnit\Framework\TestCase;

final class MovementMathTest extends TestCase
{
	public function testValidMovementQtyBounds(): void
	{
		$this->assertTrue(MovementMath::isValidMovementQty(1_000_000));
		$this->assertTrue(MovementMath::isValidMovementQty(1_000_001, 1_000_000_000));
		$this->assertFalse(MovementMath::isValidMovementQty(1_000_001));
		$this->assertFalse(MovementMath::isValidMovementQty(-1));
		$this->assertFalse(MovementMath::isValidMovementQty(0));
		$this->assertTrue(MovementMath::isValidMovementQty(1));
	}

	public function testBalanceBounds(): void
	{
		$this->assertTrue(MovementMath::isValidBalance(0));
		$this->assertTrue(MovementMath::isValidBalance(MovementMath::BALANCE_MIN));
		$this->assertTrue(MovementMath::isValidBalance(MovementMath::BALANCE_MAX));
		$this->assertFalse(MovementMath::isValidBalance(MovementMath::BALANCE_MIN - 1));
		$this->assertFalse(MovementMath::isValidBalance(MovementMath::BALANCE_MAX + 1));
	}

	public function testDeltaForKindSigns(): void
	{
		$this->assertSame(5, MovementMath::deltaForKind('receive', 5));
		$this->assertSame(5, MovementMath::deltaForKind('transfer_in', 5));
		$this->assertSame(-5, MovementMath::deltaForKind('issue', 5));
		$this->assertSame(-5, MovementMath::deltaForKind('transfer_out', 5));
	}

	public function testDeltaForKindRejectsUnknown(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		MovementMath::deltaForKind('adjust', 1);
	}

	public function testApplyDelta(): void
	{
		$this->assertSame(7, MovementMath::applyDelta(10, -3));
		$this->assertSame(15, MovementMath::applyDelta(10, 5));
	}

	/** @dataProvider insufficientProvider */
	public function testInsufficientStock(int $before, int $after, bool $allow, bool $expected): void
	{
		$this->assertSame($expected, MovementMath::isInsufficientStock($before, $after, $allow));
	}

	public function insufficientProvider(): array
	{
		return [
			'allow_any' => [0, -5, true, false],
			'positive_ok' => [5, 2, false, false],
			'to_zero_ok' => [5, 0, false, false],
			'new_negative' => [5, -1, false, true],
			'more_negative' => [-2, -5, false, true],
			'less_negative_ok' => [-5, -2, false, false],
			'to_positive_ok' => [-3, 1, false, false],
		];
	}
}
