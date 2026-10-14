#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// Product lookup gauntlet: gate, validation, cascade, budget, sanitization.
runMutations(dirname(__DIR__, 2), 'ProductLookup|Gtin|OpenFactsProvider|UpcItemDbProvider', [
	// --- Gate must fail closed -------------------------------------------
	[
		'name' => 'disabled-gate-inverted',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => "self::KEY_ENABLED, '0') === '1'",
		'replace' => "self::KEY_ENABLED, '0') !== '1'",
	],
	[
		'name' => 'gtin-validation-skipped',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => 'if (!Gtin::isValid($barcode)) {',
		'replace' => 'if (Gtin::isValid($barcode) && $barcode === \'\') {',
	],
	// --- GTIN check digit ------------------------------------------------
	[
		'name' => 'check-digit-weights-swapped',
		'file' => 'lib/Service/Lookup/Gtin.php',
		'search' => '$sum += $digit * ($i % 2 === 0 ? 3 : 1);',
		'replace' => '$sum += $digit * ($i % 2 === 0 ? 1 : 3);',
	],
	[
		'name' => 'check-digit-comparison-inverted',
		'file' => 'lib/Service/Lookup/Gtin.php',
		'search' => 'return (ord($code[$len - 1]) - 48) === $expected;',
		'replace' => 'return (ord($code[$len - 1]) - 48) !== $expected;',
	],
	[
		'name' => 'gtin14-dropped-from-format',
		'file' => 'lib/Service/Lookup/Gtin.php',
		'search' => "'/^(?:\\d{8}|\\d{12}|\\d{13}|\\d{14})\$/D'",
		'replace' => "'/^(?:\\d{8}|\\d{12}|\\d{13})\$/D'",
	],
	// --- Rate limiting + budget ------------------------------------------
	[
		'name' => 'rate-limit-check-inverted',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => 'if ($cache->get($rateKey) !== null) {',
		'replace' => 'if ($cache->get($rateKey) === null) {',
	],
	[
		'name' => 'upc-budget-off-by-one',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => '|| $this->upcBudgetUsed($cache) >= self::UPCITEMDB_DAILY_BUDGET)) {',
		'replace' => '|| $this->upcBudgetUsed($cache) > self::UPCITEMDB_DAILY_BUDGET)) {',
	],
	[
		'name' => 'upstream-call-not-charged',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => '$cache->set($key, $used + 1, 86400);',
		'replace' => '$cache->set($key, $used, 86400);',
	],
	[
		'name' => 'cooldown-not-set-on-429',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => '$cache->set(\'cooldown:\' . $providerId, true, self::RATE_LIMIT_COOLDOWN_SECONDS);',
		'replace' => '$cache->get(\'cooldown:\' . $providerId);',
	],
	// --- Cascade semantics -------------------------------------------------
	[
		'name' => 'provider-throw-kills-cascade',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => "} catch (\\Throwable) {\n\t\t\t\t// Contract breach by a provider adapter must not kill the cascade.\n\t\t\t\t\$result = ProviderResult::error();\n\t\t\t}",
		'replace' => '} catch (\\Throwable $e) {' . "\n\t\t\t\tthrow \$e;\n\t\t\t}",
	],
	[
		'name' => 'clean-miss-not-distinguished',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => "\$sawCleanMiss = true;\n\t\t\t\tcontinue;\n\t\t\t}\n\t\t\tif (\$result->outcome === ProviderResult::HIT",
		'replace' => "continue;\n\t\t\t}\n\t\t\tif (\$result->outcome === ProviderResult::HIT",
	],
	[
		'name' => 'hit-does-not-stop-cascade',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => "\$this->logLookup('hit', \$outcomes, 'miss', \$start, \$actorUid);\n\t\t\t\treturn \$payload;",
		'replace' => "\$this->logLookup('hit', \$outcomes, 'miss', \$start, \$actorUid);\n\t\t\t\tcontinue;",
	],
	[
		'name' => 'enabled-providers-filter-dropped',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => "\$providers = array_values(array_filter(\n\t\t\t\$this->providers,\n\t\t\tstatic fn (ProductLookupProvider \$p) => in_array(\$p->id(), \$enabledIds, true),\n\t\t));",
		'replace' => "\$providers = array_values(\$this->providers);",
	],
	// --- Output safety -----------------------------------------------------
	[
		'name' => 'unsafe-scheme-allowed',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => "|| !in_array(\$parts['scheme'] ?? '', ['http', 'https'], true)",
		'replace' => "|| !in_array(\$parts['scheme'] ?? '', ['http', 'https', 'javascript'], true)",
	],
	[
		'name' => 'sanitized-empty-name-still-hit',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => "if (\$payload['name'] === '') {\n\t\t\t\t\t// Sanitization emptied the name — treat as a clean miss,\n\t\t\t\t\t// not a hit with a blank title.\n\t\t\t\t\t\$sawCleanMiss = true;\n\t\t\t\t\tcontinue;\n\t\t\t\t}",
		'replace' => "if (false) {\n\t\t\t\t\t\$sawCleanMiss = true;\n\t\t\t\t\tcontinue;\n\t\t\t\t}",
	],
	// --- Provider adapters ---------------------------------------------------
	[
		'name' => 'openfacts-404-is-error-not-miss',
		'file' => 'lib/Service/Lookup/OpenFactsProvider.php',
		'search' => "if (\$status === 404) {\n\t\t\treturn ProviderResult::miss();\n\t\t}",
		'replace' => "if (\$status === 404) {\n\t\t\treturn ProviderResult::error();\n\t\t}",
	],
	[
		'name' => 'openfacts-empty-name-still-hit',
		'file' => 'lib/Service/Lookup/OpenFactsProvider.php',
		'search' => "if (\$name === '') {\n\t\t\treturn ProviderResult::miss();\n\t\t}",
		'replace' => "if (\$name === 'never-empty') {\n\t\t\treturn ProviderResult::miss();\n\t\t}",
	],
	[
		'name' => 'openfacts-redirects-followed',
		'file' => 'lib/Service/Lookup/UpstreamClient.php',
		'search' => "'allow_redirects' => false,",
		'replace' => "'allow_redirects' => true,",
	],
	[
		'name' => 'upstream-429-not-rate-limited',
		'file' => 'lib/Service/Lookup/UpstreamClient.php',
		'search' => "if (\$response->getStatusCode() === 429) {\n\t\t\tthrow new ProviderRateLimitedException();\n\t\t}",
		'replace' => "if (\$response->getStatusCode() === 429) {\n\t\t\tthrow new \\RuntimeException('ignored');\n\t\t}",
	],
	[
		'name' => 'upcitemdb-untitled-first-masks-usable',
		'file' => 'lib/Service/Lookup/UpcItemDbProvider.php',
		'search' => "if (\$name === '') {\n\t\t\t\tcontinue;\n\t\t\t}",
		'replace' => "if (\$name === '') {\n\t\t\t\tbreak;\n\t\t\t}",
	],
	[
		'name' => 'upcitemdb-non-ok-code-still-parsed',
		'file' => 'lib/Service/Lookup/UpcItemDbProvider.php',
		'search' => "if (!is_array(\$data) || (\$data['code'] ?? '') !== 'OK') {\n\t\t\treturn ProviderResult::error();\n\t\t}",
		'replace' => "if (!is_array(\$data)) {\n\t\t\treturn ProviderResult::error();\n\t\t}",
	],
	// --- Service statistics / settings contract ----------------------------
	[
		'name' => 'corrupt-provider-list-not-fallback',
		'file' => 'lib/Service/ProductLookupService.php',
		'search' => "\$decoded = json_decode(\$raw, true);\n\t\tif (!is_array(\$decoded)) {\n\t\t\treturn \$this->providerIds();\n\t\t}",
		'replace' => "\$decoded = json_decode(\$raw, true);\n\t\tif (!is_array(\$decoded)) {\n\t\t\treturn [];\n\t\t}",
	],
]);
