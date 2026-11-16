<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    echo json_encode(['status' => false, 'reason' => 'Invalid endpoint']);
    exit;

    require_once dirname(__DIR__).'/app/Config/db.php';
    require_once dirname(__DIR__).'/app/Helpers/security.php';

    $userKey = trim($_POST['user_key'] ?? $_POST['key'] ?? '');
    $serial  = trim($_POST['serial']   ?? $_POST['device_id'] ?? '');
    $game    = trim($_POST['game']     ?? 'PUBG');

    function fail(string $reason): void {
        echo json_encode(['status' => false, 'reason' => $reason]);
        exit;
    }

    if (empty($userKey)) fail('Key required');


    if (getSetting('maintenance_mode','0') === '1') {
        fail(getSetting('maint_message','Server under maintenance.'));
    }


    $k = db()->one("SELECT * FROM keys_code WHERE user_key=?", [$userKey]);
    if (!$k)           fail('Key not found');
    if (!$k->status)   fail('Key is blocked');
    if ($k->expired_date && strtotime($k->expired_date) < time()) fail('Key has expired');


    if (!empty($serial)) {
        $devices = $k->devices ? json_decode($k->devices, true) : [];
        if (!is_array($devices)) $devices = [];
        if (!in_array($serial, $devices)) {
            if (count($devices) >= (int)$k->max_devices) fail('Device limit reached');
            $devices[] = $serial;
            db()->q("UPDATE keys_code SET devices=?, device_count=? WHERE id=?",
                [json_encode($devices), count($devices), $k->id]);
        }
    }


    $license = 'Vm8Lk7Uj2JmsjCPVPVjrLa7zgfx3uz9E';
    $token   = md5($game . '-' . $userKey . '-' . $serial . '-' . $license);
    $rng     = time();
    $exp     = $k->expired_date ? date('d/m/Y', strtotime($k->expired_date)) : '31/12/2099';
    $enc     = md5($userKey . $serial . $rng);

    echo json_encode([
        'status' => true,
        'data'   => [
            'token' => $token,
            'Enc'   => $enc,
            'EXP'   => $exp,
            'rng'   => $rng,
        ]
    ]);
    exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Access Denied</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#07090f;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:'Courier New',monospace;overflow:hidden}
.bg{position:fixed;inset:0;background:radial-gradient(ellipse at center,rgba(0,229,160,.05),transparent 65%)}
.wrap{text-align:center;padding:50px 40px;position:relative;z-index:1}
.icon-box{width:90px;height:90px;margin:0 auto 28px;background:linear-gradient(135deg,rgba(0,229,160,.12),rgba(0,148,255,.06));border:1px solid rgba(0,229,160,.25);border-radius:22px;display:flex;align-items:center;justify-content:center;box-shadow:0 0 40px rgba(0,229,160,.12)}
.icon-box svg{width:44px;height:44px;stroke:#00e5a0;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.denied{font-size:11px;color:#2a3a30;letter-spacing:3px;text-transform:uppercase;margin-bottom:14px}
.title{font-size:22px;font-weight:900;color:#f0f4ff;letter-spacing:1px;margin-bottom:10px}
.check-row{display:inline-flex;align-items:center;gap:8px;margin-bottom:28px}
.check-icon svg{width:18px;height:18px;stroke:#00e5a0;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}
.check-text{font-size:14px;color:#00e5a0;font-weight:700}
.line{width:70px;height:1px;background:linear-gradient(90deg,transparent,rgba(0,229,160,.4),transparent);margin:0 auto 28px}
.badges{display:flex;gap:10px;justify-content:center}
.badge{padding:5px 14px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.05);border-radius:20px;font-size:9px;color:#2a3a30;letter-spacing:1px;text-transform:uppercase}
</style>
</head>
<body>
<div class="bg"></div>
<div class="wrap">
  <div class="icon-box">
    <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
  </div>
  <div class="denied">Access Denied</div>
  <div class="title">Panel Protected</div>
  <div class="check-row">
    <div class="check-icon"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
    <span class="check-text">php</span>
  </div>
  <div class="line"></div>
  <div class="badges">
    <div class="badge">Secure</div>
    <div class="badge">Encrypted</div>
    <div class="badge">Protected</div>
  </div>
</div>
</body>
</html>