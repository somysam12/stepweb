<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');

echo json_encode(['status' => false, 'reason' => 'Invalid endpoint']);
exit;

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';

$userKey = trim($_POST['user_key'] ?? $_GET['user_key'] ?? $_POST['key'] ?? '');
$serial  = trim($_POST['serial']   ?? $_GET['serial']   ?? '');
$game    = trim($_POST['game']     ?? 'PUBG');

function fail(string $reason): void {
    echo json_encode(['status' => false, 'reason' => $reason]);
    exit;
}

if (empty($userKey)) {
    fail('Key required');
}

if (getSetting('maintenance_mode','0') === '1') {
    fail(getSetting('maint_message','Server under maintenance.'));
}

$k = db()->one("SELECT * FROM keys_code WHERE user_key=?", [$userKey]);

if (!$k)       fail('Key not found');
if (!$k->status) fail('Key is blocked');
if ($k->expired_date && strtotime($k->expired_date) < time()) fail('Key has expired');

if (!empty($serial)) {
    $devices = $k->devices ? json_decode($k->devices, true) : [];
    if (!is_array($devices)) $devices = [];
    if (!in_array($serial, $devices)) {
        if (count($devices) >= (int)$k->max_devices) {
            fail('Device limit reached');
        }
        $devices[] = $serial;
        db()->q("UPDATE keys_code SET devices=?, device_count=? WHERE id=?",
            [json_encode($devices), count($devices), $k->id]);
    }
}

$license = 'Vm8Lk7Uj2JmsjCPVPVjrLa7zgfx3uz9E';
$authStr = $game . '-' . $userKey . '-' . $serial . '-' . $license;
$token   = md5($authStr);

$exp = $k->expired_date ? date('d/m/Y', strtotime($k->expired_date)) : '31/12/2099';

$rng = time();

$enc = md5($userKey . $serial . $rng);

echo json_encode([
    'status' => true,
    'data'   => [
        'token' => $token,
        'Enc'   => $enc,
        'EXP'   => $exp,
        'rng'   => $rng,
    ]
]);