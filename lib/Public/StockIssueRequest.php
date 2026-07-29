<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Public;

/**
 * Request for {@see StockIssueFacade::issueBySkuBundle} (FC-IV-ISSUE / §4.4.1).
 *
 * @psalm-immutable
 */
final class StockIssueRequest
{
	public const POLICY_EXPLICIT = 'explicit_location_id';
	public const POLICY_EQUIPMENT_DEFAULT = 'equipment_default_location';
	public const POLICY_FAIL_AMBIGUOUS = 'fail_if_ambiguous';

	public const REF_MAINT_WO = 'maint_wo';
	public const REF_PROJECT = 'project';

	/**
	 * @param list<array{sku: string, qty: int}> $lines
	 */
	public function __construct(
		public readonly string $actorUid,
		public readonly array $lines,
		public readonly string $locationPolicy,
		public readonly string $refType,
		public readonly int $refId,
		public readonly ?int $locationId = null,
	) {
	}
}
