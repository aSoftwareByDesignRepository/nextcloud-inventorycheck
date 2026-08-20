<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\OpenApi;

use PHPUnit\Framework\TestCase;

/**
 * Contract test: openapi.json must stay in sync with appinfo/routes.php
 * for the HTTP (browser) API surface: /api/* and /mobile/v1/*.
 */
final class OpenApiRoutesContractTest extends TestCase
{
	public function testOpenApiPathsAndMethodsMatchRoutes(): void
	{
		$root = dirname(__DIR__, 3);
		$routesFile = $root . '/appinfo/routes.php';
		$openapiFile = $root . '/openapi.json';

		self::assertFileExists($routesFile, 'routes.php must exist');
		self::assertFileExists($openapiFile, 'openapi.json must exist');

		$routesSpec = require $routesFile;
		self::assertIsArray($routesSpec);
		self::assertArrayHasKey('routes', $routesSpec);
		self::assertIsArray($routesSpec['routes']);

		$openapi = json_decode((string)file_get_contents($openapiFile), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($openapi);
		self::assertArrayHasKey('paths', $openapi);
		self::assertIsArray($openapi['paths']);

		$prefixes = ['/api/', '/mobile/v1/'];

		$routeSet = [];
		foreach ($routesSpec['routes'] as $route) {
			if (!is_array($route)) {
				continue;
			}
			$url = (string)($route['url'] ?? '');
			$verb = strtoupper((string)($route['verb'] ?? ''));
			if ($url === '' || $verb === '') {
				continue;
			}
			$matched = false;
			foreach ($prefixes as $p) {
				if (str_starts_with($url, $p)) {
					$matched = true;
					break;
				}
			}
			if (!$matched) {
				continue;
			}

			$routeSet[$verb . ' ' . $url] = true;
		}

		$openapiSet = [];
		foreach ($openapi['paths'] as $path => $ops) {
			if (!is_string($path) || !is_array($ops)) {
				continue;
			}
			$matched = false;
			foreach ($prefixes as $p) {
				if (str_starts_with($path, $p)) {
					$matched = true;
					break;
				}
			}
			if (!$matched) {
				continue;
			}
			foreach ($ops as $methodLower => $_opSpec) {
				if (!is_string($methodLower)) {
					continue;
				}
				$method = strtoupper($methodLower);
				if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
					continue;
				}
				$openapiSet[$method . ' ' . $path] = true;
			}
		}

		$routeKeys = array_keys($routeSet);
		$openapiKeys = array_keys($openapiSet);
		sort($routeKeys);
		sort($openapiKeys);
		self::assertSame(
			$routeKeys,
			$openapiKeys,
			'openapi.json paths/methods must exactly match appinfo/routes.php for /api/* and /mobile/v1/* (order-insensitive set compare)',
		);
	}
}

