<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['ajax'])) {

    header('Content-Type: application/json');
    requireLogin();
    if ((int)($_SESSION['ulevel']??99) !== 1) { echo json_encode(['ok'=>false,'msg'=>'Owner only']); exit; }
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }

    if (!empty($_POST['add_duration'])) {
        $name  = trim($_POST['dur_name']??'');
        $hours = (int)($_POST['dur_hours']??0);
        $price = max(0,(float)($_POST['dur_price']??0));
        if (empty($name)||$hours<=0) { echo json_encode(['ok'=>false,'msg'=>'Fill all fields']); exit; }
        $ex = db()->one("SELECT id FROM prices WHERE duration=?",[$name]);
        if ($ex) { echo json_encode(['ok'=>false,'msg'=>'Duration already exists']); exit; }
        db()->q("INSERT INTO prices(duration,hours,price) VALUES(?,?,?)",[$name,$hours,$price]);
        echo json_encode(['ok'=>true,'msg'=>"Duration '$name' added!"]);
        exit;
    }

    if (!empty($_POST['del_duration'])) {
        $name = trim($_POST['duration']??'');
        db()->q("DELETE FROM prices WHERE duration=?",[$name]);
        echo json_encode(['ok'=>true,'msg'=>"Duration '$name' deleted!"]);
        exit;
    }
    $dur   = trim($_POST['duration']??'');
    $price = max(0,(float)($_POST['price']??0));
    if (empty($dur)) {
        echo json_encode(['ok'=>false,'msg'=>'Select a duration']); exit;
    }
    $exists = db()->one("SELECT id FROM prices WHERE duration=?",[$dur]);
    if ($exists) {
        db()->q("UPDATE prices SET price=? WHERE duration=?",[$price,$dur]);
        logAct($_SESSION['uid'],$_SESSION['uname']??'','UPDATE_PRICE',"$dur = $price");
        echo json_encode(['ok'=>true,'msg'=>"Price saved: $dur"]);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'Duration not found']);
    }
    exit;
}

requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$pageTitle='Pricing'; $activeNav='pricing';
require_once __DIR__.'/app/Views/Layout/header.php';

$prices  = db()->all("SELECT * FROM prices ORDER BY hours ASC");
$priceMap= [];
foreach ($prices as $p) $priceMap[$p->duration] = (float)$p->price;
$sel      = $_GET['dur'] ?? ($prices[0]->duration??'');
$selPrice = $priceMap[$sel]??0;
?>

