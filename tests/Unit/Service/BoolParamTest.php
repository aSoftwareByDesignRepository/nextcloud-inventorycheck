<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\BoolParam;
use PHPUnit\Framework\TestCase;

/**
 * PHP (bool)'false' is true — deactivate payloads must not use a bare cast.
 */
final class BoolParamTest extends TestCase
{
	public function testNativeBooleansPassThrough(): void
	{
		self::assertTrue(BoolParam::parse(true, 'active'));
		self::assertFalse(BoolParam::parse(false, 'active'));
	}

	public function testZeroOneWireForms(): void
	{
		self::assertTrue(BoolParam::parse(1, 'active'));
		self::assertTrue(BoolParam::parse('1', 'active'));
		self::assertFalse(BoolParam::parse(0, 'active'));
		self::assertFalse(BoolParam::parse('0', 'active'));
	}

	public function testStringFalseCastIsThePhpFootgun(): void
	{
		self::assertFalse(BoolParam::parse('0', 'active'));
		self::assertTrue((bool)'false', 'sanity: PHP (bool)\'false\' is true — that is the footgun');
		self::assertFalse((bool)'0', 'sanity: PHP (bool)\'0\' is false');
	}

	public function testGarbageRejected(): void
	{
		$this->expectException(ValidationException::class);
		BoolParam::parse('false', 'active');
	}

	public function testStringFalseMustNotActivate(): void
	{
		try {
			BoolParam::parse('false', 'active');
			self::fail('string false must not be accepted — (bool)\'false\' is true in PHP');
		} catch (ValidationException $e) {
			self::assertSame('invalid_type', $e->getDetails()[0]['code'] ?? '');
		}
	}
}
