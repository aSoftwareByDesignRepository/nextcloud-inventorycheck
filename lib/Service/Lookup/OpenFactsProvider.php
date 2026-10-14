<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service\Lookup;

use OCP\Http\Client\IClientService;

/**
 * Open * Facts product lookup (food / beauty / petfood / products hosts share
 * one API shape): GET {host}/api/v3/product/{gtin}?fields=…
 * Hit: HTTP 200 {"status":"success","product":{…}}. Miss: 404 {"status":"failure"}.
 */
final class OpenFactsProvider implements ProductLookupProvider
{
	private const FIELDS = 'code,product_name,generic_name,brands';

	public function __construct(
		private readonly IClientService $http,
		private readonly string $id,
		private readonly string $host,
		private readonly string $label,
	) {
	}

	public function id(): string
	{
		return $this->id;
	}

	public function label(): string
	{
		return $this->label;
	}

	public function lookup(string $gtin): ProviderResult
	{
		try {
			$response = UpstreamClient::get($this->http, $this->host . '/api/v3/product/' . rawurlencode($gtin)
				. '?fields=' . self::FIELDS);
		} catch (ProviderRateLimitedException) {
			return ProviderResult::rateLimited();
		}
		if ($response === null) {
			return ProviderResult::error();
		}
		$status = $response->getStatusCode();
		if ($status === 404) {
			return ProviderResult::miss();
		}
		if ($status !== 200) {
			return ProviderResult::error();
		}
		$body = (string)$response->getBody();
		if (strlen($body) > UpstreamClient::MAX_BODY_BYTES) {
			return ProviderResult::error();
		}
		$data = json_decode($body, true);
		if (!is_array($data)) {
			return ProviderResult::error();
		}
		$product = $data['product'] ?? null;
		if (($data['status'] ?? '') !== 'success' || !is_array($product)) {
			return ProviderResult::miss();
		}
		$name = self::text($product['product_name'] ?? null);
		if ($name === '') {
			return ProviderResult::miss();
		}
		return ProviderResult::hit(new ProductSuggestion(
			$name,
			self::text($product['brands'] ?? null) ?: null,
			self::text($product['generic_name'] ?? null) ?: null,
			$this->host . '/product/' . rawurlencode($gtin),
		));
	}

	private static function text(mixed $value): string
	{
		return is_string($value) ? trim($value) : '';
	}
}
