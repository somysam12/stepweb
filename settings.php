<?php

require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$uname = $_SESSION['uname'] ?? 'User';

$msg=''; $msgType='ok';

if ($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrf($_POST['csrf']??'')) {
    $sec = $_POST['section']??'';

    if ($sec==='branding') {
        $name = trim($_POST['panel_name']??'');
        if ($name) setSetting('panel_name', $name);
        setSetting('panel_tagline', trim($_POST['panel_tagline']??''));


        if (!empty($_FILES['logo']['name'])) {
            $f = $_FILES['logo'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $msg = 'Upload error: '.$f['error']; $msgType='err';
            } else {
                $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $f['tmp_name']);
                finfo_close($finfo);
                if (!in_array($mime, $allowed)) {
                    $msg='Only JPG/PNG/GIF/WEBP allowed.'; $msgType='err';
                } elseif ($f['size'] > 2*1024*1024) {
                    $msg='Max 2MB.'; $msgType='err';
                } else {
                    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime]??'png';

                    $uploadDir = __DIR__.'/uploads/logos/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $fn = 'logo_'.time().'.'.$ext;
                    if (move_uploaded_file($f['tmp_name'], $uploadDir.$fn)) {
                        setSetting('panel_logo', '/uploads/logos/'.$fn);
                        $msg = 'Logo uploaded!'; $msgType='ok';
                    } else {
                        $msg = 'Upload failed. Check folder permissions: chmod 777 uploads/logos/'; $msgType='err';
                    }
                }
            }
        }
        if (!$msg) { $msg='Branding saved.'; $msgType='ok'; }
        logAct($_SESSION['uid'],$uname,'UPDATE_BRANDING','');
    }

    if ($sec==='security') {
        setSetting('ddos_protection',   isset($_POST['ddos'])?'1':'0');
        setSetting('max_login_attempts',(string)(int)($_POST['max_att']??5));
        setSetting('lockout_minutes',   (string)(int)($_POST['lockout']??15));
        setSetting('panel_version',     trim($_POST['panel_version']??'3.0.0'));
        logAct($_SESSION['uid'],$uname,'UPDATE_SECURITY','');
        $msg='Settings saved.'; $msgType='ok';
    }

    if ($sec==='password') {
        $cur=$_POST['cur_pass']??''; $new=$_POST['new_pass']??''; $con=$_POST['con_pass']??'';
        $me = db()->one("SELECT password FROM users WHERE id=?",[$_SESSION['uid']]);
        if (!password_verify($cur,$me->password)) { $msg='Current password wrong.'; $msgType='err'; }
        elseif (strlen($new)<6) { $msg='Min 6 characters.'; $msgType='err'; }
        elseif ($new!==$con) { $msg='Passwords do not match.'; $msgType='err'; }
        else {
            db()->q("UPDATE users SET password=? WHERE id=?",
                [password_hash($new,PASSWORD_BCRYPT,['cost'=>10]),$_SESSION['uid']]);
            logAct($_SESSION['uid'],$uname,'CHANGE_PASSWORD','');
            $msg='Password updated!'; $msgType='ok';
        }
    }

    if ($sec==='remove_logo') {
        setSetting('panel_logo','');
        $msg='Logo removed.'; $msgType='ok';
    }


    $_SESSION['flash_msg']  = $msg ?: 'Saved.';
    $_SESSION['flash_type'] = $msgType;
    header('Location: /settings.php'); exit;
}

