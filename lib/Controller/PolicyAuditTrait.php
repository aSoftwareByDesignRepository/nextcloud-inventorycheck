<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;

/**
 * Policy mutations get an audit record: admin-reachable writes (license,
 * seats, devices, access/office/notify lists, location ACL, qty scale,
 * flange settings) log actor + what changed via the container LoggerInterface.
 *
 * Never log secrets here — no license keys, pair codes, tokens, or payloads.
 */
trait PolicyAuditTrait
{
	/**
	 * @param array<string, mixed> $context
	 */
	private function auditPolicyChange(string $actorUid, string $action, array $context = []): void
	{
		// warning = NC default loglevel — info() would silently drop the record.
		$this->logger->warning(
			'InventoryCheck policy change: ' . $action,
			['app' => Application::APP_ID, 'actor' => $actorUid] + $context,
		);
	}
}
