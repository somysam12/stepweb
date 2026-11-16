<?php

define('VB_DEBUG', false);
function vb_log(string $step): void {
    if (!VB_DEBUG) return;
    @file_put_contents(
        __DIR__.'/vb_debug.log',
        '['.date('H:i:s').'] '.$step."\n",
        FILE_APPEND
    );
}

require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
require_once __DIR__.'/app/Config/secrets.php';

header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

$GLOBALS['vb_mode'] = 'aes';
function vb_out(int $q, int $expUnix = 0): void {
    $mode = $GLOBALS['vb_mode'] ?? 'aes';
    vb_log("RESULT → returning SIGNED q=$q (mode=$mode; lib verifies signature".($mode==='aes'?' + GCM tag':'').")");
    $payload = json_encode([
        'q' => $q,
        'x' => ($expUnix ^ HB_XKEY1),
        'z' => (time()   ^ HB_XKEY2),
        'w' => random_int(100000, 999999),
        'u' => bin2hex(random_bytes(5)),
        'v' => bin2hex(random_bytes(4)),
    ]);
    echo ($mode === 'aes') ? hb_sign_aes($payload) : hb_sign($payload);
    exit;
}
function vb_fake(): void {
    vb_log("RESULT → returning FAKE js (request could not be authenticated/decrypted)");
    echo '';
    exit;
}

vb_log("==== new request  method=".$_SERVER['REQUEST_METHOD']."  ip=".($_SERVER['REMOTE_ADDR'] ?? '?'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { vb_log("FAIL: not a POST (lib must POST)"); vb_fake(); }

$p1 = trim($_POST['p1'] ?? '');
$p2 = trim($_POST['p2'] ?? '');
$p3 = trim($_POST['p3'] ?? '');
vb_log("p1 len=".strlen($p1)."  p2 len=".strlen($p2)."  build=".($p3 !== '' ? $p3 : '(none — OLD lib still deployed!)'));

$expectHmac = hash_hmac('sha256', $p1, HB_HMAC_KEY);
if (!hash_equals($expectHmac, $p2)) {
    vb_log("FAIL: HMAC mismatch → HB_HMAC_KEY differs between lib and panel.");
    vb_log("      panel expected: ".$expectHmac);
    vb_log("      lib sent (p2) : ".$p2);
    vb_fake();
}
vb_log("OK: HMAC matched (HB_HMAC_KEY is identical on both sides)");

$plain = null;
$aes = hb_aes_decrypt($p1);
if ($aes !== null && json_decode($aes, true) !== null) {
    $plain = $aes;
    $GLOBALS['vb_mode'] = 'aes';
    vb_log("OK: request is v4 AES-256-GCM (decrypted + tag verified).");
} else {
    $b = hb_b64url_decode($p1);
    if ($b !== null && json_decode($b, true) !== null) {
        $plain = $b;
        $GLOBALS['vb_mode'] = 'v2';
        vb_log("OK: request is v3 HMAC-only (base64url json).");
    } else {
        vb_log("FAIL: payload is neither v4 AES nor v3 base64url-json.");
        vb_log("      → keys mismatch or corrupt build. Check p3 marker above.");
        vb_fake();
    }
}
vb_log("OK: payload decoded → ".$plain);

$d   = json_decode($plain, true);
$key = (string)($d['k']   ?? '');
$dev = (string)($d['d']   ?? '');
$ts  = (int)   ($d['t']   ?? 0);
$pkg = (string)($d['pkg'] ?? '');
vb_log("parsed key=".substr($key,0,20)."  dev=".substr($dev,0,12)."  pkg=$pkg  ts=$ts (skew ".(time()-$ts)."s)");

if ($key === '') { vb_log("FAIL: empty key in payload"); vb_out(4); }
if ($ts && abs(time() - $ts) > 300) { vb_log("FAIL: clock skew ".(time()-$ts)."s > 300s → fix device time OR raise the window"); vb_out(4); }

if (getSetting('maintenance_mode','0') === '1') { vb_log("INFO: maintenance mode ON → q=1"); vb_out(1); }

$k = db()->one("SELECT * FROM keys_code WHERE user_key=? LIMIT 1", [$key]);
if (!$k)                        { vb_log("FAIL: key NOT FOUND in keys_code table → wrong key / wrong DB"); vb_out(4); }
vb_log("key row found id=".$k->id."  status=".$k->status."  expired_date=".($k->expired_date ?? 'null')."  max_devices=".($k->max_devices ?? 'null')."  is_used=".($k->is_used ?? 'null')."  loader_pkg=".($k->loader_pkg ?? 'null'));
if ((int)$k->status !== 1)      { vb_log("FAIL: status is ".$k->status." (need 1=active). 2=blocked, 0=inactive → q=6"); vb_out(6); }
if (!empty($k->expired_date) && strtotime($k->expired_date) < time()) { vb_log("FAIL: key expired_date ".$k->expired_date." is in the past → q=2"); vb_out(2); }

if ($dev !== '') {
    $devices = $k->devices ? json_decode($k->devices, true) : [];
    if (!is_array($devices)) $devices = [];
    if (!in_array($dev, $devices, true)) {
        $maxDev = (int)($k->max_devices ?? 1);
        if ($maxDev < 1) $maxDev = 1;
        vb_log("device not yet bound. bound=".count($devices)."/".$maxDev);
        if (count($devices) >= $maxDev) { vb_log("FAIL: device limit reached (".count($devices)."/".$maxDev.") → q=3. Reset devices in panel or raise max_devices"); vb_out(3); }
        $devices[] = $dev;
        db()->q("UPDATE keys_code SET devices=?, device_count=? WHERE id=?",
            [json_encode($devices), count($devices), $k->id]);
        vb_log("device bound OK (now ".count($devices)."/".$maxDev.")");
    } else {
        vb_log("device already bound — ok");
    }
}

if ((int)($k->is_used ?? 0) === 0) {
    db()->q("UPDATE keys_code SET is_used=1, first_used=NOW(), loader_pkg=? WHERE id=?", [$pkg, $k->id]);
    vb_log("package bound (first use) → $pkg");
} elseif (!empty($k->loader_pkg) && $pkg !== '' && $pkg !== $k->loader_pkg) {
    vb_log("FAIL: package mismatch. bound='".$k->loader_pkg."' but lib sent '".$pkg."' → q=5 (clone/different apk)");
    vb_out(5);
}

logAct($k->id ?? null, $k->registrator ?? '', 'VALIDBOOL_OK', 'dev:'.substr($dev,0,10));
vb_log("ALL CHECKS PASSED → q=7 (login should succeed)");
vb_out(7, strtotime($k->expired_date ?? '0'));