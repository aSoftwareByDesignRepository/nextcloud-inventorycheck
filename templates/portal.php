<?php

declare(strict_types=1);
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="iv-portal-root" class="iv-page-root" data-token="<?php p($_['token'] ?? ''); ?>" aria-busy="true">
	<h1 class="iv-section__title"><?php p($l->t('Your lending page')); ?></h1>
	<p class="iv-muted" role="status"><?php p($l->t('Loading…')); ?></p>
</div>
