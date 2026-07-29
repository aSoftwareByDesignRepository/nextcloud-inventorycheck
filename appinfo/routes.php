<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#dashboard', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#items', 'url' => '/items', 'verb' => 'GET'],
		['name' => 'page#item', 'url' => '/items/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'page#locations', 'url' => '/locations', 'verb' => 'GET'],
		['name' => 'page#location', 'url' => '/locations/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'page#movements', 'url' => '/movements', 'verb' => 'GET'],
		['name' => 'page#stocktake', 'url' => '/stocktake', 'verb' => 'GET'],
		['name' => 'page#stocktakeCampaign', 'url' => '/stocktake/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'page#settings', 'url' => '/settings', 'verb' => 'GET'],

		['name' => 'location#index', 'url' => '/api/locations', 'verb' => 'GET'],
		['name' => 'location#show', 'url' => '/api/locations/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'location#create', 'url' => '/api/locations', 'verb' => 'POST'],
		['name' => 'location#update', 'url' => '/api/locations/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'location#destroy', 'url' => '/api/locations/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],

		['name' => 'item#index', 'url' => '/api/items', 'verb' => 'GET'],
		['name' => 'item#byCode', 'url' => '/api/items/by-code/{code}', 'verb' => 'GET', 'requirements' => ['code' => '[^/]+']],
		['name' => 'item#label', 'url' => '/api/items/{id}/label.svg', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'item#labelAlias', 'url' => '/api/items/{id}/label', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'item#labelPrint', 'url' => '/items/{id}/label', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'item#bulkLabels', 'url' => '/api/items/labels', 'verb' => 'GET'],
		['name' => 'item#show', 'url' => '/api/items/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'item#create', 'url' => '/api/items', 'verb' => 'POST'],
		['name' => 'item#update', 'url' => '/api/items/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'item#destroy', 'url' => '/api/items/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],

		['name' => 'itemPhoto#show', 'url' => '/api/items/{id}/photo', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'itemPhoto#upload', 'url' => '/api/items/{id}/photo', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'itemPhoto#destroy', 'url' => '/api/items/{id}/photo', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],

		['name' => 'balance#index', 'url' => '/api/balances', 'verb' => 'GET'],

		['name' => 'movement#index', 'url' => '/api/movements', 'verb' => 'GET'],
		['name' => 'movement#receive', 'url' => '/api/movements/receive', 'verb' => 'POST'],
		['name' => 'movement#issue', 'url' => '/api/movements/issue', 'verb' => 'POST'],
		['name' => 'movement#transfer', 'url' => '/api/movements/transfer', 'verb' => 'POST'],
		['name' => 'movement#adjust', 'url' => '/api/movements/adjust', 'verb' => 'POST'],
		['name' => 'movement#scan', 'url' => '/api/movements/scan', 'verb' => 'POST'],

		['name' => 'lowStock#index', 'url' => '/api/low-stock', 'verb' => 'GET'],
		['name' => 'lowStock#perLocation', 'url' => '/api/low-stock/per-location', 'verb' => 'GET'],

		['name' => 'export#index', 'url' => '/api/export', 'verb' => 'GET'],

		['name' => 'import#dryRun', 'url' => '/api/import/dry-run', 'verb' => 'POST'],
		['name' => 'import#commit', 'url' => '/api/import/commit', 'verb' => 'POST'],

		['name' => 'cycleCount#index', 'url' => '/api/cycle-counts', 'verb' => 'GET'],
		['name' => 'cycleCount#show', 'url' => '/api/cycle-counts/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'cycleCount#create', 'url' => '/api/cycle-counts', 'verb' => 'POST'],
		['name' => 'cycleCount#start', 'url' => '/api/cycle-counts/{id}/start', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'cycleCount#setCount', 'url' => '/api/cycle-counts/lines/{lineId}', 'verb' => 'PUT', 'requirements' => ['lineId' => '\\d+']],
		['name' => 'cycleCount#close', 'url' => '/api/cycle-counts/{id}/close', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],

		['name' => 'favourite#index', 'url' => '/api/favourites/locations', 'verb' => 'GET'],
		['name' => 'favourite#create', 'url' => '/api/favourites/locations', 'verb' => 'POST'],
		['name' => 'favourite#destroy', 'url' => '/api/favourites/locations/{locationId}', 'verb' => 'DELETE', 'requirements' => ['locationId' => '\\d+']],

		['name' => 'flange#status', 'url' => '/api/flange', 'verb' => 'GET'],
		['name' => 'flange#issueMaintWo', 'url' => '/api/flange/maint/issue', 'verb' => 'POST'],
		['name' => 'flange#issueProject', 'url' => '/api/flange/project-issue', 'verb' => 'POST'],
		['name' => 'flange#saveSettings', 'url' => '/api/flange/settings', 'verb' => 'POST'],

		['name' => 'config#index', 'url' => '/api/config', 'verb' => 'GET'],
		['name' => 'config#saveAccess', 'url' => '/api/config/access', 'verb' => 'POST'],
		['name' => 'config#saveOffice', 'url' => '/api/config/office', 'verb' => 'POST'],
		['name' => 'config#saveNotify', 'url' => '/api/config/notify', 'verb' => 'POST'],
		['name' => 'config#saveFractional', 'url' => '/api/config/fractional', 'verb' => 'POST'],
		['name' => 'config#locationAcl', 'url' => '/api/config/location-acl', 'verb' => 'GET'],
		['name' => 'config#saveLocationAcl', 'url' => '/api/config/location-acl', 'verb' => 'PUT'],

		['name' => 'license#show', 'url' => '/api/license', 'verb' => 'GET'],
		['name' => 'license#apply', 'url' => '/api/license', 'verb' => 'POST'],
		['name' => 'license#remove', 'url' => '/api/license', 'verb' => 'DELETE'],
		['name' => 'license#seats', 'url' => '/api/license/seats', 'verb' => 'GET'],
		['name' => 'license#assignSeat', 'url' => '/api/license/seats', 'verb' => 'POST'],
		['name' => 'license#removeSeat', 'url' => '/api/license/seats/{uid}', 'verb' => 'DELETE'],
		['name' => 'license#devices', 'url' => '/api/license/devices', 'verb' => 'GET'],
		['name' => 'license#createDevice', 'url' => '/api/license/devices', 'verb' => 'POST'],
		['name' => 'license#regeneratePairCode', 'url' => '/api/license/devices/{id}/pair-code', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
		['name' => 'license#removeDevice', 'url' => '/api/license/devices/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],

		['name' => 'mobile#bootstrap', 'url' => '/mobile/v1/bootstrap', 'verb' => 'GET'],
		['name' => 'mobile#byCode', 'url' => '/mobile/v1/items/by-code/{code}', 'verb' => 'GET', 'requirements' => ['code' => '[^/]+']],
		['name' => 'mobile#locations', 'url' => '/mobile/v1/locations', 'verb' => 'GET'],
		['name' => 'mobile#balances', 'url' => '/mobile/v1/balances', 'verb' => 'GET'],
		['name' => 'mobile#movements', 'url' => '/mobile/v1/movements', 'verb' => 'GET'],
		['name' => 'mobile#scan', 'url' => '/mobile/v1/movements/scan', 'verb' => 'POST'],
		['name' => 'mobile#pairDevice', 'url' => '/mobile/v1/devices/pair', 'verb' => 'POST'],
	],
];
