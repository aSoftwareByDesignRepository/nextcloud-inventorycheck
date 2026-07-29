#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: StockIssueFacade (CHECK-SUITE FC-IV-ISSUE / §11.4 MSI).
 *
 * Kills regressions in default-off flange gate, location policy, qty floor,
 * and all-or-nothing insufficient-stock soft-fail mapping.
 */

require __DIR__ . '/harness.php';

$facade = 'lib/Public/StockIssueFacade.php';

runMutations(dirname(__DIR__, 2), 'StockIssueFacadeTest', [
	[
		'name' => 'maint-flange-gate-dropped',
		'file' => $facade,
		'search' => "if (\$req->refType === StockIssueRequest::REF_MAINT_WO\n\t\t\t&& \$this->config->getAppValue(Application::APP_ID, FlangeService::KEY_MAINT_ENABLED, '0') !== '1') {\n\t\t\treturn FacadeResult::failure('flange_disabled', 'MaintenanceCheck flange is disabled in InventoryCheck.');\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn FacadeResult::failure('flange_disabled', 'MaintenanceCheck flange is disabled in InventoryCheck.');\n\t\t}",
	],
	[
		'name' => 'actor-uid-required-dropped',
		'file' => $facade,
		'search' => "if (trim(\$req->actorUid) === '') {\n\t\t\treturn FacadeResult::failure('validation_failed', 'actorUid is required.');\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn FacadeResult::failure('validation_failed', 'actorUid is required.');\n\t\t}",
	],
	[
		'name' => 'location-unresolved-softened-to-success',
		'file' => $facade,
		'search' => "if (\$locationId === null) {\n\t\t\treturn FacadeResult::failure('location_unresolved', 'Could not resolve issue location.', [\n\t\t\t\t'policy' => \$req->locationPolicy,\n\t\t\t]);\n\t\t}",
		'replace' => "if (\$locationId === null) {\n\t\t\treturn FacadeResult::success(['movements' => []]);\n\t\t}",
	],
	[
		'name' => 'qty-floor-lowered-to-zero',
		'file' => $facade,
		'search' => 'if ($storageQty < QtyScale::serialUnit($this->config)) {',
		'replace' => 'if ($storageQty < 0) {',
	],
	[
		'name' => 'insufficient-stock-mapped-to-ok',
		'file' => $facade,
		'search' => "} catch (InsufficientStockException \$e) {\n\t\t\tif (\$this->db->inTransaction()) {\n\t\t\t\t\$this->db->rollBack();\n\t\t\t}\n\t\t\treturn FacadeResult::failure('insufficient_stock', \$e->getMessage(), [\n\t\t\t\t'sku' => \$failedSku,\n\t\t\t]);",
		'replace' => "} catch (InsufficientStockException \$e) {\n\t\t\tif (\$this->db->inTransaction()) {\n\t\t\t\t\$this->db->rollBack();\n\t\t\t}\n\t\t\treturn FacadeResult::success(['movements' => [], 'sku' => \$failedSku]);",
	],
	[
		'name' => 'flange-notifies-inside-outer-tx',
		'file' => $facade,
		'search' => "false, // defer notify until outer TX commits (no phantom alerts)",
		'replace' => 'true, // MUTANT: notify before outer commit',
	],
	[
		'name' => 'insufficient-stock-blames-first-sku',
		'file' => $facade,
		'search' => "'sku' => \$failedSku,",
		'replace' => "'sku' => \$needed[0]['sku'] ?? '',",
	],
]);
