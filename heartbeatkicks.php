<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }

// ── AJAX actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    $uname = $_SESSION['uname'] ?? '';
    switch ($_POST['action']) {
        case 'clear_all':
            $c = (int)(db()->one("SELECT COUNT(*) c FROM heartbeat_kicks")->c ?? 0);
            db()->q("TRUNCATE TABLE heartbeat_kicks");
            logAct($_SESSION['uid'],$uname,'CLEAR_KICKS',"Cleared {$c} heartbeat kick records");
            echo json_encode(['ok'=>true,'msg'=>"Cleared {$c} records."]); exit;
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            db()->q("DELETE FROM heartbeat_kicks WHERE id=?", [$id]);
            logAct($_SESSION['uid'],$uname,'DELETE_KICK',"Removed kick record #{$id}");
            echo json_encode(['ok'=>true,'msg'=>'Record removed.']); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

$pageTitle = 'Heartbeat Kicks';
$activeNav = 'kicks';
require_once __DIR__.'/app/Views/Layout/header.php';

// The table is created by heartbeat_kicks_migration.sql. Detect it rather than
// letting the page fatal if the migration has not been run yet.
$hasTable = (bool)db()->one("SHOW TABLES LIKE 'heartbeat_kicks'");

$REASONS = [
  'no_key'       => ['Empty key',        'b-reseller', 'The app sent a heartbeat with no licence key.'],
  'unknown_key'  => ['Unknown key',      'b-ban',      'That key does not exist in the panel.'],
  'blocked'      => ['Key blocked',      'b-ban',      'The key is blocked or inactive.'],
  'expired'      => ['Key expired',      'b-exp',      'The key passed its expiry date.'],
  'bad_lib'      => ['Unauthorised lib', 'b-purple',   'An unexpected .so was loaded — usually an injection tool.'],
  'lib_tampered' => ['Lib tampered',     'b-purple',   'libbgmi hash did not match the one on record.'],
  'pkg_mismatch' => ['Package mismatch', 'b-unused',   'The key is bound to a different app package (clone/crack).'],
];

$rows = []; $total = 0; $pages = 0; $pg = 1; $q = ''; $rf = '';
$stats = ['24h'=>0,'keys'=>0,'top'=>null];

if ($hasTable) {
    $pp = 30; $pg = max(1,(int)($_GET['p']??1)); $off = ($pg-1)*$pp;
    $q  = trim($_GET['q']??'');
    $rf = trim($_GET['r']??'');

    $where = 'WHERE 1=1'; $params = [];
    if ($q !== '') {
        $where .= " AND (user_key LIKE ? OR device_id LIKE ? OR pkg LIKE ? OR ip LIKE ? OR detail LIKE ?)";
        $params = array_fill(0, 5, "%$q%");
    }
    if ($rf !== '' && isset($REASONS[$rf])) { $where .= " AND reason=?"; $params[] = $rf; }

    $total = (int)(db()->one("SELECT COUNT(*) c FROM heartbeat_kicks $where",$params)->c ?? 0);
    $rows  = db()->all("SELECT * FROM heartbeat_kicks $where ORDER BY last_seen DESC LIMIT $pp OFFSET $off",$params);
    $pages = (int)ceil($total/$pp);

    $stats['24h']  = (int)(db()->one("SELECT COUNT(*) c FROM heartbeat_kicks WHERE last_seen > DATE_SUB(NOW(),INTERVAL 24 HOUR)")->c ?? 0);
    $stats['keys'] = (int)(db()->one("SELECT COUNT(DISTINCT user_key) c FROM heartbeat_kicks WHERE user_key<>''")->c ?? 0);
    $stats['top']  = db()->one("SELECT reason, COUNT(*) c FROM heartbeat_kicks GROUP BY reason ORDER BY c DESC LIMIT 1");
}
$qs = ($q!==''?'&q='.urlencode($q):'').($rf!==''?'&r='.urlencode($rf):'');
$s  = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<?php if(!$hasTable): ?>
<div class="alert a-warn">
  <?=svgIcon('alert',14)?>
  <span>The <code>heartbeat_kicks</code> table does not exist yet. Import <strong>heartbeat_kicks_migration.sql</strong> into your database, then reload this page.</span>
</div>
<?php else: ?>

<div class="g3" style="margin-bottom:18px">
  <div class="kpi" style="--kc:var(--coral);--kb:rgba(251,113,133,.14)">
    <div class="kpi-top"><div class="kpi-ico"><?=svgIcon('ban',15)?></div><div class="kpi-lbl">Kicks in last 24h</div></div>
    <div class="kpi-val"><?= number_format($stats['24h']) ?></div>
    <div class="kpi-sub"><?= number_format($total) ?> total recorded</div>
  </div>
  <div class="kpi" style="--kc:var(--gold);--kb:rgba(252,211,77,.14)">
    <div class="kpi-top"><div class="kpi-ico"><?=svgIcon('key',15)?></div><div class="kpi-lbl">Keys affected</div></div>
    <div class="kpi-val"><?= number_format($stats['keys']) ?></div>
    <div class="kpi-sub">Distinct keys that got kicked</div>
  </div>
  <div class="kpi" style="--kc:var(--lilac);--kb:rgba(192,132,252,.14)">
    <div class="kpi-top"><div class="kpi-ico"><?=svgIcon('alert',15)?></div><div class="kpi-lbl">Most common reason</div></div>
    <div class="kpi-val" style="font-size:20px">
      <?= $stats['top'] ? htmlspecialchars($REASONS[$stats['top']->reason][0] ?? $stats['top']->reason) : '—' ?>
    </div>
    <div class="kpi-sub"><?= $stats['top'] ? (int)$stats['top']->c.' occurrences' : 'Nothing logged yet' ?></div>
  </div>
</div>

<div class="card">
  <div class="card-h">
    <span class="ct"><?=svgIcon('heartbeat',15)?> Kicked sessions <span style="color:var(--muted);font-size:11px;font-weight:400">(<?=$total?>)</span></span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <div class="sbar" style="width:230px">
        <svg viewBox="0 0 24 24" <?=$s?> style="width:13px;height:13px;flex-shrink:0;margin-right:8px;opacity:.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="kickSearch" placeholder="Search key, device, package, IP..." value="<?=htmlspecialchars($q)?>">
      </div>
      <select class="fi" style="width:170px;padding:8px 10px;font-size:12.5px" onchange="location.href='/heartbeatkicks.php?r='+encodeURIComponent(this.value)">
        <option value="">All reasons</option>
        <?php foreach($REASONS as $k=>$v): ?>
        <option value="<?=$k?>" <?=$rf===$k?'selected':''?>><?=htmlspecialchars($v[0])?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-red btn-sm" onclick="confirmDo('Clear the entire kick history?',clearKicks)"><?=svgIcon('trash',12)?> Clear</button>
    </div>
  </div>

  <div class="tw">
    <table id="kicksTable">
      <thead><tr>
        <th>Key</th><th>Reason</th><th>What happened</th><th>Package</th><th>IP</th><th>Retries</th><th>Last seen</th><th></th>
      </tr></thead>
      <tbody>
        <?php if(empty($rows)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">
          <?= $total===0 ? 'No kicks recorded yet — every heartbeat has been accepted.' : 'No records match this filter.' ?>
        </td></tr>
        <?php else: foreach($rows as $r):
          [$label,$cls,$hint] = $REASONS[$r->reason] ?? [$r->reason,'b-reseller',''];
        ?>
        <tr id="kk-<?= (int)$r->id ?>">
          <td>
            <?php if($r->user_key): ?>
              <code class="kcode" style="font-size:11px"><?=htmlspecialchars($r->user_key)?></code>
            <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
          </td>
          <td><span class="bx <?=$cls?>" title="<?=htmlspecialchars($hint)?>"><?=htmlspecialchars($label)?></span></td>
          <td style="font-size:12px;color:var(--text2);max-width:280px"><?=htmlspecialchars($r->detail)?></td>
          <td style="font-size:11.5px;color:var(--muted2)"><?= $r->pkg ? htmlspecialchars($r->pkg) : '—' ?></td>
          <td class="td-mono" style="font-size:11.5px"><?= $r->ip ? htmlspecialchars($r->ip) : '—' ?></td>
          <td class="td-mono"><?= (int)$r->hits ?></td>
          <td style="font-size:11.5px;color:var(--muted)"><?= date('d M, H:i', strtotime($r->last_seen)) ?></td>
          <td>
            <button class="btn btn-ghost btn-xs" onclick="delKick(<?=(int)$r->id?>)" title="Remove">
              <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if($pages>1): ?>
  <div class="pagination">
    <?php for($p2=1;$p2<=$pages;$p2++): ?>
    <a href="?p=<?=$p2.$qs?>" class="pg-btn <?=$p2===$pg?'on':''?>"><?=$p2?></a>
    <?php endfor;?>
  </div>
  <?php endif;?>
</div>

<div class="card">
  <div class="card-h"><span class="ct"><?=svgIcon('info',15)?> What each reason means</span></div>
  <div class="card-b">
    <?php foreach($REASONS as $k=>$v): ?>
    <div class="srow">
      <span class="bx <?=$v[1]?>" style="flex-shrink:0"><?=htmlspecialchars($v[0])?></span>
      <span style="font-size:12.5px;color:var(--muted2);text-align:right"><?=htmlspecialchars($v[2])?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;
async function delKick(id){
  const r = await formPost('/heartbeatkicks.php',{action:'delete',id,csrf});
  if(r.ok){ toast(r.msg,'s'); dropRow('kk-'+id); } else toast(r.msg,'e');
}
async function clearKicks(){
  const r = await formPost('/heartbeatkicks.php',{action:'clear_all',csrf});
  if(r.ok){ toast(r.msg,'s'); setTimeout(()=>location.reload(),700); } else toast(r.msg,'e');
}
</script>
<?php endif; ?>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
