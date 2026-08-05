<?php

declare(strict_types=1);

/**
 * In-page settings chip bar (DeskCheck / DutyCheck parity).
 *
 * Nextcloud collapses #app-navigation below ~1024px; without this bar admins
 * cannot reach sibling settings pages on phones/tablets. Labels and URLs come
 * from SettingsSectionCatalog via the controller — never hardcoded here.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$ivSettingsSection = (string)($_['settingsSection'] ?? '');
$ivNavLabels = (array)($_['settingsSectionLabels'] ?? []);
$ivNavUrls = (array)(($_['urls']['settingsSections'] ?? []) ?: []);
if ($ivNavLabels === []) {
	$decoded = json_decode((string)($_['urlsJson'] ?? ''), true);
	if (is_array($decoded)) {
		$ivNavUrls = (array)($decoded['settingsSections'] ?? []);
	}
}
if ($ivNavLabels === [] || $ivNavUrls === []) {
	return;
}
?>
<nav class="iv-settings-nav" id="iv-settings-pages" aria-label="<?php p($l->t('Settings pages')); ?>">
	<?php foreach ($ivNavLabels as $sectionId => $sectionLabel):
		$sectionId = (string)$sectionId;
		$href = (string)($ivNavUrls[$sectionId] ?? '');
		if ($href === '' || $href === '#') {
			continue;
		}
		$active = $ivSettingsSection === $sectionId;
		?>
		<a class="iv-settings-nav__link<?php p($active ? ' is-active' : ''); ?>"
			href="<?php p($href); ?>"
			<?php if ($active): ?>aria-current="page"<?php endif; ?>>
			<?php p((string)$sectionLabel); ?>
		</a>
	<?php endforeach; ?>
</nav>
