#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// IV-SUB-LOCATIONS — flip-prone branches: depth cap (both directions),
// delete-refuse, self-parent, ACL descendant expansion, roots query,
// parentId input floor.
runMutations(dirname(__DIR__, 2), 'SubLocations', [
	[
		'name' => 'depth-cap-parent-may-be-child',
		'file' => 'lib/Service/LocationService.php',
		'search' => 'if ($parent->getParentId() !== null) {',
		'replace' => 'if ($parent->getParentId() === null) {',
	],
	[
		'name' => 'reparent-parent-with-children-allowed',
		'file' => 'lib/Service/LocationService.php',
		'search' => 'if ($parentId !== null && $this->locations->hasChildren($id)) {',
		'replace' => 'if ($parentId !== null && !$this->locations->hasChildren($id)) {',
	],
	[
		'name' => 'delete-parent-with-children-allowed',
		'file' => 'lib/Service/LocationService.php',
		'search' => 'if ($this->locations->hasChildren($id)) {
				throw new ConflictException(\'location_has_children\');',
		'replace' => 'if (!$this->locations->hasChildren($id)) {
				throw new ConflictException(\'location_has_children\');',
	],
	[
		'name' => 'self-parent-allowed',
		'file' => 'lib/Service/LocationService.php',
		'search' => 'if ($forId !== null && $parentId === $forId) {',
		'replace' => 'if ($forId !== null && $parentId !== $forId) {',
	],
	[
		'name' => 'float-parent-id-accepted',
		'file' => 'lib/Service/LocationService.php',
		'search' => "if (!is_int(\$raw) && !(is_string(\$raw) && ctype_digit(\$raw))) {",
		'replace' => "if (!is_numeric(\$raw)) {",
	],
	[
		'name' => 'acl-expansion-queries-self-not-children',
		'file' => 'lib/Service/LocationAclService.php',
		'search' => "->in('parent_id',",
		'replace' => "->in('id',",
	],
	[
		'name' => 'roots-endpoint-returns-children',
		'file' => 'lib/Db/LocationMapper.php',
		'search' => "->where(\$qb->expr()->isNull('parent_id'))",
		'replace' => "->where(\$qb->expr()->isNotNull('parent_id'))",
	],
]);
