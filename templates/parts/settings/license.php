<?php

declare(strict_types=1);

/**
 * InventoryCheck — license host wrapper.
 *
 * The license UX is the canonical shared Check-family license page
 * (templates/common/license-page.php, synced from _shared/license-page).
 * This wrapper only adapts InventoryCheck's service shape to the shared
 * contract — do not put markup here.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @license AGPL-3.0-or-later
 */

$l = $l ?? (isset($_['l']) ? $_['l'] : null);
if ($l === null) {
	try {
		$l = \OCP\Util::getL10N('inventorycheck');
	} catch (\Throwable) {
		$l = new class { public function t($s, $p = []) { return $s; } };
	}
}

$licenseStatus = is_array($_['licenseStatus'] ?? null) ? $_['licenseStatus'] : null;
$licenseState = is_array($licenseStatus['state'] ?? null) ? $licenseStatus['state'] : null;
$licenseSeatsCounts = is_array($licenseStatus['seats'] ?? null) ? $licenseStatus['seats'] : ['assigned' => 0, 'limit' => 0];
$licenseDeviceCounts = is_array($licenseStatus['devices'] ?? null) ? $licenseStatus['devices'] : ['active' => 0, 'limit' => 0];

$seatRowsRaw = is_array($_['licenseSeatsList']['data'] ?? null) ? $_['licenseSeatsList']['data'] : [];
$seatRows = [];
foreach ($seatRowsRaw as $row) {
	if (!is_array($row)) {
		continue;
	}
	$uid = (string)($row['uid'] ?? $row['userId'] ?? $row['id'] ?? '');
	if ($uid === '') {
		continue;
	}
	$assignedAt = $row['assignedAt'] ?? '';
	$seatRows[] = [
		'userId' => $uid,
		'displayName' => (string)($row['displayName'] ?? $uid),
		'assignedAt' => is_int($assignedAt) ? date('c', $assignedAt) : (string)$assignedAt,
	];
}

$deviceRowsRaw = is_array($_['licenseDevicesList']['data'] ?? null) ? $_['licenseDevicesList']['data'] : [];
$terminals = [];
foreach ($deviceRowsRaw as $row) {
	if (!is_array($row)) {
		continue;
	}
	$id = (string)($row['id'] ?? '');
	if ($id === '' || empty($row['active'])) {
		continue;
	}
	$createdAt = $row['createdAt'] ?? '';
	$terminals[] = [
		'id' => $id,
		'label' => (string)($row['label'] ?? ''),
		'meta' => (string)($row['state'] ?? ''),
		'registeredAt' => is_int($createdAt) ? date('c', $createdAt) : (string)$createdAt,
	];
}

$license = $licenseState === null ? null : [
	'active' => !empty($licenseState['valid']),
	'cryptographicallyValid' => true,
	'dateValid' => (string)($licenseState['validUntil'] ?? '') !== '',
	'validUntil' => (string)($licenseState['validUntil'] ?? ''),
	'customerId' => (string)($licenseState['customerId'] ?? ''),
];

$mobileLimit = (int)($licenseSeatsCounts['limit'] ?? ($licenseState['mobileSeats'] ?? 0));
$deviceLimit = (int)($licenseDeviceCounts['limit'] ?? ($licenseState['scanDevices'] ?? 0));
$urlGenerator = $_['urlGenerator'] ?? null;
if ($urlGenerator === null) {
	try {
		$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
	} catch (\Throwable) {
		$urlGenerator = null;
	}
}

$devicesBase = $urlGenerator ? $urlGenerator->linkToRoute('inventorycheck.license.devices') : '';

$_['license'] = $license;
$_['productCode'] = 'INV2';
$_['mobileSeatsUsed'] = (int)($licenseSeatsCounts['assigned'] ?? 0);
$_['mobileSeatsLimit'] = $mobileLimit;
$_['mobileSeats'] = $seatRows;
$_['showMobileSeats'] = $mobileLimit > 0;
$_['terminals'] = $terminals;
$_['terminalDevicesUsed'] = (int)($licenseDeviceCounts['active'] ?? count($terminals));
$_['terminalDevicesLimit'] = $deviceLimit;
$_['showTerminal'] = true;
$_['terminalMode'] = 'register';
$_['deviceStrings'] = [
	'title' => $l->t('Scanner devices'),
	'lead' => $l->t('Pair shared scanners in the InventoryCheck mobile app. Each pairing code is shown once — copy it into the app right away.'),
	'label' => $l->t('Scanner devices'),
	'registerLabel' => $l->t('Create device slot'),
	'nameLabel' => $l->t('Device label'),
	'namePlaceholder' => $l->t('e.g. Warehouse scanner 1'),
	'emptyTitle' => $l->t('No scanner slots yet.'),
	'emptyText' => $l->t('Create a device slot above to pair a scanner.'),
	'colLabel' => $l->t('Label'),
	'colDetail' => $l->t('State'),
	'colRegistered' => $l->t('Created'),
	'revokeLabel' => $l->t('Remove'),
	'regenLabel' => $l->t('New pairing code'),
];
$_['apiLicenseUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('inventorycheck.license.apply') : '';
$_['apiClearLicenseUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('inventorycheck.license.remove') : '';
$_['apiSeatsUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('inventorycheck.license.seats') : '';
$_['apiSeatsBatchUrl'] = '';
$_['apiRemoveSeatUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('inventorycheck.license.removeSeatPost') : '';
$_['apiSearchUsersUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('inventorycheck.directory.searchUsers') : '';
$_['apiRegisterDeviceUrl'] = $devicesBase;
$_['apiRevokeDeviceUrl'] = $devicesBase;
$_['apiRegenDeviceUrl'] = $devicesBase !== '' ? $devicesBase . '/{id}/pair-code' : '';
$_['licenseRenewMailto'] = 'mailto:info@software-by-design.de?subject=' . rawurlencode('InventoryCheck mobile & scanner licenses');
$_['productsUrl'] = 'https://nextcloud.software-by-design.de/';
try {
	$_['requesttoken'] = (string)($_['requesttoken'] ?? \OCP\Util::callRegister());
} catch (\Throwable) {
	$_['requesttoken'] = (string)($_['requesttoken'] ?? '');
}

// Keep the legacy deep-link anchor (#iv-license) working.
echo '<div id="iv-license-anchor">';
include __DIR__ . '/../../common/license-page.php';
echo '</div>';
