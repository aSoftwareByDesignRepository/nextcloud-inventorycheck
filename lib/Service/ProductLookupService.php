<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Service\Lookup\Gtin;
use OCA\InventoryCheck\Service\Lookup\LookupException;
use OCA\InventoryCheck\Service\Lookup\ProductLookupProvider;
use OCA\InventoryCheck\Service\Lookup\ProductSuggestion;
use OCA\InventoryCheck\Service\Lookup\ProviderResult;
use OCA\InventoryCheck\Service\Lookup\UpcItemDbProvider;
use OCP\DB\Exception as DBException;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Barcode → product suggestion via keyless product data APIs (OFF family +
 * UPCitemdb free tier). Opt-in: the admin toggle is the consent boundary —
 * when off, zero egress and zero lookups.
 *
 * Guards (spec §9): fixed provider endpoints only, validated GTIN, no
 * redirects, distributed result cache, per-user rate limit, UPCitemdb daily
 * budget, sanitised plain-text output.
 */
class ProductLookupService
{
	public const KEY_ENABLED = 'product_lookup_enabled';
	public const KEY_AUTOFIRE = 'product_lookup_autofire';
	public const KEY_PROVIDERS = 'product_lookup_providers';
	public const KEY_STATS = 'product_lookup_stats';

	private const CACHE_PREFIX = 'inventorycheck-product-lookup';
	private const RESULT_TTL_HIT = 86400;
	private const RESULT_TTL_MISS = 300;
	private const RATE_SECONDS = 5;
	/** Stop the cascade beyond this elapsed budget — the form must not hang. */
	private const MAX_ELAPSED_SECONDS = 15;
	/** Daily ceiling per instance below UPCitemdb's free 100/day (headroom). */
	private const UPCITEMDB_DAILY_BUDGET = 90;
	/** Short cooldown after a provider 429 — polite pause, not a retry hammer. */
	private const RATE_LIMIT_COOLDOWN_SECONDS = 300;

	/**
	 * @param list<ProductLookupProvider> $providers cascade order
	 */
	public function __construct(
		private readonly array $providers,
		private readonly ICacheFactory $cacheFactory,
		private readonly IConfig $config,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Committed read for consent-relevant config. IConfig::getAppValue reads
	 * through OC\AppConfig's distributed blob cache (cache_app_config); a
	 * reader whose SELECT straddles a concurrent save can repopulate that
	 * blob stale for its TTL, so an admin's disable could keep serving
	 * lookups for seconds. The gate must never evaluate stale config —
	 * one indexed row read per call, no blob involvement.
	 */
	private function committedAppValue(string $key, string $default): string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
		$value = $qb->executeQuery()->fetchOne();
		return $value === false ? $default : (string)$value;
	}

	/**
	 * Committed write for consent-relevant config. IConfig::setAppValue is
	 * not enough: AppConfig::setTypedValue skips the DB write when its
	 * (possibly stale, blob-backed) snapshot already holds the target value
	 * — so an admin's disable could be silently dropped while the row keeps
	 * the old value. UPDATE first; if no row changed the key is absent or
	 * already identical, so INSERT and settle a concurrent-insert race with
	 * a final UPDATE (last writer wins).
	 */
	private function setCommittedAppValue(string $key, string $value): void
	{
		$update = $this->db->getQueryBuilder();
		$update->update('appconfig')
			->set('configvalue', $update->createNamedParameter($value))
			->where($update->expr()->eq('appid', $update->createNamedParameter(Application::APP_ID)))
			->andWhere($update->expr()->eq('configkey', $update->createNamedParameter($key)));
		if ($update->executeStatement() > 0) {
			return;
		}
		try {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('appconfig')
				->setValue('appid', $insert->createNamedParameter(Application::APP_ID))
				->setValue('configkey', $insert->createNamedParameter($key))
				->setValue('configvalue', $insert->createNamedParameter($value));
			$insert->executeStatement();
		} catch (DBException $e) {
			if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$settle = $this->db->getQueryBuilder();
			$settle->update('appconfig')
				->set('configvalue', $settle->createNamedParameter($value))
				->where($settle->expr()->eq('appid', $settle->createNamedParameter(Application::APP_ID)))
				->andWhere($settle->expr()->eq('configkey', $settle->createNamedParameter($key)));
			$settle->executeStatement();
		}
	}

	/** Registered provider ids in cascade order (settings UI + validation). */
	public function providerIds(): array
	{
		return array_map(static fn (ProductLookupProvider $p) => $p->id(), $this->providers);
	}

