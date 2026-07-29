<?php

declare(strict_types=1);

/**
 * Printable bulk label sheet (Wave A5 / A12) — A4 grid, ≥12 labels per page.
 * Blank layout — Print uses @media print.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$labelsHtml = (string)($_['labelsHtml'] ?? '');
$count = (int)($_['count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?php p(\OC::$server->get(\OCP\L10N\IFactory::class)->findLanguage('inventorycheck')); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php p($l->n('%n label', '%n labels', $count)); ?></title>
	<link rel="stylesheet" href="<?php p(\OC::$server->get(\OCP\IURLGenerator::class)->linkTo('inventorycheck', 'css/app.css')); ?>">
	<style>
		body.iv-label-sheet {
			margin: 0;
			padding: 24px;
			background: #f5f5f5;
			color: #111;
			font-family: system-ui, sans-serif;
		}
		.iv-label-sheet__toolbar {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 12px;
			margin-bottom: 24px;
		}
		.iv-label-sheet__count {
			font-size: 14px;
			color: #444;
		}
		.iv-label-sheet__page {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			gap: 6mm;
			max-width: 210mm;
			margin: 0 auto 12mm;
			padding: 10mm;
			background: #fff;
			box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
		}
		.iv-label-sheet__page .iv-label-code,
		.iv-label-sheet__page text#iv-label-code {
			font-size: 12pt !important;
		}
		.iv-label-tile {
			display: flex;
			align-items: center;
			justify-content: center;
			border: 1px dashed #ccc;
			padding: 2mm;
			break-inside: avoid;
		}
		.iv-label-tile svg {
			display: block;
			width: 100%;
			height: auto;
		}
		@media print {
			@page { size: A4; margin: 10mm; }
			.iv-label-sheet__toolbar { display: none !important; }
			body.iv-label-sheet { background: #fff; padding: 0; }
			.iv-label-sheet__page {
				box-shadow: none;
				max-width: none;
				width: 100%;
				margin: 0;
				page-break-after: always;
			}
			.iv-label-tile { border: none; }
		}
	</style>
</head>
<body class="iv-label-sheet">
	<div class="iv-label-sheet__toolbar" role="toolbar" aria-label="<?php p($l->t('Label sheet actions')); ?>">
		<button type="button" class="button primary" id="iv-label-sheet-print-btn"><?php p($l->t('Print labels')); ?></button>
		<a class="button" href="<?php p((string)($_['backUrl'] ?? '/')); ?>"><?php p($l->t('Back to items')); ?></a>
		<span class="iv-label-sheet__count"><?php p($l->n('%n label', '%n labels', $count)); ?></span>
	</div>
	<main aria-label="<?php p($l->t('Label sheet')); ?>">
		<?php print_unescaped($labelsHtml); ?>
	</main>
	<script>
		document.getElementById('iv-label-sheet-print-btn')?.addEventListener('click', function () {
			window.print();
		});
	</script>
</body>
</html>
