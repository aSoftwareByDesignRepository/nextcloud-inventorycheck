#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

$search = "\t\t\tif (\$a['assignedAt'] !== \$b['assignedAt']) {\n\t\t\t\treturn \$a['assignedAt'] <=> \$b['assignedAt'];\n\t\t\t}";
$replace = "\t\t\tif (false) {\n\t\t\t\treturn \$a['assignedAt'] <=> \$b['assignedAt'];\n\t\t\t}";

runMutations(dirname(__DIR__, 2), 'SeatRankTest|DeviceRankTest', [
	[
		'name' => 'seat-ignore-assigned-at',
		'file' => 'lib/Service/SeatRank.php',
		'search' => $search,
		'replace' => $replace,
	],
	['name' => 'seat-rank-cmp-flipped', 'file' => 'lib/Service/SeatRank.php', 'search' => 'return $ranks[$seatId] <= $limit;', 'replace' => 'return $ranks[$seatId] < $limit;'],
	['name' => 'seat-limit-zero-allows', 'file' => 'lib/Service/SeatRank.php', 'search' => "if (\$limit <= 0) {\n\t\t\treturn false;\n\t\t}", 'replace' => "if (\$limit <= 0) {\n\t\t\treturn true;\n\t\t}"],
	['name' => 'seat-missing-id-allowed', 'file' => 'lib/Service/SeatRank.php', 'search' => "if (!isset(\$ranks[\$seatId])) {\n\t\t\treturn false;\n\t\t}", 'replace' => "if (false) {\n\t\t\treturn false;\n\t\t}"],
	['name' => 'device-rank-cmp-flipped', 'file' => 'lib/Service/DeviceRank.php', 'search' => 'return $ranks[$deviceId] <= $limit;', 'replace' => 'return $ranks[$deviceId] < $limit;'],
	['name' => 'device-limit-zero-allows', 'file' => 'lib/Service/DeviceRank.php', 'search' => "if (\$limit <= 0) {\n\t\t\treturn false;\n\t\t}", 'replace' => "if (\$limit <= 0) {\n\t\t\treturn true;\n\t\t}"],
	['name' => 'device-id-tiebreak-flipped', 'file' => 'lib/Service/DeviceRank.php', 'search' => 'return $a[\'id\'] <=> $b[\'id\'];', 'replace' => 'return $b[\'id\'] <=> $a[\'id\'];'],
]);
