<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service\Lookup;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/**
 * Shared guarded GET for lookup providers: fixed options, no redirects,
 * hard timeouts. Returns the response for the provider to classify
 * (hit/miss/error); null = transport failure. 429 surfaces as
 * ProviderRateLimitedException which providers map to a RATE_LIMITED outcome.
 */
final class UpstreamClient
{
	/** Response bodies are product JSON — anything beyond this is hostile/broken. */
	public const MAX_BODY_BYTES = 524288;

	public const USER_AGENT = 'InventoryCheck/1.0 (+https://software-by-design.de)';

	public static function get(IClientService $http, string $url): ?IResponse
	{
		try {
			$response = $http->newClient()->get($url, [
				'timeout' => 6,
				'connect_timeout' => 4,
				'allow_redirects' => false,
				'http_errors' => false,
				'headers' => [
					'Accept' => 'application/json',
					'User-Agent' => self::USER_AGENT,
				],
			]);
		} catch (ProviderRateLimitedException $e) {
			throw $e;
		} catch (\Throwable) {
			return null;
		}
		if ($response->getStatusCode() === 429) {
			throw new ProviderRateLimitedException();
		}
		return $response;
	}
}
