<?php

declare(strict_types=1);

/**
 * Printable item label (UJ-1 / A12). Blank layout — Print uses @media print.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$item = $_['item'] ?? [];
$svg = (string)($_['svg'] ?? '');
$name = (string)($item['name'] ?? '');
$scan = (string)($item['scanCode'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?php p(\OC::$server->get(\OCP\L10N\IFactory::class)->findLanguage('inventorycheck')); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php p($l->t('Label') . ': ' . $scan); ?></title>
	<link rel="stylesheet" href="<?php p(\OC::$server->get(\OCP\IURLGenerator::class)->linkTo('inventorycheck', 'css/app.css')); ?>">
	<style>
		body.iv-label-print {
			margin: 0;
			padding: 24px;
			background: #fff;
			color: #111;
			font-family: system-ui, sans-serif;
		}
		.iv-label-print__toolbar {
			display: flex;
			flex-wrap: wrap;
			gap: 12px;
			margin-bottom: 24px;
		}
		.iv-label-print__sheet {
			max-width: 360px;
		}
		.iv-label-print__sheet svg {
			display: block;
			width: 100%;
			height: auto;
		}
		.iv-label-print__code {
			margin-top: 12px;
			font-size: 16px;
			font-weight: 700;
			font-family: ui-monospace, monospace;
			user-select: all;
		}
		@media print {
			.iv-label-print__toolbar { display: none !important; }
			body.iv-label-print { padding: 0; }
			#iv-label-code, .iv-label-print__code { font-size: 12pt !important; }
		}
	</style>
</head>
<body class="iv-label-print">
	<div class="iv-label-print__toolbar" role="toolbar" aria-label="<?php p($l->t('Label actions')); ?>">
		<button type="button" class="button primary" id="iv-label-print-btn"><?php p($l->t('Print label')); ?></button>
		<a class="button" href="<?php p((string)($_['downloadUrl'] ?? '#')); ?>"><?php p($l->t('Download SVG')); ?></a>
		<a class="button" href="<?php p((string)($_['backUrl'] ?? '/')); ?>"><?php p($l->t('Back to item')); ?></a>
	</div>
	<main class="iv-label-print__sheet" aria-labelledby="iv-label-heading">
		<h1 id="iv-label-heading" class="iv-sr-only"><?php p($name !== '' ? $name : $scan); ?></h1>
		<?php print_unescaped($svg); ?>
		<p class="iv-label-print__code" id="iv-label-code"><?php p($scan); ?></p>
	</main>
	<script>
		document.getElementById('iv-label-print-btn')?.addEventListener('click', function () {
			window.print();
		});
	</script>
</body>
</html>
