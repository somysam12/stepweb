<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    requireLogin();
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    $ulevel2 = (int)($_SESSION['ulevel']??99);
    $uname2  = $_SESSION['uname']??'';
    $whereOwn = $ulevel2>1 ? " AND registrator='".addslashes($uname2)."'" : '';

    switch($_POST['action']){
        case 'block':
            $kid=(int)($_POST['kid']??0);
            db()->q("UPDATE keys_code SET status=0 WHERE id=?{$whereOwn}",[$kid]);
            logAct($_SESSION['uid'],$uname2,'BLOCK_KEY',"ID:$kid");
            echo json_encode(['ok'=>true,'msg'=>'Key blocked.']); exit;
        case 'unblock':
            $kid=(int)($_POST['kid']??0);
            db()->q("UPDATE keys_code SET status=1 WHERE id=?{$whereOwn}",[$kid]);
            logAct($_SESSION['uid'],$uname2,'UNBLOCK_KEY',"ID:$kid");
            echo json_encode(['ok'=>true,'msg'=>'Key unblocked.']); exit;
        case 'delete':
            $kid=(int)($_POST['kid']??0);
            db()->q("DELETE FROM keys_code WHERE id=?{$whereOwn}",[$kid]);
            logAct($_SESSION['uid'],$uname2,'DELETE_KEY',"ID:$kid");
            echo json_encode(['ok'=>true,'msg'=>'Key deleted.']); exit;
        case 'reset_device':
            $kid=(int)($_POST['kid']??0);
            db()->q("UPDATE keys_code SET devices=NULL,device_count=0 WHERE id=?{$whereOwn}",[$kid]);
            logAct($_SESSION['uid'],$uname2,'RESET_DEVICE',"ID:$kid");
            echo json_encode(['ok'=>true,'msg'=>'Device reset.']); exit;
        case 'delete_expired':
            $c=(int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE expired_date IS NOT NULL AND expired_date<NOW()$whereOwn")->c??0);
            db()->q("DELETE FROM keys_code WHERE expired_date IS NOT NULL AND expired_date<NOW()$whereOwn");
            logAct($_SESSION['uid'],$uname2,'DELETE_EXPIRED',"$c keys");
            echo json_encode(['ok'=>true,'msg'=>"Deleted $c expired keys."]); exit;
        case 'delete_unused':
            $c=(int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE device_count=0 AND status=1$whereOwn")->c??0);
            db()->q("DELETE FROM keys_code WHERE device_count=0 AND status=1$whereOwn");
            logAct($_SESSION['uid'],$uname2,'DELETE_UNUSED',"$c keys");
            echo json_encode(['ok'=>true,'msg'=>"Deleted $c unused keys."]); exit;
        case 'delete_blocked':
            $c=(int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE status=0$whereOwn")->c??0);
            db()->q("DELETE FROM keys_code WHERE status=0$whereOwn");
            logAct($_SESSION['uid'],$uname2,'DELETE_BLOCKED',"$c keys");
            echo json_encode(['ok'=>true,'msg'=>"Deleted $c blocked keys."]); exit;
        case 'reset_all':
            $w=$ulevel2>1?" WHERE registrator='".addslashes($uname2)."'":'';
            db()->q("UPDATE keys_code SET devices=NULL,device_count=0$w");
            logAct($_SESSION['uid'],$uname2,'RESET_ALL_DEVICES','');
            echo json_encode(['ok'=>true,'msg'=>'All devices reset.']); exit;
        case 'delete_all':
            if($ulevel2!==1){echo json_encode(['ok'=>false,'msg'=>'Owner only.']);exit;}
            $c=(int)(db()->one("SELECT COUNT(*) c FROM keys_code")->c??0);
            db()->q("TRUNCATE TABLE keys_code");
            logAct($_SESSION['uid'],$uname2,'DELETE_ALL_KEYS',"$c keys");
            echo json_encode(['ok'=>true,'msg'=>"All $c keys deleted."]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

$pageTitle = 'All Keys';
$activeNav = 'allkeys';
require_once __DIR__.'/app/Views/Layout/header.php';

$pp=20; $pg=max(1,(int)($_GET['p']??1)); $off=($pg-1)*$pp;
$q=trim($_GET['q']??''); $st=$_GET['st']??'';
$where = $ulevel>1 ? "WHERE registrator='".addslashes($uname)."'" : 'WHERE 1=1';
$params=[];
if($q){$where.=" AND (user_key LIKE ? OR game LIKE ? OR registrator LIKE ?)";$params=["%$q%","%$q%","%$q%"];}
if($st==='active') $where.=' AND status=1 AND (expired_date IS NULL OR expired_date>NOW())';
if($st==='unused') $where.=' AND device_count=0 AND status=1';
if($st==='expired') $where.=' AND expired_date IS NOT NULL AND expired_date<NOW()';
if($st==='blocked') $where.=' AND status=0';

$total=(int)(db()->one("SELECT COUNT(*) c FROM keys_code $where",$params)->c??0);
$keys=db()->all("SELECT * FROM keys_code $where ORDER BY created_at DESC LIMIT $pp OFFSET $off",$params);
$pages=(int)ceil($total/$pp);

function kStatus($k){
    if(!$k->status) return ['Blocked','b-ban'];
    if($k->expired_date&&strtotime($k->expired_date)<time()) return ['Expired','b-exp'];
    if(!$k->device_count) return ['Unused','b-unused'];
    return ['Active','b-active'];
}
$s='fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="card">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6M15 3l3 3"/></svg>
      All Keys <span style="color:var(--muted);font-size:11px;font-weight:400;text-transform:none">(<?=$total?>)</span>
    </span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end">
      <div class="sbar" style="width:200px">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;flex-shrink:0;margin-right:7px;opacity:.4"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="qs" placeholder="Search key, user..." value="<?=htmlspecialchars($q)?>" onkeydown="if(event.key==='Enter')applyF()">
      </div>
      <select class="fi" id="stF" style="width:120px;padding:7px 10px;font-size:11px" onchange="applyF()">
        <option value="">All Status</option>
        <option value="active" <?=$st==='active'?'selected':''?>>Active</option>
        <option value="unused" <?=$st==='unused'?'selected':''?>>Unused</option>
        <option value="expired" <?=$st==='expired'?'selected':''?>>Expired</option>
        <option value="blocked" <?=$st==='blocked'?'selected':''?>>Blocked</option>
      </select>


      <?php if($ulevel===1): ?>
      <div style="position:relative" id="actWrap">
        <button class="btn btn-acc btn-sm" onclick="toggleAct()" id="actBtn" style="background:var(--acc);color:#000;font-weight:800">
          <svg viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          Actions
          <svg viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="11" height="11" id="actChev"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div id="actMenu" style="display:none;position:fixed;right:16px;top:auto;background:#0d1219;border:1px solid rgba(0,229,160,.25);border-radius:12px;padding:8px;min-width:220px;z-index:9999;box-shadow:0 16px 48px rgba(0,0,0,.95),0 0 0 1px rgba(0,229,160,.1)">
          <a href="/generate.php" class="act-item">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6M15 3l3 3"/></svg>
            Generate Keys
          </a>
          <div class="act-item-sep"></div>
          <button class="act-item" onclick="bulkAct('delete_expired')">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Delete Expired Keys
          </button>
          <button class="act-item" onclick="bulkAct('delete_unused')">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Delete Unused Keys
          </button>
          <button class="act-item" onclick="bulkAct('delete_blocked')">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            Delete Blocked Keys
          </button>
          <button class="act-item" onclick="bulkAct('reset_all')">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg>
            Reset All Devices
          </button>
          <?php if($ulevel===1): ?>
          <div class="act-item-sep" style="background:rgba(255,77,109,.2)"></div>
          <button class="act-item" style="color:#ff4d6d" onclick="bulkAct('delete_all')">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
            Delete All Keys
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="tw">
    <table id="keysTable">
      <thead><tr>
        <th>#</th><th>License Key</th><th>Game</th><th>Devices</th><th>Duration</th>
        <?php if($ulevel===1): ?><th>Owner</th><?php endif; ?>
        <th>Expires</th><th>Status</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php if(empty($keys)): ?>
        <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted)">No keys found</td></tr>
        <?php else: $i=$off+1; foreach($keys as $k):
          [$st2,$sc]=kStatus($k);
          $expColor=($k->expired_date&&strtotime($k->expired_date)<time())?'color:var(--danger)':'';
        ?>
        <tr id="kr-<?=$k->id?>">
          <td style="color:var(--muted)"><?=$i++?></td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <code class="kcode" style="font-size:10px"><?=htmlspecialchars($k->user_key)?></code>
              <button class="btn btn-ghost btn-xs" onclick="copyText('<?=htmlspecialchars($k->user_key)?>','Key')" title="Copy">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              </button>
            </div>
          </td>
          <td><?=htmlspecialchars($k->game)?></td>
          <td style="font-family:var(--mono)"><?=(int)$k->device_count?>/<?=(int)$k->max_devices?></td>
          <td style="font-family:var(--mono)"><?php $h=(int)$k->duration;echo $h<24?"{$h}H":round($h/24)."D";?></td>
          <?php if($ulevel===1): ?><td style="font-size:11px;color:var(--muted2)"><?=htmlspecialchars($k->registrator)?></td><?php endif; ?>
          <td style="font-size:11px;<?=$expColor?>"><?=$k->expired_date?date('d M Y',strtotime($k->expired_date)):'∞'?></td>
          <td><span class="bx <?=$sc?>"><?=$st2?></span></td>
          <td>
            <div style="display:flex;gap:4px;flex-wrap:wrap">
              <?php if($k->status): ?>
              <button class="btn btn-warn btn-xs" onclick="kAct(<?=$k->id?>,'block')" title="Block">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
              </button>
              <?php else: ?>
              <button class="btn btn-acc btn-xs" onclick="kAct(<?=$k->id?>,'unblock')" title="Unblock">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><polyline points="20 6 9 17 4 12"/></svg>
              </button>
              <?php endif; ?>
              <button class="btn btn-blue btn-xs" onclick="kAct(<?=$k->id?>,'reset_device')" title="Reset Device">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg>
              </button>
              <button class="btn btn-red btn-xs" onclick="confirmDo('Delete this key?',()=>kAct(<?=$k->id?>,'delete'))" title="Delete">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach;endif;?>
      </tbody>
    </table>
  </div>
  <?php if($pages>1): ?>
  <div class="pagination">
    <?php for($p2=1;$p2<=$pages;$p2++): ?>
    <a href="?p=<?=$p2?>&q=<?=urlencode($q)?>&st=<?=urlencode($st)?>" class="pg-btn <?=$p2===$pg?'on':''?>"><?=$p2?></a>
    <?php endfor;?>
  </div>
  <?php endif;?>
</div>

<style>
.act-item{display:flex;align-items:center;gap:10px;width:100%;padding:11px 14px;background:none;border:none;border-radius:8px;color:#c8d8ff;font-size:13px;font-family:"Inter",sans-serif;font-weight:500;cursor:pointer;transition:.12s;text-decoration:none;text-align:left;white-space:nowrap;letter-spacing:0}
.act-item:hover{background:rgba(0,229,160,.1);color:#00e5a0}
.act-item:hover svg{opacity:1;stroke:#00e5a0}
.act-item svg{flex-shrink:0;opacity:.7;stroke:#c8d8ff}
.act-item-sep{height:1px;background:rgba(255,255,255,.06);margin:4px 0}
</style>

<script>
const csrf=document.querySelector('meta[name="csrf"]').content;
async function kAct(id,action){
  const r=await formPost('/keys.php',{action,kid:id,csrf});
  if(r.ok){toast(r.msg,'s');if(action==='delete')document.getElementById('kr-'+id)?.remove();else setTimeout(()=>location.reload(),500);}
  else toast(r.msg,'e');
}
async function bulkAct(action){
  closeAct();
  try {
    const r = await formPost('/keys.php', {action: action, csrf: csrf});
    if(r.ok){
      toast(r.msg, 's');
      setTimeout(()=>location.reload(), 1200);
    } else {
      toast(r.msg || 'Error', 'e');
    }
  } catch(e){
    toast('Error: '+e.message, 'e');
  }
}
function toggleAct(){
  const m = document.getElementById('actMenu');
  const c = document.getElementById('actChev');
  const btn = document.getElementById('actBtn');
  const open = m.style.display === 'block';
  if(!open){

    const rect = btn.getBoundingClientRect();
    m.style.top  = (rect.bottom + 8) + 'px';
    m.style.right = (window.innerWidth - rect.right) + 'px';
    m.style.display = 'block';
    m.style.opacity = '0';
    m.style.transform = 'translateY(-6px) scale(.97)';
    m.style.transition = 'opacity .18s,transform .18s';
    requestAnimationFrame(()=>{
      m.style.opacity='1';
      m.style.transform='translateY(0) scale(1)';
    });
  } else {
    m.style.opacity='0';
    m.style.transform='translateY(-4px) scale(.97)';
    setTimeout(()=>{ m.style.display='none'; m.style.opacity=''; m.style.transform=''; },180);
  }
  c.style.transition='transform .2s';
  c.style.transform = open ? '' : 'rotate(180deg)';
}
function closeAct(){
  const m = document.getElementById('actMenu');
  const c = document.getElementById('actChev');
  if(m && m.style.display==='block'){
    m.style.opacity='0';
    m.style.transform='translateY(-4px)';
    setTimeout(()=>{ m.style.display='none'; m.style.opacity=''; m.style.transform=''; },180);
  }
  if(c) c.style.transform='';
}
document.addEventListener('click',e=>{if(!document.getElementById('actWrap')?.contains(e.target))closeAct();});
function applyF(){location.href=`/keys.php?q=${encodeURIComponent(document.getElementById('qs').value)}&st=${encodeURIComponent(document.getElementById('stF').value)}`;}
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>