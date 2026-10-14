<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service\Lookup;

use OCA\InventoryCheck\Service\Lookup\OpenFactsProvider;
use OCA\InventoryCheck\Service\Lookup\ProviderResult;
use OCA\InventoryCheck\Service\Lookup\UpstreamClient;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/LookupHttpFake.php';

final class OpenFactsProviderTest extends TestCase
{
	private const GTIN = '4006381333931';

	private function provider(FakeClient $client): OpenFactsProvider
	{
		return new OpenFactsProvider(
			new FakeClientService($client),
			'openfoodfacts',
			'https://world.openfoodfacts.org',
			'Open Food Facts',
		);
	}

	public function testHitMapsProductFields(): void
	{
		$client = new FakeClient([new FakeResponse(200, json_encode([
			'status' => 'success',
			'product' => [
				'product_name' => ' Varta V625U Batterie ',
				'brands' => 'Varta',
				'generic_name' => 'Knopfzelle',
			],
		]))]);
		$result = $this->provider($client)->lookup(self::GTIN);

		self::assertSame(ProviderResult::HIT, $result->outcome);
		self::assertNotNull($result->suggestion);
		self::assertSame('Varta V625U Batterie', $result->suggestion->name);
		self::assertSame('Varta', $result->suggestion->brand);
		self::assertSame('Knopfzelle', $result->suggestion->description);
		self::assertSame('https://world.openfoodfacts.org/product/' . self::GTIN, $result->suggestion->sourceUrl);

		// Request contract: fixed api/v3 endpoint, digit-only path, no redirects.
		self::assertCount(1, $client->requests);
		[$uri, $options] = $client->requests[0];
		self::assertSame(
			'https://world.openfoodfacts.org/api/v3/product/' . self::GTIN . '?fields=code,product_name,generic_name,brands',
			$uri,
		);
		self::assertFalse($options['allow_redirects']);
		self::assertSame(UpstreamClient::USER_AGENT, $options['headers']['User-Agent']);
	}

	public function testNotFound404IsMiss(): void
	{
		$client = new FakeClient([new FakeResponse(404, '{"status":"failure"}')]);
		self::assertSame(ProviderResult::MISS, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testFailureStatusIn200BodyIsMiss(): void
	{
		$client = new FakeClient([new FakeResponse(200, '{"status":"failure"}')]);
		self::assertSame(ProviderResult::MISS, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testMissingProductNameIsMiss(): void
	{
		$client = new FakeClient([new FakeResponse(200, json_encode([
			'status' => 'success',
			'product' => ['brands' => 'Varta'],
		]))]);
		self::assertSame(ProviderResult::MISS, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testServerErrorIsError(): void
	{
		$client = new FakeClient([new FakeResponse(500, 'oops')]);
		self::assertSame(ProviderResult::ERROR, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testTransportFailureIsError(): void
	{
		$client = new FakeClient([new \RuntimeException('dns')]);
		self::assertSame(ProviderResult::ERROR, $this->provider($client)->lookup(self::GTIN)->outcome);
	}

	public function testMalformedJsonIsError(): void
	{
		$client = new FakeClient([new FakeResponse(200, '{not json')]);
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
