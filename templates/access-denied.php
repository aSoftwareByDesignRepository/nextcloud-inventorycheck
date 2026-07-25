<?php

declare(strict_types=1);

/**
 * Access denied page (L2 gate).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div class="iv-app iv-app--denied" id="app-content">
	<div class="iv-shell">
		<section class="iv-empty iv-empty-state iv-empty--denied" role="alert" aria-labelledby="iv-denied-title">
			<div class="iv-empty__main iv-empty-state__main">
				<h1 id="iv-denied-title" class="iv-empty__title"><?php p($l->t('Access denied')); ?></h1>
				<p class="iv-empty__text"><?php p((string)($_['message'] ?? '')); ?></p>
				<p class="iv-empty__hint"><?php p((string)($_['hint'] ?? '')); ?></p>
			</div>
			<div class="iv-empty__action iv-empty-state__action">
				<a class="button primary iv-btn iv-btn--primary" href="<?php p((string)($_['homeUrl'] ?? '/')); ?>">
					<?php p($l->t('Back to Nextcloud')); ?>
				</a>
			</div>
		</section>
	</div>
</div>
