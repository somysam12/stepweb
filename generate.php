<?php
$pageTitle = 'Generate Key';
$activeNav = 'generate';
require_once __DIR__.'/app/Views/Layout/header.php';

$success = []; $error = '';
$keyPrefix  = getSetting('key_prefix','HB');
$keyFormat  = getSetting('key_format','classic');
$oneDevMode = getSetting('one_device_mode','0') === '1';
$maxCap     = max(1,(int)getSetting('max_devices_cap','10'));

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verifyCsrf($_POST['csrf']??'')) { $error='Invalid token.'; }
    else {
        $duration  = trim($_POST['duration']??'30 Days');
        $maxDev    = $oneDevMode ? 1 : min($maxCap, max(1,(int)($_POST['max_devices']??1)));
        $qty       = max(1,min(10,(int)($_POST['qty']??1)));
        $customKey = trim($_POST['custom_key']??'');
        $isCustom  = !empty($_POST['is_custom']) && $customKey!=='' && $qty===1;

        $priceRow = db()->one("SELECT hours,price FROM prices WHERE duration=?",[$duration]);
        if (!$priceRow) { $error='Invalid duration.'; }
        else {
            $hours  = (int)$priceRow->hours;
            $cost   = (float)$priceRow->price * $maxDev * $qty;
            $expiry = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));


            if ($ulevel > 1) {
                $bal = (float)(db()->one("SELECT balance FROM users WHERE id=?",[$_SESSION['uid']])->balance??0);
                if ($bal < $cost) { $error="Insufficient balance. Need \${$cost}, have \${$bal}."; }
            }

            if (!$error) {
                for ($i=0; $i<$qty; $i++) {
                    $key = ($isCustom && $i===0) ? strtoupper($customKey) : generateKey($keyPrefix);
                    while(db()->one("SELECT id FROM keys_code WHERE user_key=?",[$key])) $key=generateKey($keyPrefix);
                    db()->q("INSERT INTO keys_code(user_key,game,duration,max_devices,status,registrator,expired_date) VALUES(?,?,?,?,1,?,?)",
                        [$key,'PUBG',$hours,$maxDev,$uname,$expiry]);
                    $success[] = $key;
                }
                if ($ulevel > 1) db()->q("UPDATE users SET balance=balance-? WHERE id=?",[$cost,$_SESSION['uid']]);
                logAct($_SESSION['uid'],$uname,'GENERATE_KEY',count($success)." keys Dur:{$duration} Dev:{$maxDev}");
            }
        }
    }
}

$prices    = db()->all("SELECT * FROM prices ORDER BY hours ASC");
$priceMap  = [];
foreach ($prices as $pr) $priceMap[$pr->duration] = $pr->price;
$myBal     = (float)(db()->one("SELECT balance FROM users WHERE id=?",[$_SESSION['uid']])->balance??0);
$genHistory= db()->all("SELECT * FROM keys_code WHERE registrator=? ORDER BY created_at DESC LIMIT 8",[$uname]);
?>

<?php if($error): ?><div class="alert a-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if(!empty($success)): ?>

