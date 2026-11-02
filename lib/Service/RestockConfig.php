<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCP\IConfig;

/**
 * Restock suite config surface (SPEC §9): per-category budgets, admin-managed
 * category list, stale/threshold knobs and the F-16 approval threshold.
 * App-admin writes only; callers audit via PolicyAuditTrait.
 */
final class RestockConfig
{
	public const KEY_BUDGETS = 'restock_budgets';
	public const KEY_CATEGORY_LIST = 'category_list';
	public const KEY_ON_ORDER_STALE_DAYS = 'on_order_stale_days';
	public const KEY_EXPIRY_WARN_DAYS = 'expiry_warn_days';
	public const KEY_VELOCITY_WINDOW_DAYS = 'velocity_window_days';
	public const KEY_SUGGESTION_MODE = 'restock_suggestion_mode';
	public const KEY_APPROVAL_THRESHOLD = 'order_approval_threshold_minor';
	/** Owner-managed emergency allowance above the monthly budget (minor units). */
	public const KEY_EMERGENCY_BUDGET = 'restock_emergency_budget_minor';
	public const KEY_DIGEST_ENABLED = 'restock_digest_enabled';
	public const KEY_WEBHOOK_URL = 'restock_webhook_url';
	public const KEY_WEBHOOK_SECRET = 'restock_webhook_secret';
	public const KEY_WEBHOOK_DISABLED = 'restock_webhook_disabled';

	public const MAX_MONEY_MINOR = 100_000_000;
	public const MAX_SPEND_MINOR = 100_000_000_000;
	public const MAX_CATEGORY_LEN = 100;

	public const UNCATEGORIZED = 'Uncategorized';

	/** Stored suggestion-mode vocabulary (F-10): deficit-filling default or velocity-window coverage. */
	public const SUGGESTION_MODES = ['static', 'coverage'];

	public function __construct(
		private readonly IConfig $config,
	) {
	}

