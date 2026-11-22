#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// IV-TOTAL-STOCK — the flip-prone lines of the feature: the `?? 0`
// fallback (AC-1) and the ACL-scope pass-through (NN-01).
runMutations(dirname(__DIR__, 2), 'ItemServiceTotalQtyTest', [
	[
		'name' => 'list-totalqty-fallback-becomes-1',
		'file' => 'lib/Service/ItemService.php',
		'search' => "\$api['totalQty'] = \$sums[(int)\$i->getId()] ?? 0;",
		'replace' => "\$api['totalQty'] = \$sums[(int)\$i->getId()] ?? 1;",
	],
	[
		'name' => 'list-sums-ignore-acl-scope',
		'file' => 'lib/Service/ItemService.php',
		'search' => '$sums = $this->balances->sumQtyByItem($visible);',
		'replace' => '$sums = $this->balances->sumQtyByItem(null);',
	],
	[
		'name' => 'get-sums-ignore-acl-scope',
		'file' => 'lib/Service/ItemService.php',
		'search' => "\$item['totalQty'] = \$this->balances->sumQtyForItem(\n\t\t\t\$id,\n\t\t\t\$this->locationAcl->visibleLocationIds(\$actorUid),\n\t\t);",
		'replace' => "\$item['totalQty'] = \$this->balances->sumQtyForItem(\$id, null);",
	],
	[
		'name' => 'bycode-sums-ignore-acl-scope',
		'file' => 'lib/Service/ItemService.php',
		'search' => "\$api['totalQty'] = \$this->balances->sumQtyForItem((int)\$item->getId(), \$visible);",
		'replace' => "\$api['totalQty'] = \$this->balances->sumQtyForItem((int)\$item->getId(), null);",
	],
]);
