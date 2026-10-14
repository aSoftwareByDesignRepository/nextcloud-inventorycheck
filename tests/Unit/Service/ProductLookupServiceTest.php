<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Service;

use OCA\InventoryCheck\Service\Lookup\LookupException;
use OCA\InventoryCheck\Service\Lookup\ProductLookupProvider;
use OCA\InventoryCheck\Service\Lookup\ProductSuggestion;
use OCA\InventoryCheck\Service\Lookup\ProviderResult;
use OCA\InventoryCheck\Service\Lookup\UpcItemDbProvider;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\DB\IResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProductLookupServiceTest extends TestCase
{
	private const GTIN = '4006381333931';

	private ArrayCache $cache;
	/** @var array<string, string> what IConfig would serve (may be stale) */
	private array $appValues;
	/** @var array<string, string> committed oc_appconfig rows */
	private array $dbValues;

	protected function setUp(): void
	{
		parent::setUp();
		$this->cache = new ArrayCache();
		$this->appValues = [ProductLookupService::KEY_ENABLED => '1'];
		$this->dbValues = [ProductLookupService::KEY_ENABLED => '1'];
	}

	/** @param list<ProductLookupProvider> $providers */
	private function service(array $providers, ?ICache $cache = null): ProductLookupService
	{
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache ?? $this->cache);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, ?string $default = null): string => $this->appValues[$key] ?? ($default ?? ''),
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->appValues[$key] = $value;
			},
		);
		return new ProductLookupService(
			$providers,
			$factory,
			$config,
			$this->db(),
			new NullLogger(),
		);
	}

	/**
	 * Fake IDBConnection backing the committed-read seam: the last named
	 * parameter of each query is the configkey being read (see
	 * ProductLookupService::committedAppValue — appid param first, key second).
	 */
	private function db(): IDBConnection
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$mode = null;
		$params = [];
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturnCallback(
			function () use (&$params) {
				$key = end($params);
				return $this->dbValues[$key] ?? false;
			},
		);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('setValue')->willReturnSelf();
		$qb->method('update')->willReturnCallback(
			function () use (&$mode, &$params, $qb) {
				$mode = 'update';
				$params = [];
				return $qb;
			},
		);
		$qb->method('insert')->willReturnCallback(
			function () use (&$mode, &$params, $qb) {
				$mode = 'insert';
				$params = [];
				return $qb;
			},
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($v) use (&$params) {
				$params[] = $v;
				return ':p' . count($params);
			},
		);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$mode, &$params) {
				if ($mode === 'update') {
					// setCommittedAppValue UPDATE: params [value, appid, key]
					[$value, , $key] = $params;
					if (!array_key_exists($key, $this->dbValues) || $this->dbValues[$key] === $value) {
						return 0; // absent or identical — MariaDB changed-rows semantics
					}
					$this->dbValues[$key] = $value;
					return 1;
				}
				if ($mode === 'insert') {
					// setCommittedAppValue INSERT: params [appid, key, value]
					[, $key, $value] = $params;
					if (array_key_exists($key, $this->dbValues)) {
						throw new class ('duplicate key') extends DBException {
							public function getReason(): ?int
							{
								return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
							}
						};
					}
					$this->dbValues[$key] = $value;
					return 1;
				}
				return 0;
			},
		);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function hitProvider(string $id, ?ProductSuggestion $suggestion = null): StubProvider
	{
		return new StubProvider($id, ProviderResult::hit(
			$suggestion ?? new ProductSuggestion('Widget', 'ACME', 'A widget', 'https://example.org/p/1'),
		));
	}

	private function expectLookupError(callable $fn, string $code, int $status): void
	{
		try {
			$fn();
			self::fail('expected LookupException ' . $code);
		} catch (LookupException $e) {
			self::assertSame($code, $e->errorCode);
			self::assertSame($status, $e->status);
		}
	}

	public function testDisabledGateIsFailClosed(): void
	{
		$this->dbValues[ProductLookupService::KEY_ENABLED] = '0';
		$p = $this->hitProvider('openfoodfacts');
		$this->expectLookupError(
			fn () => $this->service([$p])->lookup(self::GTIN, 'alice'),
			'feature_disabled',
			403,
		);
		self::assertSame([], $p->calls, 'disabled toggle must mean zero egress');
	}

	public function testDisabledByDefaultWhenKeyUnset(): void
	{
		// Consent default (MH-01): no config key at all must mean OFF.
		$this->appValues = [];
		$this->dbValues = [];
		$p = $this->hitProvider('openfoodfacts');
		$this->expectLookupError(
			fn () => $this->service([$p])->lookup(self::GTIN, 'alice'),
			'feature_disabled',
			403,
		);
		self::assertSame([], $p->calls, 'unset toggle must mean zero egress');
	}

	public function testInvalidBarcodeRejected(): void
	{
		$p = $this->hitProvider('openfoodfacts');
		foreach (['', '12345', '4006381333932', '4006381333931 OR 1=1', '../etc/passwd', str_repeat('4', 33), str_repeat(' ', 64) . '4006381333931'] as $bad) {
			$this->expectLookupError(
				fn () => $this->service([$p])->lookup($bad, 'alice'),
				'invalid_barcode',
				422,
			);
		}
		self::assertSame([], $p->calls);
	}

	public function testFirstHitWinsAndStopsCascade(): void
	{
		$miss = new StubProvider('openfoodfacts', ProviderResult::miss());
		$hit = $this->hitProvider('openbeautyfacts');
		$never = $this->hitProvider('upcitemdb');
		$payload = $this->service([$miss, $hit, $never])->lookup(self::GTIN, 'alice');

		self::assertTrue($payload['found']);
		self::assertSame('Widget', $payload['name']);
		self::assertSame('openbeautyfacts', $payload['provider']);
		self::assertSame(self::GTIN, $payload['barcode']);
		self::assertSame([self::GTIN], $miss->calls);
		self::assertSame([self::GTIN], $hit->calls);
		self::assertSame([], $never->calls, 'providers after a hit must not be called');
	}

	public function testCleanMissPayload(): void
	{
		$miss = new StubProvider('openfoodfacts', ProviderResult::miss());
		$payload = $this->service([$miss])->lookup(self::GTIN, 'alice');
		self::assertSame(['found' => false], $payload);
	}

	public function testAllErrorsMeansUnavailable(): void
	{
		$err = new StubProvider('openfoodfacts', ProviderResult::error());
		$this->expectLookupError(
			fn () => $this->service([$err])->lookup(self::GTIN, 'alice'),
			'unavailable',
			502,
		);
	}

	public function testAllRateLimitedMeansUnavailable(): void
	{
		$limited = new StubProvider('openfoodfacts', ProviderResult::rateLimited());
		$this->expectLookupError(
			fn () => $this->service([$limited])->lookup(self::GTIN, 'alice'),
			'unavailable',
			502,
		);
	}

	public function testProviderThrowingDoesNotKillCascade(): void
	{
		$throwing = new StubProvider('openfoodfacts', static fn () => throw new \RuntimeException('bug'));
		$hit = $this->hitProvider('upcitemdb');
		$payload = $this->service([$throwing, $hit])->lookup(self::GTIN, 'alice');
		self::assertTrue($payload['found']);
	}

	public function testPerUserRateLimit(): void
	{
		$this->cache->set('rate:' . hash('sha256', 'alice'), true, 5);
		$p = $this->hitProvider('openfoodfacts');
		$this->expectLookupError(
			fn () => $this->service([$p])->lookup(self::GTIN, 'alice'),
			'rate_limited',
			429,
		);
		self::assertSame([], $p->calls);
	}

	public function testRateLimitKeyUsesThrottleWindow(): void
	{
		$cache = new TtlRecordingCache();
		$this->service([$this->hitProvider('openfoodfacts')], $cache)->lookup(self::GTIN, 'alice');
		self::assertSame(5, $cache->ttlOf('rate:' . hash('sha256', 'alice')), 'rate key must use the 5s throttle window');
	}

	public function testCacheHitShortCircuitsProvidersAndRateLimit(): void
	{
		$cached = ['found' => true, 'name' => 'Cached', 'provider' => 'openfoodfacts'];
		$this->cache->set('product:' . self::GTIN, $cached, 3600);
		$this->cache->set('rate:' . hash('sha256', 'alice'), true, 5); // even rate-limited users get cache hits
		$p = $this->hitProvider('openfoodfacts');
		$payload = $this->service([$p])->lookup(self::GTIN, 'alice');
		self::assertSame($cached, $payload);
		self::assertSame([], $p->calls);
	}

	public function testUpcItemDbDailyBudgetSkipsProvider(): void
	{
		$this->cache->set('budget-count:' . UpcItemDbProvider::ID . ':' . gmdate('Ymd'), 90, 86400);
		$miss = new StubProvider('openfoodfacts', ProviderResult::miss());
		$upc = $this->hitProvider(UpcItemDbProvider::ID);
		$payload = $this->service([$miss, $upc])->lookup(self::GTIN, 'alice');

		self::assertFalse($payload['found'], 'clean miss from OFF + budgeted UPCitemdb = clean miss, not unavailable');
		self::assertSame([], $upc->calls, 'daily budget must block the upstream call entirely');
	}

	public function testUpstreamCallChargesUpcItemDbBudget(): void
	{
		$key = 'budget-count:' . UpcItemDbProvider::ID . ':' . gmdate('Ymd');
		$upc = $this->hitProvider(UpcItemDbProvider::ID);
		$this->service([$upc])->lookup(self::GTIN, 'alice');
		self::assertSame(1, (int)$this->cache->get($key), 'every upstream call must count against the daily budget');
	}

	public function testRateLimitedProviderGetsCooldownSkip(): void
	{
		$limited = new StubProvider('openfoodfacts', ProviderResult::rateLimited());
		$this->expectLookupError(
			fn () => $this->service([$limited])->lookup(self::GTIN, 'alice'),
			'unavailable',
			502,
		);
		// Clear the per-user rate key (TTL'd in a real cache) but keep cooldown.
		$this->cache->remove('rate:' . hash('sha256', 'alice'));

		// Same provider id — now in cooldown: skipped before any upstream call.
		$retry = new StubProvider('openfoodfacts', ProviderResult::hit(
			new ProductSuggestion('Widget', null, null, null),
		));
		$this->expectLookupError(
			fn () => $this->service([$retry])->lookup(self::GTIN, 'alice'),
			'unavailable',
			502,
		);
		self::assertSame([], $retry->calls, 'a provider that just 429ed must cool down, not be retried');
	}

	public function testProviderToggleListNarrowsCascade(): void
	{
		$this->dbValues[ProductLookupService::KEY_PROVIDERS] = '["upcitemdb"]';
		$off = $this->hitProvider('openfoodfacts');
		$upc = new StubProvider('upcitemdb', ProviderResult::miss());
		$payload = $this->service([$off, $upc])->lookup(self::GTIN, 'alice');
		self::assertFalse($payload['found']);
		self::assertSame([], $off->calls, 'disabled provider must not be called');
		self::assertSame([self::GTIN], $upc->calls);
	}

	public function testEmptyProviderListIsUnavailable(): void
	{
		$this->dbValues[ProductLookupService::KEY_PROVIDERS] = '[]';
		$this->expectLookupError(
			fn () => $this->service([$this->hitProvider('openfoodfacts')])->lookup(self::GTIN, 'alice'),
			'unavailable',
			502,
		);
	}

	public function testCorruptProviderListFallsBackToAll(): void
	{
		$this->dbValues[ProductLookupService::KEY_PROVIDERS] = '{broken';
		$p = $this->hitProvider('openfoodfacts');
		$payload = $this->service([$p])->lookup(self::GTIN, 'alice');
		self::assertTrue($payload['found']);
	}

	public function testSanitizationCollapsesWhitespaceAndTruncates(): void
	{
		$dirty = new ProductSuggestion(
			"Multi\nline\tname  " . str_repeat('x', 300),
			null,
			str_repeat('d', 12000),
			'https://example.org/p/1',
		);
		$payload = $this->service([$this->hitProvider('openfoodfacts', $dirty)])->lookup(self::GTIN, 'alice');
		self::assertStringNotContainsString("\n", (string)$payload['name']);
		self::assertStringNotContainsString("\t", (string)$payload['name']);
		self::assertLessThanOrEqual(255, mb_strlen((string)$payload['name']));
		self::assertLessThanOrEqual(10000, mb_strlen((string)$payload['description']));
	}

	public function testUnsafeSourceUrlsAreDropped(): void
	{
		foreach (['javascript:alert(1)', 'javascript://evil.example/%0Aalert(1)', 'data:text/html,<b>x</b>', 'https://user:pw@evil/x', "https://evil/x\n", 'ftp://example.com/x', 'gopher://example.com/x'] as $url) {
			$suggestion = new ProductSuggestion('Widget', null, null, $url);
			$payload = $this->service([$this->hitProvider('openfoodfacts', $suggestion)])->lookup(self::GTIN, 'alice');
			self::assertNull($payload['sourceUrl'], $url);
			// ArrayCache ignores TTLs — clear what a real run would expire.
			$this->cache->remove('product:' . self::GTIN);
			$this->cache->remove('rate:' . hash('sha256', 'alice'));
		}
	}

	public function testSanitizedEmptyNameDegradesToMiss(): void
	{
		$suggestion = new ProductSuggestion("   \n\t ", null, null, null);
		$payload = $this->service([$this->hitProvider('openfoodfacts', $suggestion)])->lookup(self::GTIN, 'alice');
		self::assertSame(['found' => false], $payload);
	}

	public function testStatsShapeIsNormalized(): void
	{
		$svc = $this->service([$this->hitProvider('openfoodfacts'), $this->hitProvider('upcitemdb')]);
		$stats = $svc->stats();
		foreach (['openfoodfacts', 'upcitemdb'] as $id) {
			self::assertSame(['hit' => 0, 'miss' => 0, 'error' => 0, 'rate_limited' => 0], $stats['providers'][$id]);
		}
		self::assertSame(['provider' => 'upcitemdb', 'used' => 0, 'limit' => 90], $stats['dailyBudget']);
		self::assertArrayHasKey('totals', $stats);
	}

	public function testStatsCountersIncrementAndDerivedTotals(): void
	{
		$miss = new StubProvider('openfoodfacts', ProviderResult::miss());
		$hit = $this->hitProvider('upcitemdb');
		$svc = $this->service([$miss, $hit]);
		$svc->lookup(self::GTIN, 'alice');

		$stats = $svc->stats();
		self::assertSame(1, $stats['cache']['miss']);
		self::assertSame(1, $stats['providers']['openfoodfacts']['miss']);
		self::assertSame(1, $stats['providers']['upcitemdb']['hit']);
		self::assertSame(1, $stats['totals']['lookups']);
		self::assertSame(1, $stats['totals']['hits']);
		self::assertSame(1, $stats['totals']['misses']);
		// Stored appconfig value must not contain derived keys.
		$stored = json_decode($this->appValues[ProductLookupService::KEY_STATS], true);
		self::assertArrayNotHasKey('totals', $stored);
		self::assertArrayNotHasKey('dailyBudget', $stored);
	}

	public function testRateLimitedProviderCoolsDownAndIsSkipped(): void
	{
		$limited = new StubProvider('openfoodfacts', ProviderResult::rateLimited());
		$hit = $this->hitProvider('upcitemdb');
		$svc = $this->service([$limited, $hit]);
		$svc->lookup(self::GTIN, 'alice');
		self::assertSame(1, count($limited->calls));

		// Second lookup: OFF is in cooldown — skipped, UPC still answers.
		$this->cache->remove('product:' . self::GTIN);
		$this->cache->remove('rate:' . hash('sha256', 'alice'));
		$payload = $svc->lookup('00614141999996', 'alice');
		self::assertTrue($payload['found']);
		self::assertSame(1, count($limited->calls), 'cooldown provider must not be re-called');
		self::assertCount(2, $hit->calls);
	}

	public function testUpcItemDb429MarksDailyBudgetExhausted(): void
	{
		$limited = new StubProvider(UpcItemDbProvider::ID, ProviderResult::rateLimited());
		$miss = new StubProvider('openfoodfacts', ProviderResult::miss());
		$svc = $this->service([$miss, $limited]);
		// OFF cleanly missed → result is found:false; the 429 still sets the flags.
		$payload = $svc->lookup(self::GTIN, 'alice');
		self::assertSame(['found' => false], $payload);
		self::assertNotNull(
			$this->cache->get('budget:' . UpcItemDbProvider::ID . ':' . gmdate('Ymd')),
			'a 429 must exhaust the shared daily budget flag',
		);
		self::assertNotNull($this->cache->get('cooldown:' . UpcItemDbProvider::ID));
	}

	public function testUpcItemDbBudgetCounterChargesEveryAttempt(): void
	{
		$miss = new StubProvider('openfoodfacts', ProviderResult::miss());
		$upc = new StubProvider(UpcItemDbProvider::ID, ProviderResult::miss());
		$this->service([$miss, $upc])->lookup(self::GTIN, 'alice');
		self::assertSame(
			1,
			(int)$this->cache->get('budget-count:' . UpcItemDbProvider::ID . ':' . gmdate('Ymd')),
			'every UPCitemdb attempt must be charged — even misses',
		);
		// OFF-family providers are not charged against the UPC budget.
		self::assertSame(1, count($miss->calls));
	}

	public function testOffProviderIsNotBudgetCharged(): void
	{
		$miss = new StubProvider('openbeautyfacts', ProviderResult::miss());
		$this->service([$miss])->lookup(self::GTIN, 'alice');
		self::assertNull($this->cache->get('budget-count:' . UpcItemDbProvider::ID . ':' . gmdate('Ymd')));
	}

	public function testRateLimitIsPerActor(): void
	{
		$this->cache->set('rate:' . hash('sha256', 'alice'), true, 5);
		$hit = $this->hitProvider('openfoodfacts');
		$payload = $this->service([$hit])->lookup(self::GTIN, 'bob');
		self::assertTrue($payload['found'], 'alice\'s rate limit must not block bob');
		self::assertSame([self::GTIN], $hit->calls);
	}

	public function testCacheTtlsAreHit24hMiss5m(): void
	{
		$cache = new TtlRecordingCache();
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, ?string $default = null): string => $this->appValues[$key] ?? ($default ?? ''),
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->appValues[$key] = $value;
			},
		);
		$miss = new StubProvider('openfoodfacts', ProviderResult::miss());
		$hit = $this->hitProvider('openbeautyfacts');
		$svc = new ProductLookupService([$miss, $hit], $factory, $config, $this->db(), new NullLogger());
		$svc->lookup(self::GTIN, 'alice');
		self::assertSame(86400, $cache->ttlOf('product:' . self::GTIN));

		$miss2 = new StubProvider('openfoodfacts', ProviderResult::miss());
		$svc2 = new ProductLookupService([$miss2], $factory, $config, $this->db(), new NullLogger());
		$svc2->lookup('00614141999996', 'bob');
		self::assertSame(300, $cache->ttlOf('product:00614141999996'));
	}

	public function testCooldownSkipDoesNotCountAsCleanMiss(): void
	{
		// All providers rate-limited → cooldowns set → next call: all skipped,
		// none cleanly missed → still 'unavailable', never a false found:false.
		$this->cache->set('cooldown:openfoodfacts', true, 300);
		$err = new StubProvider('openfoodfacts', ProviderResult::miss());
		$this->expectLookupError(
			fn () => $this->service([$err])->lookup(self::GTIN, 'alice'),
			'unavailable',
			502,
		);
		self::assertSame([], $err->calls);
	}

	public function testSetEnabledWritesWhenStaleSnapshotClaimsNoChange(): void
	{
		// A stale IConfig snapshot claims the flag is already '1' while the
		// committed row is '0'. AppConfig's no-change skip would drop this
		// write entirely — the committed upsert must still land, otherwise an
		// admin save is silently lost (see bugs/appconfig-localcache-*).
		$this->appValues[ProductLookupService::KEY_ENABLED] = '1';
		$this->dbValues[ProductLookupService::KEY_ENABLED] = '0';
		$svc = $this->service([]);
		$svc->setEnabled(true);
		self::assertSame('1', $this->dbValues[ProductLookupService::KEY_ENABLED]);
		self::assertTrue($svc->isEnabled(), 'committed read must see the new value');
	}

	public function testSetEnabledInsertsAbsentKey(): void
	{
		$this->dbValues = [];
		$svc = $this->service([]);
		$svc->setEnabled(true);
		self::assertSame('1', $this->dbValues[ProductLookupService::KEY_ENABLED]);
		self::assertTrue($svc->isEnabled());
	}

	public function testSetEnabledIdenticalValueConverges(): void
	{
		// UPDATE affects 0 rows on an identical value; the INSERT fallback
		// hits the unique constraint and settles on a final UPDATE.
		$this->dbValues[ProductLookupService::KEY_ENABLED] = '1';
		$svc = $this->service([]);
		$svc->setEnabled(true);
		self::assertSame('1', $this->dbValues[ProductLookupService::KEY_ENABLED]);
		self::assertTrue($svc->isEnabled());
	}

	public function testSetEnabledProvidersPersistsCommitted(): void
	{
		$svc = $this->service([
			$this->hitProvider('openfoodfacts'),
			$this->hitProvider('upcitemdb'),
		]);
		$svc->setEnabledProviders(['upcitemdb', 'bogus', 'upcitemdb']);
		self::assertSame(
			'["upcitemdb"]',
			$this->dbValues[ProductLookupService::KEY_PROVIDERS],
			'unknown ids and duplicates must be stripped before persisting',
		);
		self::assertSame(['upcitemdb'], $svc->enabledProviderIds());
	}
}

