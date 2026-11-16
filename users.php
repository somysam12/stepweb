<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    requireLogin();


    $ulevel = (int)($_SESSION['ulevel']??99);
    $uname  = $_SESSION['uname']??'';
    if ($ulevel > 2) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    $uid2    = (int)($_POST['uid2']??0);
    $blockIp = !empty($_POST['block_ip']);

    function getLastIp(int $uid): string {
        $row = db()->one("SELECT ip FROM activity_logs WHERE user_id=? AND ip IS NOT NULL ORDER BY id DESC LIMIT 1",[$uid]);
        return $row ? ($row->ip??'') : '';
    }
    function blockUserIp(int $uid, string $reason): void {
        $ip = getLastIp($uid);
        if ($ip) db()->q("INSERT IGNORE INTO blocked_ips(ip,reason) VALUES(?,?)",[$ip,$reason]);
    }

    switch ($_POST['action']) {
        case 'ban':
            db()->q("UPDATE users SET status=0 WHERE id=?",[$uid2]);
            if ($blockIp && $ulevel===1) blockUserIp($uid2,'Banned by owner - IP block');
            logAct($_SESSION['uid'],$uname,'BAN_USER',"ID:$uid2".($blockIp?' +IP_block':''));
            echo json_encode(['ok'=>true,'msg'=>'User banned'.($blockIp?' & IP blocked.':'.')]);
            exit;
        case 'unban':
            db()->q("UPDATE users SET status=1 WHERE id=?",[$uid2]);
            logAct($_SESSION['uid'],$uname,'UNBAN_USER',"ID:$uid2");
            echo json_encode(['ok'=>true,'msg'=>'User unbanned.']);
            exit;
        case 'delete':
            if ((int)($_SESSION['ulevel']??99)!==1) { echo json_encode(['ok'=>false,'msg'=>'Owner only.']); exit; }
            if ($blockIp) blockUserIp($uid2,'Deleted by owner - IP block');
            db()->q("DELETE FROM users WHERE id=?",[$uid2]);
            logAct($_SESSION['uid'],$uname,'DELETE_USER',"ID:$uid2".($blockIp?' +IP_block':''));
            echo json_encode(['ok'=>true,'msg'=>'User deleted'.($blockIp?' & IP blocked.':'.')]);
            exit;
        case 'delete_all':
            if ((int)($_SESSION['ulevel']??99)!==1) { echo json_encode(['ok'=>false,'msg'=>'Owner only.']); exit; }
            $c = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level>1")->c??0);
            db()->q("DELETE FROM users WHERE level>1");
            logAct($_SESSION['uid'],$uname,'DELETE_ALL_USERS',"$c users deleted");
            echo json_encode(['ok'=>true,'msg'=>"$c users deleted. Owners kept."]);
            exit;
        case 'set_limit':
            $limit = (int)($_POST['limit']??0);
            db()->q("UPDATE users SET referral_limit=? WHERE id=?",[$limit,$uid2]);
            echo json_encode(['ok'=>true,'msg'=>"Limit set to $limit."]);
            exit;
        case 'set_role':
            if ($ulevel!==1) { echo json_encode(['ok'=>false,'msg'=>'Owner only.']); exit; }
            $newLevel = (int)($_POST['level']??3);
            if (!in_array($newLevel,[1,2,3],true)) { echo json_encode(['ok'=>false,'msg'=>'Invalid role.']); exit; }
            if ($uid2 === (int)$_SESSION['uid']) { echo json_encode(['ok'=>false,'msg'=>"Can't change your own role."]); exit; }
            db()->q("UPDATE users SET level=? WHERE id=?",[$newLevel,$uid2]);
            if ($newLevel===1) db()->q("UPDATE users SET balance=999999999.99 WHERE id=?",[$uid2]);
            logAct($_SESSION['uid'],$uname,'SET_ROLE',"ID:$uid2 -> level $newLevel");
            echo json_encode(['ok'=>true,'msg'=>'Role updated to '.[1=>'Owner',2=>'Admin',3=>'Reseller'][$newLevel].'.']);
            exit;
        case 'unblock_ip':
            if ((int)($_SESSION['ulevel']??99)!==1) { echo json_encode(['ok'=>false,'msg'=>'Owner only.']); exit; }
            $ip = trim($_POST['ip']??'');
            db()->q("DELETE FROM blocked_ips WHERE ip=?",[$ip]);
            logAct($_SESSION['uid'],$uname,'UNBLOCK_IP',$ip);
            echo json_encode(['ok'=>true,'msg'=>"IP $ip unblocked."]);
            exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
    exit;
}

