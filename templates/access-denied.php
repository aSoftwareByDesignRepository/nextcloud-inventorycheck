<?php

declare(strict_types=1);

/**
 * Access denied page (L2 gate).
 * Minimal shell with skip link + main landmark (WCAG 2.4.1 / design-system).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\InventoryCheck\Support\IconCatalog;

$message = (string)($_['message'] ?? '');
$hint = (string)($_['hint'] ?? '');
$homeUrl = (string)($_['homeUrl'] ?? '/');
?>
<div class="iv-app iv-app--denied" id="app-content">
	<a class="iv-skip-link" href="#iv-denied-main"><?php p($l->t('Skip to main content')); ?></a>
	<div class="iv-shell">
		<main id="iv-denied-main" class="iv-main" tabindex="-1">
			<section class="iv-empty iv-empty-state iv-empty--denied" role="alert" aria-labelledby="iv-denied-title">
				<div class="iv-empty__icon iv-empty-state__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render('shield', 'iv-empty__icon-svg')); ?>
				</div>
				<div class="iv-empty__main iv-empty-state__main">
					<h1 id="iv-denied-title" class="iv-empty__title"><?php p($l->t('Access denied')); ?></h1>
					<p class="iv-empty__text"><?php p($message); ?></p>
					<?php if ($hint !== ''): ?>
						<p class="iv-empty__hint"><?php p($hint); ?></p>
					<?php endif; ?>
				</div>
				<div class="iv-empty__action iv-empty-state__action">
					<a class="button primary iv-btn iv-btn--primary" href="<?php p($homeUrl); ?>">
						<?php p($l->t('Back to Nextcloud')); ?>
					</a>
				</div>
			</section>
		</main>
	</div>
</div>
