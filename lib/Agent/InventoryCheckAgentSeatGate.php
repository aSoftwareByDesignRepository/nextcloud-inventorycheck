<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Agent;

use OCA\InventoryCheck\Exception\AppAccessDeniedException;
use OCA\InventoryCheck\Exception\MobileGateException;
use OCA\InventoryCheck\Service\MobileGateService;

/**
 * Per-request entitlement for agent requests (BQ-3).
 *
 * InventoryCheck's companion API is seat-licensed: the exact same
 * MobileGateService::assertGate ladder that guards /mobile/v1/* applies to
 * agent callers (app door → valid IV2 license → assigned seat within
 * limit). An agent token never widens entitlement — a user who loses the
 * seat or the license loses agent access on the next call, without
 * waiting for token expiry. Every gate failure collapses to one uniform
 * 403 — no oracle on which rung failed (license_missing vs seat_required
 * stay indistinguishable for the token holder).
 */
final class InventoryCheckAgentSeatGate implements AgentSeatGate
{
	public function __construct(
		private readonly MobileGateService $gate,
	) {
	}

	public function assertEntitled(string $uid): void
	{
		try {
			// User callers (never a paired ScanDevice) run the seat ladder.
			$this->gate->assertGate($uid, null);
		} catch (MobileGateException|AppAccessDeniedException) {
			throw new AgentException(
				'agent_seat_revoked',
				'This agent token has no active entitlement.',
				403,
			);
		}
	}
}
