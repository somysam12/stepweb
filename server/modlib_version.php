<?php

header('Content-Type: text/plain');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';

define('MOD_XOR_KEY', 0xA5);
function xorEncode(string $data): string {
    $out = '';
    for ($i = 0, $len = strlen($data); $i < $len; $i++) $out .= chr(ord($data[$i]) ^ MOD_XOR_KEY);
    return base64_encode($out);
}

$modVer  = getSetting('modlib_version', '1.0');
$modKill  = getSetting('modlib_kill_enabled', '0') === '1';
$modRdr   = getSetting('modlib_kill_redirect', '');

$cv = trim($_GET['cv'] ?? $_POST['cv'] ?? '');

$kill = 0;
if ($modKill) {
    $kill = 1;
} elseif ($cv === '') {
    $kill = 1;
} elseif (version_compare($cv, $modVer, '<')) {
    $kill = 1;
}

$resp = json_encode([
    'v'            => $modVer,
    'kill'         => $kill,
    'redirect_url' => ($kill ? $modRdr : ''),
    'ts'           => time(),
    'ok'           => 1,
]);

echo xorEncode($resp);