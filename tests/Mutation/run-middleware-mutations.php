#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: AppAccessMiddleware JSON vs page denial + envelopes.
 */

require __DIR__ . '/harness.php';

$file = 'lib/Middleware/AppAccessMiddleware.php';

runMutations(dirname(__DIR__, 2), 'AppAccessMiddlewareEnvelopeTest', [
	[
		'name' => 'mobile-controller-skip-removed',
		'file' => $file,
		'search' => "if (str_contains(\$class, 'MobileController')) {\n\t\t\treturn;\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn;\n\t\t}",
	],
	[
		'name' => 'af-iv20-web-402-guard-removed',
		'file' => $file,
		'search' => "if (!str_contains(\$class, 'MobileController')) {\n\t\t\t\tthrow \$exception;\n\t\t\t}",
		'replace' => "if (false) {\n\t\t\t\tthrow \$exception;\n\t\t\t}",
	],
	[
		'name' => 'json-route-api-check-dropped',
		'file' => $file,
		'search' => "return str_contains(\$path, '/api/')\n\t\t\t|| str_contains(\$path, '/mobile/')\n\t\t\t|| \$this->request->getMethod() !== 'GET';",
		'replace' => "return str_contains(\$path, '/mobile/')\n\t\t\t|| \$this->request->getMethod() !== 'GET';",
	],
	[
		'name' => 'permission-denied-status-200',
		'file' => $file,
		'search' => "return \$this->envelope('permission_denied', \$l->t('You do not have permission for this action.'), Http::STATUS_FORBIDDEN);",
		'replace' => "return \$this->envelope('permission_denied', \$l->t('You do not have permission for this action.'), Http::STATUS_OK);",
	],
	[
		'name' => 'insufficient-stock-code-wrong',
		'file' => $file,
		'search' => "return \$this->envelope('insufficient_stock', \$msg, Http::STATUS_CONFLICT);",
		'replace' => "return \$this->envelope('stock_gone', \$msg, Http::STATUS_CONFLICT);",
	],
	[
		'name' => 'insufficient-stock-qty-placeholder-broken',
		'file' => $file,
		'search' => "\$l->t('Only %s left in %s.', [\$qty, \$exception->getLocationLabel()])",
		'replace' => "\$l->t('Only %n left in %s.', [\$qty, \$exception->getLocationLabel()], (int)\$qty)",
	],
	[
		'name' => 'auth-required-status-402',
		'file' => $file,
		'search' => "if (\$exception->getErrorCode() === 'auth_required') {\n\t\t\t\treturn \$this->envelope(\n\t\t\t\t\t'auth_required',\n\t\t\t\t\t\$l->t('Authentication required.'),\n\t\t\t\t\tHttp::STATUS_UNAUTHORIZED,\n\t\t\t\t);",
		'replace' => "if (\$exception->getErrorCode() === 'auth_required') {\n\t\t\t\treturn \$this->envelope(\n\t\t\t\t\t'auth_required',\n\t\t\t\t\t\$l->t('Authentication required.'),\n\t\t\t\t\tself::HTTP_PAYMENT_REQUIRED,\n\t\t\t\t);",
	],
	[
		'name' => 'rate-limit-status-402',
		'file' => $file,
		'search' => "if (\$exception->getErrorCode() === 'rate_limited') {\n\t\t\t\treturn \$this->envelope(\n\t\t\t\t\t'rate_limited',\n\t\t\t\t\t\$l->t('Too many pairing attempts. Try again later.'),\n\t\t\t\t\t429,\n\t\t\t\t);",
		'replace' => "if (\$exception->getErrorCode() === 'rate_limited') {\n\t\t\t\treturn \$this->envelope(\n\t\t\t\t\t'rate_limited',\n\t\t\t\t\t\$l->t('Too many pairing attempts. Try again later.'),\n\t\t\t\t\tself::HTTP_PAYMENT_REQUIRED,\n\t\t\t\t);",
	],
	[
		'name' => 'item-lock-conflict-maps-to-409',
		'file' => $file,
		'search' => "\$status = \$code === 'item_lock_conflict'\n\t\t\t\t? Http::STATUS_LOCKED\n\t\t\t\t: Http::STATUS_CONFLICT;",
		'replace' => "\$status = \$code === 'item_lock_conflict'\n\t\t\t\t? Http::STATUS_CONFLICT\n\t\t\t\t: Http::STATUS_CONFLICT;",
	],
	[
		'name' => 'access-denied-code-wrong',
		'file' => $file,
		'search' => "'app_access_denied',",
		'replace' => "'access_forbidden',",
	],
]);
