#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: AccessControlService L0–L3 layer model.
 */

require __DIR__ . '/harness.php';

$file = 'lib/Service/AccessControlService.php';

runMutations(dirname(__DIR__, 2), 'AccessControlServiceTest', [
	[
		'name' => 'system-admin-check-dropped',
		'file' => $file,
		'search' => "return \$userId !== '' && \$this->groupManager->isAdmin(\$userId);",
		'replace' => "return \$userId !== '';",
	],
	[
		'name' => 'app-admin-list-ignored',
		'file' => $file,
		'search' => "|| in_array(\$userId, \$this->getJsonIdList(self::KEY_APP_ADMINS), true);",
		'replace' => '|| false;',
	],
	[
		'name' => 'restriction-flag-inverted',
		'file' => $file,
		'search' => "self::KEY_ACCESS_RESTRICTION, '0') === '1';",
		'replace' => "self::KEY_ACCESS_RESTRICTION, '0') !== '1';",
	],
	[
		'name' => 'admin-bypass-removed',
		'file' => $file,
		'search' => "if (\$this->isAppAdmin(\$userId)) {\n\t\t\treturn true;\n\t\t}\n\t\tif (\$this->isAccessRestrictionEnabled()",
		'replace' => "if (false) {\n\t\t\treturn true;\n\t\t}\n\t\tif (\$this->isAccessRestrictionEnabled()",
	],
	[
		'name' => 'restriction-and-becomes-or',
		'file' => $file,
		'search' => 'if ($this->isAccessRestrictionEnabled() && !$this->userMatchesAllowList($userId)) {',
		'replace' => 'if ($this->isAccessRestrictionEnabled() || !$this->userMatchesAllowList($userId)) {',
	],
	[
		'name' => 'office-user-list-ignored',
		'file' => $file,
		'search' => 'if (in_array($userId, $this->getJsonIdList(self::KEY_OFFICE_USER_IDS), true)) {',
		'replace' => 'if (false) {',
	],
	[
		'name' => 'office-groups-ignored',
		'file' => $file,
		'search' => 'foreach ($this->getJsonIdList(self::KEY_OFFICE_GROUP_IDS) as $gid) {',
		'replace' => 'foreach ([] as $gid) {',
	],
	[
		'name' => 'allow-groups-ignored',
		'file' => $file,
		'search' => 'foreach ($this->getJsonIdList(self::KEY_ACCESS_ALLOWED_GROUP_IDS) as $gid) {',
		'replace' => 'foreach ([] as $gid) {',
	],
	[
		'name' => 'allow-users-ignored',
		'file' => $file,
		'search' => 'if (in_array($userId, $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_USER_IDS), true)) {',
		'replace' => 'if (false) {',
	],
	[
		'name' => 'require-office-inverted',
		'file' => $file,
		'search' => 'if (!$this->isOffice($userId)) {',
		'replace' => 'if ($this->isOffice($userId)) {',
	],
	[
		'name' => 'require-admin-inverted',
		'file' => $file,
		'search' => 'if (!$this->isAppAdmin($userId)) {',
		'replace' => 'if ($this->isAppAdmin($userId)) {',
	],
	[
		'name' => 'id-list-dedup-removed',
		'file' => $file,
		'search' => "\t\treturn array_values(array_unique(\$out));",
		'replace' => "\t\treturn \$out;",
	],
	[
		'name' => 'empty-uid-can-use-app',
		'file' => $file,
		'search' => "\t\tif (\$userId === '') {\n\t\t\treturn false;\n\t\t}\n\t\tif (\$this->isAppAdmin(\$userId)) {",
		'replace' => "\t\tif (\$this->isAppAdmin(\$userId)) {",
	],
]);
