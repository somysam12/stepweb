<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['ajax'])) {
    requireLogin();
    header('Content-Type: application/json');
    $ulv = (int)($_SESSION['ulevel']??99);
    if ($ulv !== 1) { echo json_encode(['ok'=>false,'msg'=>'Owner only']); exit; }
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }

    $sec = $_POST['section'] ?? '';

    if ($sec === 'maintenance') {
        $on  = ($_POST['maintenance_mode']??'0')==='1'?'1':'0';
        setSetting('maintenance_mode',$on);
        setSetting('maint_message', trim($_POST['maint_message']??''));
        logAct($_SESSION['uid'],$_SESSION['uname']??'','MAINTENANCE_'.($on?'ON':'OFF'),'');
        echo json_encode(['ok'=>true,'msg'=>'Maintenance '.($on?'ENABLED':'DISABLED').' successfully!','status'=>$on]);
        exit;
    }
    if ($sec === 'server') {
        setSetting('server_url', trim($_POST['server_url']??''));
        setSetting('apk_version', trim($_POST['apk_version']??''));
        logAct($_SESSION['uid'],$_SESSION['uname']??'','UPDATE_SERVER_SETTINGS','');
        echo json_encode(['ok'=>true,'msg'=>'Server settings saved!']);
        exit;
    }
    if ($sec === 'base') {
        $b = trim($_POST['base_url']??'');
        if ($b !== '' && !preg_match('#^https?://[^\s/]+#i', $b)) {
            echo json_encode(['ok'=>false,'msg'=>'Enter a full URL, e.g. https://sub.yourdomain.com']); exit;
        }
        setSetting('panel_base_url', rtrim($b,'/'));
        logAct($_SESSION['uid'],$_SESSION['uname']??'','SET_BASE_URL',$b?:'(default)');
        echo json_encode(['ok'=>true,'msg'=>$b?"Base domain set to ".rtrim($b,'/'):'Base domain reset to default.']);
        exit;
    }
    if ($sec === 'ddos') {
        setSetting('ddos_protection', ($_POST['ddos_protection']??'0')==='1'?'1':'0');
        setSetting('max_login_attempts', max(3,(int)($_POST['max_login_attempts']??5)));
        setSetting('lockout_minutes', max(1,(int)($_POST['lockout_minutes']??15)));
        echo json_encode(['ok'=>true,'msg'=>'DDoS settings saved!']);
        exit;
    }
    if ($sec === 'license') {
        $licKey = strtoupper(trim($_POST['license_key']??''));
        if (empty($licKey)) { echo json_encode(['ok'=>false,'msg'=>'Enter license key']); exit; }
        $ex = db()->one("SELECT id FROM panel_settings WHERE skey='panel_license_key'");
        if ($ex) db()->q("UPDATE panel_settings SET svalue=? WHERE skey='panel_license_key'",[$licKey]);
        else     db()->q("INSERT INTO panel_settings(skey,svalue) VALUES('panel_license_key',?)",[$licKey]);
        db()->q("UPDATE panel_settings SET svalue='' WHERE skey='lic_cache'");
        db()->q("UPDATE panel_settings SET svalue='0' WHERE skey='lic_cache_time'");
        logAct($_SESSION['uid'],$_SESSION['uname']??'','UPDATE_LICENSE_KEY','');
        echo json_encode(['ok'=>true,'msg'=>'License key saved!']);
        exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown section']); exit;
}

requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$pageTitle = 'Server & Maintenance';
$activeNav = 'panelset';
require_once __DIR__.'/app/Views/Layout/header.php';

$maintMode    = getSetting('maintenance_mode','0')==='1';
$maintMsg     = getSetting('maint_message','Server is under maintenance. Please try again later.');
$serverUrl    = getSetting('server_url','');
$panelBaseSet = getSetting('panel_base_url','');
$apkVer       = getSetting('apk_version','1.0.0');
$ddosProt     = getSetting('ddos_protection','1')==='1';
$maxAttempts  = getSetting('max_login_attempts','5');
$lockoutMins  = getSetting('lockout_minutes','15');
$curLicKey    = getSetting('panel_license_key','');
$licValid     = null; $licDaysLeft = 0; $licExpiry = '';
if (!empty($curLicKey)) {
    $cached = getSetting('lic_cache','');
    if ($cached) {
        $licData = json_decode($cached,true);
        $licValid    = $licData['valid'] ?? null;
        $licDaysLeft = $licData['days_left'] ?? 0;
        $licExpiry   = $licData['expires_at'] ?? '';
    }
}

