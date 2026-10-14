<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Lookup;

use PHPUnit\Framework\TestCase;

/**
 * SSRF contract (spec NN-01/NN-04/NN-08): the entire security model of the
 * product lookup is that outbound requests can only ever go to the fixed
 * provider endpoints. This test scans the source, not the runtime — any
 * future change that introduces a dynamic URL, a redirect follow, or a
 * fetch of upstream-supplied content fails here immediately.
 */
final class FixedEndpointContractTest extends TestCase
{
	private const LOOKUP_DIR = __DIR__ . '/../../../lib/Service/Lookup';
	private const APPLICATION = __DIR__ . '/../../../lib/AppInfo/Application.php';

	private const ALLOWED_HOSTS = [
		'https://world.openfoodfacts.org',
		'https://world.openbeautyfacts.org',
		'https://world.openpetfoodfacts.org',
		'https://world.openproductsfacts.org',
		'https://api.upcitemdb.com',
	];

	/** @return array<string,string> file => contents */
	private function lookupSources(): array
	{
		$files = [];
		foreach (glob(self::LOOKUP_DIR . '/*.php') ?: [] as $path) {
			$files[basename($path)] = (string)file_get_contents($path);
		}
		self::assertNotEmpty($files);
		return $files;
	}

	public function testOnlyUpstreamClientTouchesTheHttpClient(): void
	{
		foreach ($this->lookupSources() as $file => $src) {
			if ($file === 'UpstreamClient.php') {
				continue;
			}
			self::assertSame(
				0,
				preg_match('/->newClient\s*\(/', $src),
				"{$file} must not create an HTTP client — only UpstreamClient may",
			);
			self::assertSame(
				0,
				preg_match('/\$http->get\s*\(|->post\s*\(/', $src),
				"{$file} must not issue raw HTTP calls",
			);
		}
	}

	public function testEveryUpstreamGetUsesAFixedBasePlusEncodedGtin(): void
	{
		foreach ($this->lookupSources() as $file => $src) {
			if (!str_contains($src, 'UpstreamClient::get(')) {
				continue;
			}
			preg_match_all('/UpstreamClient::get\(\s*\$this->http,\s*([^;]+);/s', $src, $m);
			self::assertGreaterThan(0, count($m[1]), "{$file}: cannot parse the URL expression");
			foreach ($m[1] as $expr) {
				// The base must be a constant (self::CONST) or the injected
				// host property — never anything derived from the response
				// body, headers, or request input.
				self::assertMatchesRegularExpression(
					'/^(self::ENDPOINT|\$this->host)\b/',
					trim($expr),
					"{$file}: URL base must be a constant or the fixed host — got {$expr}",
				);
				self::assertStringContainsString(
					'rawurlencode($gtin)',
					$expr,
					"{$file}: the only variable part allowed is the url-encoded GTIN",
				);
			}
		}
	}

	public function testRegisteredProviderEndpointsAreInTheAllowlist(): void
	{
		$app = (string)file_get_contents(self::APPLICATION);
		preg_match_all('/new (?:OpenFactsProvider|UpcItemDbProvider)\(\$http[^)]*\)/s', $app, $m);
		self::assertGreaterThan(0, count($m[0]), 'no provider registrations found in Application.php');
		foreach ($m[0] as $call) {
			preg_match_all("#'(https?://[^']+)'#", $call, $urls);
			foreach ($urls[1] as $url) {
				$matched = false;
				foreach (self::ALLOWED_HOSTS as $allowed) {
					if (str_starts_with($url, $allowed)) {
						$matched = true;
						break;
					}
				}
				self::assertTrue($matched, "endpoint {$url} is not in the fixed allowlist");
			}
			self::assertStringNotContainsString('http://', $call, 'endpoints must be https');
		}
	}

	public function testUpstreamClientOptionsAreHardened(): void
	{
		$src = (string)file_get_contents(self::LOOKUP_DIR . '/UpstreamClient.php');
		self::assertStringContainsString("'allow_redirects' => false", $src, 'redirects must stay disabled');
		self::assertStringContainsString("'http_errors' => false", $src, 'providers classify status codes themselves');
		self::assertMatchesRegularExpression("/'timeout' => [1-9]\d*/", $src, 'a total timeout must be set');
		self::assertMatchesRegularExpression("/'connect_timeout' => [1-9]\d*/", $src, 'a connect timeout must be set');
		self::assertStringContainsString("'Accept' => 'application/json'", $src);
		self::assertMatchesRegularExpression(
			"/USER_AGENT = 'InventoryCheck\/[^']*software-by-design\.de[^']*'/",
			$src,
			'providers require an identifiable User-Agent',
		);
		self::assertStringContainsString('MAX_BODY_BYTES', $src, 'the body-size cap constant must exist');
	}

	public function testUpstreamSuppliedUrlsAreNeverFetched(): void
	{
		$src = (string)file_get_contents(self::LOOKUP_DIR . '/UpstreamClient.php');
		self::assertSame(
			0,
			preg_match('/\$url[^;]*sourceUrl|sourceUrl[^;]*\$url/s', $src),
			'the fetched URL must never come from upstream response content',
		);
		foreach ($this->lookupSources() as $file => $code) {
			// No provider may pass a parsed-response field into UpstreamClient::get.
			preg_match_all('/UpstreamClient::get\(\s*\$this->http,\s*([^;]+);/s', $code, $m);
			foreach ($m[1] as $expr) {
				self::assertStringNotContainsString('$item', $expr, "{$file}: response field used as fetch URL");
				self::assertStringNotContainsString('$product', $expr, "{$file}: response field used as fetch URL");
				self::assertStringNotContainsString('$data', $expr, "{$file}: response field used as fetch URL");
				self::assertStringNotContainsString('$body', $expr, "{$file}: response body used as fetch URL");
			}
		}
	}

	public function testBodySizeCapIsEnforcedByEveryProvider(): void
	{
		foreach ($this->lookupSources() as $file => $src) {
			if (!str_contains($src, 'UpstreamClient::get(')) {
				continue;
			}
			self::assertStringContainsString(
				'MAX_BODY_BYTES',
				$src,
				"{$file} fetches but never enforces the 512 KB body cap",
			);
		}
	}
}
