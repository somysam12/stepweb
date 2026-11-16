<?php
// ================================================
// HEARTBEAT ENDPOINT — POST /p/sw.js
// Verifies APK key, libs whitelist, libbgmi hash,
// package binding, and returns encrypted status.
// ================================================

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Config/secrets.php';

// ── SHARED SECRETS (from app/Config/secrets.php — match APK + native lib) ──
define('XKEY1', HB_XKEY1);
define('XKEY2', HB_XKEY2);

// Only these libs are allowed in APK (any extra = injection)
$ALLOWED_LIBS = explode(',', LIB_ALLOWED);

// ── ROUTING ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fakeJs();
}

$p1 = trim($_POST['p1'] ?? '');
$p2 = trim($_POST['p2'] ?? '');
if (!$p1 || !$p2) {
    fakeJs();
}

// 1. HMAC verify — authenticates the request (only holder of HB_HMAC_KEY can sign)
if (!hash_equals(hash_hmac('sha256', $p1, HB_HMAC_KEY), $p2)) {
    beat(1, 0); exit;
}

// 2. Decrypt/decode payload. Try v4 AES-256-GCM first, then v3 base64url-json.
//    Reply is returned in the SAME format the lib used (see $GLOBALS['hb_mode']).
$GLOBALS['hb_mode'] = 'aes';
$plain = null;
$aes = hb_aes_decrypt($p1);
if ($aes !== null && json_decode($aes, true) !== null) {
    $plain = $aes; $GLOBALS['hb_mode'] = 'aes';
} else {
    $b = hb_b64url_decode($p1);
    if ($b !== null && json_decode($b, true) !== null) {
        $plain = $b; $GLOBALS['hb_mode'] = 'v2';
    } else {
        beat(1, 0); exit;              // undecodable → soft beat
    }
}

// 3. Parse JSON payload
$d        = json_decode($plain, true);
$key      = (string)($d['k']   ?? '');
$dev      = (string)($d['d']   ?? '');
$ts       = (int)  ($d['t']   ?? 0);
$pkg      = (string)($d['pkg'] ?? '');
$libs     = (string)($d['libs']?? '');
$bgmiHash = (string)($d['bh']  ?? '');

// 4. Replay window — 300 second tolerance (covers device clock drift up to 5 min)
if (abs(time() - $ts) > 300) {
    beat(1, 0); exit;
}
if (!$key) {
    hb_kick('', $dev, $pkg, 'no_key', 'Empty key in heartbeat');
    beat(2, 0); exit;
}

// 4b. Maintenance — soft beat (q=1): app keeps running, no crash, no unlock
$mRow = db()->one("SELECT svalue FROM panel_settings WHERE skey='maintenance_mode' LIMIT 1");
if ($mRow && (string)($mRow->svalue ?? '0') === '1') {
    beat(1, 0); exit;
}

// 5. DB key lookup (JOIN lib for libbgmi hash in one query)
$row = db()->one(
    "SELECT kc.status, kc.expired_date, kc.loader_pkg, kc.is_used,
            l.lib_hash
     FROM keys_code kc
     LEFT JOIN lib l ON l.id = 1
     WHERE kc.user_key = ?
     LIMIT 1",
    [$key]
);
if (!$row) {
    hb_kick($key, $dev, $pkg, 'unknown_key', 'Key not found in DB');
    beat(2, 0); exit;
}

// 6. Key state checks — MUST be active (status=1). The panel "Block" action sets
//    status=0 (keys.php), so checking only for '2' would never kick a blocked key
//    that is already running. Anything other than 1 (0=blocked, 2=blocked, etc.)
//    = not allowed → q=2 → the lib SIGKILLs the session on the next 60s beat.
if ((int)$row->status !== 1) {
    hb_kick($key, $dev, $pkg, 'blocked', 'Key status='.$row->status);
    beat(2, 0); exit;
}
if (!empty($row->expired_date) && strtotime($row->expired_date) < time()) {
    hb_kick($key, $dev, $pkg, 'expired', 'Expired '.$row->expired_date);
    beat(2, 0); exit;
}