	/**
	 * @return array<string, int> category → minorUnits
	 */
	public function budgets(): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_BUDGETS, '{}');
		$decoded = json_decode(is_string($raw) ? $raw : '{}', true);
		$out = [];
		if (is_array($decoded)) {
			foreach ($decoded as $cat => $v) {
				if (is_string($cat) && is_int($v) && $v >= 0) {
					$out[$cat] = $v;
				}
			}
		}
		return $out;
	}

	/**
	 * @param array<string, int> $map validated category → minorUnits
	 */
	public function setBudgets(array $map): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_BUDGETS,
			json_encode($map, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		);
	}

	/**
	 * @return list<string>
	 */
	public function categoryList(): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_CATEGORY_LIST, '[]');
		$decoded = json_decode(is_string($raw) ? $raw : '{}', true);
		$out = [];
		if (is_array($decoded)) {
			foreach ($decoded as $c) {
				$c = trim((string)$c);
				if ($c !== '') {
					$out[$c] = true;
				}
			}
		}
		return array_keys($out);
	}

	/** @param list<string> $cats */
	public function setCategoryList(array $cats): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_CATEGORY_LIST,
			json_encode(array_values($cats), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
		);
	}

	public function categoryKnown(string $category): bool
	{
		return in_array($category, $this->categoryList(), true);
	}

	/**
	 * Validate a budget map from request input. Values 0..MAX_MONEY_MINOR;
	 * empty/absent entry removes the cap for that category.
	 *
	 * @return array<string, int>
	 */
	public function parseBudgets(mixed $raw, array $allowedCategories): array
	{
		if ($raw === null || $raw === '') {
			return [];
		}
		if (!is_array($raw)) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'restockBudgets', 'code' => 'invalid_type'],
			]);
		}
		$allowed = $allowedCategories === [] ? null : array_fill_keys($allowedCategories, true);
		$out = [];
		foreach ($raw as $cat => $v) {
			$cat = trim((string)$cat);
			if ($cat === '' || mb_strlen($cat) > self::MAX_CATEGORY_LEN) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'restockBudgets', 'code' => 'validation_failed'],
				]);
			}
			if ($allowed !== null && !isset($allowed[$cat]) && $cat !== self::UNCATEGORIZED) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'restockBudgets', 'code' => 'unknown_category'],
				]);
			}
			if ($v === null || $v === '') {
				continue;
			}
			if (!is_int($v) && !(is_string($v) && preg_match('/^\d+$/', $v) === 1)) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'restockBudgets', 'code' => 'validation_failed'],
				]);
			}
			$v = (int)$v;
			if ($v < 0 || $v > self::MAX_MONEY_MINOR) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'restockBudgets', 'code' => 'validation_failed'],
				]);
			}
			$out[$cat] = $v;
		}
		return $out;
	}

	/** @return list<string> */
	public function parseCategoryList(mixed $raw): array
	{
		if ($raw === null) {
			return [];
		}
		if (!is_array($raw)) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'categoryList', 'code' => 'invalid_type'],
			]);
		}
		$out = [];
		foreach ($raw as $c) {
			if (!is_string($c) && !is_int($c)) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'categoryList', 'code' => 'invalid_type'],
				]);
			}
			$c = trim((string)$c);
			if ($c === '') {
				continue;
			}
			if (mb_strlen($c) > self::MAX_CATEGORY_LEN) {
				throw new ValidationException('validation_failed', '', [
					['field' => 'categoryList', 'code' => 'validation_failed'],
				]);
			}
			$out[$c] = true;
		}
		return array_keys($out);
	}

	public function daysOf(string $key, int $default): int
	{
		$raw = $this->config->getAppValue(Application::APP_ID, $key, (string)$default);
		$v = (int)$raw;
		return $v >= 1 && $v <= 3650 ? $v : $default;
	}

	public function onOrderStaleDays(): int
	{
		return $this->daysOf(self::KEY_ON_ORDER_STALE_DAYS, 30);
	}

	public function expiryWarnDays(): int
	{
		return $this->daysOf(self::KEY_EXPIRY_WARN_DAYS, 30);
	}

	public function velocityWindowDays(): int
	{
		return $this->daysOf(self::KEY_VELOCITY_WINDOW_DAYS, 28);
	}

	public function parseDays(mixed $raw, string $field): int
	{
		if (!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
			throw new ValidationException('validation_failed', '', [
				['field' => $field, 'code' => 'validation_failed'],
			]);
		}
		$v = (int)$raw;
		if ($v < 1 || $v > 3650) {
			throw new ValidationException('validation_failed', '', [
				['field' => $field, 'code' => 'validation_failed'],
			]);
		}
		return $v;
	}

	public function setDays(string $key, int $days): void
	{
		$this->config->setAppValue(Application::APP_ID, $key, (string)$days);
	}

	/** static|coverage — F-10 suggested-qty basis toggle. */
	public function suggestionMode(): string
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_SUGGESTION_MODE, 'static') === 'coverage'
			? 'coverage'
			: 'static';
	}

	public function setSuggestionMode(string $mode): void
	{
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_SUGGESTION_MODE,
			in_array($mode, self::SUGGESTION_MODES, true) ? $mode : 'static',
		);
	}

	/** F-16: null = no approval threshold. */
	/** Emergency allowance the owner may authorize beyond the monthly budget. */
	public function emergencyBudgetMinor(): ?int
	{
		$raw = trim((string)$this->config->getAppValue(Application::APP_ID, self::KEY_EMERGENCY_BUDGET, ''));
		if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
			return null;
		}
		return (int)$raw;
	}

	public function setEmergencyBudgetMinor(?int $v): void
	{
		if ($v === null) {
			$this->config->deleteAppValue(Application::APP_ID, self::KEY_EMERGENCY_BUDGET);
			return;
		}
		if ($v < 0 || $v > self::MAX_SPEND_MINOR) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'emergencyBudgetMinor', 'code' => 'validation_failed'],
			]);
		}
		$this->config->setAppValue(Application::APP_ID, self::KEY_EMERGENCY_BUDGET, (string)$v);
	}

	public function approvalThresholdMinor(): ?int
	{
		$raw = trim((string)$this->config->getAppValue(Application::APP_ID, self::KEY_APPROVAL_THRESHOLD, ''));
		if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
			return null;
		}
		return (int)$raw;
	}

	public function setApprovalThresholdMinor(?int $v): void
	{
		if ($v === null) {
			$this->config->deleteAppValue(Application::APP_ID, self::KEY_APPROVAL_THRESHOLD);
			return;
		}
		if ($v < 0 || $v > self::MAX_SPEND_MINOR) {
			throw new ValidationException('validation_failed', '', [
				['field' => 'approvalThresholdMinor', 'code' => 'validation_failed'],
			]);
		}
		$this->config->setAppValue(Application::APP_ID, self::KEY_APPROVAL_THRESHOLD, (string)$v);
	}

	public function digestEnabled(): bool
	{
		return $this->config->getAppValue(Application::APP_ID, self::KEY_DIGEST_ENABLED, '1') === '1';
	}

	public function setDigestEnabled(bool $enabled): void
	{
		$this->config->setAppValue(Application::APP_ID, self::KEY_DIGEST_ENABLED, $enabled ? '1' : '0');
	}
}
