<?php

declare(strict_types=1);

/**
 * Common page opening — ArbeitszeitCheck shell parity.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\InventoryCheck\Support\IconCatalog;

$pageId = (string)$_['pageId'];
$pageTitle = (string)$_['pageTitle'];
$pageHint = (string)$_['pageHint'];
$entityId = $_['entityId'] ?? null;
$currentUserId = (string)$_['currentUserId'];
$isAppAdmin = !empty($_['isAppAdmin']);
$isSystemAdmin = !empty($_['isSystemAdmin']);
$isOffice = !empty($_['isOffice']);
$mobileAppStatus = (string)$_['mobileAppStatus'];
$urlsJson = (string)$_['urlsJson'];
$allowNegativeStock = !empty($_['allowNegativeStock']);
$locationReorderHintEnabled = !empty($_['locationReorderHintEnabled']);
$qtyScale = (int)($_['qtyScale'] ?? 0);
$locationAclEnabled = !empty($_['locationAclEnabled']);
$timezone = (string)($_['timezone'] ?? 'UTC');
$roleLabel = (string)($_['roleLabel'] ?? ($isAppAdmin ? $l->t('Administrator') : ($isOffice ? $l->t('Office') : $l->t('Field'))));
$htmlLang = str_replace('_', '-', $l->getLanguageCode());

$pageIcons = [
	'dashboard' => 'layout-grid',
	'items' => 'list-checks',
	'item-detail' => 'list-checks',
	'locations' => 'map-pin',
	'location-detail' => 'map-pin',
	'movements' => 'history',
	'stocktake' => 'clipboard-list',
	'settings' => 'settings',
	'access-denied' => 'shield',
];
$headerIcon = $pageIcons[$pageId] ?? 'inbox';

$navUrls = json_decode($urlsJson, true)['pages'] ?? [];
$homeUrl = (string)($navUrls['dashboard'] ?? '#');

require __DIR__ . '/navigation.php';
?>
<div id="app-content" class="iv-app iv-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-iv-page="<?php p($pageId); ?>"
	<?php if ($entityId !== null): ?>data-iv-entity-id="<?php p((string)$entityId); ?>"<?php endif; ?>
	data-iv-current-user="<?php p($currentUserId); ?>"
	data-iv-is-app-admin="<?php p($isAppAdmin ? '1' : '0'); ?>"
	data-iv-is-system-admin="<?php p($isSystemAdmin ? '1' : '0'); ?>"
	data-iv-is-office="<?php p($isOffice ? '1' : '0'); ?>"
	data-iv-allow-negative="<?php p($allowNegativeStock ? '1' : '0'); ?>"
	data-iv-location-reorder-hint="<?php p($locationReorderHintEnabled ? '1' : '0'); ?>"
	data-iv-qty-scale="<?php p((string)$qtyScale); ?>"
	data-iv-location-acl="<?php p($locationAclEnabled ? '1' : '0'); ?>"
	data-iv-mobile-app-status="<?php p($mobileAppStatus); ?>"
	data-iv-timezone="<?php p($timezone); ?>"
	data-iv-urls="<?php p($urlsJson); ?>">
	<a class="iv-skip-link" href="#iv-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="iv-live-region" class="iv-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="iv-alert-region" class="iv-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="iv-toast-region" class="iv-toast-region" role="region" aria-label="<?php p($l->t('Notifications')); ?>"></div>
	<div id="app-content-wrapper" class="iv-shell">
		<header class="iv-page-header" aria-labelledby="iv-page-title">
			<nav class="iv-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol class="iv-breadcrumb__list">
					<li class="iv-breadcrumb__item">
						<a class="iv-breadcrumb__link" href="<?php p($homeUrl); ?>"><?php p($l->t('InventoryCheck')); ?></a>
					</li>
					<li class="iv-breadcrumb__item iv-breadcrumb__item--current" aria-current="page">
						<span class="iv-breadcrumb__current"><?php p($pageTitle); ?></span>
					</li>
				</ol>
			</nav>
			<div class="iv-page-header__main">
				<div class="iv-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($headerIcon, 'iv-page-header__icon-svg')); ?>
				</div>
				<div class="iv-page-header__text">
					<h1 id="iv-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHint !== ''): ?>
						<p class="iv-page-header__lead"><?php p($pageHint); ?></p>
					<?php endif; ?>
				</div>
				<div id="iv-page-actions" class="iv-page-header__actions" aria-live="polite"></div>
			</div>
			<div class="iv-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<span class="iv-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="iv-badge iv-badge--neutral iv-scope-strip__badge"><?php p($roleLabel); ?></span>
				<span class="iv-scope-strip__sep" aria-hidden="true">·</span>
				<span class="iv-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="iv-scope-strip__value"><?php p($timezone); ?></span>
			</div>
		</header>
		<main id="iv-main-content" class="iv-main" tabindex="-1" aria-labelledby="iv-page-title">
