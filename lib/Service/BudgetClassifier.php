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

	/**
	 * A missing or non-positive budget entry means "uncapped" (A4: budget=0
	 * is no_budget — nobody is taught to type 0 for "no cap").
	 */
	public static function state(?int $budgetMinor, int $estMinor): string
	{
		if ($budgetMinor === null || $budgetMinor <= 0) {
			return self::STATE_NO_BUDGET;
		}
		return $estMinor <= $budgetMinor ? self::STATE_WITHIN : self::STATE_OVER;
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
	 * Worst state across per-category states: over > within > no_budget.
	 * no_budget only when *every* bucket is uncapped.
	 *
	 * @param list<string> $states
	 */
	public static function worst(array $states): string
	{
		$hasBudget = false;
		foreach ($states as $s) {
			if ($s !== self::STATE_NO_BUDGET) {
				$hasBudget = true;
			}
			if ($s === self::STATE_OVER) {
				return self::STATE_OVER;
			}
		}
		return $hasBudget ? self::STATE_WITHIN : self::STATE_NO_BUDGET;
	}
}
