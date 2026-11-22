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

	public static function get(IClientService $http, string $url, ?float $maxWaitSeconds = null): ?IResponse
	{
		$timeout = $maxWaitSeconds === null ? 6.0 : min(6.0, max(0.05, $maxWaitSeconds));
		try {
			$response = $http->newClient()->get($url, [
				'timeout' => $timeout,
				'connect_timeout' => min(4.0, $timeout),
				'allow_redirects' => false,
				'http_errors' => false,
				// Lazy body: body() reads at most MAX_BODY_BYTES+1, so a
				// hostile multi-GB payload never materializes in memory
				// (GAP-4 — the cap must be a precheck, not post-hoc).
				'stream' => true,
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

	/**
	 * Bounded body read for get() responses. With 'stream' the body is a
	 * resource — read at most MAX_BODY_BYTES+1 bytes; Content-Length
	 * declared beyond the cap rejects without reading at all.
	 * Returns null for oversized bodies (provider maps to error).
	 * Accepts string bodies too so unit mocks keep working.
	 */
	public static function body(IResponse $response): ?string
	{
		$length = $response->getHeader('Content-Length');
		if ($length !== '' && (int)$length > self::MAX_BODY_BYTES) {
			return null;
		}
		$raw = $response->getBody();
		if (is_string($raw)) {
			return strlen($raw) <= self::MAX_BODY_BYTES ? $raw : null;
		}
		if (!is_resource($raw)) {
			return null;
		}
		$body = stream_get_contents($raw, self::MAX_BODY_BYTES + 1);
		fclose($raw);
		if ($body === false || strlen($body) > self::MAX_BODY_BYTES) {
			return null;
		}
		return $body;
	}
}