$ownerCount    = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level=1")->c??0);
$adminCount    = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level=2")->c??0);
$resellerCount = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level=3")->c??0);

$s = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="card" style="border-color:<?=$maintMode?'rgba(255,184,0,.3)':'var(--border)'?>">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      Maintenance Mode
    </span>
    <span id="maintBadge" class="<?=$maintMode?'pill p-warn':'pill p-on'?>">
      <span class="dot"></span><?=$maintMode?'ON':'OFF'?>
    </span>
  </div>
  <div class="card-b">
    <div class="fg">
      <label class="fl">Maintenance Message</label>
      <textarea class="fi" id="maintMsg" rows="2"><?=htmlspecialchars($maintMsg)?></textarea>
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-acc" style="flex:1" onclick="saveMaint(0)">
        <svg viewBox="0 0 24 24" <?=$s?> width="13" height="13"><polyline points="20 6 9 17 4 12"/></svg>
        Turn OFF
      </button>
      <button class="btn btn-warn" style="flex:1" onclick="saveMaint(1)">
        <svg viewBox="0 0 24 24" <?=$s?> width="13" height="13"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
        Turn ON
      </button>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
      Server Settings
    </span>
  </div>
  <div class="card-b">

    <div class="fg">
      <label class="fl">Base Domain <span style="color:var(--muted)">(for a new domain/subdomain — all links follow this)</span></label>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <input class="fi fi-mono" id="baseUrl" style="flex:1;min-width:200px" value="<?=htmlspecialchars($panelBaseSet)?>" placeholder="https://sub.yourdomain.com">
        <button class="btn btn-acc btn-sm" onclick="saveBase()">Save</button>
      </div>
      <div class="fi-hint">Blank = default <code><?=htmlspecialchars(rtrim(PANEL_URL,'/'))?></code>. Active now: <code><?=htmlspecialchars(panelBase())?></code></div>
    </div>
    <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
    <?php if(empty($serverUrl)): ?>

    <div class="alert a-info" style="margin-bottom:14px;font-size:11px">
      <svg viewBox="0 0 24 24" <?=$s?> width="13" height="13"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
      APK Connect URL sirf 1 baar set hoti hai — save ke baad permanently lock ho jaayegi.
    </div>
    <div class="fg">
      <label class="fl">APK Connect URL</label>
      <input class="fi fi-mono" id="serverUrl" placeholder="https://yourdomain.com/server/" oninput="updatePreview(this.value)">
      <div class="fi-hint" id="urlPreview" style="color:var(--acc);font-family:var(--mono);margin-top:6px;word-break:break-all"></div>
    </div>
    <div class="fg">
      <label class="fl">APK Version</label>
      <input class="fi fi-mono" id="apkVer" value="<?=htmlspecialchars($apkVer)?>" placeholder="1.0.0">
    </div>
    <button class="btn btn-acc btn-full" onclick="saveServer()">
      <svg viewBox="0 0 24 24" <?=$s?> width="13" height="13"><polyline points="20 6 9 17 4 12"/></svg>
      Save & Lock APK URL
    </button>

    <?php else: ?>

    <div style="background:var(--s3);border:1px solid rgba(0,229,160,.2);border-radius:10px;padding:14px;margin-bottom:12px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;gap:4px">
        <svg viewBox="0 0 24 24" <?=$s?> width="11" height="11"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/></svg>
        APK / Lib Connect URL
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap" id="urlLockedView">
        <code style="flex:1;font-size:12px;color:var(--acc);word-break:break-all;background:rgba(0,229,160,.06);padding:8px 10px;border-radius:7px;border:1px solid rgba(0,229,160,.15)"><?=htmlspecialchars($serverUrl)?></code>
        <button onclick="copyText('<?=htmlspecialchars(addslashes($serverUrl))?>','Connect URL')" class="btn btn-acc btn-sm" style="flex-shrink:0">
          <svg viewBox="0 0 24 24" <?=$s?> width="12" height="12"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          Copy
        </button>
        <button onclick="document.getElementById('urlEditView').style.display='block';this.parentElement.style.display='none'" class="btn btn-ghost btn-sm" style="flex-shrink:0">Edit</button>
      </div>
      <div id="urlEditView" style="display:none">
        <input class="fi fi-mono" id="serverUrl" value="<?=htmlspecialchars($serverUrl)?>" placeholder="https://arkcheat.battleground-hub.shop/validbool" oninput="updatePreview(this.value)" style="margin-bottom:8px">
        <div class="fi-hint" id="urlPreview" style="color:var(--acc);font-family:var(--mono);margin-bottom:8px;word-break:break-all"></div>
        <button class="btn btn-acc btn-sm" onclick="saveServer()">Save New URL</button>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:8px">
        APK &amp; lib use this URL to verify keys. Recommended: <code>https://arkcheat.battleground-hub.shop/validbool</code>
      </div>
    </div>
    <div class="fg">
      <label class="fl">APK Version</label>
      <input class="fi fi-mono" id="apkVer" value="<?=htmlspecialchars($apkVer)?>" placeholder="1.0.0">
    </div>
    <button class="btn btn-acc btn-full" onclick="saveApkVer()">
      <svg viewBox="0 0 24 24" <?=$s?> width="13" height="13"><polyline points="20 6 9 17 4 12"/></svg>
      Save APK Version
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Panel Stats (Owner Only)
    </span>
  </div>
  <div class="card-b">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
      <div style="background:var(--s3);border:1px solid rgba(0,229,160,.15);border-radius:10px;padding:14px;text-align:center">
        <div style="font-size:22px;font-weight:900;font-family:var(--mono);color:var(--acc)"><?=$ownerCount?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;margin-top:4px">Owners</div>
      </div>
      <div style="background:var(--s3);border:1px solid rgba(0,148,255,.15);border-radius:10px;padding:14px;text-align:center">
        <div style="font-size:22px;font-weight:900;font-family:var(--mono);color:var(--acc2)"><?=$adminCount?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;margin-top:4px">Admins</div>
      </div>
      <div style="background:var(--s3);border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:14px;text-align:center">
        <div style="font-size:22px;font-weight:900;font-family:var(--mono);color:var(--muted2)"><?=$resellerCount?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;margin-top:4px">Resellers</div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      DDoS Protection
    </span>
    <label class="tog">
      <input type="checkbox" id="ddosTog" <?=$ddosProt?'checked':''?> onchange="saveDdos()">
      <span class="togsl"></span>
    </label>
  </div>
  <div class="card-b">
    <div class="g2" style="gap:12px">
      <div class="fg" style="margin:0">
        <label class="fl">Max Login Attempts</label>
        <input class="fi" type="number" id="maxAttempts" value="<?=$maxAttempts?>" min="3" max="20">
      </div>
      <div class="fg" style="margin:0">
        <label class="fl">Lockout (Minutes)</label>
        <input class="fi" type="number" id="lockoutMins" value="<?=$lockoutMins?>" min="1" max="60">
      </div>
    </div>
    <button class="btn btn-acc btn-full" style="margin-top:12px" onclick="saveDdos()">
      <svg viewBox="0 0 24 24" <?=$s?> width="13" height="13"><polyline points="20 6 9 17 4 12"/></svg>
      Save DDoS Settings
    </button>
  </div>
