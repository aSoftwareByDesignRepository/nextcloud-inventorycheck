<?php

declare(strict_types=1);

use OCA\InventoryCheck\Support\IconCatalog;
use OCP\Util;

/**
 * Sidebar navigation — ArbeitszeitCheck shell parity (nav-menu + collapsible admin).
 *
 * @var \OCP\IL10N $l
 * @var string $pageId
 * @var bool $isAppAdmin
 * @var bool $isOffice
 * @var string $urlsJson
 * @var string $roleLabel
 */

Util::addScript('inventorycheck', 'common/navigation');

$navUrls = json_decode($urlsJson, true)['pages'] ?? [];
$ivNavIcon = static function (string $name): string {
	return IconCatalog::render($name, 'iv-nav__icon-svg');
};

$activeNavId = match ($pageId) {
	'item-detail' => 'items',
	'location-detail' => 'locations',
	default => $pageId,
};

$isDashboard = $activeNavId === 'dashboard';
$isItems = $activeNavId === 'items';
$isLocations = $activeNavId === 'locations';
$isMovements = $activeNavId === 'movements';
$isStocktake = $activeNavId === 'stocktake';
$isSettings = $activeNavId === 'settings';
$isAdmin = $isSettings;
?>
<div id="inventorycheck-app" class="inventorycheck-app">
	<a href="#app-navigation" class="skip-link iv-skip-link--nav"><?php p($l->t('Skip to app navigation')); ?></a>

	<div id="app-navigation" class="iv-nav" role="navigation" aria-label="<?php p($l->t('Main navigation')); ?>">
		<div class="sidebar-header">
			<div class="app-brand">
				<div class="app-icon" aria-hidden="true">
					<span class="iv-nav__icon"><?php print_unescaped($ivNavIcon('inbox')); ?></span>
				</div>
				<div class="app-info">
					<h3><?php p($l->t('InventoryCheck')); ?></h3>
					<p class="app-brand__subtitle"><?php p($l->t('Stock & movements')); ?></p>
					<p class="app-brand__role"><?php p($roleLabel); ?></p>
				</div>
			</div>
		</div>

		<ul class="nav-menu">
			<li class="<?php p($isDashboard ? 'active' : ''); ?>" <?php if ($isDashboard): ?>aria-current="page"<?php endif; ?>>
				<a href="<?php p((string)($navUrls['dashboard'] ?? '#')); ?>"
					title="<?php p($l->t('Dashboard: Low stock and recent bookings')); ?>"
					aria-label="<?php p($l->t('Go to dashboard')); ?>">
					<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('layout-grid')); ?></span>
					<span><?php p($l->t('Dashboard')); ?></span>
				</a>
			</li>
			<li class="<?php p($isItems ? 'active' : ''); ?>" <?php if ($isItems): ?>aria-current="page"<?php endif; ?>>
				<a href="<?php p((string)($navUrls['items'] ?? '#')); ?>"
					title="<?php p($l->t('Items: SKU and scan codes')); ?>"
					aria-label="<?php p($l->t('Go to items')); ?>">
					<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('list-checks')); ?></span>
					<span><?php p($l->t('Items')); ?></span>
				</a>
			</li>
			<li class="<?php p($isLocations ? 'active' : ''); ?>" <?php if ($isLocations): ?>aria-current="page"<?php endif; ?>>
				<a href="<?php p((string)($navUrls['locations'] ?? '#')); ?>"
					title="<?php p($l->t('Locations: Warehouse, van, site')); ?>"
					aria-label="<?php p($l->t('Go to locations')); ?>">
					<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('map-pin')); ?></span>
					<span><?php p($l->t('Locations')); ?></span>
				</a>
			</li>
			<li class="<?php p($isMovements ? 'active' : ''); ?>" <?php if ($isMovements): ?>aria-current="page"<?php endif; ?>>
				<a href="<?php p((string)($navUrls['movements'] ?? '#')); ?>"
					title="<?php p($l->t('Movements: Booking history')); ?>"
					aria-label="<?php p($l->t('Go to movements')); ?>">
					<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('history')); ?></span>
					<span><?php p($l->t('Movements')); ?></span>
				</a>
			</li>
			<?php if ($isOffice): ?>
				<li class="<?php p($isStocktake ? 'active' : ''); ?>" <?php if ($isStocktake): ?>aria-current="page"<?php endif; ?>>
					<a href="<?php p((string)($navUrls['stocktake'] ?? '#')); ?>"
						title="<?php p($l->t('Stocktake: Cycle counts and Inventur campaigns')); ?>"
						aria-label="<?php p($l->t('Go to stocktake')); ?>">
						<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('clipboard-list')); ?></span>
						<span><?php p($l->t('Stocktake')); ?></span>
					</a>
				</li>
			<?php endif; ?>
			<?php if ($isAppAdmin): ?>
				<li class="nav-section-divider" role="separator" aria-hidden="true"></li>
				<li class="nav-item-has-children <?php p($isAdmin ? 'is-open' : ''); ?>">
					<button class="nav-parent-toggle"
						type="button"
						aria-expanded="<?php p($isAdmin ? 'true' : 'false'); ?>"
						aria-controls="iv-admin-subnav">
						<span class="iv-nav__icon" aria-hidden="true"><?php print_unescaped($ivNavIcon('shield')); ?></span>
						<span><?php p($l->t('Administration')); ?></span>
						<span class="nav-parent-chevron" aria-hidden="true"></span>
					</button>
					<ul id="iv-admin-subnav" class="nav-submenu" <?php p($isAdmin ? '' : 'hidden'); ?>>
						<li class="<?php p($isSettings ? 'active' : ''); ?>" <?php if ($isSettings): ?>aria-current="page"<?php endif; ?>>
							<a href="<?php p((string)($navUrls['settings'] ?? '#')); ?>"
								title="<?php p($l->t('Access, license, support')); ?>"
								aria-label="<?php p($l->t('Open settings')); ?>">
								<span><?php p($l->t('Settings')); ?></span>
							</a>
						</li>
					</ul>
				</li>
			<?php endif; ?>
		</ul>
	</div>
