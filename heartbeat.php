<?php

require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }


    if (($_POST['action'] ?? '') === 'set_base') {
        $b = trim($_POST['base_url'] ?? '');
        if ($b !== '' && !preg_match('#^https?://[^\s/]+#i', $b)) {
            echo json_encode(['ok'=>false,'msg'=>'Enter a full URL, e.g. https://sub.yourdomain.com']); exit;
        }
        setSetting('panel_base_url', rtrim($b,'/'));
        logAct($_SESSION['uid'],$_SESSION['uname']??'','SET_BASE_URL',$b?:'(default)');
        echo json_encode(['ok'=>true,'msg'=>$b?'Base domain set — all links updated.':'Base domain reset to default.']);
        exit;
    }

    $clean = fn($h)=>strtolower(preg_replace('/[^a-fA-F0-9]/','',trim($h ?? '')));
    $bgmi  = $clean($_POST['hash_bgmi']  ?? '');
    $sh    = $clean($_POST['hash_shaitaan'] ?? '');
    $el    = $clean($_POST['hash_elite'] ?? '');
    foreach ([$bgmi,$sh,$el] as $hh) {
        if ($hh !== '' && strlen($hh) !== 64) { echo json_encode(['ok'=>false,'msg'=>'Each hash must be 64 hex chars (sha256) or empty.']); exit; }
    }

    db()->q("INSERT INTO lib(id,lib_name,lib_hash) VALUES(1,'libbgmi.so',?) ON DUPLICATE KEY UPDATE lib_hash=?", [$bgmi,$bgmi]);
    setSetting('hash_libshaitaan',  $sh);
    setSetting('hash_libelitecore', $el);
    logAct($_SESSION['uid'],$_SESSION['uname']??'','SET_LIB_HASHES','');
    echo json_encode(['ok'=>true,'msg'=>'Lib hashes saved.']);
    exit;
}

$pageTitle = 'Heartbeat & Security';
$activeNav = 'heartbeat';
require_once __DIR__.'/app/Views/Layout/header.php';

$base       = panelBase();
$panelBaseSet = getSetting('panel_base_url','');
$hbUrl      = $base.'/p/sw.js';
$unlockUrl  = $base.'/secure/unlock';
$libUrl     = $base.'/secure/lib';
$validUrl   = $base.'/validbool';
$libKillUrl = $base.'/server/lib_version.php';
$apkKillUrl = $base.'/server/apk_kill.php';

$libHash  = getSetting('lib_kill_below','') !== '' ? '' : '';
$libRow   = db()->one("SELECT lib_hash FROM lib WHERE id=1 LIMIT 1");
$bgmiHash = $libRow->lib_hash ?? '';
$hashShaitaan = getSetting('hash_libshaitaan','');
$hashElite    = getSetting('hash_libelitecore','');
$hbCsrf   = csrfToken();
$maint    = getSetting('maintenance_mode','0')==='1';
$maintMsg = getSetting('maint_message','');
$sv       = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';

function row(string $label,string $url):string{
  $u=htmlspecialchars($url); $a=htmlspecialchars(addslashes($url));
  return '<div style="margin-bottom:12px"><div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px">'.htmlspecialchars($label).'</div>'
    .'<div class="code-box">'.$u.'<button class="cb-copy" onclick="copyText(\''.$a.'\',\''.htmlspecialchars($label).'\')">Copy</button></div></div>';
}
?>
<style>
.code-box{background:var(--bg2,rgba(255,255,255,.03));border:1px solid var(--border);border-radius:8px;padding:12px 14px;font-family:var(--mono);font-size:11px;color:var(--acc);word-break:break-all;position:relative}
.code-box .cb-copy{position:absolute;top:8px;right:8px;padding:3px 8px;font-size:10px;background:var(--s3,rgba(255,255,255,.06));border:1px solid var(--border);border-radius:4px;cursor:pointer;color:var(--muted);font-family:var(--mono)}
.hb-badge{display:inline-flex;align-items:center;gap:7px;padding:5px 12px;border-radius:20px;font-family:var(--mono);font-size:11px;font-weight:700}
.hb-dot{width:8px;height:8px;border-radius:50%;background:currentColor;box-shadow:0 0 8px currentColor}
.hb-layer{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border)}
.hb-layer:last-child{border-bottom:none}
.hb-ic{width:26px;height:26px;border-radius:7px;background:rgba(0,229,160,.1);border:1px solid rgba(0,229,160,.25);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--acc)}
</style>

