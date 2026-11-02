<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\BudgetClassifier;
use OCA\InventoryCheck\Service\MoneyVisibility;
use OCA\InventoryCheck\Service\RestockEstimate;
use PHPUnit\Framework\TestCase;

/**
 * Mutation targets for the restock money paths (SPEC §7.1/§7.2/§15,
 * NN-04, A3–A8): every boundary condition must be asserted, not sampled.
 */
final class RestockMoneyMathTest extends TestCase
{
	public function testPackRoundingCeilsExactly(): void
	{
		self::assertSame(1, RestockEstimate::packs(3, 100));
		self::assertSame(2, RestockEstimate::packs(101, 100));
		self::assertSame(1, RestockEstimate::packs(100, 100));
		self::assertSame(0, RestockEstimate::packs(0, 100));
		self::assertNull(RestockEstimate::packs(50, null));
		self::assertNull(RestockEstimate::packs(50, 0));
	}

	public function testPackLineCostIsPacksTimesPackPrice(): void
	{
		// Spec fixture: suggested 3 with pack 100 @1299 → 1299; 101 → 2598.
		self::assertSame(1299, RestockEstimate::lineMinor(3, 100, 1299, null, 1));
		self::assertSame(2598, RestockEstimate::lineMinor(101, 100, 1299, null, 1));
		self::assertSame(0, RestockEstimate::lineMinor(0, 100, 1299, null, 1));
	}

	public function testFallbackUnitPriceRoundsHalfUp(): void
	{
		// scale=3: 2500 storage × 1299 / 1000 = 3247.5 → 3248.
		self::assertSame(3248, RestockEstimate::lineMinor(2500, null, null, 1299, 1000));
		// scale=0: 42 × 250 / 1.
		self::assertSame(10500, RestockEstimate::lineMinor(42, null, null, 250, 1));
		// exact .5 boundary rounds UP, not banker's.
		self::assertSame(3, RestockEstimate::lineMinor(5, null, null, 5, 10));
	}

	public function testNoPriceYieldsNullNotZero(): void
	{
		self::assertNull(RestockEstimate::lineMinor(42, null, null, null, 1));
		self::assertNull(RestockEstimate::lineMinor(42, 100, null, null, 1));
		// pack price null falls through to unit price.
		self::assertSame(10500, RestockEstimate::lineMinor(42, 100, null, 250, 1));
	}

	public function testBudgetStates(): void
	{
		self::assertSame('within', BudgetClassifier::state(5000, 5000));
		self::assertSame('within', BudgetClassifier::state(5000, 4999));
		self::assertSame('over', BudgetClassifier::state(5000, 5001));
		self::assertSame('no_budget', BudgetClassifier::state(null, 5001));
		self::assertSame('no_budget', BudgetClassifier::state(0, 5001));
		self::assertSame('no_budget', BudgetClassifier::state(-1, 0));
		self::assertSame(4000, BudgetClassifier::delta(5000, 1000));
		self::assertSame(-1, BudgetClassifier::delta(5000, 5001));
		self::assertNull(BudgetClassifier::delta(null, 5000));
		self::assertNull(BudgetClassifier::delta(0, 5000));
	}

	public function testCycleAwareStates(): void
	{
		// MTD spend consumes the budget — remaining = 5000 − 2000 = 3000.
		self::assertSame('within', BudgetClassifier::stateExtended(5000, 3000, 2000));
		self::assertSame('within', BudgetClassifier::stateExtended(5000, 3000, 2000, 0));
		// Over remaining but inside the owner emergency allowance.
		self::assertSame('over_emergency', BudgetClassifier::stateExtended(5000, 3001, 2000, 100));
		self::assertSame('over_emergency', BudgetClassifier::stateExtended(5000, 3100, 2000, 100));
		// Over remaining + emergency is plain over; no emergency set → over.
		self::assertSame('over', BudgetClassifier::stateExtended(5000, 3101, 2000, 100));
		self::assertSame('over', BudgetClassifier::stateExtended(5000, 3001, 2000, 0));
		// Spend past the budget itself → remaining floors at 0.
		self::assertSame('over', BudgetClassifier::stateExtended(5000, 1, 9000, 0));
		self::assertSame('over_emergency', BudgetClassifier::stateExtended(5000, 50, 9000, 50));
		// no_budget ignores MTD/emergency entirely.
		self::assertSame('no_budget', BudgetClassifier::stateExtended(0, 10, 999, 999));
		self::assertSame('no_budget', BudgetClassifier::stateExtended(null, 10, 999, 999));
	}

	public function testRemainingFloorsAtZero(): void
	{
		self::assertSame(3000, BudgetClassifier::remaining(5000, 2000));
		self::assertSame(0, BudgetClassifier::remaining(5000, 5000));
		self::assertSame(0, BudgetClassifier::remaining(5000, 9000));
		self::assertSame(5000, BudgetClassifier::remaining(5000, 0));
		self::assertNull(BudgetClassifier::remaining(0, 0));
		self::assertNull(BudgetClassifier::remaining(null, 100));
	}