	/** @return list<array{id:string,label:string}> every provider with its UI label. */
	public function availableProviders(): array
	{
		return array_map(
			static fn (ProductLookupProvider $p) => ['id' => $p->id(), 'label' => $p->label()],
			$this->providers,
		);
	}

	public function isEnabled(): bool
	{
		return $this->committedAppValue(self::KEY_ENABLED, '0') === '1';
	}

	public function isAutofire(): bool
	{
		return $this->committedAppValue(self::KEY_AUTOFIRE, '1') === '1';
	}

	public function setEnabled(bool $enabled): void
	{
		$this->setCommittedAppValue(self::KEY_ENABLED, $enabled ? '1' : '0');
	}

	public function setAutofire(bool $enabled): void
	{
		$this->setCommittedAppValue(self::KEY_AUTOFIRE, $enabled ? '1' : '0');
	}

	/**
	 * @return list<string>
	 */
	public function enabledProviderIds(): array
	{
		$raw = $this->committedAppValue(self::KEY_PROVIDERS, '');
		if ($raw === '') {
			return $this->providerIds();
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return $this->providerIds();
		}
		$known = $this->providerIds();
		$out = [];
		foreach ($decoded as $id) {
			if (is_string($id) && in_array($id, $known, true) && !in_array($id, $out, true)) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * @param list<string> $ids
	 */
	public function setEnabledProviders(array $ids): void
	{
		$known = $this->providerIds();
		$clean = [];
		foreach ($ids as $id) {
			if (is_string($id) && in_array($id, $known, true) && !in_array($id, $clean, true)) {
				$clean[] = $id;
			}
		}
		$this->setCommittedAppValue(
			self::KEY_PROVIDERS,
			json_encode(array_values($clean), JSON_UNESCAPED_UNICODE),
		);
	}

	/** Per-provider counters + cache counters for the settings stats panel. */
	public function stats(): array
	{
		$raw = $this->config->getAppValue(Application::APP_ID, self::KEY_STATS, '');
		$decoded = $raw !== '' ? json_decode($raw, true) : null;
		$stats = is_array($decoded) ? $decoded : [];
		foreach ($this->providerIds() as $id) {
			$row = $stats['providers'][$id] ?? null;
			$stats['providers'][$id] = [
				'hit' => (int)($row['hit'] ?? 0),
				'miss' => (int)($row['miss'] ?? 0),
				'error' => (int)($row['error'] ?? 0),
				'rate_limited' => (int)($row['rate_limited'] ?? 0),
			];
		}
		$cache = $stats['cache'] ?? null;
		$stats['cache'] = [
			'hit' => (int)($cache['hit'] ?? 0),
			'miss' => (int)($cache['miss'] ?? 0),
		];
		$stats['unavailable'] = (int)($stats['unavailable'] ?? 0);

		// Derived settings-page counters — recomputed every read, never stored.
		$stats['totals'] = [
			'lookups' => $stats['cache']['hit'] + $stats['cache']['miss'],
			'hits' => 0,
			'misses' => 0,
			'errors' => $stats['unavailable'],
			'rateLimited' => 0,
			'cacheHits' => $stats['cache']['hit'],
		];
		foreach ($stats['providers'] as $row) {
			$stats['totals']['hits'] += $row['hit'];
			$stats['totals']['misses'] += $row['miss'];
			$stats['totals']['errors'] += $row['error'];
			$stats['totals']['rateLimited'] += $row['rate_limited'];
		}
		$stats['dailyBudget'] = [
			'provider' => UpcItemDbProvider::ID,
			'used' => $this->upcBudgetUsed($this->cacheFactory->createDistributed(self::CACHE_PREFIX)),
			'limit' => self::UPCITEMDB_DAILY_BUDGET,
		];
		return $stats;
	}

	/**
	 * Look up a barcode and return the wire payload.
	 *
	 * @return array<string, mixed> {found:true,barcode,name,brand,description,sourceUrl,provider} | {found:false}
	 * @throws LookupException feature_disabled|invalid_barcode|rate_limited|unavailable
	 */
	public function lookup(string $barcode, string $actorUid): array
	{
		if (!$this->isEnabled()) {
			throw new LookupException('feature_disabled', 'Product lookup is disabled.', 403);
		}
		// Reject oversized input before trim copies it — GTIN-14 is the
		// longest legal form; anything beyond 32 chars cannot be valid.
		if (strlen($barcode) > 32) {
			throw new LookupException('invalid_barcode', 'Barcode is not a valid EAN/UPC/GTIN.', 422);
		}
		$barcode = trim($barcode);
		if (!Gtin::isValid($barcode)) {
			throw new LookupException('invalid_barcode', 'Barcode is not a valid EAN/UPC/GTIN.', 422);
		}
		$cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
		$cached = $cache->get('product:' . $barcode);
		if (is_array($cached) && array_key_exists('found', $cached)) {
			$this->bumpStat('cache', 'hit');
			$this->logger->info('inventorycheck product lookup cache-hit', [
				'app' => Application::APP_ID,
				'providers' => [],
				'cache' => 'hit',
				'durationMs' => 0,
				'actor' => hash('sha256', $actorUid),
			]);
			return $cached;
		}
		$this->bumpStat('cache', 'miss');

		$rateKey = 'rate:' . hash('sha256', $actorUid);
		if ($cache->get($rateKey) !== null) {
			throw new LookupException('rate_limited', 'Please wait a few seconds before searching again.', 429);
		}
		// Soft check-then-set: two truly concurrent calls can both pass —
		// bounded overshoot on a politeness limit, documented and accepted.
		$cache->set($rateKey, true, self::RATE_SECONDS);

		$enabledIds = $this->enabledProviderIds();
		$providers = array_values(array_filter(
			$this->providers,
			static fn (ProductLookupProvider $p) => in_array($p->id(), $enabledIds, true),
		));
		if ($providers === []) {
			throw new LookupException('unavailable', 'No product lookup source is enabled.', 502);
		}

		$start = microtime(true);
		$outcomes = [];
		$sawCleanMiss = false;
		foreach ($providers as $provider) {
			$id = $provider->id();
			$blocked = $this->blockedReason($id, $cache);
			if ($blocked !== null) {
				$outcomes[] = ['id' => $id, 'outcome' => 'skipped-' . $blocked];
				continue;
			}
			if (microtime(true) - $start > self::MAX_ELAPSED_SECONDS) {
				$outcomes[] = ['id' => $id, 'outcome' => 'skipped-timeout'];
				continue;
			}
			$this->chargeUpstreamCall($id, $cache);
			try {
				$result = $provider->lookup($barcode);
			} catch (\Throwable) {
				// Contract breach by a provider adapter must not kill the cascade.
				$result = ProviderResult::error();
			}
			$outcomes[] = ['id' => $id, 'outcome' => $result->outcome];
			$this->bumpStat('providers', $result->outcome, $id);
			if ($result->outcome === ProviderResult::RATE_LIMITED) {
				$this->markRateLimited($id, $cache);
				continue;
			}
			if ($result->outcome === ProviderResult::MISS) {
				$sawCleanMiss = true;
				continue;
			}
			if ($result->outcome === ProviderResult::HIT && $result->suggestion !== null) {
				$payload = $this->payload($result->suggestion, $id, $barcode);
				if ($payload['name'] === '') {
					// Sanitization emptied the name — treat as a clean miss,
					// not a hit with a blank title.
					$sawCleanMiss = true;
					continue;
				}
				$cache->set('product:' . $barcode, $payload, self::RESULT_TTL_HIT);
				$this->logLookup('hit', $outcomes, 'miss', $start, $actorUid);
				return $payload;
			}
		}

		if ($sawCleanMiss) {
			$payload = ['found' => false];
			$cache->set('product:' . $barcode, $payload, self::RESULT_TTL_MISS);
			$this->logLookup('miss', $outcomes, 'miss', $start, $actorUid);
			return $payload;
		}

		$this->bumpStat('unavailable', 'unavailable');
		$this->logger->warning('inventorycheck product lookup unavailable — every provider failed or was skipped', [
			'app' => Application::APP_ID,
			'providers' => $outcomes,
			'cache' => 'miss',
			'durationMs' => (int)((microtime(true) - $start) * 1000),
			'actor' => hash('sha256', $actorUid),
		]);
		throw new LookupException('unavailable', 'Online product lookup is unavailable right now.', 502);
	}

	/**
	 * Structured per-lookup outcome log (US-004): provider sequence + outcome,
	 * cache state, duration, hashed actor — no PII, no secrets.
	 *
	 * @param list<array{id:string,outcome:string}> $outcomes
	 */
	private function logLookup(string $event, array $outcomes, string $cache, float $start, string $actorUid): void
	{
		$this->logger->info('inventorycheck product lookup ' . $event, [
			'app' => Application::APP_ID,
			'providers' => $outcomes,
			'cache' => $cache,
			'durationMs' => (int)((microtime(true) - $start) * 1000),
			'actor' => hash('sha256', $actorUid),
		]);
	}

	/**
	 * Why a provider must be skipped before any upstream call, or null.
	 * 'cooldown' — the provider just 429'd; 'budget' — UPCitemdb's shared
	 * daily ceiling is spent.
	 */
	private function blockedReason(string $providerId, \OCP\ICache $cache): ?string
	{
		if ($cache->get('cooldown:' . $providerId) !== null) {
			return 'cooldown';
		}
		if ($providerId === UpcItemDbProvider::ID
			&& ($cache->get('budget:' . $providerId . ':' . gmdate('Ymd')) !== null
				|| $this->upcBudgetUsed($cache) >= self::UPCITEMDB_DAILY_BUDGET)) {
			return 'budget';
		}
		return null;
	}

	/**
	 * Count the upstream call against UPCitemdb's shared daily budget BEFORE
	 * issuing it — the provider charges every request regardless of outcome.
	 * Soft get+set: concurrent callers can overshoot slightly; the 10-request
	 * headroom to the real 100/day limit absorbs it (documented).
	 */
	private function chargeUpstreamCall(string $providerId, \OCP\ICache $cache): void
	{
		if ($providerId !== UpcItemDbProvider::ID) {
			return;
		}
		$key = 'budget-count:' . UpcItemDbProvider::ID . ':' . gmdate('Ymd');
		$used = (int)($cache->get($key) ?? 0);
		$cache->set($key, $used + 1, 86400);
	}

	private function upcBudgetUsed(\OCP\ICache $cache): int
	{
		return (int)($cache->get('budget-count:' . UpcItemDbProvider::ID . ':' . gmdate('Ymd')) ?? 0);
	}

	/** A provider 429: short cooldown for everyone, daily flag for UPCitemdb. */
	private function markRateLimited(string $providerId, \OCP\ICache $cache): void
	{
		$cache->set('cooldown:' . $providerId, true, self::RATE_LIMIT_COOLDOWN_SECONDS);
		if ($providerId === UpcItemDbProvider::ID) {
			$cache->set('budget:' . UpcItemDbProvider::ID . ':' . gmdate('Ymd'), true, 86400);
		}
	}

	/**
	 * Sanitize upstream text into the wire payload. Suggestions are untrusted
	 * third-party content: collapse whitespace, cap to field limits, and keep
	 * only http(s) source links — the URL is displayed, never fetched (NN-01).
	 */
	private function payload(ProductSuggestion $suggestion, string $providerId, string $barcode): array
	{
		return [
			'found' => true,
			'barcode' => $barcode,
			'name' => $this->plain($suggestion->name, 255),
			'brand' => $suggestion->brand !== null ? $this->plain($suggestion->brand, 255) : null,
			'description' => $suggestion->description !== null
				? $this->plain($suggestion->description, 10000)
				: null,
			'sourceUrl' => $this->safeUrl($suggestion->sourceUrl),
			'provider' => $providerId,
		];
	}

	private function plain(string $text, int $limit): string
	{
		return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, $limit);
	}

	private function safeUrl(?string $url): ?string
	{
		if ($url === null) {
			return null;
		}
		$parts = parse_url($url);
		if (!is_array($parts)
			|| !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
			|| ($parts['host'] ?? '') === ''
			|| isset($parts['user']) || isset($parts['pass'])
			|| strlen($url) > 4096
			|| preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
			return null;
		}
		return $url;
	}

	/**
	 * @param 'cache'|'providers'|'unavailable' $group
	 */
	private function bumpStat(string $group, string $key, ?string $providerId = null): void
	{
		$stats = $this->stats();
		if ($group === 'cache') {
			$stats['cache'][$key] = ($stats['cache'][$key] ?? 0) + 1;
		} elseif ($group === 'providers' && $providerId !== null) {
			$stats['providers'][$providerId][$key] = ($stats['providers'][$providerId][$key] ?? 0) + 1;
		} elseif ($group === 'unavailable') {
			$stats['unavailable'] = ($stats['unavailable'] ?? 0) + 1;
		}
		// Derived keys are recomputed on every stats() read — never persist them.
		unset($stats['totals'], $stats['dailyBudget']);
		$this->config->setAppValue(
			Application::APP_ID,
			self::KEY_STATS,
			json_encode($stats, JSON_UNESCAPED_UNICODE),
		);
	}
}
