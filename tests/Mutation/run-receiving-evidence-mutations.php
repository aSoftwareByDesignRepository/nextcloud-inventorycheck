#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/harness.php';

// C-2 receiving-evidence — flip-prone lines: receive-kind fence, uniform-404
// visibility gate, hasDeliveryNote semantics, upload cap/whitelist.
runMutations(dirname(__DIR__, 2), 'MovementEvidence', [
	[
		'name' => 'kind-fence-eq-becomes-neq',
		'file' => 'lib/Service/MovementEvidenceService.php',
		'search' => "if (\$mov->getKind() !== 'receive') {",
		'replace' => "if (\$mov->getKind() === 'receive') {",
	],
	[
		'name' => 'visibility-membership-inverted',
		'file' => 'lib/Service/MovementEvidenceService.php',
		'search' => '!in_array($mov->getLocationId(), $visible, true)',
		'replace' => 'in_array($mov->getLocationId(), $visible, true)',
	],
	[
		'name' => 'visible-null-guard-dropped',
		'file' => 'lib/Service/MovementEvidenceService.php',
		'search' => 'if ($visible !== null && !in_array($mov->getLocationId(), $visible, true)) {',
		'replace' => 'if (!in_array($mov->getLocationId(), $visible ?? [], true)) {',
	],
	[
		'name' => 'note-empty-check-dropped',
		'file' => 'lib/Service/MovementEvidenceService.php',
		'search' => "if (\$name === null || \$name === '') {",
		'replace' => 'if ($name === null) {',
	],
	[
		'name' => 'max-bytes-shrinks',
		'file' => 'lib/Service/MovementEvidenceService.php',
		'search' => 'public const MAX_BYTES = 10_485_760;',
		'replace' => 'public const MAX_BYTES = 1_048_576;',
	],
	[
		'name' => 'flag-loses-empty-check',
		'file' => 'lib/Db/Movement.php',
		'search' => "'hasDeliveryNote' => \$this->deliveryNotePath !== null && \$this->deliveryNotePath !== '',",
		'replace' => "'hasDeliveryNote' => \$this->deliveryNotePath !== null,",
	],
]);
