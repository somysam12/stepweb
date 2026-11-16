<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['ajax'])) {
    requireLogin();
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';
    $ulevel = (int)($_SESSION['ulevel'] ?? 99);


    if ($action === 'check') {
        $masterUrl = getSetting('update_server_url', '');
        if (empty($masterUrl)) {
            echo json_encode(['ok'=>false,'msg'=>'Update server URL not configured']);
            exit;
        }
        $ch = curl_init($masterUrl . '/updates/version.json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'HBPanel-Updater/1.0',
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$res || $code !== 200) {
            echo json_encode(['ok'=>false,'msg'=>'Cannot reach update server']);
            exit;
        }
        $data = json_decode($res, true);
        if (!$data || empty($data['version'])) {
            echo json_encode(['ok'=>false,'msg'=>'Invalid version data from server']);
            exit;
        }

        $current = getSetting('panel_version','3.0.0');
        $latest  = $data['version'];
        $hasUpdate = version_compare($latest, $current, '>');

        echo json_encode([
            'ok'         => true,
            'current'    => $current,
            'latest'     => $latest,
            'has_update' => $hasUpdate,
            'changelog'  => $data['changelog'] ?? [],
            'date'       => $data['date'] ?? '',
        ]);
        exit;
    }


    if ($action === 'apply') {
        if ($ulevel !== 1) {
            echo json_encode(['ok'=>false,'msg'=>'Owner only']);
            exit;
        }
        if (!verifyCsrf($_POST['csrf']??'')) {
            echo json_encode(['ok'=>false,'msg'=>'Invalid token']);
            exit;
        }

        $masterUrl = getSetting('update_server_url','');
        $secToken  = getSetting('update_secret_token','');

        if (empty($masterUrl) || empty($secToken)) {
            echo json_encode(['ok'=>false,'msg'=>'Update server not configured in settings']);
            exit;
        }


        $zipUrl = $masterUrl . '/updates/latest.zip?token=' . urlencode($secToken);
        $tmpZip = sys_get_temp_dir() . '/hbpanel_update_' . time() . '.zip';

        $ch = curl_init($zipUrl);
        $fp = fopen($tmpZip, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'HBPanel-Updater/1.0',
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$res || $code !== 200) {
            @unlink($tmpZip);
            echo json_encode(['ok'=>false,'msg'=>"Download failed (HTTP $code). Check token."]); exit;
        }

        if (!class_exists('ZipArchive')) {
            @unlink($tmpZip);
            echo json_encode(['ok'=>false,'msg'=>'ZipArchive not available on server']); exit;
        }


        $zip  = new ZipArchive();
        $root = __DIR__;

        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            echo json_encode(['ok'=>false,'msg'=>'Cannot open ZIP file']); exit;
        }



        $skipFiles = [
            'app/Config/config.php',
            'app/Config/db.php',
            'index.php',
            'server/index.php',
            'register.php',
            'reset-device.php',
            'forgot.php',
            'setup.php',
        ];


        $skipHtaccess = true;

        $extracted = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            $parts = explode('/', $name, 2);
            $relative = count($parts) > 1 ? $parts[1] : $name;
            if (empty($relative)) continue;
            if (in_array($relative, $skipFiles)) continue;

            if ($skipHtaccess && basename($relative) === '.htaccess') continue;

            $dest = $root . '/' . $relative;
            if (substr($name, -1) === '/') {
                @mkdir($dest, 0755, true);
            } else {
                @mkdir(dirname($dest), 0755, true);
                file_put_contents($dest, $zip->getFromIndex($i));
                $extracted++;
            }
        }
        $zip->close();
        @unlink($tmpZip);


        $ch2  = curl_init($masterUrl . '/updates/version.json');
        curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>false]);
        $vRes = curl_exec($ch2); curl_close($ch2);
        $vData= json_decode($vRes, true);
        $newVer = $vData['version'] ?? '3.1.0';


        db()->q("UPDATE panel_settings SET svalue=? WHERE skey='panel_version'", [$newVer]);
        logAct($_SESSION['uid'], $_SESSION['uname']??'', 'PANEL_UPDATE', "Updated to v$newVer ($extracted files)");

        echo json_encode([
            'ok'      => true,
            'msg'     => "Panel updated to v$newVer successfully! ($extracted files)",
            'version' => $newVer,
        ]);
        exit;
    }


    if ($action === 'save_config') {
        if ($ulevel !== 1) { echo json_encode(['ok'=>false,'msg'=>'Owner only']); exit; }
        $url    = rtrim(trim($_POST['url']??''),'/');
        $token  = trim($_POST['token']??'');
        $licKey = trim($_POST['lic_key']??'');
        if (empty($url)||empty($token)) { echo json_encode(['ok'=>false,'msg'=>'Fill both fields']); exit; }

        $settings = [
            'update_server_url'    => $url,
            'update_secret_token'  => $token,
            'lic_cache'            => '',
            'lic_cache_time'       => '0',
        ];
        if (!empty($licKey)) $settings['panel_license_key'] = $licKey;

        foreach ($settings as $k => $v) {
            $ex = db()->one("SELECT id FROM panel_settings WHERE skey=?",[$k]);
            if ($ex) db()->q("UPDATE panel_settings SET svalue=? WHERE skey=?",[$v,$k]);
            else     db()->q("INSERT INTO panel_settings(skey,svalue) VALUES(?,?)",[$k,$v]);
        }
        echo json_encode(['ok'=>true,'msg'=>'Config saved!']); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$pageTitle = 'Panel Update'; $activeNav = 'panelupdate';
require_once __DIR__.'/app/Views/Layout/header.php';

$currentVer   = getSetting('panel_version','3.0.0');
$masterUrl    = getSetting('update_server_url','');
$secToken     = getSetting('update_secret_token','');
$configured   = !empty($masterUrl) && !empty($secToken);
$s = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="g2">

  <div class="card">
    <div class="card-h">
      <span class="ct">
        <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg>
        Panel Update Manager
      </span>
      <span id="upBadge" class="bx b-active">Checking...</span>
    </div>
    <div class="card-b">

      <div id="upStatus" style="background:rgba(0,229,160,.05);border:1px solid rgba(0,229,160,.15);border-radius:10px;padding:16px;margin-bottom:16px;text-align:center">
        <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Checking for updates...</div>
        <div style="width:40px;height:40px;margin:0 auto;border:3px solid rgba(0,229,160,.2);border-top-color:var(--acc);border-radius:50%;animation:spin 1s linear infinite"></div>
      </div>


      <div style="display:flex;gap:12px;margin-bottom:14px">
        <div style="flex:1;background:var(--s3);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center">
          <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">Current Version</div>
          <div style="font-size:28px;font-weight:900;font-family:var(--mono);color:var(--acc)"><?=$currentVer?></div>
        </div>
        <div style="flex:1;background:var(--s3);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center">
          <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">New Version</div>
          <div id="newVerDisplay" style="font-size:28px;font-weight:900;font-family:var(--mono);color:var(--muted2)">—</div>
          <div id="newVerDate" style="font-size:10px;color:var(--muted);margin-top:4px"></div>
        </div>
      </div>


      <div style="display:flex;gap:10px;margin-bottom:14px">
        <button class="btn btn-ghost" style="flex:1" onclick="checkUpdate()">
          <svg viewBox="0 0 24 24" <?=$s?> width="13" height="13"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Check for Update
        </button>
        <button id="applyBtn" class="btn btn-acc" style="flex:1;display:none" onclick="applyUpdate()">
          <svg viewBox="0 0 24 24" <?=$s?> width="13" height="13"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg>
          Apply Update
        </button>
      </div>


      <div id="changelogBox" style="display:none">
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;display:flex;align-items:center;gap:6px">
          <svg viewBox="0 0 24 24" <?=$s?> width="12" height="12"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          What's New
        </div>
        <div id="changelogList" style="background:var(--s3);border:1px solid var(--border);border-radius:8px;padding:12px"></div>
      </div>

      <?php if(!$configured): ?>
      <div class="alert a-warn" style="margin-top:14px">
        <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Update server not configured. Set it below.
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php if(!$configured): ?>

  <div class="card" id="cfgCard">
    <div class="card-h">
      <span class="ct">
        <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Update Server Config
      </span>
    </div>
    <div class="card-b">
      <div class="alert a-info" style="margin-bottom:16px;font-size:11px">
        Ek baar config karo — phir ye section hamesha ke liye hide ho jaayega.
      </div>
      <form onsubmit="saveConfig(event)">
        <div class="fg">
          <label class="fl">Master Domain URL</label>
          <input class="fi" type="url" id="cfgUrl" placeholder="https://battleground-hub.shop">
          <div class="fi-hint">Without trailing slash</div>
        </div>
        <div class="fg">
          <label class="fl">Secret Token</label>
          <div style="display:flex;align-items:center;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden">
            <input class="fi fi-mono" type="password" id="cfgToken" placeholder="Enter secret token" style="border:none;border-radius:0">
            <button type="button" onclick="toggleTok()" style="padding:0 12px;background:none;border:none;color:var(--muted);cursor:pointer;font-size:12px;white-space:nowrap;font-family:var(--mono)">Show</button>
          </div>
          <div class="fi-hint">Token jo battleground-hub.shop ke .htaccess mein hai</div>
        </div>
        <button type="submit" class="btn btn-acc btn-full">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><polyline points="20 6 9 17 4 12"/></svg>
          Save & Connect
        </button>
      </form>
    </div>
  </div>
<?php endif; ?>
</div>

<style>
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;

async function checkUpdate(){
  document.getElementById('upStatus').innerHTML = '<div style="font-size:12px;color:var(--muted);margin-bottom:8px">Checking...</div><div style="width:30px;height:30px;margin:0 auto;border:3px solid rgba(0,229,160,.2);border-top-color:var(--acc);border-radius:50%;animation:spin 1s linear infinite"></div>';
  document.getElementById('upBadge').className='bx b-exp';
  document.getElementById('upBadge').textContent='Checking...';
  document.getElementById('applyBtn').style.display='none';
  document.getElementById('changelogBox').style.display='none';

  const r = await formPost('/panelupdate.php',{ajax:1,action:'check',csrf});
  if(!r.ok){
    document.getElementById('upStatus').innerHTML = '<div style="color:var(--danger);font-size:13px;font-weight:700">'+r.msg+'</div>';
    document.getElementById('upBadge').className='bx b-ban';
    document.getElementById('upBadge').textContent='Error';
    return;
  }

  document.getElementById('newVerDisplay').textContent = r.latest;
  document.getElementById('newVerDate').textContent = r.date || '';

  if(r.has_update){
    document.getElementById('upStatus').innerHTML = '<div style="color:var(--warn);font-size:13px;font-weight:700;margin-bottom:4px">New version available!</div><div style="font-size:11px;color:var(--muted)">v'+r.current+' → v'+r.latest+'</div>';
    document.getElementById('upBadge').className='bx b-exp';
    document.getElementById('upBadge').textContent='Update Available';
    document.getElementById('applyBtn').style.display='flex';
    document.getElementById('newVerDisplay').style.color='var(--warn)';


    if(r.changelog && r.changelog.length){
      const list = r.changelog.map(c=>`<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:12px;color:var(--text)"><span style="color:var(--acc);font-weight:900">•</span>${c}</div>`).join('');
      document.getElementById('changelogList').innerHTML = list;
      document.getElementById('changelogBox').style.display='block';
    }
  } else {
    document.getElementById('upStatus').innerHTML = '<div style="color:var(--acc);font-size:13px;font-weight:700">Already up to date — v'+r.current+'</div>';
    document.getElementById('upBadge').className='bx b-active';
    document.getElementById('upBadge').textContent='Up to Date';
    document.getElementById('newVerDisplay').style.color='var(--acc)';
  }
}

async function applyUpdate(){
  const btn = document.getElementById('applyBtn');
  btn.disabled=true; btn.textContent='Updating...';
  const r = await formPost('/panelupdate.php',{ajax:1,action:'apply',csrf});
  if(r.ok){
    toast(r.msg,'s');
    setTimeout(()=>location.reload(),2000);
  } else {
    toast(r.msg,'e');
    btn.disabled=false;
    btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg> Apply Update';
  }
}

function toggleTok(){
  const inp = document.getElementById('cfgToken');
  const btn = inp.nextElementSibling;
  if(inp.type==='password'){ inp.type='text'; btn.textContent='Hide'; }
  else { inp.type='password'; btn.textContent='Show'; }
}
async function saveConfig(e){
  e.preventDefault();
  const url   = document.getElementById('cfgUrl').value.trim().replace(/\/$/,'');
  const token = document.getElementById('cfgToken').value.trim();
  if(!url||!token){toast('Fill both fields','e');return;}
  const r = await formPost('/panelupdate.php',{ajax:1,action:'save_config',url,token,csrf});
  if(r.ok){
    toast('Connected! Config saved permanently.','s');

    const card = document.getElementById('cfgCard');
    if(card){ card.style.transition='opacity .4s'; card.style.opacity='0'; setTimeout(()=>card.remove(),400); }
    setTimeout(()=>checkUpdate(), 800);
  } else toast(r.msg,'e');
}

window.addEventListener('load', checkUpdate);
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>