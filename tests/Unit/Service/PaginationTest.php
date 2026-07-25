<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
	public function testDefaults(): void
	{
		$this->assertSame(['limit' => 50, 'offset' => 0], Pagination::parse(null, null));
	}

	public function testMax(): void
	{
		$this->assertSame(['limit' => 200, 'offset' => 10], Pagination::parse('200', '10'));
	}

	public function testInvalid(): void
	{
		$this->expectException(ValidationException::class);
		Pagination::parse(201, 0);
	}

	public function testNegativeOffset(): void
	{
		$this->expectException(ValidationException::class);
		Pagination::parse(10, -1);
	}
}
