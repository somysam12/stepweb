<?php

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';
require_once dirname(__DIR__).'/app/Config/secrets.php';

header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

function out(array $payload): void {
    echo hb_aes_encrypt(json_encode($payload));
    exit;
}
function deny(): void {

    out(['ok'=>0,
         'a'=>bin2hex(random_bytes(6)),
         'b'=>random_int(100000,999999),
         'c'=>bin2hex(random_bytes(4))]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    echo '';
    exit;
}

$p1 = trim($_POST['p1'] ?? '');
$p2 = trim($_POST['p2'] ?? '');
if (!$p1 || !$p2) deny();

if (!hash_equals(hash_hmac('sha256', $p1, HB_HMAC_KEY), $p2)) deny();

$plain = hb_aes_decrypt($p1);
if (!$plain) deny();

$d     = json_decode($plain, true);
$key   = (string)($d['k']   ?? '');
$dev   = (string)($d['d']   ?? '');
$nonce = (string)($d['n']   ?? '');
$pkg   = (string)($d['pkg'] ?? '');

if ($key === '' || $nonce === '' || strlen($nonce) < 8 || strlen($nonce) > 128) deny();

if (getSetting('maintenance_mode','0') === '1') deny();

$k = db()->one("SELECT * FROM keys_code WHERE user_key=? LIMIT 1", [$key]);
if (!$k)                                                              deny();
if ((int)$k->status !== 1)                                            deny();
if (!empty($k->expired_date) && strtotime($k->expired_date) < time())deny();

if ($dev !== '') {
    $devices = $k->devices ? json_decode($k->devices, true) : [];
    if (!is_array($devices)) $devices = [];
    if (!in_array($dev, $devices, true)) {
        if (count($devices) >= (int)$k->max_devices) deny();
        $devices[] = $dev;
        db()->q("UPDATE keys_code SET devices=?, device_count=? WHERE id=?",
            [json_encode($devices), count($devices), $k->id]);
    }
}

if ((int)($k->is_used ?? 0) === 0) {
    db()->q("UPDATE keys_code SET is_used=1, first_used=NOW(), loader_pkg=? WHERE id=?",
        [$pkg, $k->id]);
} elseif (!empty($k->loader_pkg) && $pkg !== '' && $pkg !== $k->loader_pkg) {
    deny();
}

$libRow  = db()->one("SELECT lib_name, lib_hash FROM lib WHERE id=1 LIMIT 1");
$libHash = $libRow->lib_hash ?? '';
$libFile = pick_deliver_lib();

$grant = hb_grant($key, $nonce);

logAct($k->id ?? null, $k->registrator ?? '', 'LIB_UNLOCK', "Key OK · dev:".substr($dev,0,10));

out([
    'ok'  => 1,
    'g'   => $grant,
    'lib' => $libFile,
    'lh'  => $libHash,
    'a'   => bin2hex(random_bytes(5)),
    'b'   => random_int(100000, 999999),
    'c'   => bin2hex(random_bytes(4)),
]);

function pick_deliver_lib(): string {
    $dir = dirname(__DIR__).'/server/libs/';
    $skip = ['.', '..', '.htaccess', 'version.txt'];
    $best = ''; $bestTime = -1;
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if (in_array($f, $skip, true)) continue;
            if (!is_file($dir.$f)) continue;
            $t = filemtime($dir.$f);
            if ($t > $bestTime) { $bestTime = $t; $best = $f; }
        }
    }
    return $best;
}