</div>

<div class="card" style="border-color:<?=$licValid===false?'rgba(255,77,109,.3)':($licValid===true?'rgba(0,229,160,.2)':'var(--border)')?>">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Panel License Key
    </span>
    <?php if($licValid===true): ?>
      <span class="bx b-active">● ACTIVE — <?=$licDaysLeft?>d left</span>
    <?php elseif($licValid===false): ?>
      <span class="bx b-ban">● EXPIRED</span>
    <?php elseif(!empty($curLicKey)): ?>
      <span class="bx b-reseller">● Not Verified</span>
    <?php endif; ?>
  </div>
  <div class="card-b">
    <?php if($licValid===false): ?>
    <div class="alert a-err" style="margin-bottom:14px">License expired! Enter new key below.</div>
    <?php elseif($licValid===true && $licDaysLeft<=7): ?>
    <div class="alert a-warn" style="margin-bottom:14px">Expiring in <?=$licDaysLeft?> days! Contact @Hamzakhan24</div>
    <?php endif; ?>

    <?php if($curLicKey): ?>
    <div style="background:var(--s3);border:1px solid var(--border);border-radius:9px;padding:12px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
      <div>
        <div style="font-size:10px;color:var(--muted);margin-bottom:3px">Current Key</div>
        <div style="font-family:var(--mono);color:var(--acc);font-weight:700"><?=htmlspecialchars($curLicKey)?></div>
      </div>
      <?php if($licExpiry): ?>
      <div style="text-align:right">
        <div style="font-size:10px;color:var(--muted)">Expires</div>
        <div style="font-size:12px;font-family:var(--mono);color:<?=$licDaysLeft<=7?'var(--danger)':'var(--muted2)'?>"><?=date('d M Y',strtotime($licExpiry))?></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="fg">
      <label class="fl">Enter / Update License Key</label>
      <input class="fi fi-mono" type="text" id="newLicKey" placeholder="HBL-XXXXX-XXXXX-XXXXX-XXXXX" oninput="this.value=this.value.toUpperCase()">
    </div>
    <button onclick="saveLicense()" class="btn btn-acc btn-full">
      <svg viewBox="0 0 24 24" <?=$s?> width="13" height="13"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Save License Key
    </button>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;