	public function testWorstRanksOverEmergencyBetweenOverAndWithin(): void
	{
		self::assertSame('over_emergency', BudgetClassifier::worst(['within', 'over_emergency', 'no_budget']));
		self::assertSame('over', BudgetClassifier::worst(['over_emergency', 'over']));
		self::assertSame('within', BudgetClassifier::worst(['within', 'no_budget']));
		self::assertSame('no_budget', BudgetClassifier::worst([]));
	}

	public function testWorstStateAggregation(): void
	{
		self::assertSame('no_budget', BudgetClassifier::worst(['no_budget', 'no_budget']));
		self::assertSame('within', BudgetClassifier::worst(['no_budget', 'within']));
		self::assertSame('over', BudgetClassifier::worst(['within', 'over', 'no_budget']));
		self::assertSame('no_budget', BudgetClassifier::worst([]));
	}

	public function testItemWebOfficeKeepsEverything(): void
	{
		$item = [
			'lastPriceMinor' => 100,
			'orderPackPriceMinor' => 200,
			'supplierName' => 'Acme',
			'supplierSku' => 'A-1',
			'supplierNote' => 'n',
			'onOrderBy' => 'alice',
			'onOrderAt' => 123,
			'onOrderQty' => 50,
		];
		self::assertSame($item, MoneyVisibility::itemForWeb($item, true));
	}

	public function testItemWebFieldStripsMoneySupplierAndActor(): void
	{
		$item = [
			'id' => 7,
			'lastPriceMinor' => 100,
			'orderPackPriceMinor' => 200,
			'supplierName' => 'Acme',
			'supplierSku' => 'A-1',
			'supplierNote' => 'n',
			'onOrderBy' => 'alice',
			'onOrderAt' => 123,
			'onOrderQty' => 50,
			'orderPackQty' => 100,
		];
		$out = MoneyVisibility::itemForWeb($item, false);
		self::assertArrayNotHasKey('lastPriceMinor', $out);
		self::assertArrayNotHasKey('orderPackPriceMinor', $out);
		self::assertArrayNotHasKey('supplierName', $out);
		self::assertArrayNotHasKey('supplierSku', $out);
		self::assertArrayNotHasKey('supplierNote', $out);
		self::assertArrayNotHasKey('onOrderBy', $out);
		self::assertArrayNotHasKey('onOrderAt', $out);
		// Neutral flag + pack qty are quantity fields — they stay.
		self::assertSame(50, $out['onOrderQty']);
		self::assertSame(100, $out['orderPackQty']);
	}

	public function testItemMobileNeverCarriesMoney(): void
	{
		$item = [
			'lastPriceMinor' => 100,
			'orderPackPriceMinor' => 200,
			'supplierName' => 'Acme',
			'supplierSku' => 'A-1',
			'supplierNote' => 'n',
			'onOrderBy' => 'alice',
			'onOrderAt' => 123,
			'onOrderQty' => 50,
			'orderPackQty' => 100,
		];
		// Office SEAT still gets zero money (Q8) but keeps supplier fields.
		$seat = MoneyVisibility::itemForMobile($item, false, true);
		self::assertArrayNotHasKey('lastPriceMinor', $seat);
		self::assertArrayNotHasKey('orderPackPriceMinor', $seat);
		self::assertArrayNotHasKey('onOrderBy', $seat);
		self::assertArrayNotHasKey('onOrderAt', $seat);
		self::assertSame('Acme', $seat['supplierName']);
		// FIELD seat loses supplier fields too.
		$field = MoneyVisibility::itemForMobile($item, false, false);
		self::assertArrayNotHasKey('supplierName', $field);
		self::assertArrayNotHasKey('supplierSku', $field);
		// DEVICE never gets supplier fields regardless.
		$device = MoneyVisibility::itemForMobile($item, true, true);
		self::assertArrayNotHasKey('supplierName', $device);
		self::assertArrayNotHasKey('supplierSku', $device);
		self::assertArrayNotHasKey('supplierNote', $device);
		// Device-safe quantities remain for F-20 scan-to-receive.
		self::assertSame(50, $device['onOrderQty']);
		self::assertSame(100, $device['orderPackQty']);
	}

	public function testMovementSpendStripped(): void
	{
		$m = ['id' => 1, 'kind' => 'receive', 'spendMinor' => 9900];
		self::assertSame($m, MoneyVisibility::movementForWeb($m, true));
		self::assertArrayNotHasKey('spendMinor', MoneyVisibility::movementForWeb($m, false));
		self::assertArrayNotHasKey('spendMinor', MoneyVisibility::movementForMobile($m));
	}

	public function testZeroQtyFactorFallsBackToOne(): void
	{
		// qtyFactor <= 0 is invalid input; the estimator must clamp to 1
		// rather than divide by zero (mutation target: unguarded $factor).
		self::assertSame(500, RestockEstimate::lineMinor(2, null, null, 250, 0));
		self::assertSame(500, RestockEstimate::lineMinor(2, null, null, 250, -7));
	}

	public function testNegativeSpentClampsToZero(): void
	{
		// A negative MTD spend (corrupt/migrated row) must not inflate the
		// remaining budget beyond the cap itself.
		self::assertSame(5000, BudgetClassifier::remaining(5000, -100));
		self::assertSame('within', BudgetClassifier::stateExtended(5000, 5000, -100));
		self::assertSame('over', BudgetClassifier::stateExtended(5000, 5001, -100));
	}
}
