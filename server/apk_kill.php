<?php

header('Content-Type: text/plain');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';

define('APK_XOR_KEY', 0xA5);

function xorEncode(string $data): string {
    $out = '';
    for ($i = 0, $len = strlen($data); $i < $len; $i++) {
        $out .= chr(ord($data[$i]) ^ APK_XOR_KEY);
    }
    return base64_encode($out);
}

$killEnabled    = getSetting('apk_kill_enabled', '0') === '1';
$killVersionTgt = getSetting('apk_kill_version_target', '');
$killMessage    = getSetting('apk_kill_message', 'This version has been expired. Please update.');
$killRedirect   = getSetting('apk_kill_redirect', '');
$latestVersion  = getSetting('apk_version', '1.0.0');
$apkUrl         = getSetting('apk_url', '');

$clientVersion  = trim($_GET['cv'] ?? $_POST['cv'] ?? '');

$killed       = 0;
$forceUpdate  = 0;
$popupMessage = '';
$redirectUrl  = '';

if ($killEnabled) {

    if (!empty($killVersionTgt)) {
        $targets = array_map('trim', explode(',', $killVersionTgt));
        if (in_array($clientVersion, $targets) || in_array('*', $targets)) {
            $killed       = 1;
            $popupMessage = $killMessage;
            $redirectUrl  = $killRedirect;
        }
    }


    if (!$killed && !empty($clientVersion)) {
        if (version_compare($clientVersion, $latestVersion, '<')) {
            $forceUpdate = 1;
        }
    }
}

$resp = json_encode([
    'kill'          => $killed,
    'popup_msg'     => $popupMessage,
    'redirect_url'  => $redirectUrl,
    'force_update'  => $forceUpdate,
    'latest_ver'    => $latestVersion,
    'apk_url'       => $apkUrl,
    'ts'            => time(),
    'ok'            => 1
]);

echo xorEncode($resp);