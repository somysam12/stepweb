<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
require_once __DIR__.'/app/Helpers/icons.php';
ddosCheck();
if (isLoggedIn()) { header('Location: /dashboard.php'); exit; }

$error=''; $timeout=isset($_GET['timeout']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verifyCsrf($_POST['csrf']??'')) { $error='Invalid token.'; }
    else {
        $r = attemptLogin(trim($_POST['username']??''), $_POST['password']??'');
        if ($r['ok']) {
            if (!empty($_POST['remember_me'])) {
                $lt = 86400;
                $_SESSION['remember_until'] = time() + $lt;
                $_SESSION['remember_me']    = true;

                $remData = base64_encode(json_encode(['until'=>time()+$lt,'user'=>$_SESSION['uname']??'']));
                setcookie('hb_remember', $remData, time()+$lt, '/', '', false, true);

                setcookie(session_name(), session_id(), time()+$lt, '/');
                session_set_cookie_params($lt);
            }
            header('Location: /dashboard.php'); exit;
        }
        $error=$r['msg'];
    }
}
$panelName=getSetting('panel_name','HB Panel');
$panelLogo=getSetting('panel_logo','');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?=htmlspecialchars($panelName)?></title>
<link rel="stylesheet" href="/assets/css/panel.css?v=10">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
/* ── Login page extras ── */
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
  box-shadow:inset 0 2px 6px rgba(0,0,0,.2), 0 0 0 4px rgba(0,229,160,.08);
}
.auth-ico{
  width:46px;display:flex;align-items:center;justify-content:center;
  flex-shrink:0;color:var(--muted);
}
.auth-ico svg{opacity:.5}
.auth-inp{
  flex:1;background:none;border:none;color:var(--text);
  padding:13px 12px 13px 0;font-size:14px;
  outline:none;font-family:'Inter',sans-serif;width:100%;
}
.auth-inp::placeholder{color:rgba(255,255,255,.2)}
.auth-toggle{
  width:46px;display:flex;align-items:center;justify-content:center;
  flex-shrink:0;background:none;border:none;color:var(--muted);
  cursor:pointer;transition:.18s;
}
.auth-toggle:hover{color:var(--acc)}
.sec-row{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:22px}
.sec-tag{
  display:inline-flex;align-items:center;gap:5px;
  padding:5px 12px;
  background:rgba(0,229,160,.04);
  border:1px solid rgba(0,229,160,.12);
  border-radius:20px;font-size:10px;color:var(--muted);font-family:var(--mono);
}
.sec-tag svg{opacity:.6}
</style>
</head>
<body>
<div class="login-scene">
  <div class="orb1"></div><div class="orb2"></div><div class="orb3"></div>
  <div class="pts" id="pts"></div>
  <div class="login-wrap">
    <div class="login-card anim-in">
      <div class="login-top">
        <div class="brand-logo">
          <?php if($panelLogo): ?><img src="<?=htmlspecialchars($panelLogo)?>" alt="Logo">
          <?php else: ?><div class="brand-logo-inner" style="font-size:22px;font-weight:900;color:var(--acc);font-family:var(--mono)"><?=strtoupper(substr($panelName,0,2))?></div><?php endif; ?>
        </div>
        <a href="/dashboard.php" style="text-decoration:none">
          <div class="brand-name"><?=htmlspecialchars(strtoupper($panelName))?></div>
        </a>
        <div class="brand-sub"><?=htmlspecialchars(getSetting('panel_tagline','Secure Admin Panel'))?> — v<?=getSetting('panel_version','3.0.0')?></div>
      </div>
      <div class="login-body">
        <?php if($timeout): ?><div class="alert a-warn" style="margin-bottom:14px"><?=svgIcon('alert',14)?> Session expired. Please login again.</div><?php endif; ?>
        <?php if($error): ?><div class="alert a-err" style="margin-bottom:14px"><?=svgIcon('ban',14)?> <?=htmlspecialchars($error)?></div><?php endif; ?>
        <div class="sec-row">
          <span class="sec-tag"><?=svgIcon('shield',11)?> Encrypted</span>
          <span class="sec-tag"><?=svgIcon('lock',11)?> Brute-Force Guard</span>
          <span class="sec-tag"><?=svgIcon('check',11)?> Secure Session</span>
        </div>
        <form method="POST" id="loginForm">
          <?=csrfField()?>
          <div class="input-wrap">
            <label>Username</label>
            <div class="auth-input-wrap">
              <span class="auth-ico"><?=svgIcon('user',16)?></span>
              <input class="auth-inp" type="text" name="username" placeholder="Enter your username" required autocomplete="username">
            </div>
          </div>
          <div class="input-wrap">
            <label>Password</label>
            <div class="auth-input-wrap">
              <span class="auth-ico"><?=svgIcon('lock',16)?></span>
              <input class="auth-inp" type="password" name="password" id="passInp" placeholder="Enter your password" required autocomplete="current-password">
              <button type="button" class="auth-toggle" id="eyeBtn" onclick="togglePassV()">
                <span id="eyeIco"><?=svgIcon('eye',16)?></span>
              </button>
            </div>
          </div>
          <div class="row-opts" style="margin-bottom:22px">
            <div style="display:flex;align-items:center;gap:8px">
              <label class="tog"><input type="checkbox" name="remember_me" value="1"><span class="togsl"></span></label>
              <span class="stay-lbl">Remember Me for 24 Hours</span>
            </div>
            <a href="/forgot.php" class="forgot-link">Forgot Password?</a>
          </div>
          <button type="submit" class="btn-login" id="loginBtn">
            <span id="loginTxt" style="display:flex;align-items:center;justify-content:center;gap:8px"><?=svgIcon('login',16)?> ACCESS SYSTEM</span>
            <span id="loginSpin" style="display:none">Loading...</span>
          </button>
        </form>
      </div>
      <div class="login-foot">
        <p>No account? <a href="/register.php">Register with referral code</a></p>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,.05)">
          <a href="/reset-device.php" style="font-size:11px;color:var(--muted2);font-family:var(--mono);text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--border);border-radius:7px;transition:.15s">
            <?=svgIcon('phone',13)?> Reset Login IP
          </a>
        </div>
      </div>
    </div>
  </div>
</div>
<div id="toasts" class="toasts"></div>
<script src="/assets/js/panel.js"></script>
<script>
spawnParticles('pts',22);
let passVisible=false;
function togglePassV(){
  passVisible=!passVisible;
  document.getElementById('passInp').type=passVisible?'text':'password';
  document.getElementById('eyeIco').innerHTML=passVisible?`<?=svgIcon('eye-off',16)?>`:`<?=svgIcon('eye',16)?>`;
}
document.getElementById('loginForm').addEventListener('submit',()=>{
  document.getElementById('loginTxt').style.display='none';
  document.getElementById('loginSpin').style.display='block';
  document.getElementById('loginBtn').disabled=true;
});
</script>
</body>
</html>