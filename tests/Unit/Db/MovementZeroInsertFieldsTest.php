<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Db;

use OCA\InventoryCheck\Db\Movement;
use PHPUnit\Framework\TestCase;

/**
 * Entity skips setters when the new value equals the property default.
 * qty_after / qty_delta of 0 must still be inserted (last-unit issue / adjust-to-zero).
 */
final class MovementZeroInsertFieldsTest extends TestCase
{
	public function testZeroQtyAfterAndDeltaAreMarkedUpdated(): void
	{
		$m = new Movement();
		$m->setQtyAfter(0);
		$m->setQtyDelta(0);
		$updated = $m->getUpdatedFields();
		self::assertArrayHasKey('qtyAfter', $updated);
		self::assertArrayHasKey('qtyDelta', $updated);
		self::assertSame(0, $m->getQtyAfter());
		self::assertSame(0, $m->getQtyDelta());
	}

	public function testPositiveValuesAlsoMarked(): void
	{
		$m = new Movement();
		$m->setQtyAfter(7);
		$m->setQtyDelta(-3);
		$updated = $m->getUpdatedFields();
		self::assertArrayHasKey('qtyAfter', $updated);
		self::assertArrayHasKey('qtyDelta', $updated);
	}
}
