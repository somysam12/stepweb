<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$uname = $_SESSION['uname'] ?? 'User';
if($_SERVER['REQUEST_METHOD']==='POST'&&verifyCsrf($_POST['csrf']??'')){
    header('Content-Type: application/json');
    $act=$_POST['action']??'';
    if($act==='unblock'){$id=(int)($_POST['bid']??0);db()->q("DELETE FROM blocked_ips WHERE id=?",[$id]);logAct($_SESSION['uid'],$uname,'UNBLOCK_IP',"ID:$id");echo json_encode(['ok'=>true,'msg'=>'IP unblocked.']);}
    elseif($act==='unblock_all'){db()->q("TRUNCATE TABLE blocked_ips");db()->q("TRUNCATE TABLE rate_limits");logAct($_SESSION['uid'],$uname,'UNBLOCK_ALL_IPS','');echo json_encode(['ok'=>true,'msg'=>'All IPs unblocked.']);}
    elseif($act==='block'){$ip=trim($_POST['ip']??'');if(filter_var($ip,FILTER_VALIDATE_IP)){db()->q("INSERT IGNORE INTO blocked_ips(ip,reason) VALUES(?,?)",[$ip,'Manual block']);logAct($_SESSION['uid'],$uname,'MANUAL_BLOCK_IP',$ip);echo json_encode(['ok'=>true,'msg'=>"IP {$ip} blocked."]);}else echo json_encode(['ok'=>false,'msg'=>'Invalid IP.']);}
    else echo json_encode(['ok'=>false,'msg'=>'Unknown']);exit;
}
$pageTitle='IP Management'; $activeNav='iplimit';
require_once __DIR__.'/app/Views/Layout/header.php';
$blocked=db()->all("SELECT * FROM blocked_ips ORDER BY created_at DESC LIMIT 100");
?>
<div class="card"><div class="card-h"><span class="ct">Blocked IPs <span style="color:var(--muted);font-size:11px;font-weight:400;text-transform:none">(<?=count($blocked)?>)</span></span>
<div style="display:flex;gap:8px">
<form id="mBF" onsubmit="manualBlock(event)"><input class="fi fi-mono" name="mip" placeholder="IP to block..." style="width:170px;padding:7px 10px;font-size:11px"><button class="btn btn-red btn-sm">Block</button></form>
<button class="btn btn-acc btn-sm" onclick="confirmDo('Unblock ALL IPs?',unblockAll)">Unblock All</button>
</div></div>
<div class="tw"><table><thead><tr><th>#</th><th>IP Address</th><th>Reason</th><th>Blocked At</th><th>Expires</th><th>Action</th></tr></thead><tbody>
<?php if(empty($blocked)): ?><tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">No blocked IPs</td></tr>
<?php else: $i=1; foreach($blocked as $b): ?>
<tr id="bi-<?=$b->id?>"><td style="color:var(--muted)"><?=$i++?></td>
<td style="font-family:var(--mono)"><?=htmlspecialchars($b->ip)?></td>
<td style="font-size:11px;color:var(--muted2)"><?=htmlspecialchars($b->reason??'—')?></td>
<td style="font-size:11px;color:var(--muted)"><?=date('d M Y H:i',strtotime($b->created_at))?></td>
<td style="font-size:11px;<?=$b->expires_at&&strtotime($b->expires_at)<time()?'color:var(--acc)':'' ?>"><?=$b->expires_at?date('d M H:i',strtotime($b->expires_at)):'<span style="color:var(--danger)">Permanent</span>'?></td>
<td><button class="btn btn-acc btn-xs" onclick="unblockOne(<?=$b->id?>)">Unblock</button></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div></div>
<script>
const csrf=document.querySelector('meta[name="csrf"]').content;
async function unblockOne(id){const r=await formPost('/iplimit.php',{action:'unblock',bid:id,csrf});if(r.ok){document.getElementById('bi-'+id)?.remove();toast(r.msg,'s');}else toast(r.msg,'e');}
async function unblockAll(){const r=await formPost('/iplimit.php',{action:'unblock_all',csrf});if(r.ok){location.reload();}else toast(r.msg,'e');}
function manualBlock(e){e.preventDefault();const ip=e.target.querySelector('[name=mip]').value;formPost('/iplimit.php',{action:'block',ip,csrf}).then(r=>{if(r.ok){toast(r.msg,'s');location.reload();}else toast(r.msg,'e');});}
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>