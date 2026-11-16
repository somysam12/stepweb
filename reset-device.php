<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
require_once __DIR__.'/app/Helpers/icons.php';
$msg=''; $msgType=''; $done=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if(!verifyCsrf($_POST['csrf']??'')){ $msg='Invalid token.'; $msgType='err'; }
    else {
        $username=strtolower(trim($_POST['username']??''));
        $password=$_POST['password']??'';
        $u=db()->one("SELECT * FROM users WHERE username=?",[$username]);
        if(!$u||!password_verify($password,$u->password)){ $msg='Invalid username or password.'; $msgType='err'; }
        elseif($u->status==0){ $msg='Account is banned.'; $msgType='err'; }
        else {

            $blocked = false;
            try {
                $limit  = max(1,(int)getSetting('panel_device_reset_limit','1'));
                $resets = (int)($u->device_resets ?? 0);
                $last   = $u->device_reset_at ?? null;
                if ($last && strtotime($last) < strtotime('-24 hours')) $resets = 0;
                if ($resets >= $limit) {
                    $msg="Reset limit reached ({$limit} per 24h). Try again later.";
                    $msgType='err'; $blocked=true;
                } else {
                    db()->q("UPDATE users SET login_device=NULL, device_resets=?, device_reset_at=NOW() WHERE id=?",
                        [$resets+1, $u->id]);
                }
            } catch (Throwable $e) {  }

            if (!$blocked) {
                logAct($u->id,$username,'RESET_LOGIN_IP','Reset panel login IP from login page');
                $msg='Login IP reset! You can now log in from a new device.'; $msgType='ok'; $done=true;
            }
        }
    }
}
$panelName=getSetting('panel_name','HB Panel');
$panelLogo=getSetting('panel_logo','');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reset Device — <?=htmlspecialchars($panelName)?></title>
<link rel="stylesheet" href="/assets/css/panel.css?v=10">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
.auth-input-wrap{position:relative;display:flex;align-items:center;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;transition:.2s;overflow:hidden}
.auth-input-wrap:focus-within{background:rgba(255,184,0,.04);border-color:rgba(255,184,0,.35);box-shadow:0 0 0 3px rgba(255,184,0,.07)}
.auth-ico{width:44px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--muted)}
.auth-inp{flex:1;background:none;border:none;color:var(--text);padding:12px 12px 12px 0;font-size:14px;outline:none;font-family:'Inter',sans-serif;width:100%}
.auth-inp::placeholder{color:rgba(255,255,255,.2)}
.auth-toggle{width:44px;display:flex;align-items:center;justify-content:center;background:none;border:none;color:var(--muted);cursor:pointer;transition:.15s}
</style>
</head>
<body>
<div class="login-scene">
  <div class="orb1"></div><div class="orb2"></div>
  <div class="pts" id="pts"></div>
  <div style="width:100%;max-width:420px;position:relative;z-index:10">
    <div class="login-card anim-in">
      <div class="login-top">
        <div class="brand-logo" style="width:54px;height:54px;border-radius:14px;margin:0 auto 14px;background:linear-gradient(135deg,rgba(255,184,0,.15),rgba(255,100,0,.08));border-color:rgba(255,184,0,.25)">
          <div class="brand-logo-inner"><?=svgIcon('phone',24)?></div>
        </div>
        <a href="/" style="text-decoration:none"><div class="brand-name" style="font-size:18px">RESET LOGIN IP</div></a>
        <div class="brand-sub"><?=htmlspecialchars($panelName)?> · Login IP Reset</div>
      </div>
      <div class="login-body">
        <?php if($msg): ?>
        <div class="alert a-<?=$msgType?>" style="margin-bottom:16px">
          <?=svgIcon($msgType==='ok'?'check':'ban',14)?> <?=htmlspecialchars($msg)?>
        </div>
        <?php endif; ?>
        <?php if(!$done): ?>
        <div class="alert a-warn" style="margin-bottom:16px;font-size:11px">
          <?=svgIcon('alert',13)?> It will reset your login IP.
        </div>
        <form method="POST">
          <?=csrfField()?>
          <div class="input-wrap">
            <label>Username</label>
            <div class="auth-input-wrap">
              <span class="auth-ico"><?=svgIcon('user',16)?></span>
              <input class="auth-inp" type="text" name="username" placeholder="Your username" required>
            </div>
          </div>
          <div class="input-wrap">
            <label>Password</label>
            <div class="auth-input-wrap">
              <span class="auth-ico"><?=svgIcon('lock',16)?></span>
              <input class="auth-inp" type="password" name="password" id="rPass" placeholder="Your password" required>
              <button type="button" class="auth-toggle" onclick="toggleR()"><span id="rEye"><?=svgIcon('eye',16)?></span></button>
            </div>
          </div>
          <button type="submit" style="width:100%;padding:13px;background:linear-gradient(135deg,#ff7f00,#ffb800);border:none;border-radius:10px;color:#000;font-weight:900;font-size:14px;font-family:var(--mono);cursor:pointer;letter-spacing:.5px;display:flex;align-items:center;justify-content:center;gap:8px">
            <?=svgIcon('refresh',16)?> RESET LOGIN IP
          </button>
        </form>
        <?php else: ?>
        <a href="/" class="btn btn-acc btn-full" style="justify-content:center;margin-top:8px"><?=svgIcon('login',15)?> Back to Login</a>
        <?php endif; ?>
      </div>
      <div class="login-foot"><a href="/" style="font-size:12px;color:var(--muted);text-decoration:none;display:inline-flex;align-items:center;gap:5px"><?=svgIcon('arrow-right',12)?> Back to Login</a></div>
    </div>
  </div>
</div>
<div id="toasts" class="toasts"></div>
<script src="/assets/js/panel.js"></script>
<script>
spawnParticles('pts',14);
let rv=false;
function toggleR(){rv=!rv;document.getElementById('rPass').type=rv?'text':'password';document.getElementById('rEye').innerHTML=rv?`<?=svgIcon('eye-off',16)?>`:`<?=svgIcon('eye',16)?>}`;}
</script>
</body>
</html>