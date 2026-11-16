<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['ajax'])) {
    requireLogin();
    header('Content-Type: application/json');
    $ulv = (int)($_SESSION['ulevel']??99);
    $unm = $_SESSION['uname']??'';
    if ($ulv > 2) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }

    $target = trim($_POST['target_user']??'');
    $amount = max(0,(float)($_POST['amount']??0));
    $action = $_POST['action']??'';

    if (!$target||!$amount||!in_array($action,['add','deduct'])) {
        echo json_encode(['ok'=>false,'msg'=>'Fill all fields']); exit;
    }


    $u = db()->one("SELECT * FROM users WHERE username=?",[$target]);
    if (!$u) { echo json_encode(['ok'=>false,'msg'=>'User not found']); exit; }


    if ($ulv===2) {
        $adminCodes = db()->all("SELECT code FROM referral_codes WHERE created_by=?",[$unm]);
        $codes = array_column((array)$adminCodes,'code');
        if ($u->level !== 3 || !in_array($u->ref_code_used??'', $codes)) {
            echo json_encode(['ok'=>false,'msg'=>'You can only manage your own resellers']); exit;
        }
    }

    if ($action==='add') {
        db()->q("UPDATE users SET balance=balance+? WHERE username=?",[$amount,$target]);
        logAct($_SESSION['uid'],$unm,'ADD_BALANCE',"$target +$amount");
        $newBal = (float)(db()->one("SELECT balance FROM users WHERE username=?",[$target])->balance??0);
        echo json_encode(['ok'=>true,'msg'=>"Added $amount to $target. New balance: $newBal"]);
    } else {
        $cur = (float)(db()->one("SELECT balance FROM users WHERE username=?",[$target])->balance??0);
        if ($cur < $amount) { echo json_encode(['ok'=>false,'msg'=>"Insufficient balance ($cur)"]); exit; }
        db()->q("UPDATE users SET balance=balance-? WHERE username=?",[$amount,$target]);
        logAct($_SESSION['uid'],$unm,'DEDUCT_BALANCE',"$target -$amount");
        echo json_encode(['ok'=>true,'msg'=>"Deducted $amount from $target."]);
    }
    exit;
}

if (isset($_GET['get_users'])) {
    requireLogin();
    header('Content-Type: application/json');
    $ulv = (int)($_SESSION['ulevel']??99);
    $unm = $_SESSION['uname']??'';
    $q   = trim($_GET['q']??'');

    if ($ulv===1) {

        $users = db()->all("SELECT username,level,balance FROM users WHERE username LIKE ? ORDER BY level,username LIMIT 20",["%$q%"]);
    } else {

        $adminCodes = db()->all("SELECT code FROM referral_codes WHERE created_by=?",[$unm]);
        $codes = array_column((array)$adminCodes,'code');
        if (empty($codes)) { echo json_encode([]); exit; }
        $ph = implode(',',array_fill(0,count($codes),'?'));
        $params = $codes;
        if ($q) { $params[] = "%$q%"; }
        $users = db()->all("SELECT username,level,balance FROM users WHERE level=3 AND ref_code_used IN ($ph)".($q?" AND username LIKE ?":"")." ORDER BY username LIMIT 20",$params);
    }
    echo json_encode(array_values((array)$users));
    exit;
}

requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) > 2) { header('Location: /dashboard.php'); exit; }
$pageTitle='Balance'; $activeNav='balance';
require_once __DIR__.'/app/Views/Layout/header.php';

$levelMap=[1=>'Owner',2=>'Admin',3=>'Reseller'];
$ulv2=(int)($_SESSION['ulevel']??99);
$unm2=$_SESSION['uname']??'';

