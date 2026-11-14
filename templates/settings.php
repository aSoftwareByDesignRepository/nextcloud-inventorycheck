<?php
declare(strict_types=1);
/** @var array $_ */
/** @var \OCP\IL10N $l */
require __DIR__ . '/common/page-start.php';

$ivSettingsSection = (string)($_['settingsSection'] ?? '');
if ($ivSettingsSection !== '' && !empty($_['isAppAdmin'])) {
	require __DIR__ . '/parts/settings-nav.php';
}
?>
<div id="iv-page-root" class="iv-page-root" aria-busy="true">
	<p class="iv-loading"><?php p($l->t('Loading…')); ?></p>
</div>
<?php
if ($ivSettingsSection === 'license' && !empty($_['isAppAdmin'])) {
	// Server-rendered shared license page — js/app.js relocates it into the
	// settingsPageSection('license', …) shell inside #iv-page-root.
	echo '<div id="iv-license-server-part">';
	require __DIR__ . '/parts/settings/license.php';
	echo '</div>';
}
if ($ivSettingsSection === 'transfer' && !empty($_['isAppAdmin'])) {
	// Server-rendered transfer cards — js/app.js wraps them into the
	// settingsPageSection('transfer', …) shell inside #iv-page-root.
	echo '<div id="iv-transfer-server-part">';
	require __DIR__ . '/parts/settings/transfer.php';
	echo '</div>';
}
if ($ivSettingsSection === 'support' && !empty($_['isAppAdmin'])) {
	$supportUsLinks = new \OCA\InventoryCheck\Support\SupportUsLinks(
		'InventoryCheck',
		true,
		!empty($_['supportUsLicenseUrl']) ? (string)$_['supportUsLicenseUrl'] : null,
	);
	$supportUsCssPrefix = 'iv';
	$supportUsBtnPrimaryClass = 'button primary';
	$supportUsBtnSecondaryClass = 'button';
	require __DIR__ . '/parts/support-us-section.php';
}
require __DIR__ . '/common/page-end.php';