<div class="g2">
  <div class="card">
    <div class="card-h"><span class="ct">Update Price</span></div>
    <div class="card-b">
      <div class="fg">
        <label class="fl">Select Duration</label>
        <select class="fi" id="durSel" onchange="onDurChange(this.value)">
          <?php foreach($prices as $p): ?>
          <option value="<?=htmlspecialchars($p->duration)?>" <?=$p->duration===$sel?'selected':''?>>
            <?=htmlspecialchars($p->duration)?> (<?=$p->hours>=720?round($p->hours/720).'M':($p->hours>=168?round($p->hours/24).'D':$p->hours.'H')?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg">
        <label class="fl">Price per Device</label>
        <div style="position:relative">
          <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-family:var(--mono);font-size:16px">$</span>
          <input class="fi fi-mono" type="number" id="priceInp"
            value="<?=number_format($selPrice,2)?>"
            step="0.01" min="0"
            style="padding-left:28px;font-size:20px;font-weight:800;color:var(--acc)">
        </div>
      </div>

      <button onclick="savePrice()" id="saveBtn"
        style="width:100%;padding:14px;background:linear-gradient(135deg,#00c88a,#00e5a0);border:none;border-radius:10px;color:#000;font-weight:900;font-size:15px;cursor:pointer;letter-spacing:.5px;transition:.2s;display:flex;align-items:center;justify-content:center;gap:8px">
        <span id="saveBtnTxt">SAVE PRICE</span>
        <span id="saveBtnLoad" style="display:none">Saving...</span>
      </button>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><span class="ct">All Prices</span></div>
    <div class="tw"><table>
      <thead><tr><th>Duration</th><th>Hours</th><th>Price/Device</th><th></th></tr></thead>
      <tbody>
        <?php foreach($prices as $p): ?>
        <tr>
          <td style="font-weight:700"><?=htmlspecialchars($p->duration)?></td>
          <td style="font-family:var(--mono);color:var(--muted)"><?=$p->hours?>h</td>
          <td style="color:var(--acc);font-family:var(--mono);font-weight:700" id="pval-<?=md5($p->duration)?>">$<?=number_format((float)$p->price,2)?></td>
          <td><button class="btn btn-ghost btn-xs" onclick="selectDur('<?=htmlspecialchars(addslashes($p->duration))?>')">Edit</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<script>
const priceMap = <?=json_encode($priceMap)?>;

const csrf = document.querySelector('meta[name="csrf"]').content;

function onDurChange(dur){
  document.getElementById('priceInp').value = (priceMap[dur]||0).toFixed(2);
}

function selectDur(dur){
  document.getElementById('durSel').value = dur;
  onDurChange(dur);
  document.getElementById('saveBtn').scrollIntoView({behavior:'smooth',block:'center'});
  document.getElementById('priceInp').focus();
}

async function savePrice(){
  const dur   = document.getElementById('durSel').value;
  const price = parseFloat(document.getElementById('priceInp').value)||0;
  const btn   = document.getElementById('saveBtn');
  const txt   = document.getElementById('saveBtnTxt');
  const load  = document.getElementById('saveBtnLoad');

  txt.style.display='none'; load.style.display='block';
  btn.style.opacity='.7'; btn.disabled=true;

  try {
    const body = new URLSearchParams();
    body.append('ajax','1');
    body.append('duration',dur);
    body.append('price',String(price));
    body.append('csrf',csrf);

    const r = await fetch('/pricing.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: body.toString()
    });

    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch(e){ throw new Error('Server returned non-JSON: '+text.substring(0,100)); }

    if(d.ok){
      priceMap[dur]=price;

      btn.style.background='linear-gradient(135deg,#00a870,#00c88a)';
      txt.textContent='SAVED!';
      txt.style.display='block'; load.style.display='none';
      toast('Price saved: '+dur+' → $'+price.toFixed(2),'s');
      setTimeout(()=>{
        btn.style.background='linear-gradient(135deg,#00c88a,#00e5a0)';
        txt.textContent='SAVE PRICE';
        btn.style.opacity='1'; btn.disabled=false;
      },2000);
    } else {
      toast(d.msg||'Save failed','e');
      txt.style.display='block'; load.style.display='none';
      btn.style.opacity='1'; btn.disabled=false;
    }
  } catch(err){
    console.error(err);
    toast('Error: '+err.message,'e');
    txt.style.display='block'; load.style.display='none';
    btn.style.opacity='1'; btn.disabled=false;
  }
}
</script>

<div class="card" style="margin-top:16px">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
      Add / Delete Duration
    </span>
    <span class="owner-only">Owner Only</span>
  </div>
  <div class="card-b">
    <div style="background:rgba(0,229,160,.04);border:1px solid rgba(0,229,160,.15);border-radius:10px;padding:14px;margin-bottom:16px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Add New Duration</div>
      <div class="g2" style="gap:10px;margin-bottom:10px">
        <div class="fg" style="margin:0">
          <label class="fl">Duration Name</label>
          <input class="fi" id="newDurName" placeholder="e.g. 45 Days">
        </div>
        <div class="fg" style="margin:0">
          <label class="fl">Hours</label>
          <input class="fi fi-mono" type="number" id="newDurHours" placeholder="1080" min="1">
        </div>
      </div>
      <div class="fg" style="margin-bottom:10px">
        <label class="fl">Starting Price</label>
        <input class="fi fi-mono" type="number" id="newDurPrice" placeholder="5.00" step="0.01" min="0">
      </div>
      <button onclick="addDuration()" class="btn btn-acc btn-full">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        Add Duration
      </button>
    </div>

    <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Delete Duration</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach($prices as $p): ?>
      <button class="btn btn-red btn-sm" onclick="delDuration('<?=htmlspecialchars(addslashes($p->duration))?>')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="11" height="11"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        <?=htmlspecialchars($p->duration)?>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
async function addDuration(){
  const name  = document.getElementById('newDurName').value.trim();
  const hours = document.getElementById('newDurHours').value;
  const price = document.getElementById('newDurPrice').value;
  if(!name||!hours){toast('Fill name and hours','e');return;}
  const csrf = document.querySelector('meta[name="csrf"]').content;
  const r = await formPost('/pricing.php',{ajax:1,add_duration:1,dur_name:name,dur_hours:hours,dur_price:price||0,csrf});
  if(r.ok){toast(r.msg,'s');setTimeout(()=>location.reload(),1000);}
  else toast(r.msg,'e');
}
async function delDuration(name){
  const csrf = document.querySelector('meta[name="csrf"]').content;
  const r = await formPost('/pricing.php',{ajax:1,del_duration:1,duration:name,csrf});
  if(r.ok){toast(r.msg,'s');setTimeout(()=>location.reload(),800);}
  else toast(r.msg,'e');
}
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>