if ($ulv2===1) {
    $allUsers = db()->all("SELECT username,level,balance FROM users ORDER BY level,username");
} else {
    $adminCodes = db()->all("SELECT code FROM referral_codes WHERE created_by=?",[$unm2]);
    $codes = array_column((array)$adminCodes,'code');
    if (!empty($codes)) {
        $ph=implode(',',array_fill(0,count($codes),'?'));
        $allUsers = db()->all("SELECT username,level,balance FROM users WHERE level=3 AND ref_code_used IN ($ph)",$codes);
    } else { $allUsers=[]; }
}
$s='fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="g2">
  <div class="card">
    <div class="card-h">
      <span class="ct">
        <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Add / Deduct Balance
      </span>
    </div>
    <div class="card-b">
      <div class="fg">
        <label class="fl">Username</label>
        <div style="position:relative">
          <input class="fi" id="targetUser" placeholder="Type to search users..." oninput="searchUsers(this.value)" onfocus="searchUsers(this.value)" autocomplete="off">
          <div id="userDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#111827;border:1px solid rgba(0,229,160,.2);border-radius:10px;z-index:100;max-height:200px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.8);margin-top:4px"></div>
        </div>
      </div>
      <div class="fg">
        <label class="fl">Amount</label>
        <div style="position:relative">
          <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-family:var(--mono)">$</span>
          <input class="fi fi-mono" type="number" id="amountInp" min="0.01" step="0.01" placeholder="0.00" style="padding-left:28px;font-size:16px;font-weight:700;color:var(--acc)">
        </div>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-acc" style="flex:1;padding:12px" onclick="doBal('add')">
          <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          Add Balance
        </button>
        <button class="btn btn-red" style="flex:1;padding:12px" onclick="doBal('deduct')">
          <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          Deduct
        </button>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h">
      <span class="ct">
        <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        User Balances
      </span>
    </div>
    <div class="tw"><table>
      <thead><tr><th>Username</th><th>Role</th><th>Balance</th></tr></thead>
      <tbody>
        <?php if(empty($allUsers)): ?>
        <tr><td colspan="3" style="text-align:center;padding:24px;color:var(--muted)">No users found</td></tr>
        <?php else: foreach($allUsers as $u):
          $lvl=(int)$u->level;
          $cls=['b-owner','b-admin','b-reseller'][$lvl-1]??'b-reseller';
        ?>
        <tr>
          <td style="font-weight:700;cursor:pointer" onclick="selectUser('<?=htmlspecialchars($u->username)?>')">
            <?=htmlspecialchars($u->username)?>
          </td>
          <td><span class="bx <?=$cls?>" style="font-size:9px"><?=$levelMap[$lvl]??'?'?></span></td>
          <td style="color:var(--acc);font-family:var(--mono);font-weight:700" id="bal-<?=htmlspecialchars($u->username)?>"><?=number_format((float)$u->balance,0)?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;
let searchTimer;

async function searchUsers(q){
  clearTimeout(searchTimer);
  const dd = document.getElementById('userDropdown');
  searchTimer = setTimeout(async()=>{
    const res = await fetch('/balance.php?get_users=1&q='+encodeURIComponent(q.trim()));
    const users = await res.json();
    if(!users.length){ dd.innerHTML='<div style="padding:10px 14px;color:var(--muted);font-size:12px">No matching users</div>'; dd.style.display='block'; return; }
    dd.innerHTML = users.map(u=>
      `<div onclick="selectUser('${u.username}')" style="padding:10px 14px;cursor:pointer;font-size:13px;display:flex;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,.05);transition:.12s" onmouseover="this.style.background='rgba(0,229,160,.08)'" onmouseout="this.style.background=''">
        <span style="font-weight:700">${u.username}</span>
        <span style="color:var(--acc);font-family:monospace">${parseFloat(u.balance).toLocaleString()}</span>
      </div>`
    ).join('');
    dd.style.display='block';
  }, 300);
}

function selectUser(name){
  document.getElementById('targetUser').value = name;
  document.getElementById('userDropdown').style.display='none';
  document.getElementById('amountInp').focus();
}

document.addEventListener('click', e=>{
  if(!e.target.closest('#targetUser')&&!e.target.closest('#userDropdown'))
    document.getElementById('userDropdown').style.display='none';
});

async function doBal(action){
  const user   = document.getElementById('targetUser').value.trim();
  const amount = parseFloat(document.getElementById('amountInp').value)||0;
  if(!user||!amount){ toast('Enter username and amount','e'); return; }
  const r = await formPost('/balance.php',{ajax:1,target_user:user,amount,action,csrf});
  if(r.ok){
    toast(r.msg,'s');

    const cell = document.getElementById('bal-'+user);
    if(cell){ setTimeout(()=>location.reload(),1200); }
  } else toast(r.msg,'e');
}
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>