if (!empty($_SESSION['flash_msg'])) {
    $msg     = $_SESSION['flash_msg'];
    $msgType = $_SESSION['flash_type'] ?? 'ok';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

$pageTitle = 'Settings';
$activeNav = 'settings';
require_once __DIR__.'/app/Views/Layout/header.php';

$pname   = getSetting('panel_name','HB Panel');
$plogo   = getSetting('panel_logo','');
$pver    = getSetting('panel_version','3.0.0');
$ddos    = getSetting('ddos_protection','1');
$maxAtt  = getSetting('max_login_attempts','5');
$lout    = getSetting('lockout_minutes','15');
?>

<?php if($msg): ?><div class="alert a-<?= $msgType ?>"><?= $msgType==='ok'?'':'' ?> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="g2">

  <div class="card">
    <div class="card-h"><span class="ct"><?=svgIcon('shield',14)?> Panel Branding</span><span class="owner-only">Owner Only</span></div>
    <div class="card-b">
      <?php if($plogo): ?>

      <div style="display:flex;align-items:center;gap:12px;padding:12px;background:rgba(0,229,160,.06);border:1px solid rgba(0,229,160,.2);border-radius:10px;margin-bottom:14px">
        <img src="<?= htmlspecialchars($plogo) ?>" style="width:54px;height:54px;border-radius:10px;object-fit:cover;border:2px solid rgba(0,229,160,.3)" onerror="this.style.display='none'">
        <div>
          <div style="font-size:11px;font-weight:700;color:var(--acc);margin-bottom:4px">Current Logo</div>
          <div style="font-size:10px;color:var(--muted)"><?= htmlspecialchars(basename($plogo)) ?></div>
        </div>
        <form method="POST" style="margin-left:auto">
          <?= csrfField() ?>
          <input type="hidden" name="section" value="remove_logo">
          <button type="submit" class="btn btn-red btn-xs">Remove</button>
        </form>
      </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="section" value="branding">

        <div class="fg">
          <label class="fl">Panel Name</label>
          <input class="fi" name="panel_name" value="<?= htmlspecialchars($pname) ?>" required>
        </div>

        <div class="fg">
          <label class="fl">Login Tagline <span style="color:var(--muted);font-weight:400">(shown under the name on the login screen)</span></label>
          <input class="fi" name="panel_tagline" value="<?= htmlspecialchars(getSetting('panel_tagline','Secure Admin Panel')) ?>" placeholder="Secure Admin Panel">
        </div>

        <div class="fg">
          <label class="fl">Panel Logo</label>
          <div class="logo-drop" onclick="document.getElementById('logoFile').click()" id="dropZone">
            <div id="logoPrevWrap" style="display:none;margin-bottom:10px">
              <img id="logoPrev" style="width:64px;height:64px;border-radius:12px;object-fit:cover;margin:0 auto;display:block;border:2px solid rgba(0,229,160,.3)">
            </div>
            <div style="font-size:28px;margin-bottom:8px;opacity:.5"></div>
            <div style="font-size:11px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.5px">Click to upload logo</div>
            <div style="font-size:10px;color:var(--muted);margin-top:4px" id="dropFileName">PNG · JPG · WEBP · Max 2MB</div>
            <input type="file" id="logoFile" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" onchange="previewLogo(this)">
          </div>
          <div class="fi-hint">Server must have write permission on <code>uploads/logos/</code> folder</div>
        </div>

        <button type="submit" class="btn btn-acc btn-full"><?=svgIcon('check',14)?> SAVE BRANDING</button>
      </form>
    </div>
  </div>

  <div>

    <div class="card" style="margin-bottom:16px">
      <div class="card-h"><span class="ct"><?=svgIcon('lock',14)?> Security &amp; Version</span></div>
      <div class="card-b">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="section" value="security">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--border);margin-bottom:12px">
            <div><div style="font-weight:600">DDoS Protection</div><div style="font-size:11px;color:var(--muted)">Auto IP blocking</div></div>
            <label class="tog"><input type="checkbox" name="ddos" value="1" <?= $ddos==='1'?'checked':'' ?>><span class="togsl"></span></label>
          </div>
          <div class="fg"><label class="fl">Panel Version</label><input class="fi fi-mono" name="panel_version" value="<?= htmlspecialchars($pver) ?>"></div>
          <div class="g2" style="gap:12px">
            <div class="fg" style="margin:0"><label class="fl">Max Login Attempts</label><input class="fi" type="number" name="max_att" value="<?= $maxAtt ?>" min="1" max="20"></div>
            <div class="fg" style="margin:0"><label class="fl">Lockout (min)</label><input class="fi" type="number" name="lockout" value="<?= $lout ?>" min="1"></div>
          </div>
          <button type="submit" class="btn btn-acc" style="margin-top:14px;width:100%"><?=svgIcon('lock',14)?> Save Security</button>
        </form>
      </div>
    </div>


    <div class="card">
      <div class="card-h"><span class="ct"><?=svgIcon('key',14)?> Change Password</span></div>
      <div class="card-b">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="section" value="password">
          <div class="fg"><label class="fl">Current Password</label><input class="fi" type="password" name="cur_pass" required placeholder="Current password"></div>
          <div class="fg"><label class="fl">New Password</label><input class="fi" type="password" name="new_pass" required minlength="6" placeholder="Min 6 chars"></div>
          <div class="fg"><label class="fl">Confirm Password</label><input class="fi" type="password" name="con_pass" required placeholder="Repeat new password"></div>
          <button type="submit" class="btn btn-warn btn-full"><?=svgIcon('key',14)?> UPDATE PASSWORD</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  const f = input.files[0];
  document.getElementById('dropFileName').textContent = '' + f.name + ' (' + (f.size/1024).toFixed(0) + ' KB)';
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('logoPrev').src = e.target.result;
    document.getElementById('logoPrevWrap').style.display = 'block';
  };
  reader.readAsDataURL(f);
}
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>