if (isset($_GET['get_users'])) {
    requireLogin();
    header('Content-Type: application/json');
    $ulv=(int)($_SESSION['ulevel']??99); $unm=$_SESSION['uname']??'';
    if ($ulv>2){ echo json_encode([]); exit; }
    $q=trim($_GET['q']??'');
    if ($ulv===1) {
        $rows=db()->all("SELECT username,level FROM users WHERE username LIKE ? ORDER BY level,username LIMIT 15",["%$q%"]);
    } else {
        $codes=array_column((array)db()->all("SELECT code FROM referral_codes WHERE created_by=?",[$unm]),'code');
        if(empty($codes)){ echo json_encode([]); exit; }
        $ph=implode(',',array_fill(0,count($codes),'?'));
        $params=$codes; if($q)$params[]="%$q%";
        $rows=db()->all("SELECT username,level FROM users WHERE ref_code_used IN ($ph)".($q?" AND username LIKE ?":"")." ORDER BY username LIMIT 15",$params);
    }
    echo json_encode(array_values((array)$rows)); exit;
}

requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) > 2) { header('Location: /dashboard.php'); exit; }
$pageTitle='Manage Users'; $activeNav='users';
require_once __DIR__.'/app/Views/Layout/header.php';
$s = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';

$q    = trim($_GET['q']??'');
$role = $_GET['role']??'';
$where = 'WHERE 1=1'; $params = [];
if ($q)             { $where.=" AND (username LIKE ? OR referred_by LIKE ? OR ref_code_used LIKE ?)"; $params=["%$q%","%$q%","%$q%"]; }
if ($role==='owner')    $where.=' AND level=1';
if ($role==='admin')    $where.=' AND level=2';
if ($role==='reseller') $where.=' AND level=3';
$users = db()->all("SELECT * FROM users $where ORDER BY level ASC, created_at DESC", $params);
$levelMap = [1=>'Owner',2=>'Admin',3=>'Reseller'];
$levelCls = [1=>'b-owner',2=>'b-admin',3=>'b-reseller'];
?>

