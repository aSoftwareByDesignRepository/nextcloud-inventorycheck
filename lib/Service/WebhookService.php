<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * F-28 stock-change webhook — server-side push to a configured endpoint.
 * SSRF-guarded: https only, no private/loopback IPs (literal or resolved),
 * no userinfo, no redirects, hard size/timeout caps. Best-effort — a webhook
 * failure never blocks the movement.
 */
class WebhookService
{
	public const KEY_URL = 'restock_webhook_url';
	private const MAX_BODY = 4096;

	public function __construct(
		private readonly IClientService $clientService,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	public function url(): ?string
	{
		$u = trim((string)$this->config->getAppValue(Application::APP_ID, self::KEY_URL, ''));
		return $u !== '' ? $u : null;
	}

	public function setUrl(?string $url): void
	{
		if ($url !== null) {
			self::assertSafeUrl($url);
			$this->config->setAppValue(Application::APP_ID, self::KEY_URL, $url);
		} else {
			$this->config->deleteAppValue(Application::APP_ID, self::KEY_URL);
		}
	}

	/** Reject anything that is not a plain public https endpoint. */
	public static function assertSafeUrl(string $url): void
	{
		$bad = fn () => throw new \OCA\InventoryCheck\Exception\ValidationException(
			'webhook_url_invalid', '', [['field' => 'webhookUrl', 'code' => 'webhook_url_invalid']],
		);
		$url = trim($url);
		if ($url === '' || mb_strlen($url) > 1024 || preg_match('/[\s\x00-\x1f]/', $url)) {
			$bad();
		}
		$parts = parse_url($url);
		if ($parts === false
			|| ($parts['scheme'] ?? '') !== 'https'
			|| isset($parts['user']) || isset($parts['pass'])
			|| empty($parts['host'])) {
			$bad();
		}
		$host = strtolower($parts['host']);
		if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')
			|| str_ends_with($host, '.local') || str_ends_with($host, '.corp')) {
			$bad();
		}
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			if (self::isPrivateIp($host)) {
				$bad();
			}
			return;
		}
		// Hostname — resolve and reject if ANY answer is private/loopback.
		$ips = array_merge(
			array_map(fn ($r) => (string)($r['ip'] ?? ''), gethostbynamel($host) ?: []),
			array_map(fn ($r) => (string)($r['ipv6'] ?? ''), (array)(dns_get_record($host, DNS_AAAA) ?: [])),
		);
		$ips = array_filter($ips, fn ($ip) => $ip !== '');
		if ($ips === [] || array_filter($ips, self::isPrivateIp(...)) !== []) {
			$bad();
		}
	}

	private static function isPrivateIp(string $ip): bool
	{
		$ip = trim($ip, '[]');
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$long = ip2long($ip);
			return ($long >= 0x7F000000 && $long <= 0x7FFFFFFF)      // 127/8
				|| ($long >= 0x0A000000 && $long <= 0x0AFFFFFF)      // 10/8
				|| ($long >= 0xAC100000 && $long <= 0xAC1FFFFF)      // 172.16/12
				|| ($long >= 0xC0A80000 && $long <= 0xC0A8FFFF)      // 192.168/16
				|| ($long >= 0xA9FE0000 && $long <= 0xA9FEFFFF)      // 169.254/16 link-local
				|| ($long >= 0x00000000 && $long <= 0x00FFFFFF)      // 0/8
				|| ($long >= 0xE0000000)                            // multicast+reserved
				|| ($long >= 0xC0000000 && $long <= 0xC00000FF);     // 192.0.0/24
		}
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$low = strtolower($ip);
			return $low === '::1' || $low === '::'
				|| str_starts_with($low, 'fc') || str_starts_with($low, 'fd')
				|| str_starts_with($low, 'fe80') || str_starts_with($low, '::ffff:127.')
				|| str_starts_with($low, '::ffff:10.') || str_starts_with($low, '::ffff:172.')
				|| str_starts_with($low, '::ffff:192.168');
		}
		return true; // unparseable — fail closed
	}

	/** Post a stock-change event; failure is logged, never thrown. */
	public function postStockChange(string $kind, int $itemId, int $qtyDelta): void
	{
		$url = $this->url();
		if ($url === null) {
			return;
		}
		try {
			self::assertSafeUrl($url); // re-validate at send — DNS-rebind guard
			$body = json_encode([
				'event' => 'stock.change',
				'kind' => $kind,
				'itemId' => $itemId,
				'qtyDelta' => $qtyDelta,
				'ts' => time(),
			], JSON_UNESCAPED_SLASHES);
			if ($body === false || strlen($body) > self::MAX_BODY) {
				return;
			}
			$client = $this->clientService->newClient();
			$client->post($url, [
				'body' => $body,
				'timeout' => 5,
				'headers' => ['Content-Type' => 'application/json'],
				'nextcloud' => ['allow_local_address' => false],
				'allow_redirects' => false,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('InventoryCheck webhook post failed', [
				'app' => Application::APP_ID,
				'kind' => $kind,
				'itemId' => $itemId,
				'message' => $e->getMessage(),
			]);
		}
	}
}