// 7. Lib whitelist check — any unknown .so = injection tool present
if ($libs) {
    $loaded = array_filter(array_map('trim', explode(',', $libs)));
    foreach ($loaded as $lib) {
        if (!in_array($lib, $GLOBALS['ALLOWED_LIBS'], true)) {
            hb_kick($key, $dev, $pkg, 'bad_lib', 'Unknown lib: '.$lib);
            beat(2, 0); exit;
        }
    }
}

// 8. libbgmi.so hash check (dynamic, stored in DB)
$expectedBgmi = $row->lib_hash ?? '';
if ($expectedBgmi && $bgmiHash && $bgmiHash !== $expectedBgmi) {
    hb_kick($key, $dev, $pkg, 'lib_tampered', 'Hash mismatch');
    beat(2, 0); exit;
}

// 9. Package binding — first heartbeat binds pkg, subsequent must match
if ((int)$row->is_used === 0) {
    db()->q(
        "UPDATE keys_code SET is_used=1, first_used=NOW(), loader_pkg=? WHERE user_key=?",
        [$pkg, $key]
    );
} else {
    if ($row->loader_pkg && $pkg && $pkg !== $row->loader_pkg) {
        hb_kick($key, $dev, $pkg, 'pkg_mismatch', 'Expected '.$row->loader_pkg.' got '.$pkg);
        beat(2, 0); exit;
    }
}

// 10. All checks passed - record heartbeat for Online Devices panel
$_hbIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if ($dev) {
    try {
        $hbEx = db()->one("SELECT id FROM device_reports WHERE user_key=? AND serial=? AND DATE(created_at)=CURDATE() LIMIT 1", [$key, $dev]);
        if ($hbEx) {
            db()->q("UPDATE device_reports SET pkg=?, ip=?, created_at=NOW() WHERE id=?", [$pkg, $_hbIp, $hbEx->id]);
        } else {
            db()->q("INSERT INTO device_reports (user_key, serial, pkg, ip) VALUES (?,?,?,?)", [$key, $dev, $pkg, $_hbIp]);
        }
    } catch(Exception $e) {}
}
beat(7, strtotime($row->expired_date ?? '0'));
exit;

// ── HELPERS ───────────────────────────────────────────────────

function beat(int $q, int $expUnix): void {
    $now      = time();
    $bgmiHash = '';

    // Send current expected bgmi hash back to APK for client-side compare
    $lRow = db()->one("SELECT lib_hash FROM lib WHERE id=1 LIMIT 1");
    if ($lRow) $bgmiHash = $lRow->lib_hash ?? '';

    $payload = json_encode([
        'q'  => $q,
        'x'  => ($expUnix ^ XKEY1),
        'z'  => ($now    ^ XKEY2),
        'lh' => $bgmiHash,
        'w'  => random_int(100000, 999999),
        'v'  => random_int(100000, 999999),
        'u'  => bin2hex(random_bytes(5)),
    ]);

    header('Content-Type: text/javascript; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    // Reply encrypted (v4) or plaintext-signed (v3), matching the request format.
    $mode = $GLOBALS['hb_mode'] ?? 'aes';
    echo ($mode === 'aes') ? hb_sign_aes($payload) : hb_sign($payload);
    exit;
}

function fakeJs(): void {
    header('Content-Type: text/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    echo '/* v'.rand(1,9).'.'.rand(0,99).' */';
    exit;
}

function hb_kick(string $key, string $dev, string $pkg, string $reason, string $detail): void {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    try {
        $ex = db()->one(
            "SELECT id, hits FROM heartbeat_kicks WHERE user_key=? AND device_id=? AND reason=? AND last_seen > DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 1",
            [$key, $dev, $reason]
        );
        if ($ex) {
            db()->q("UPDATE heartbeat_kicks SET hits=hits+1, last_seen=NOW(), ip=?, detail=? WHERE id=?",
                [$ip, $detail, $ex->id]);
        } else {
            db()->q("INSERT INTO heartbeat_kicks (user_key, device_id, pkg, reason, detail, ip) VALUES (?,?,?,?,?,?)",
                [$key, $dev, $pkg, $reason, $detail, $ip]);
        }
    } catch (\Exception $e) {}
}