<div class="card" style="margin-bottom:16px">
  <div class="card-h"><span class="ct"><svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> Base Domain</span></div>
  <div class="card-b">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <input type="text" class="fi fi-mono" id="hbBase" style="flex:1;min-width:220px" value="<?=htmlspecialchars($panelBaseSet)?>" placeholder="https://sub.yourdomain.com">
      <button class="btn btn-acc" onclick="saveHbBase()">Save Domain</button>
    </div>
    <div style="margin-top:8px;font-size:11px;color:var(--muted)">All links below rebuild from this. Blank = default. Active now: <code><?=htmlspecialchars($base)?></code></div>
  </div>
</div>

<div class="g2">
  <div>

    <div class="card">
      <div class="card-h">
        <span class="ct"><svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg> Heartbeat Endpoint</span>
        <span id="hbStatus" class="hb-badge" style="background:rgba(255,184,0,.12);color:#ffb800"><span class="hb-dot"></span> CHECKING…</span>
      </div>
      <div class="card-b">
        <div class="info-box" style="background:rgba(99,102,241,.05);border:1px solid rgba(99,102,241,.2);border-radius:8px;padding:12px 14px;font-size:12px;color:#a5b4fc;line-height:1.6;margin-bottom:14px">
          The APK posts an encrypted beat here every 60s. A plain <code>GET</code> returns a fake JS
          string, so it looks like an ordinary script to scanners. The live check below confirms it responds.
        </div>
        <?= row('Heartbeat URL (POST from APK)', $hbUrl) ?>
        <div id="hbProbe" style="font-size:12px;color:var(--muted);font-family:var(--mono);margin-top:6px"></div>
      </div>
    </div>


    <div class="card">
      <div class="card-h"><span class="ct"><svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> All Security Endpoints</span></div>
      <div class="card-b">
        <?= row('Key verify (login)', $validUrl) ?>
        <?= row('Lib unlock (grant)', $unlockUrl) ?>
        <?= row('Gated lib download', $libUrl) ?>
        <?= row('Lib kill-check', $libKillUrl) ?>
        <?= row('APK kill / update-check', $apkKillUrl) ?>
      </div>
    </div>


    <div class="card">
      <div class="card-h"><span class="ct"><svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Lib Names &amp; Hashes</span></div>
      <div class="card-b">
        <div class="info-box" style="background:rgba(99,102,241,.05);border:1px solid rgba(99,102,241,.2);border-radius:8px;padding:12px 14px;font-size:12px;color:#a5b4fc;line-height:1.6;margin-bottom:14px">
          Paste the SHA-256 of each lib. <code>libbgmi.so</code> is verified live by the heartbeat &amp; unlock;
          the other two are the fixed native libs the APK checks. Get a hash with
          <code>sha256sum libname.so</code>. Leave blank to skip a check.
        </div>
        <div class="fg">
          <label class="fl">libbgmi.so <span style="color:var(--muted)">(dynamic — heartbeat/unlock)</span></label>
          <input class="fi fi-mono" id="hBgmi" value="<?=htmlspecialchars($bgmiHash)?>" placeholder="64-char sha256" style="font-size:11px">
        </div>
        <div class="fg">
          <label class="fl">libshaitaan.so</label>
          <input class="fi fi-mono" id="hShaitaan" value="<?=htmlspecialchars($hashShaitaan)?>" placeholder="64-char sha256" style="font-size:11px">
        </div>
        <div class="fg">
          <label class="fl">libEliteCore.so</label>
          <input class="fi fi-mono" id="hElite" value="<?=htmlspecialchars($hashElite)?>" placeholder="64-char sha256" style="font-size:11px">
        </div>
        <button class="btn btn-acc btn-full" onclick="saveHashes()">Save Lib Hashes</button>
      </div>
    </div>
  </div>

  <div>

    <div class="card">
      <div class="card-h"><span class="ct"><svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Active Security Layers</span></div>
      <div class="card-b">
        <?php
        $layers = [
          ['AES-256-GCM + HMAC','Every heartbeat/unlock body is authenticated-encrypted. Capture = useless.'],
          ['Replay window (120s)','Old captured beats are rejected by the timestamp check.'],
          ['Lib whitelist','Only the 4 allowed .so libs; any extra (libnull etc.) = crash.'],
          ['libbgmi hash','Dynamic hash from DB, cross-checked every beat.'],
          ['Package binding','First beat binds the package; clones with another pkg are rejected.'],
          ['Login-patch proof','Lib unlock re-checks the key in DB — a faked login never gets a grant.'],
        ];
        foreach($layers as $l): ?>
        <div class="hb-layer">
          <div class="hb-ic"><svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg></div>
          <div><div style="font-weight:700;font-size:12px"><?=htmlspecialchars($l[0])?></div>
            <div style="font-size:11px;color:var(--muted);line-height:1.5"><?=htmlspecialchars($l[1])?></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>


    <div class="card">
      <div class="card-h"><span class="ct"><svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Current State</span></div>
      <div class="card-b">
        <div class="srow"><span>Maintenance mode</span>
          <span class="bx <?=$maint?'b-ban':'b-active'?>"><?=$maint?'ON':'OFF'?></span></div>
        <div class="srow"><span>libbgmi.so hash set</span>
          <span class="bx <?=$bgmiHash?'b-active':'b-unused'?>"><?=$bgmiHash?'YES':'NOT SET'?></span></div>
        <?php if($maint && $maintMsg): ?>
        <div style="margin-top:10px;font-size:11px;color:var(--muted)">Maintenance message sent to APK:</div>
        <div class="code-box" style="margin-top:6px;color:var(--warn)"><?=htmlspecialchars($maintMsg)?></div>
        <?php endif; ?>
        <div style="margin-top:12px;font-size:11px;color:var(--muted);line-height:1.6">
          Your custom maintenance message is sent to the APK at login by <code>/validbool</code> and
          <code>/connect</code>. While maintenance is ON, the heartbeat pauses gracefully (no crash) and
          <code>/secure/unlock</code> blocks lib delivery — so the APK &amp; lib both honour it.
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const HB_CSRF = '<?=htmlspecialchars($hbCsrf,ENT_QUOTES)?>';
async function saveHbBase(){
  const b=document.getElementById('hbBase').value.trim();
  const body=new URLSearchParams({ajax:'1',csrf:HB_CSRF,action:'set_base',base_url:b});
  const r=await fetch('/heartbeat.php',{method:'POST',body});
  const d=await r.json();
  toast(d.msg, d.ok?'s':'e');
  if(d.ok) setTimeout(()=>location.reload(),800);
}
async function saveHashes(){
  const body = new URLSearchParams({
    ajax:'1', csrf:HB_CSRF,
    hash_bgmi: document.getElementById('hBgmi').value.trim(),
    hash_shaitaan: document.getElementById('hShaitaan').value.trim(),
    hash_elite: document.getElementById('hElite').value.trim()
  });
  const r = await fetch('/heartbeat.php', {method:'POST', body});
  const d = await r.json();
  toast(d.msg, d.ok?'s':'e');
  if(d.ok) setTimeout(()=>location.reload(), 900);
}

(function(){
  const badge=document.getElementById('hbStatus');
  const probe=document.getElementById('hbProbe');
  fetch('<?=$hbUrl?>',{method:'GET',cache:'no-store'})
    .then(r=>r.text().then(t=>({ok:r.ok,t})))
    .then(({ok,t})=>{
      const live = ok && /\/\*\s*v\d/.test(t);
      if(live){ badge.style.background='rgba(45,212,80,.14)'; badge.style.color='#2dd450';
        badge.innerHTML='<span class="hb-dot"></span> ACTIVE'; probe.textContent='Response: '+t.trim().slice(0,40); }
      else { badge.style.background='rgba(255,77,109,.14)'; badge.style.color='#ff4d6d';
        badge.innerHTML='<span class="hb-dot"></span> UNEXPECTED'; probe.textContent='Got: '+t.trim().slice(0,60); }
    })
    .catch(e=>{ badge.style.background='rgba(255,77,109,.14)'; badge.style.color='#ff4d6d';
      badge.innerHTML='<span class="hb-dot"></span> UNREACHABLE'; probe.textContent=String(e); });
})();
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>