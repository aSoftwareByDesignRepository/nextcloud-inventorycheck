<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\Exception\ValidationException;

/**
 * Adjust semantics (SPEC S3 / S4).
 *
 * mode=set  → delta := target − current (under lock); target ≥ 0 unless allowNegative
 * mode=delta → signed qtyDelta; same result-≥-0 rule when negatives off
 * delta of 0 (incl. set to current) → 422 invalid_qty (no no-op ledger rows)
 */
final class AdjustSemantics
{
	/**
	 * @return array{delta: int, qtyAfter: int}
	 */
	public static function compute(
		string $mode,
		int $currentQty,
		?int $qty,
		?int $qtyDelta,
		bool $allowNegative,
		int $maxMovementQty = MovementMath::QTY_MAX,
	): array {
		$mode = strtolower(trim($mode));
		if ($mode === 'set') {
			if ($qty === null) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'qty', 'code' => 'validation_failed'],
				]);
			}
			if (!$allowNegative && $qty < 0) {
				throw new ValidationException('invalid_qty', 'target_negative');
			}
			if (!MovementMath::isValidBalance($qty)) {
				throw new ValidationException('qty_out_of_range');
			}
			$delta = $qty - $currentQty;
			$qtyAfter = $qty;
		} elseif ($mode === 'delta') {
			if ($qtyDelta === null) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'qtyDelta', 'code' => 'validation_failed'],
				]);
			}
			// Absolute magnitude must obey S1 movement qty bounds when non-zero.
			$abs = abs($qtyDelta);
			if ($abs !== 0 && !MovementMath::isValidMovementQty($abs, $maxMovementQty)) {
				throw new ValidationException('invalid_qty');
			}
			$delta = $qtyDelta;
			$qtyAfter = MovementMath::applyDelta($currentQty, $delta);
			if (!MovementMath::isValidBalance($qtyAfter)) {
				throw new ValidationException('qty_out_of_range');
			}
			if (!$allowNegative && $qtyAfter < 0 && $qtyAfter < $currentQty) {
				// Caller maps to insufficient_stock; signal via dedicated code.
				throw new ValidationException('would_go_negative');
			}
		} else {
			throw new ValidationException('validation_failed', '', [
				['field' => 'mode', 'code' => 'validation_failed'],
			]);
		}

		if ($delta === 0) {
			throw new ValidationException('invalid_qty', 'noop');
		}

		return ['delta' => $delta, 'qtyAfter' => $qtyAfter];
	}
}