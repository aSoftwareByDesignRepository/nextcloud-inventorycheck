#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: ConfigController directory validation (unknown uid/gid).
 */

require __DIR__ . '/harness.php';

$file = 'lib/Controller/ConfigController.php';

runMutations(dirname(__DIR__, 2), 'ConfigDirectoryValidationTest', [
	[
		'name' => 'user-exists-check-dropped',
		'file' => $file,
		'search' => "if (!\$this->userManager->userExists(\$userId)) {\n\t\t\t\tthrow new ValidationException('unknown_user', 'Unknown user: ' . \$userId, [\n\t\t\t\t\t['field' => \$field, 'code' => 'unknown_user'],\n\t\t\t\t]);\n\t\t\t}",
		'replace' => "if (false) {\n\t\t\t\tthrow new ValidationException('unknown_user', 'Unknown user: ' . \$userId, [\n\t\t\t\t\t['field' => \$field, 'code' => 'unknown_user'],\n\t\t\t\t]);\n\t\t\t}",
	],
	[
		'name' => 'group-exists-check-dropped',
		'file' => $file,
		'search' => "if (!\$this->groupManager->groupExists(\$gid)) {\n\t\t\t\tthrow new ValidationException('unknown_group', 'Unknown group: ' . \$gid, [\n\t\t\t\t\t['field' => \$field, 'code' => 'unknown_group'],\n\t\t\t\t]);\n\t\t\t}",
		'replace' => "if (false) {\n\t\t\t\tthrow new ValidationException('unknown_group', 'Unknown group: ' . \$gid, [\n\t\t\t\t\t['field' => \$field, 'code' => 'unknown_group'],\n\t\t\t\t]);\n\t\t\t}",
	],
	[
		'name' => 'system-admin-gate-always-true',
		'file' => $file,
		'search' => 'if (array_key_exists(\'appAdmins\', $p) && $this->access->isSystemAdmin($uid)) {',
		'replace' => 'if (array_key_exists(\'appAdmins\', $p) && true) {',
	],
	[
		'name' => 'array-type-check-dropped',
		'file' => $file,
		'search' => "if (!is_array(\$value)) {\n\t\t\tthrow new ValidationException('validation_failed', \$field . ' must be an array of ids.', [\n\t\t\t\t['field' => \$field, 'code' => 'invalid_type'],\n\t\t\t]);\n\t\t}",
		'replace' => "if (false) {\n\t\t\tthrow new ValidationException('validation_failed', \$field . ' must be an array of ids.', [\n\t\t\t\t['field' => \$field, 'code' => 'invalid_type'],\n\t\t\t]);\n\t\t}",
	],
	[
		'name' => 'unique-dedupe-removed',
		'file' => $file,
		'search' => 'return array_values(array_unique($out));',
		'replace' => 'return $out;',
	],
	[
		'name' => 'string-zero-treated-as-true',
		'file' => $file,
		'search' => "if (\$value === 0 || \$value === '0') {\n\t\t\treturn false;\n\t\t}",
		'replace' => "if (\$value === 0) {\n\t\t\treturn false;\n\t\t}",
	],
	[
		'name' => 'commit-before-group-validation',
		'file' => $file,
		'search' => "\$allowedUsers = array_key_exists('allowedUsers', \$p)\n\t\t\t? \$this->validatedUserIds(\$p['allowedUsers'], 'allowedUsers')\n\t\t\t: null;\n\t\t\$allowedGroups = array_key_exists('allowedGroups', \$p)\n\t\t\t? \$this->validatedGroupIds(\$p['allowedGroups'], 'allowedGroups')\n\t\t\t: null;",
		'replace' => "\$allowedUsers = array_key_exists('allowedUsers', \$p)\n\t\t\t? \$this->validatedUserIds(\$p['allowedUsers'], 'allowedUsers')\n\t\t\t: null;\n\t\tif (\$allowedUsers !== null) {\n\t\t\t\$this->access->setJsonIdList(AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, \$allowedUsers);\n\t\t}\n\t\t\$allowedGroups = array_key_exists('allowedGroups', \$p)\n\t\t\t? \$this->validatedGroupIds(\$p['allowedGroups'], 'allowedGroups')\n\t\t\t: null;",
	],
]);
