<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Integration;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\ItemService;
use OCA\InventoryCheck\Service\LocationService;
use OCA\InventoryCheck\Service\MovementService;
use Test\TestCase;

/**
 * N1/N2 soft latency gate on a miniature reference workload.
 *
 * Full N4 (50/2k/20k) is too heavy for every CI run; this proves the movement
 * transaction path stays under SPEC budgets with warm PHP/MariaDB on Docker.
 * Raise sample count in docs/ops when certifying a release on the full N4 seed.
 *
 * @group DB
 */
final class LatencySmokeIntegrationTest extends TestCase
{
	/** SPEC N2: p95 movement post < 300 ms (single instance). */
	private const MOVEMENT_P95_MS = 300.0;

	/** SPEC N1: p95 API-equivalent service call < 500 ms. */
	private const READ_P95_MS = 500.0;

	public function testMovementPostAndListStayUnderSpecBudgets(): void
	{
		$app = new Application();
		$c = $app->getContainer();
		/** @var MovementService $movements */
		$movements = $c->get(MovementService::class);
		/** @var LocationService $locations */
		$locations = $c->get(LocationService::class);
		/** @var ItemService $items */
		$items = $c->get(ItemService::class);

		$suffix = bin2hex(random_bytes(3));
		$uid = 'admin';
		$loc = $locations->create($uid, [
			'code' => 'LAT-L-' . $suffix,
			'name' => 'Latency Loc',
			'kind' => 'other',
		]);
		$item = $items->create($uid, [
			'sku' => 'LAT-I-' . $suffix,
			'name' => 'Latency Item',
		]);
		$itemId = (int)$item['id'];
		$locId = (int)$loc['id'];

		$movements->receive($uid, $itemId, $locId, 500, 'latency-seed');

		$postSamples = [];
		for ($i = 0; $i < 20; $i++) {
			$t0 = hrtime(true);
			$movements->issue($uid, $itemId, $locId, 1, 'latency');
			$postSamples[] = (hrtime(true) - $t0) / 1e6;
		}

		$readSamples = [];
		for ($i = 0; $i < 20; $i++) {
			$t0 = hrtime(true);
			$movements->list('admin', null, $itemId, $locId, null, null, null, 50, 0);
			$readSamples[] = (hrtime(true) - $t0) / 1e6;
		}

		$postP95 = $this->percentile($postSamples, 95);
		$readP95 = $this->percentile($readSamples, 95);

		$this->assertLessThan(
			self::MOVEMENT_P95_MS,
			$postP95,
			sprintf('N2 movement p95=%.1fms (budget %.0fms); samples=%s', $postP95, self::MOVEMENT_P95_MS, json_encode($postSamples)),
		);
		$this->assertLessThan(
			self::READ_P95_MS,
			$readP95,
			sprintf('N1 list p95=%.1fms (budget %.0fms); samples=%s', $readP95, self::READ_P95_MS, json_encode($readSamples)),
		);
	}

	/**
	 * @param list<float> $samples
	 */
	private function percentile(array $samples, int $p): float
	{
		sort($samples);
		$n = count($samples);
		$this->assertGreaterThan(0, $n);
		$idx = (int)ceil(($p / 100) * $n) - 1;
		$idx = max(0, min($n - 1, $idx));
		return $samples[$idx];
	}
}
