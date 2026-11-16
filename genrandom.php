<?php
$pageTitle='Random Keys'; $activeNav='genrandom';
require_once __DIR__.'/app/Views/Layout/header.php';

$msg=''; $genKeys=[];
$keyPrefix  = getSetting('key_prefix','HB');
$keyFormat  = getSetting('key_format','classic');
$oneDevMode = getSetting('one_device_mode','0') === '1';
$maxCap     = max(1,(int)getSetting('max_devices_cap','10'));

if ($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrf($_POST['csrf']??'')) {
    $duration = trim($_POST['duration']??'30 Days');
    $qty      = max(1,min(10,(int)($_POST['qty']??1)));
    $maxDev   = $oneDevMode ? 1 : min($maxCap, max(1,(int)($_POST['max_devices']??1)));
    $pr = db()->one("SELECT hours,price FROM prices WHERE duration=?",[$duration]);
    if ($pr) {
        $hrs=(int)$pr->hours;
        $exp=date('Y-m-d H:i:s',strtotime("+{$hrs} hours"));
        for ($i=0;$i<$qty;$i++) {
            $k=generateKey($keyPrefix);
            while(db()->one("SELECT id FROM keys_code WHERE user_key=?",[$k])) $k=generateKey($keyPrefix);
            db()->q("INSERT INTO keys_code(user_key,game,duration,max_devices,status,registrator,expired_date) VALUES(?,?,?,?,1,?,?)",
                [$k,'PUBG',$hrs,$maxDev,$uname,$exp]);
            $genKeys[]=$k;
        }
        logAct($_SESSION['uid'],$uname,'GEN_RANDOM_KEYS',"{$qty} keys {$duration}");
        $msg="Generated {$qty} keys!";
    }
}
$prices=db()->all("SELECT * FROM prices ORDER BY hours ASC");
?>
<?php if($msg): ?><div class="alert a-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if(!empty($genKeys)): ?>

<div class="card" style="margin-bottom:16px;border-color:rgba(0,229,160,.35);box-shadow:0 0 30px rgba(0,229,160,.12)">
  <div class="card-h" style="background:rgba(0,229,160,.06)"><span class="ct" style="color:var(--acc)"><?= count($genKeys) ?> Key<?= count($genKeys)>1?'s':'' ?> Generated!</span><button class="btn btn-acc btn-sm" onclick="copyText(document.getElementById('keyBatch').textContent.trim(),'All keys')">Copy All</button></div>
  <div class="card-b" style="padding:14px">
    <div style="background:#04060e;border:1px solid rgba(0,229,160,.15);border-radius:10px;padding:14px;font-family:var(--mono);font-size:13px;color:var(--acc);line-height:2;max-height:240px;overflow:auto;word-break:break-all" id="keyBatch"><?= implode("\n",array_map('htmlspecialchars',$genKeys)) ?></div>
    <div style="display:flex;gap:8px;margin-top:12px">
      <button class="btn btn-acc" style="flex:1" onclick="copyText(document.getElementById('keyBatch').textContent.trim(),'All keys')">Copy All</button>
      <button class="btn btn-ghost" style="flex:1" onclick="downloadBatch()">Download .txt</button>
    </div>
    <script>
      function downloadBatch(){const k=document.getElementById('keyBatch').textContent.trim();const b=new Blob([k+'\n'],{type:'text/plain'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='keys-<?= date('Y-m-d') ?>.txt';a.click();}

      try{ navigator.clipboard.writeText(document.getElementById('keyBatch').textContent.trim()).then(()=>{ if(window.toast) toast('Keys auto-copied!','s'); }); }catch(e){}
    </script>
  </div>
</div>
<?php endif; ?>

<div class="g-left">
  <div class="card">
    <div class="card-h"><span class="ct">Generate Random Keys</span></div>
    <div class="card-b">
      <?php if($oneDevMode): ?><div class="alert a-warn" style="margin-bottom:14px">One Device Mode ON — Keys limited to 1 device</div><?php endif; ?>
      <form method="POST"><?= csrfField() ?>
        <input type="hidden" name="game" value="PUBG">
        <div style="padding:10px 14px;background:rgba(0,229,160,.06);border:1px solid rgba(0,229,160,.15);border-radius:8px;margin-bottom:14px;font-family:var(--mono);font-size:13px;color:var(--acc);font-weight:700">PUBG</div>
        <div class="g2" style="gap:12px">
          <?php if($ulevel<=2): ?>
          <div class="fg" style="margin:0 0 14px"><label class="fl">Quantity (Max 10)</label><input class="fi fi-mono" type="number" name="qty" value="1" min="1" max="10"></div>
          <?php else: ?>
          <div class="fg" style="margin:0 0 14px"><label class="fl">Quantity</label><input class="fi fi-mono" type="number" name="qty" value="1" min="1" max="1" readonly style="opacity:.5"><div class="fi-hint">Resellers can only generate 1 key at a time</div></div>
          <?php endif; ?>
          <div class="fg" style="margin:0 0 14px"><label class="fl">Max Devices Each <?= $oneDevMode?'<span style="color:var(--warn)">(Locked:1)</span>':'' ?></label><input class="fi fi-mono" type="number" name="max_devices" value="1" min="1" max="<?= $oneDevMode?'1':$maxCap ?>" <?= $oneDevMode?'readonly style="opacity:.5"':'' ?>></div>
        </div>
        <div class="fg"><label class="fl">Duration</label>
          <select class="fi" name="duration">
            <?php foreach($prices as $pr): ?><option value="<?= htmlspecialchars($pr->duration) ?>"><?= htmlspecialchars($pr->duration) ?> — $<?= $pr->price ?></option><?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-acc btn-full">GENERATE KEYS</button>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>