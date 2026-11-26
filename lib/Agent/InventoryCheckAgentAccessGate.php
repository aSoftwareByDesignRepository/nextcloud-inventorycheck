<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Agent;

use OCA\InventoryCheck\Service\AccessControlService;

/**
 * App-door adapter: the agent surface honours the exact same access policy
 * as the web UI. canUseApp = the app access door; canAdminister = the
 * dedicated InventoryCheck app-admin role (isAppAdmin already covers NC
 * server admins) — matching who may reach the settings page that hosts the
 * "AI access (MCP)" section.
 */
final class InventoryCheckAgentAccessGate implements AgentAccessGate
{
	public function __construct(
		private readonly AccessControlService $access,
	) {
	}

	public function canUseApp(string $uid): bool
	{
		return $this->access->canUseApp($uid);
	}

	public function canAdminister(string $uid): bool
	{
		return $this->access->isAppAdmin($uid);
	}
}
