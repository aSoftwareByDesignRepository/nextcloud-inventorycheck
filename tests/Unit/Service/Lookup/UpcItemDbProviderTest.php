<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service\Lookup;

use OCA\InventoryCheck\Service\Lookup\ProviderResult;
use OCA\InventoryCheck\Service\Lookup\UpcItemDbProvider;
use OCA\InventoryCheck\Service\Lookup\UpstreamClient;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/LookupHttpFake.php';

final class UpcItemDbProviderTest extends TestCase
{
	private const GTIN = '036000291452';

	private function provider(FakeClient $client): UpcItemDbProvider
	{
		return new UpcItemDbProvider(new FakeClientService($client));
	}

	public function testHitMapsFirstUsableItem(): void
	{
		$client = new FakeClient([new FakeResponse(200, json_encode([
			'code' => 'OK',
			'total' => 2,
			'items' => [
				['title' => ''],
				['title' => 'Coca Cola 330ml', 'brand' => 'Coca-Cola', 'description' => 'Can', 'link' => 'https://upcitemdb.com/x'],
			],
		]))]);
		$result = $this->provider($client)->lookup(self::GTIN);

		self::assertSame(ProviderResult::HIT, $result->outcome);
		self::assertSame('Coca Cola 330ml', $result->suggestion->name);
		self::assertSame('Coca-Cola', $result->suggestion->brand);
		self::assertSame('Can', $result->suggestion->description);
		self::assertSame('https://upcitemdb.com/x', $result->suggestion->sourceUrl);

		self::assertCount(1, $client->requests);
		[$uri] = $client->requests[0];
		self::assertSame('https://api.upcitemdb.com/prod/trial/lookup?upc=' . self::GTIN, $uri);
	}

	public function testAllItemsUntitledIsMiss(): void
	{
		$client = new FakeClient([new FakeResponse(200, json_encode([
			'code' => 'OK',
			'items' => [['title' => ''], 'string-item'],
		]))]);
		self::assertSame(ProviderResult::MISS, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testNonArrayItemsIsMissNotCrash(): void
	{
		// Malformed upstream shape — must degrade to miss, never warn/crash.
		$client = new FakeClient([new FakeResponse(200, '{"code":"OK","items":42}')]);
		self::assertSame(ProviderResult::MISS, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testNonOkCodeIsError(): void
	{
		$client = new FakeClient([new FakeResponse(200, '{"code":"INVALID_UPC"}')]);
		self::assertSame(ProviderResult::ERROR, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function test404IsMiss(): void
	{
		$client = new FakeClient([new FakeResponse(404, '{"code":"NOT_FOUND"}')]);
		self::assertSame(ProviderResult::MISS, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testServerErrorIsError(): void
	{
		$client = new FakeClient([new FakeResponse(503, '')]);
		self::assertSame(ProviderResult::ERROR, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testTransportFailureIsError(): void
	{
		$client = new FakeClient([new \RuntimeException('timeout')]);
		self::assertSame(ProviderResult::ERROR, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testMalformedJsonIsError(): void
	{
		$client = new FakeClient([new FakeResponse(200, 'not json')]);
		self::assertSame(ProviderResult::ERROR, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testOversizedBodyIsError(): void
	{
		$client = new FakeClient([new FakeResponse(200, str_repeat('x', UpstreamClient::MAX_BODY_BYTES + 1))]);
		self::assertSame(ProviderResult::ERROR, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function test429IsRateLimited(): void
	{
		$client = new FakeClient([new FakeResponse(429, '')]);
		self::assertSame(ProviderResult::RATE_LIMITED, $this->provider($client)->lookup(self::GTIN)->outcome);
	}
}