/** In-memory ICache — TTLs ignored on purpose (unit scope). */
class ArrayCache implements ICache
{
	/** @var array<string, mixed> */
	public array $data = [];

	public function get($key)
	{
		return $this->data[$key] ?? null;
	}

	public function set($key, $value, $ttl = 0)
	{
		$this->data[$key] = $value;
		return true;
	}

	public function hasKey($key)
	{
		return array_key_exists($key, $this->data);
	}

	public function remove($key)
	{
		unset($this->data[$key]);
		return true;
	}

	public function clear($prefix = '')
	{
		$this->data = [];
		return true;
	}

	public static function isAvailable(): bool
	{
		return true;
	}
}

final class StubProvider implements ProductLookupProvider
{
	/** @var list<string> */
	public array $calls = [];

	/** @var ProviderResult|callable */
	private $result;

	/** @param ProviderResult|callable(string):ProviderResult $result */
	public function __construct(
		private readonly string $id,
		$result,
		private readonly string $label = 'Stub',
	) {
		$this->result = $result;
	}

	public function id(): string
	{
		return $this->id;
	}

	public function label(): string
	{
		return $this->label;
	}

	public function lookup(string $gtin): ProviderResult
	{
		$this->calls[] = $gtin;
		if (is_callable($this->result)) {
			return ($this->result)($gtin);
		}
		return $this->result;
	}
}

/** ArrayCache that also records TTLs so hit/miss TTL branches are asserted. */
final class TtlRecordingCache extends ArrayCache
{
	/** @var array<string, int> */
	private array $ttls = [];

	public function set($key, $value, $ttl = 0)
	{
		$this->ttls[$key] = $ttl;
		return parent::set($key, $value, $ttl);
	}

	public function ttlOf(string $key): ?int
	{
		return $this->ttls[$key] ?? null;
	}
}