<div class="card" style="margin-bottom:14px">
  <div class="card-h">
    <span class="ct"><svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Search & Filter</span>
    <?php if($ulevel===1): ?>
    <button class="btn btn-red btn-sm" onclick="deleteAll()">
      <svg viewBox="0 0 24 24" <?=$s?> width="12" height="12"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
      Delete All Users
    </button>
    <?php endif; ?>
  </div>
  <div class="card-b" style="padding:14px">
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <div style="position:relative;flex:1;min-width:180px">
        <div class="sbar">
          <svg viewBox="0 0 24 24" <?=$s?> style="width:13px;height:13px;flex-shrink:0;margin-right:7px;opacity:.35"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input id="uq" placeholder="Type to search users..." value="<?=htmlspecialchars($q)?>" autocomplete="off" oninput="searchU(this.value)" onfocus="searchU(this.value)" onkeydown="if(event.key==='Enter')applyF()">
        </div>
        <div id="uDrop" style="display:none;position:absolute;top:100%;left:0;right:0;background:#111827;border:1px solid rgba(0,229,160,.2);border-radius:10px;z-index:100;max-height:220px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.8);margin-top:4px"></div>
      </div>
      <?php if($ulevel===1): ?>
      <select class="fi" id="roleF" style="width:130px;padding:8px 10px;font-size:12px" onchange="applyF()">
        <option value="">All Roles</option>
        <option value="owner"    <?=$role==='owner'   ?'selected':''?>>Owner</option>
        <option value="admin"    <?=$role==='admin'   ?'selected':''?>>Admin</option>
        <option value="reseller" <?=$role==='reseller'?'selected':''?>>Reseller</option>
      </select>
      <?php else: ?>
      <input type="hidden" id="roleF" value="">
      <?php endif; ?>
      <button class="btn btn-acc btn-sm" onclick="applyF()">Search</button>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      All Users <span style="color:var(--muted);font-size:11px;font-weight:400;text-transform:none">(<?=count($users)?>)</span>
    </span>
  </div>
  <div class="tw">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Username</th><th>Role</th><th>Balance</th>
          <th>Ref Code Used</th><th>Ref Given By</th><th>Expiry</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
        <tr><td colspan="9" style="text-align:center;padding:36px;color:var(--muted)">No users found</td></tr>
        <?php else: $i=1; foreach ($users as $u):
          $lvl  = (int)$u->level;
          $lname= $levelMap[$lvl]??'?';
          $lcls = $levelCls[$lvl]??'b-reseller';
          $exp  = $u->expiration_date && strtotime($u->expiration_date) < time();

          $lastIp = '';
          if ($ulevel===1) {
              $ipRow  = db()->one("SELECT ip FROM activity_logs WHERE user_id=? AND ip IS NOT NULL ORDER BY id DESC LIMIT 1",[$u->id]);
              $lastIp = $ipRow ? ($ipRow->ip??'') : '';
          }
        ?>
        <tr id="ur-<?=$u->id?>">
          <td style="color:var(--muted);font-size:11px"><?=$i++?></td>
          <td>
            <div style="font-weight:700;font-size:12px"><?=htmlspecialchars($u->username)?></div>
            <?php if($u->last_login): ?>
            <div style="font-size:10px;color:var(--muted)">Last: <?=date('d M H:i',strtotime($u->last_login))?></div>
            <?php endif; ?>
            <?php if($lastIp): ?>
            <div style="font-size:9px;color:var(--muted);font-family:var(--mono)"><?=htmlspecialchars($lastIp)?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="bx <?=$lcls?>" style="font-size:9px">
              <?=$lvl===1?'● ':($lvl===2?'▲ ':'○ ')?>
              <?=$lname?>
            </span>
          </td>
          <td style="color:var(--acc);font-family:var(--mono);font-weight:700;font-size:12px">
            <?=number_format((float)$u->balance,0)?>
          </td>
          <td>
            <?php if($u->ref_code_used): ?>
            <code style="font-size:10px"><?=htmlspecialchars($u->ref_code_used)?></code>
            <?php else: ?>
            <span style="color:var(--muted)">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:11px;color:var(--muted2)"><?=htmlspecialchars($u->ref_giver??'—')?></td>
          <td style="font-size:11px;<?=$exp?'color:var(--danger)':''?>">
            <?=$u->expiration_date ? date('d M Y',strtotime($u->expiration_date)) : '∞'?>
          </td>
          <td>
            <?php if($u->status==1):    echo '<span class="bx b-active" style="font-size:9px">Active</span>';
            elseif($u->status==2): echo '<span class="bx b-exp" style="font-size:9px">Pending</span>';
            else:                   echo '<span class="bx b-ban" style="font-size:9px">Banned</span>';
            endif; ?>
          </td>
          <td>
            <?php if ($u->id != $_SESSION['uid']): ?>
            <div style="display:flex;gap:3px;flex-wrap:wrap">
              <?php
              $canManage = ($ulevel===1) || ($ulevel===2 && $lvl===3);
              $isOwnerUser = ($lvl===1);
              if($canManage && !$isOwnerUser):
              ?>
                <?php if ($u->status == 1): ?>
                  <?php if($ulevel===1): ?>
                  <button class="btn btn-red btn-xs" onclick="openBanModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>')">Ban</button>
                  <?php else: ?>
                  <button class="btn btn-red btn-xs" onclick="uAct(<?=$u->id?>,'ban')">Ban</button>
                  <?php endif; ?>
                <?php else: ?>
                  <button class="btn btn-acc btn-xs" onclick="uAct(<?=$u->id?>,'unban')">Unban</button>
                  <?php if ($ulevel===1 && $lastIp): ?>
                  <button class="btn btn-blue btn-xs" onclick="unblockIp('<?=htmlspecialchars($lastIp)?>')">Unblock IP</button>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($ulevel===1): ?>
                <button class="btn btn-blue btn-xs" onclick="openRoleModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>',<?=$lvl?>)">Role</button>
                <button class="btn btn-blue btn-xs" onclick="openLimitModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>'  ,<?=(int)($u->referral_limit??0)?>)">Limit</button>
                <button class="btn btn-ghost btn-xs" onclick="openDeleteModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>')">Del</button>
                <?php endif; ?>
              <?php endif; ?>
              <?php if($ulevel===1 && $isOwnerUser): ?>
              <button class="btn btn-blue btn-xs" onclick="openRoleModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>',<?=$lvl?>)">Role</button>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-bg" id="banModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot" style="background:var(--danger)"></span> BAN USER</div><button class="mcls" onclick="closeModal('banModal')">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi fi-mono" id="bm-user" readonly style="color:var(--danger);font-weight:700"></div>
      <?php if($ulevel===1): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:rgba(255,77,109,.05);border:1px solid rgba(255,77,109,.15);border-radius:9px">
        <div>
          <div style="font-weight:700;font-size:12px">Also Block IP Address</div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px">User cannot register again or use any account</div>
        </div>
        <label class="tog"><input type="checkbox" id="bm-blockip"><span class="togsl"></span></label>
      </div>
      <?php endif; ?>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('banModal')">Cancel</button>
      <button class="btn btn-red" onclick="doBan()">Ban User</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="deleteModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot" style="background:var(--danger)"></span> DELETE USER</div><button class="mcls" onclick="closeModal('deleteModal')">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi fi-mono" id="dm-user" readonly style="color:var(--danger);font-weight:700"></div>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:rgba(255,77,109,.05);border:1px solid rgba(255,77,109,.15);border-radius:9px">
        <div>
          <div style="font-weight:700;font-size:12px">Also Block IP Address</div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px">Prevents re-registration from same IP</div>
        </div>
        <label class="tog"><input type="checkbox" id="dm-blockip"><span class="togsl"></span></label>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
      <button class="btn btn-red" onclick="doDelete()">Delete User</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="roleModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> CHANGE ROLE</div><button class="mcls" onclick="closeModal('roleModal')">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi" id="rm-uname" readonly style="color:var(--acc);font-family:var(--mono)"></div>
      <div class="fg">
        <label class="fl">Role / Level</label>
        <select class="fi" id="rm-level">
          <option value="1">Level 1 — Owner</option>
          <option value="2">Level 2 — Admin</option>
          <option value="3">Level 3 — Reseller</option>
        </select>
        <div class="fi-hint">Setting a user to Owner grants unlimited balance.</div>
      </div>
      <input type="hidden" id="rm-uid" value="">
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('roleModal')">Cancel</button>
      <button class="btn btn-acc" onclick="saveRole()">Save Role</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="limitModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> SET REFERRAL LIMIT</div><button class="mcls" onclick="closeModal('limitModal')">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi" id="lm-uname" readonly style="color:var(--acc);font-family:var(--mono)"></div>
      <div class="fg"><label class="fl">Max Referral Codes (0 = unlimited)</label><input class="fi fi-mono" type="number" id="lm-limit" min="0" value="0"></div>
      <input type="hidden" id="lm-uid" value="">
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('limitModal')">Cancel</button>
      <button class="btn btn-acc" onclick="saveLimit()">Save Limit</button>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;
