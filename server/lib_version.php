<?php

header('Content-Type: text/plain');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';

define('VER_XOR_KEY', 0xA5);

function xorEncode(string $data): string {
    $out = '';
    for ($i = 0, $len = strlen($data); $i < $len; $i++) {
        $out .= chr(ord($data[$i]) ^ VER_XOR_KEY);
    }
    return base64_encode($out);
}

$libKillEnabled = getSetting('lib_kill_enabled', '0') === '1';
$libKillBelow   = getSetting('lib_kill_below', '0.0');
$libKillRdr     = getSetting('lib_kill_redirect', '');
$libVersion     = getSetting('lib_version', '1.0');

$clientVersion  = trim($_GET['cv'] ?? $_POST['cv'] ?? '');

$kill = 0;
if ($libKillEnabled && !empty($clientVersion)) {

    if (version_compare($clientVersion, $libKillBelow, '<')) {
        $kill = 1;
    }
} elseif ($libKillEnabled && empty($clientVersion)) {

    $kill = 1;
}

$resp = json_encode([
    'v'    => $libVersion,
    'kb'   => $libKillBelow,
    'kill' => $kill,
    'redirect_url' => ($kill ? $libKillRdr : ''),
    'ts'   => time(),
    'ok'   => 1
]);

echo xorEncode($resp);