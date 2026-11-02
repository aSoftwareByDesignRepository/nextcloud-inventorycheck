#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
// Money-path gauntlet (spec §10: MSI ≥90% on RestockEstimate, pack ceiling,
// budget classifier — the paths where wrong math ships wrong money).
$est = 'lib/Service/RestockEstimate.php';
$cls = 'lib/Service/BudgetClassifier.php';
runMutations(dirname(__DIR__, 2), 'RestockMoneyMathTest|BudgetClassifier|SuggestedOrder|OrderSheetBudgetGate', [
	['name' => 'pack-ceil-dropped', 'file' => $est, 'search' => 'return intdiv($suggestedQty + $packQty - 1, $packQty);', 'replace' => 'return intdiv($suggestedQty, $packQty);'],
	['name' => 'pack-ceil-overshoot', 'file' => $est, 'search' => 'return intdiv($suggestedQty + $packQty - 1, $packQty);', 'replace' => 'return intdiv($suggestedQty + $packQty, $packQty);'],
	['name' => 'pack-qty-positive', 'file' => $est, 'search' => 'if ($packQty === null || $packQty <= 0) {', 'replace' => 'if ($packQty === null || $packQty < 0) {'],
	// suggested-zero-pack dropped: intdiv truncates toward zero, so <=0 vs <0
	// is behaviour-identical (equivalent mutant, not a coverage gap).
	['name' => 'pack-price-required', 'file' => $est, 'search' => 'if ($packQty !== null && $packQty > 0 && $packPriceMinor !== null) {', 'replace' => 'if ($packQty !== null && $packQty > 0) {'],
	['name' => 'halfup-round-down', 'file' => $est, 'search' => 'return intdiv($suggestedQty * $unitPriceMinor + intdiv($factor, 2), $factor);', 'replace' => 'return intdiv($suggestedQty * $unitPriceMinor, $factor);'],
	['name' => 'halfup-round-up', 'file' => $est, 'search' => 'return intdiv($suggestedQty * $unitPriceMinor + intdiv($factor, 2), $factor);', 'replace' => 'return intdiv($suggestedQty * $unitPriceMinor + $factor, $factor);'],
	['name' => 'factor-unguarded', 'file' => $est, 'search' => '$factor = $qtyFactor > 0 ? $qtyFactor : 1;', 'replace' => '$factor = $qtyFactor;'],
	['name' => 'within-boundary-open', 'file' => $cls, 'search' => 'if ($estMinor <= $remaining) {
			return self::STATE_WITHIN;
		}', 'replace' => 'if ($estMinor < $remaining) {
			return self::STATE_WITHIN;
		}'],
	['name' => 'no-budget-boundary', 'file' => $cls, 'search' => 'if ($budgetMinor === null || $budgetMinor <= 0) {
			return self::STATE_NO_BUDGET;
		}
		$remaining', 'replace' => 'if ($budgetMinor === null || $budgetMinor < 0) {
			return self::STATE_NO_BUDGET;
		}
		$remaining'],
	['name' => 'emergency-ignored', 'file' => $cls, 'search' => 'return $estMinor <= $remaining + max(0, $emergencyMinor)', 'replace' => 'return $estMinor <= $remaining'],
	['name' => 'spent-floor-dropped', 'file' => $cls, 'search' => '$remaining = max(0, $budgetMinor - max(0, $spentMtdMinor));', 'replace' => '$remaining = max(0, $budgetMinor - $spentMtdMinor);'],
	['name' => 'delta-sign-flip', 'file' => $cls, 'search' => 'return $budgetMinor - $estMinor;', 'replace' => 'return $estMinor - $budgetMinor;'],
	['name' => 'worst-over-skipped', 'file' => $cls, 'search' => 'if ($s === self::STATE_OVER) {
				return self::STATE_OVER;
			}', 'replace' => 'if (false) {
				return self::STATE_OVER;
			}'],
	['name' => 'worst-emergency-skipped', 'file' => $cls, 'search' => 'if ($s === self::STATE_OVER_EMERGENCY) {
				$worst = self::STATE_OVER_EMERGENCY;
			}', 'replace' => 'if (false) {
				$worst = self::STATE_OVER_EMERGENCY;
			}'],
]);