let banUid=0, delUid=0;

function openBanModal(uid,uname){banUid=uid;document.getElementById('bm-user').value=uname;openModal('banModal');}
function doBan(){
  const blockIp=document.getElementById('bm-blockip')?.checked?1:0;
  formPost('/users.php',{action:'ban',uid2:banUid,block_ip:blockIp,csrf}).then(r=>{
    if(r.ok){toast(r.msg,'s');closeModal('banModal');setTimeout(()=>location.reload(),700);}
    else toast(r.msg,'e');
  });
}
function openDeleteModal(uid,uname){delUid=uid;document.getElementById('dm-user').value=uname;openModal('deleteModal');}
function doDelete(){
  const blockIp=document.getElementById('dm-blockip')?.checked?1:0;
  formPost('/users.php',{action:'delete',uid2:delUid,block_ip:blockIp,csrf}).then(r=>{
    if(r.ok){toast(r.msg,'s');closeModal('deleteModal');document.getElementById('ur-'+delUid)?.remove();}
    else toast(r.msg,'e');
  });
}
async function uAct(uid,action){
  const r=await formPost('/users.php',{action,uid2:uid,csrf});
  if(r.ok){toast(r.msg,'s');setTimeout(()=>location.reload(),600);}else toast(r.msg,'e');
}
async function unblockIp(ip){
  if(!confirm('Unblock IP: '+ip+'?'))return;
  const r=await formPost('/users.php',{action:'unblock_ip',ip,csrf});
  r.ok?toast(r.msg,'s'):toast(r.msg,'e');
}
async function deleteAll(){
  const r=await formPost('/users.php',{action:'delete_all',csrf});
  if(r.ok){toast(r.msg,'s');setTimeout(()=>location.reload(),700);}else toast(r.msg,'e');
}
function openRoleModal(uid,uname,curLevel){
  document.getElementById('rm-uid').value=uid;
  document.getElementById('rm-uname').value=uname;
  document.getElementById('rm-level').value=curLevel;
  openModal('roleModal');
}
async function saveRole(){
  const r=await formPost('/users.php',{action:'set_role',uid2:document.getElementById('rm-uid').value,level:document.getElementById('rm-level').value,csrf});
  if(r.ok){toast(r.msg,'s');closeModal('roleModal');setTimeout(()=>location.reload(),700);}else toast(r.msg,'e');
}
function openLimitModal(uid,uname,cur){
  document.getElementById('lm-uid').value=uid;
  document.getElementById('lm-uname').value=uname;
  document.getElementById('lm-limit').value=cur;
  openModal('limitModal');
}
async function saveLimit(){
  const r=await formPost('/users.php',{action:'set_limit',uid2:document.getElementById('lm-uid').value,limit:document.getElementById('lm-limit').value,csrf});
  if(r.ok){toast(r.msg,'s');closeModal('limitModal');}else toast(r.msg,'e');
}
function applyF(){location.href=`/users.php?q=${encodeURIComponent(document.getElementById('uq').value)}&role=${encodeURIComponent(document.getElementById('roleF').value)}`;}
let uT;
function searchU(q){
  clearTimeout(uT);
  const dd=document.getElementById('uDrop');
  uT=setTimeout(async()=>{
    try{
      const r=await fetch('/users.php?get_users=1&q='+encodeURIComponent(q.trim()));
      const list=await r.json();
      if(!list.length){ dd.style.display='none'; return; }
      const lv={1:'Owner',2:'Admin',3:'Reseller'};
      dd.innerHTML=list.map(u=>`<div onclick="pickU('${u.username}')" style="padding:9px 13px;cursor:pointer;font-size:13px;display:flex;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,.05)" onmouseover="this.style.background='rgba(0,229,160,.08)'" onmouseout="this.style.background=''"><span style="font-weight:700">${u.username}</span><span style="color:var(--muted);font-size:11px">${lv[u.level]||''}</span></div>`).join('');
      dd.style.display='block';
    }catch(e){ dd.style.display='none'; }
  },250);
}
function pickU(name){ document.getElementById('uq').value=name; document.getElementById('uDrop').style.display='none'; applyF(); }
document.addEventListener('click',e=>{ if(!e.target.closest('#uq')&&!e.target.closest('#uDrop')) document.getElementById('uDrop').style.display='none'; });
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>