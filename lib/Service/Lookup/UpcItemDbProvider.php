<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service\Lookup;

use OCP\Http\Client\IClientService;

/**
 * UPCitemdb free ("trial") tier: GET https://api.upcitemdb.com/prod/trial/lookup?upc={gtin}
 * Hit: HTTP 200 {"code":"OK","total":N,"items":[{title,brand,description,link,…}]}.
 * Miss: 404 {"code":"NOT_FOUND"…} or items empty. 429 → RATE_LIMITED outcome
 * (the service then marks the provider's daily budget exhausted).
 */
final class UpcItemDbProvider implements ProductLookupProvider
{
	public const ID = 'upcitemdb';
	private const ENDPOINT = 'https://api.upcitemdb.com/prod/trial/lookup';

	public function __construct(private readonly IClientService $http)
	{
	}

	public function id(): string
	{
		return self::ID;
	}

	public function label(): string
	{
		return 'UPCitemdb';
	}

	public function lookup(string $gtin): ProviderResult
	{
		try {
			$response = UpstreamClient::get($this->http, self::ENDPOINT . '?upc=' . rawurlencode($gtin));
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
		if (!is_array($data) || ($data['code'] ?? '') !== 'OK') {
			return ProviderResult::error();
		}
		$items = $data['items'] ?? null;
		if (!is_array($items)) {
			return ProviderResult::miss();
		}
		// First item with a usable title wins — an untitled top entry must not
		// mask a resolvable product behind it.
		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}
			$name = is_string($item['title'] ?? null) ? trim($item['title']) : '';
			if ($name === '') {
				continue;
			}
			return ProviderResult::hit(new ProductSuggestion(
				$name,
				is_string($item['brand'] ?? null) ? trim($item['brand']) ?: null : null,
				is_string($item['description'] ?? null) ? trim($item['description']) ?: null : null,
				is_string($item['link'] ?? null) ? trim($item['link']) : null,
			));
		}
		return ProviderResult::miss();
	}
}
