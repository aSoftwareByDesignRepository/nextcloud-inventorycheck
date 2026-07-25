#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * N4 reference dataset seeder (SPEC §12 N4).
 *
 * Usage (Docker):
 *   docker compose exec nextcloud php custom_apps/inventorycheck/tests/fixtures/seed-reference-dataset.php \
 *     --locations=50 --items=2000 --movements=20000
 *
 * Defaults match N4. Use small counts for smoke tests:
 *   --locations=2 --items=5 --movements=10
 *
 * Requires InventoryCheck enabled and a logged-in CLI context (runs as admin via services).
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "CLI only\n");
	exit(1);
}

$rootCandidates = [
	dirname(__DIR__, 4),
	'/var/www/html',
];
$base = null;
foreach ($rootCandidates as $candidate) {
	if (is_file($candidate . '/lib/base.php')) {
		$base = $candidate . '/lib/base.php';
		break;
	}
}
if ($base === null) {
	fwrite(STDERR, "Nextcloud lib/base.php not found\n");
	exit(2);
}
require $base;

$opts = getopt('', ['locations::', 'items::', 'movements::', 'help']);
if (isset($opts['help'])) {
	fwrite(STDOUT, "seed-reference-dataset.php [--locations=50] [--items=2000] [--movements=20000]\n");
	exit(0);
}

$nLoc = max(1, (int)($opts['locations'] ?? 50));
$nItems = max(1, (int)($opts['items'] ?? 2000));
$nMov = max(0, (int)($opts['movements'] ?? 20000));

$app = new \OCA\InventoryCheck\AppInfo\Application();
$c = $app->getContainer();
/** @var \OCA\InventoryCheck\Service\LocationService $locations */
$locations = $c->get(\OCA\InventoryCheck\Service\LocationService::class);
/** @var \OCA\InventoryCheck\Service\ItemService $items */
$items = $c->get(\OCA\InventoryCheck\Service\ItemService::class);
/** @var \OCA\InventoryCheck\Service\MovementService $movements */
$movements = $c->get(\OCA\InventoryCheck\Service\MovementService::class);

$uid = 'admin';
$suffix = bin2hex(random_bytes(3));
$locIds = [];
$kinds = ['warehouse', 'shelf', 'van', 'site', 'other'];

fwrite(STDOUT, "Seeding locations=$nLoc items=$nItems movements=$nMov …\n");

for ($i = 0; $i < $nLoc; $i++) {
	$row = $locations->create($uid, [
		'code' => sprintf('N4L-%s-%04d', $suffix, $i),
		'name' => sprintf('N4 Location %d', $i),
		'kind' => $kinds[$i % count($kinds)],
	]);
	$locIds[] = (int)$row['id'];
}

$itemIds = [];
for ($i = 0; $i < $nItems; $i++) {
	$row = $items->create($uid, [
		'sku' => sprintf('N4I-%s-%05d', $suffix, $i),
		'name' => sprintf('N4 Item %d', $i),
		'reorderLevel' => $i % 7 === 0 ? 5 : 0,
	]);
	$itemIds[] = (int)$row['id'];
}

$posted = 0;
for ($i = 0; $i < $nMov; $i++) {
	$itemId = $itemIds[$i % count($itemIds)];
	$locId = $locIds[$i % count($locIds)];
	$kind = $i % 4;
	try {
		if ($kind === 0 || $kind === 1) {
			$movements->receive($uid, $itemId, $locId, 1 + ($i % 3), 'n4-seed');
			$posted++;
		} elseif ($kind === 2) {
			$movements->issue($uid, $itemId, $locId, 1, 'n4-seed');
			$posted++;
		} else {
			$to = $locIds[($i + 1) % count($locIds)];
			if ($to !== $locId) {
				$movements->transfer($uid, $itemId, $locId, $to, 1, 'n4-seed');
				$posted++;
			} else {
				$movements->receive($uid, $itemId, $locId, 1, 'n4-seed');
				$posted++;
			}
		}
	} catch (\OCA\InventoryCheck\Exception\InsufficientStockException) {
		$movements->receive($uid, $itemId, $locId, 5, 'n4-seed-topup');
		$posted++;
	}
}

fwrite(STDOUT, "Done. locations=$nLoc items=$nItems movement_ops≈$posted suffix=$suffix\n");
exit(0);