async function saveMaint(on){
  const msg = document.getElementById('maintMsg').value;
  const r = await formPost('/panelset.php',{ajax:1,section:'maintenance',maintenance_mode:on,maint_message:msg,csrf});
  if(r.ok){
    toast(r.msg, 's');
    const badge = document.getElementById('maintBadge');
    if(on==1){ badge.className='pill p-warn'; badge.innerHTML='<span class="dot"></span>ON'; }
    else { badge.className='pill p-on'; badge.innerHTML='<span class="dot"></span>OFF'; }
  } else toast(r.msg,'e');
}

function updatePreview(val){
  const p = document.getElementById('urlPreview');
  if(p) p.textContent = val ? '→ ' + val : '';
}
async function saveApkVer(){
  const r = await formPost('/panelset.php',{ajax:1,section:'server',server_url:document.getElementById('serverUrl')?.value||'<?=addslashes($serverUrl)?>',apk_version:document.getElementById('apkVer').value,csrf});
  r.ok ? toast(r.msg,'s') : toast(r.msg,'e');
}
async function saveBase(){
  const b=document.getElementById('baseUrl').value.trim();
  const r=await formPost('/panelset.php',{ajax:1,section:'base',base_url:b,csrf});
  r.ok?toast(r.msg,'s'):toast(r.msg,'e');
  if(r.ok) setTimeout(()=>location.reload(),800);
}
async function saveServer(){
  const r = await formPost('/panelset.php',{ajax:1,section:'server',server_url:document.getElementById('serverUrl').value,apk_version:document.getElementById('apkVer').value,csrf});
  r.ok ? toast(r.msg,'s') : toast(r.msg,'e');
  if(r.ok) setTimeout(()=>location.reload(),700);
}

async function saveDdos(){
  const r = await formPost('/panelset.php',{ajax:1,section:'ddos',ddos_protection:document.getElementById('ddosTog').checked?'1':'0',max_login_attempts:document.getElementById('maxAttempts').value,lockout_minutes:document.getElementById('lockoutMins').value,csrf});
  r.ok ? toast(r.msg,'s') : toast(r.msg,'e');
}

async function saveLicense(){
  const key = document.getElementById('newLicKey').value.trim();
  if(!key){toast('Enter license key','e');return;}
  const r = await formPost('/panelset.php',{ajax:1,section:'license',license_key:key,csrf});
  if(r.ok){toast(r.msg,'s');setTimeout(()=>location.reload(),1200);}
  else toast(r.msg,'e');
}
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>