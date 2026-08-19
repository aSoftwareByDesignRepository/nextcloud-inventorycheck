<?php

declare(strict_types=1);

/**
 * Nav footer: popover with Report / Suggest / GitHub links.
 *
 * Expected variables (set by the including template):
 * @var \OCP\IL10N $l
 * @var \OCA\InventoryCheck\Support\AppFeedbackLinks $appFeedbackLinks optional; constructed when omitted
 * @var string $appFeedbackCssPrefix CSS BEM prefix (e.g. azc, dc, crm)
 * @var string|null $appFeedbackLanguageCode
 * @var string|null $appFeedbackVersion
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

use OCA\InventoryCheck\Support\AppFeedbackLinks;

$l = $l ?? (\OCP\Util::getL10N('inventorycheck'));
$prefix = isset($appFeedbackCssPrefix) && is_string($appFeedbackCssPrefix) && $appFeedbackCssPrefix !== ''
	? preg_replace('/[^a-z0-9\-]/i', '', $appFeedbackCssPrefix)
	: 'iv';
$lang = isset($appFeedbackLanguageCode) && is_string($appFeedbackLanguageCode) && $appFeedbackLanguageCode !== ''
	? $appFeedbackLanguageCode
	: (method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en');
$version = isset($appFeedbackVersion) && is_string($appFeedbackVersion) ? $appFeedbackVersion : '';
if (!isset($appFeedbackLinks) || !$appFeedbackLinks instanceof AppFeedbackLinks) {
	$appFeedbackLinks = new AppFeedbackLinks('inventorycheck', 'InventoryCheck', $version);
}
$pageUrl = '';
if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
	$pageUrl = $appFeedbackLinks->sanitizePageUrl((string)$_SERVER['REQUEST_URI']);
}
$ncVersion = '';
if (class_exists(\OCP\Server::class)) {
	try {
		$config = \OCP\Server::get(\OCP\IConfig::class);
		$ncVersion = (string)$config->getSystemValue('version', '');
	} catch (\Throwable) {
		$ncVersion = '';
	}
}
$ctx = [
	'pageUrl' => $pageUrl,
	'locale' => $lang,
	'ncVersion' => $ncVersion,
];
$links = $appFeedbackLinks->forLocale($lang, $ctx);
$github = (string)($links['githubIssuesUrl'] ?? '');
$footerId = $prefix . '-nav-footer';
$menuId = $prefix . '-nav-footer-menu';
$newTab = $l->t('(opens in a new tab)');
?>
<div
	class="<?php p($prefix); ?>-nav-footer"
	id="<?php p($footerId); ?>"
	data-app-feedback="1"
	data-app-feedback-app="<?php p((string)$links['appId']); ?>"
>
	<button
		type="button"
		class="<?php p($prefix); ?>-nav-footer__trigger"
		aria-expanded="false"
		aria-controls="<?php p($menuId); ?>"
		aria-haspopup="true"
	><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="iv-icon" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg><?php p($l->t('Help & Feedback')); ?></button>

	<nav
		class="<?php p($prefix); ?>-nav-footer__menu"
		id="<?php p($menuId); ?>"
		role="menu"
		aria-label="<?php p($l->t('Help & Feedback')); ?>"
		hidden
	>
		<a
			class="<?php p($prefix); ?>-nav-footer__menu-item"
			id="<?php p($prefix); ?>-feedback-problem"
			href="<?php p((string)$links['problemMailto']); ?>"
			role="menuitem"
			data-app-feedback-kind="problem"
		><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="iv-icon" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg><?php p($l->t('Report a problem')); ?></a>

		<a
			class="<?php p($prefix); ?>-nav-footer__menu-item"
			id="<?php p($prefix); ?>-feedback-idea"
			href="<?php p((string)$links['ideaMailto']); ?>"
			role="menuitem"
			data-app-feedback-kind="idea"
		><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="iv-icon" width="20" height="20" aria-hidden="true" focusable="false"><path d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg><?php p($l->t('Suggest an improvement')); ?></a>

		<?php if ($github !== ''): ?>
		<a
			class="<?php p($prefix); ?>-nav-footer__menu-item"
			id="<?php p($prefix); ?>-feedback-github"
			href="<?php p($github); ?>"
			target="_blank"
			rel="noopener noreferrer"
			role="menuitem"
		><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="iv-icon" width="20" height="20" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg><?php p($l->t('Open GitHub Issues')); ?><span class="<?php p($prefix); ?>-nav-footer__new-tab"><?php p($newTab); ?></span></a>
		<?php endif; ?>

		<p class="<?php p($prefix); ?>-nav-footer__disclaimer"><?php p($l->t('Email is best-effort — no reply SLA. Need booked help? Use Support & us.')); ?></p>
	</nav>

	<script type="application/json" id="<?php p($prefix); ?>-app-feedback-config"><?php
		print_unescaped(json_encode([
			'appId' => $links['appId'],
			'appDisplayName' => $links['appDisplayName'],
			'appVersion' => $links['appVersion'],
			'feedbackEmail' => $links['feedbackEmail'],
			'githubIssuesUrl' => $github,
			'problemMailto' => $links['problemMailto'],
			'ideaMailto' => $links['ideaMailto'],
			'cssPrefix' => $prefix,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP));
	?></script>
</div>
