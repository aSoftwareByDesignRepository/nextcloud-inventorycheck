#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: MovementService office gates + BalanceMapper lock order.
 */

require __DIR__ . '/harness.php';

$movement = 'lib/Service/MovementService.php';
$balances = 'lib/Db/BalanceMapper.php';
$rowLocking = 'lib/Db/RowLocking.php';
$itemService = 'lib/Service/ItemService.php';

runMutations(dirname(__DIR__, 2), 'MovementLockingProtocolTest|SpecEdgeCasesIntegrationTest|LifecycleAndRaceIntegrationTest|WaveCFeaturesIntegrationTest', [
	[
		'name' => 'receive-office-gate-dropped',
		'file' => $movement,
		'search' => "\$this->access->requireOffice(\$actorUid);\n\t\t\$this->assertLocationAccess(\$actorUid, \$locationId);\n\t\treturn \$this->postSingle(\$actorUid, 'receive', \$itemId, \$locationId, \$qty, \$reason, \$lotCode, \$notifyLowStock);",
		'replace' => "\$this->assertLocationAccess(\$actorUid, \$locationId);\n\t\treturn \$this->postSingle(\$actorUid, 'receive', \$itemId, \$locationId, \$qty, \$reason, \$lotCode, \$notifyLowStock);",
	],
	[
		'name' => 'lock-order-item-reversed',
		'file' => $balances,
		'search' => "\$a['itemId'] <=> \$b['itemId']",
		'replace' => "\$b['itemId'] <=> \$a['itemId']",
	],
	[
		'name' => 'for-update-removed',
		'file' => $balances,
		'search' => 'FOR UPDATE',
		'replace' => '/* no lock */',
	],
	[
		'name' => 'entity-shared-lock-downgraded-to-plain-read',
		'file' => $movement,
		'search' => '$item = $this->items->lockById($itemId, $exclusive);',
		'replace' => '$item = $this->items->findById($itemId);',
	],
	[
		'name' => 'inactive-item-recheck-dropped',
		'file' => $movement,
		'search' => "\t\tif (!\$item->getActive()) {\n\t\t\tthrow new ValidationException('inactive_item');\n\t\t}",
		'replace' => "\t\tif (false) {\n\t\t\tthrow new ValidationException('inactive_item');\n\t\t}",
	],
	[
		'name' => 'share-mode-suffix-dropped',
		'file' => $rowLocking,
		'search' => "IDBConnection::PLATFORM_MYSQL => ' LOCK IN SHARE MODE',",
		'replace' => "IDBConnection::PLATFORM_MYSQL => '',",
	],
	[
		'name' => 'deactivate-exclusive-lock-downgraded',
		'file' => $itemService,
		'search' => 'lockById($id, true)',
		'replace' => 'findById($id)',
	],
	[
		'name' => 'scan-transfer-to-location-required-dropped',
		'file' => $movement,
		'search' => "\$toLocationId === null || \$toLocationId <= 0\n\t\t\t\t? throw new ValidationException('validation_failed', '', [\n\t\t\t\t\t['field' => 'toLocationId', 'code' => 'validation_failed'],\n\t\t\t\t])\n\t\t\t\t: \$this->transfer(",
		'replace' => "\$this->transfer(",
	],
	[
		'name' => 'serial-capacity-ceiling-raised',
		'file' => $movement,
		'search' => 'if ($current + $incomingDelta > $unit) {',
		'replace' => 'if ($current + $incomingDelta > $unit * 100) {',
	],
]);
