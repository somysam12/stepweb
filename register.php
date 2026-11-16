<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
require_once __DIR__.'/app/Helpers/icons.php';
ddosCheck();
if (isLoggedIn()) { header('Location: /dashboard.php'); exit; }

$errors=[]; $success=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verifyCsrf($_POST['csrf']??'')) { $errors[]='Invalid token.'; }
    else {
        $username=strtolower(trim($_POST['username']??''));
        $password=$_POST['password']??'';
        $refCode=strtoupper(trim($_POST['referral']??''));
        if(strlen($username)<4) $errors[]='Username min 4 characters.';
        if(!preg_match('/^[a-z0-9_]+$/',$username)) $errors[]='Username: letters, numbers, underscore only.';
        if(strlen($password)<6) $errors[]='Password min 6 characters.';
        if(empty($refCode)) $errors[]='Referral code required.';
        if(empty($errors)){
            $ref=db()->one("SELECT * FROM referral_codes WHERE code=? AND is_used=0",[$refCode]);
            if(!$ref){ $errors[]='Invalid or used referral code.'; }
            else {
                $exists=db()->one("SELECT id FROM users WHERE username=?",[$username]);
                if($exists){ $errors[]='Username already taken.'; }
                else {
                    $expMap=['30 Days'=>30,'60 Days'=>60,'90 Days'=>90,'180 Days'=>180,'1 Year'=>365,'1 Week'=>7,'15 Days'=>15];
                    $days=$expMap[$ref->acc_expire]??30;
                    $expiry=date('Y-m-d H:i:s',strtotime("+{$days} days"));
                    $hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>10]);
                    $refLimitNew = (int)($ref->referral_limit??0);
                    db()->q("INSERT INTO users(username,password,level,balance,referred_by,ref_code_used,ref_giver,status,expiration_date,referral_limit) VALUES(?,?,?,?,?,?,?,1,?,?)",
                        [$username,$hash,(int)$ref->level,$ref->saldo,$ref->created_by,$refCode,$ref->created_by,$expiry,$refLimitNew]);
                    $newId=db()->lastId();
                    db()->q("UPDATE referral_codes SET is_used=1,used_by=?,used_at=NOW() WHERE id=?",[$username,$ref->id]);
                    db()->q("UPDATE users SET referrals_used=referrals_used+1 WHERE username=?",[$ref->created_by]);
                    logAct((int)$newId,$username,'REGISTER',"Via referral: {$refCode}");
                    $success=true;
                }
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
<title>Register — <?=htmlspecialchars($panelName)?></title>
<link rel="stylesheet" href="/assets/css/panel.css?v=10">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
.auth-input-wrap{
  position:relative;display:flex;align-items:center;
  background:rgba(0,0,0,.35);
  border:1px solid rgba(255,255,255,.08);
  border-radius:13px;transition:.22s;overflow:hidden;
  box-shadow:inset 0 2px 8px rgba(0,0,0,.35);
}
.auth-input-wrap:focus-within{
  background:rgba(0,229,160,.035);
  border-color:rgba(0,229,160,.38);
  box-shadow:inset 0 2px 6px rgba(0,0,0,.2),0 0 0 4px rgba(0,229,160,.08);
}
.auth-ico{width:46px;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--muted)}
.auth-ico svg{opacity:.5}
.auth-inp{flex:1;background:none;border:none;color:var(--text);padding:13px 12px 13px 0;font-size:14px;outline:none;font-family:'Inter',sans-serif;width:100%}
.auth-inp::placeholder{color:rgba(255,255,255,.2)}
.auth-toggle{width:46px;display:flex;align-items:center;justify-content:center;background:none;border:none;color:var(--muted);cursor:pointer;transition:.18s}
.ref-wrap{
  background:rgba(0,229,160,.04);
  border:1px solid rgba(0,229,160,.18);
  border-radius:14px;padding:16px 18px;margin-bottom:16px;
}
.ref-inp-row{
  display:flex;align-items:center;
  background:rgba(0,0,0,.3);
  border:1px solid rgba(0,229,160,.2);border-radius:10px;overflow:hidden;
  box-shadow:inset 0 2px 6px rgba(0,0,0,.3);
}
.ref-inp{flex:1;background:none;border:none;color:var(--acc);padding:12px 12px;font-size:16px;font-family:var(--mono);letter-spacing:2px;text-transform:uppercase;outline:none;width:100%}
.ref-inp::placeholder{color:rgba(0,229,160,.3);font-size:12px;letter-spacing:.5px;text-transform:none}
</style>
</head>
<body>
<div class="login-scene">
  <div class="orb1"></div><div class="orb2"></div>
  <div class="pts" id="pts"></div>
  <div style="width:100%;max-width:440px;position:relative;z-index:10">
    <div class="login-card anim-in">
      <div class="login-top">
        <div class="brand-logo" style="width:54px;height:54px;border-radius:14px;margin:0 auto 14px">
          <?php if($panelLogo): ?><img src="<?=htmlspecialchars($panelLogo)?>" alt="">
          <?php else: ?><div class="brand-logo-inner" style="font-size:20px;font-weight:900;color:var(--acc);font-family:var(--mono)"><?=strtoupper(substr($panelName,0,2))?></div><?php endif; ?>
        </div>
        <a href="/dashboard.php" style="text-decoration:none"><div class="brand-name" style="font-size:18px">CREATE ACCOUNT</div></a>
        <div class="brand-sub"><?=htmlspecialchars($panelName)?> · Referral Required</div>
      </div>
      <div class="login-body">
        <?php if($success): ?>
        <div class="alert a-ok" style="margin-bottom:16px"><?=svgIcon('check',14)?> Account created! <a href="/" style="color:var(--acc);font-weight:700">Login now</a></div>
        <?php endif; ?>
        <?php if($errors): ?>
        <div class="alert a-err" style="margin-bottom:16px;flex-direction:column;align-items:flex-start;gap:4px">
          <?php foreach($errors as $e): ?><div style="display:flex;align-items:center;gap:6px"><?=svgIcon('ban',12)?> <?=htmlspecialchars($e)?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if(!$success): ?>
        <form method="POST">
          <?=csrfField()?>
          <div class="input-wrap">
            <label>Username</label>
            <div class="auth-input-wrap">
              <span class="auth-ico"><?=svgIcon('user',16)?></span>
              <input class="auth-inp" type="text" name="username" placeholder="Min 4 chars, letters/numbers" required minlength="4" autocomplete="username" value="<?=htmlspecialchars($_POST['username']??'')?>">
            </div>
            <div class="fi-hint">Letters, numbers, underscore only</div>
          </div>
          <div class="input-wrap">
            <label>Password</label>
            <div class="auth-input-wrap">
              <span class="auth-ico"><?=svgIcon('lock',16)?></span>
              <input class="auth-inp" type="password" name="password" id="regPass" placeholder="Min 6 characters" required minlength="6" autocomplete="new-password">
              <button type="button" class="auth-toggle" onclick="togglePass()">
                <span id="eyeIco2"><?=svgIcon('eye',16)?></span>
              </button>
            </div>
          </div>
          <div class="ref-wrap">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--acc);margin-bottom:8px;display:flex;align-items:center;gap:5px"><?=svgIcon('link',12)?> Referral Code <span style="color:var(--danger);margin-left:4px">* Required</span></div>
            <div class="ref-inp-row">
              <span style="padding:0 12px;color:var(--acc);opacity:.5"><?=svgIcon('key',16)?></span>
              <input class="ref-inp" type="text" name="referral" placeholder="e.g. AB12CD" required maxlength="6" minlength="6" oninput="this.value=this.value.toUpperCase()" value="<?=htmlspecialchars($_POST['referral']??'')?>">
            </div>
            <div style="font-size:10px;color:var(--muted);margin-top:7px;display:flex;align-items:center;gap:4px"><?=svgIcon('info',11)?> Valid referral code from admin or owner required</div>
          </div>
          <button type="submit" class="btn-login" style="display:flex;align-items:center;justify-content:center;gap:8px"><?=svgIcon('register',16)?> CREATE ACCOUNT</button>
        </form>
        <?php endif; ?>
      </div>
      <div class="login-foot">
        <p>Have an account? <a href="/">Login here</a></p>
      </div>
    </div>
  </div>
</div>
<div id="toasts" class="toasts"></div>
<script src="/assets/js/panel.js"></script>
<script>
spawnParticles('pts',16);
let pv=false;
function togglePass(){pv=!pv;document.getElementById('regPass').type=pv?'text':'password';document.getElementById('eyeIco2').innerHTML=pv?`<?=svgIcon('eye-off',16)?>`:`<?=svgIcon('eye',16)?>}`;}
</script>
</body>
</html>