<div class="card" style="margin-bottom:16px;border-color:rgba(0,229,160,.35);box-shadow:0 0 30px rgba(0,229,160,.12)">
  <div class="card-h" style="background:rgba(0,229,160,.06)">
    <span class="ct" style="color:var(--acc)">
      <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      <?= count($success) ?> Key<?= count($success)>1?'s':'' ?> Generated!
    </span>
    <button class="btn btn-acc btn-sm" onclick="copyAllKeys()">Copy All</button>
  </div>
  <div class="card-b" style="padding:12px">
    <div id="generatedKeys" style="background:#04060e;border:1px solid rgba(0,229,160,.15);border-radius:8px;padding:12px;font-family:var(--mono);font-size:12px;color:var(--acc);line-height:2;word-break:break-all">
      <?= implode("\n",array_map('htmlspecialchars',$success)) ?>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px">
      <button class="btn btn-acc" style="flex:1" onclick="copyAllKeys()">
        <svg viewBox="0 0 24 24" width="13" height="13" stroke="currentColor" fill="none" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        Copy All
      </button>
      <button class="btn btn-ghost" style="flex:1" onclick="downloadKeys()">
        <svg viewBox="0 0 24 24" width="13" height="13" stroke="currentColor" fill="none" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Download
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="g-left">
  <div class="card">
    <div class="card-h">
      <span class="ct">
        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
        Create License Key
      </span>
      <a href="/keys.php" class="btn btn-ghost btn-sm">View All Keys</a>
    </div>
    <div class="card-b">
      <?php if($oneDevMode): ?><div class="alert a-warn" style="margin-bottom:14px">One Device Mode ON — Keys limited to 1 device</div><?php endif; ?>
      <form method="POST" id="genForm">
        <?= csrfField() ?>

        <div class="g2" style="gap:12px">
          <div class="fg" style="margin:0 0 14px">
            <label class="fl">Max Devices <?= $oneDevMode?'<span style="color:var(--warn)">(Locked: 1)</span>':'' ?></label>
            <input class="fi" type="number" name="max_devices" id="maxDevices" value="1" min="1" max="<?= $oneDevMode?'1':$maxCap ?>" <?= $oneDevMode?'readonly style="opacity:.5"':'' ?> oninput="updateEst()">
          </div>
          <?php if($ulevel <= 2): ?>
          <?php if($ulevel<=2): ?>
          <div class="fg" style="margin:0 0 14px">
            <label class="fl">Quantity <span style="color:var(--muted);font-weight:400">(Max 10)</span></label>
            <input class="fi" type="number" name="qty" id="qtyInp" value="1" min="1" max="10" oninput="updateEst()">
          </div>
          <?php else: ?>
          <input type="hidden" name="qty" value="1">
          <?php endif; ?>
          <?php else: ?>
          <input type="hidden" name="qty" id="qtyInp" value="1">
          <?php endif; ?>
        </div>

        <div class="fg">
          <label class="fl">Duration</label>
          <select class="fi" name="duration" id="durationSel" onchange="updateEst()">
            <?php foreach($prices as $pr): ?>
            <option value="<?= htmlspecialchars($pr->duration) ?>" data-price="<?= $pr->price ?>"><?= htmlspecialchars($pr->duration) ?> — $<?= $pr->price ?>/device</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="padding:12px 14px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:9px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between">
          <div style="display:flex;align-items:center;gap:10px">
            <label class="tog"><input type="checkbox" id="custTog" name="is_custom" value="1" onchange="toggleCustom()"><span class="togsl"></span></label>
            <span style="font-size:12px;font-weight:600">Custom Key String <span style="color:var(--muted);font-size:10px">(only for qty=1)</span></span>
          </div>
        </div>
        <div id="custWrap" style="display:none" class="fg">
          <label class="fl">Custom Key</label>
          <input class="fi fi-mono" type="text" name="custom_key" placeholder="Enter custom key...">
        </div>

        <div class="fg">
          <label class="fl">Estimated Cost</label>
          <input class="fi fi-mono" id="estimation" readonly style="color:var(--acc);font-weight:800;font-size:16px">
          <?php if($ulevel>1): ?><div class="fi-hint">Your balance: <strong style="color:var(--acc)">$<?= number_format($myBal,2) ?></strong></div><?php endif; ?>
        </div>

        <button type="submit" class="btn btn-acc btn-full">
          <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" fill="none" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          GENERATE KEY(S)
        </button>
      </form>
    </div>
  </div>

  <div>


    <div class="card">
      <div class="card-h"><span class="ct">Recent Generated</span></div>
      <div class="tw"><table>
        <thead><tr><th>Key</th><th>Dur</th><th>Status</th></tr></thead>
        <tbody>
          <?php if(empty($genHistory)): ?>
          <tr><td colspan="3" style="text-align:center;padding:20px;color:var(--muted)">No history</td></tr>
          <?php else: foreach($genHistory as $h):
            $exp=$h->expired_date && strtotime($h->expired_date)<time();
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:6px">
                <code style="font-size:10px"><?= substr(htmlspecialchars($h->user_key),0,14) ?>...</code>
                <button class="btn btn-ghost btn-xs" onclick="copyText('<?= htmlspecialchars($h->user_key) ?>','Key')" title="Copy">
                  <svg viewBox="0 0 24 24" width="10" height="10" stroke="currentColor" fill="none" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                </button>
              </div>
            </td>
            <td style="font-family:var(--mono)"><?php $hh=(int)$h->duration; echo $hh<24?"{$hh}H":round($hh/24)."D"; ?></td>
            <td><?php
              if(!$h->status) echo '<span class="bx b-ban">Blocked</span>';
              elseif($exp) echo '<span class="bx b-exp">Expired</span>';
              elseif(!$h->device_count) echo '<span class="bx b-unused">Unused</span>';
              else echo '<span class="bx b-active">Active</span>';
            ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<script>
const priceMap = <?= json_encode($priceMap) ?>;
function updateEst(){
  const dev = parseInt(document.getElementById('maxDevices').value)||1;
  const qty = parseInt(document.getElementById('qtyInp').value)||1;
  const dur = document.getElementById('durationSel').value;
  const unit= parseFloat(priceMap[dur]||0);
  document.getElementById('estimation').value = '$ '+(dev*qty*unit).toFixed(2)+(qty>1?' ('+qty+' keys)':'');
}
updateEst();
function toggleCustom(){
  const on=document.getElementById('custTog').checked;
  document.getElementById('custWrap').style.display=on?'block':'none';
  if(on) document.getElementById('qtyInp').value=1;
}
function copyAllKeys(){
  const el=document.getElementById('generatedKeys');
  if(!el) return;
  copyText(el.textContent.trim(),'All keys');
}

(function(){ const el=document.getElementById('generatedKeys'); if(el){ try{ navigator.clipboard.writeText(el.textContent.trim()).then(()=>{ if(window.toast) toast('Keys auto-copied!','s'); }); }catch(e){} } })();
function downloadKeys(){
  const el=document.getElementById('generatedKeys');
  if(!el) return;
  const b=new Blob([el.textContent.trim()+'\n'],{type:'text/plain'});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(b);
  a.download='keys-<?= date('Y-m-d') ?>.txt';
  a.click();
}
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>