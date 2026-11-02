<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

/**
 * Restock F-02: per-category restock budget check.
 * Informational only — 'over' never blocks anything (NN-01).
 */
final class BudgetClassifier
{
	public const STATE_NO_BUDGET = 'no_budget';
	public const STATE_WITHIN = 'within';
	public const STATE_OVER = 'over';
	/** Over the monthly budget but inside the owner emergency ceiling. */
	public const STATE_OVER_EMERGENCY = 'over_emergency';

	/**
	 * A missing or non-positive budget entry means "uncapped" (A4: budget=0
	 * is no_budget — nobody is taught to type 0 for "no cap").
	 */
	public static function state(?int $budgetMinor, int $estMinor): string
	{
		return self::stateExtended($budgetMinor, $estMinor);
	}

	/**
	 * Cycle-aware check: the month's received spend has already consumed part
	 * of the budget, so the order is judged against what is left; an order
	 * that only fits inside the owner emergency allowance lands in
	 * over_emergency.
	 */
	public static function stateExtended(?int $budgetMinor, int $estMinor, int $spentMtdMinor = 0, int $emergencyMinor = 0): string
	{
		if ($budgetMinor === null || $budgetMinor <= 0) {
			return self::STATE_NO_BUDGET;
		}
		$remaining = max(0, $budgetMinor - max(0, $spentMtdMinor));
		if ($estMinor <= $remaining) {
			return self::STATE_WITHIN;
		}
		return $estMinor <= $remaining + max(0, $emergencyMinor)
			? self::STATE_OVER_EMERGENCY
			: self::STATE_OVER;
	}

	/** Available this cycle: budget − already-received spend (floor 0). */
	public static function remaining(?int $budgetMinor, int $spentMtdMinor): ?int
	{
		if ($budgetMinor === null || $budgetMinor <= 0) {
			return null;
		}
		return max(0, $budgetMinor - max(0, $spentMtdMinor));
	}

	/**
	 * budget − est; negative when over. Null when no budget is set.
	 */
	public static function delta(?int $budgetMinor, int $estMinor): ?int
	{
		if ($budgetMinor === null || $budgetMinor <= 0) {
			return null;
		}
		return $budgetMinor - $estMinor;
	}

	/**
	 * Worst state across per-category states:
	 * over > over_emergency > within > no_budget.
	 * no_budget only when *every* bucket is uncapped.
	 *
	 * @param list<string> $states
	 */
	public static function worst(array $states): string
	{
		$hasBudget = false;
		$worst = self::STATE_WITHIN;
		foreach ($states as $s) {
			if ($s !== self::STATE_NO_BUDGET) {
				$hasBudget = true;
			}
			if ($s === self::STATE_OVER) {
				return self::STATE_OVER;
			}
			if ($s === self::STATE_OVER_EMERGENCY) {
				$worst = self::STATE_OVER_EMERGENCY;
			}
		}
		if (!$hasBudget) {
			return self::STATE_NO_BUDGET;
		}
		return $worst;
	}
}
