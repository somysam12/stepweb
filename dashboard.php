<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__.'/app/Views/Layout/header.php';

$totalKeys   = (int)(db()->one("SELECT COUNT(*) c FROM keys_code")->c ?? 0);
$activeKeys  = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE status=1 AND (expired_date IS NULL OR expired_date>NOW())")->c ?? 0);
$totalUsers  = (int)(db()->one("SELECT COUNT(*) c FROM users")->c ?? 0);
$totalRefs   = (int)(db()->one("SELECT COUNT(*) c FROM referral_codes")->c ?? 0);
$usedRefs    = (int)(db()->one("SELECT COUNT(*) c FROM referral_codes WHERE is_used=1")->c ?? 0);
$blockedIPs  = (int)(db()->one("SELECT COUNT(*) c FROM blocked_ips WHERE expires_at IS NULL OR expires_at>NOW()")->c ?? 0);
$todayKeys   = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE DATE(created_at)=CURDATE()")->c ?? 0);
$totalBal    = (float)(db()->one("SELECT COALESCE(SUM(balance),0) t FROM users")->t ?? 0);

if ($ulevel > 1) {
    $totalKeys = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE registrator=?",[$uname])->c ?? 0);
    $activeKeys= (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE registrator=? AND status=1",[$uname])->c ?? 0);
}

$recentKeys = ($ulevel===1)
    ? db()->all("SELECT * FROM keys_code ORDER BY created_at DESC LIMIT 8")
    : db()->all("SELECT * FROM keys_code WHERE registrator=? ORDER BY created_at DESC LIMIT 8",[$uname]);

$recentLogs = db()->all("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 6");

function keyStatus($key): array {
    if (!$key->status) return ['Blocked','b-ban'];
    if ($key->expired_date && strtotime($key->expired_date) < time()) return ['Expired','b-exp'];
    if (!$key->device_count) return ['Unused','b-unused'];
    return ['Active','b-active'];
}
function fmtDur(int $h): string {
    if ($h < 24) return "{$h}H";
    $d = round($h/24);
    return "{$d}D";
}
?>

<div class="g4" style="margin-bottom:16px">
  <div class="kpi" style="--kc:var(--acc);--kb:rgba(0,229,160,.1);--ka:rgba(0,229,160,.07)">
    <div class="kpi-top"><div class="kpi-ico"><?=ico('keys')?></div><div class="kpi-trend t-up">↑ <?= $todayKeys ?> today</div></div>
    <div class="kpi-val" id="statKeys"><?= number_format($totalKeys) ?></div>
    <div class="kpi-lbl">Total Keys</div>
    <div class="pbar"><div class="pfill" style="width:<?= $totalKeys>0?min(100,round($activeKeys/$totalKeys*100)):0 ?>%"></div></div>
    <div class="kpi-sub"><?= $activeKeys ?> active</div>
  </div>
  <div class="kpi" style="--kc:var(--acc2);--kb:rgba(0,148,255,.1);--ka:rgba(0,148,255,.06)">
    <div class="kpi-top"><div class="kpi-ico"><?=ico('users')?></div></div>
    <div class="kpi-val" id="statUsers"><?= number_format($totalUsers) ?></div>
    <div class="kpi-lbl">Total Users</div>
    <div class="kpi-sub">
      <?php
        $o=db()->one("SELECT COUNT(*) c FROM users WHERE level=1")->c??0;
        $a=db()->one("SELECT COUNT(*) c FROM users WHERE level=2")->c??0;
        $r=db()->one("SELECT COUNT(*) c FROM users WHERE level=3")->c??0;
      ?>
      <?= $o ?> Owner · <?= $a ?> Admin · <?= $r ?> Reseller
    </div>
  </div>
  <div class="kpi" style="--kc:var(--warn);--kb:rgba(255,184,0,.1);--ka:rgba(255,184,0,.06)">
    <div class="kpi-top"><div class="kpi-ico"><?=ico('referral')?></div></div>
    <div class="kpi-val" id="statRefs"><?= number_format($totalRefs) ?></div>
    <div class="kpi-lbl">Referral Codes</div>
    <div class="kpi-sub"><?= $usedRefs ?> used · <?= $totalRefs-$usedRefs ?> available</div>
  </div>
  <?php if($ulevel===1): ?>
  <div class="kpi" style="--kc:var(--acc3);--kb:rgba(183,0,255,.1);--ka:rgba(183,0,255,.06)">
    <div class="kpi-top"><div class="kpi-ico"><?=ico('balance')?></div></div>
    <div class="kpi-val" id="statBal" style="font-size:clamp(13px,4vw,24px);word-break:break-all;line-height:1.1;white-space:normal"><?= number_format($totalBal,0) ?></div>
    <div class="kpi-lbl">Total Balance</div>
    <div class="kpi-sub">Across all users</div>
  </div>
  <?php else: ?>
  <div class="kpi" style="--kc:var(--danger);--kb:rgba(255,77,109,.1);--ka:rgba(255,77,109,.06)">
    <div class="kpi-top"><div class="kpi-ico"><?=ico('iplimit')?></div></div>
    <div class="kpi-val"><?= number_format($blockedIPs) ?></div>
    <div class="kpi-lbl">Blocked IPs</div>
    <div class="kpi-sub">Auto DDoS shield</div>
  </div>
  <?php endif; ?>
</div>

<div class="g2">

  <div class="card">
    <div class="card-h">
      <span class="ct">Recent Keys</span>
      <?php if($ulevel===1): ?><a href="/keys.php" class="btn btn-ghost btn-sm">All Keys →</a><?php endif; ?>
    </div>
    <div class="tw">
      <table>
        <thead><tr><th>Key</th><th>Game</th><th>Duration</th><th>Status</th></tr></thead>
        <tbody>
          <?php if(empty($recentKeys)): ?>
          <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--muted)">No keys yet</td></tr>
          <?php else: foreach($recentKeys as $k):
            [$st,$sc] = keyStatus($k);
          ?>
          <tr>
            <td><code><?= htmlspecialchars(substr($k->user_key,0,18)).'...' ?></code></td>
            <td><?= htmlspecialchars($k->game) ?></td>
            <td style="font-family:var(--mono)"><?= fmtDur((int)$k->duration) ?></td>
            <td><span class="bx <?= $sc ?>"><?= $st ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>


  <div class="card">
    <div class="card-h">
      <span class="ct">Recent Activity</span>
      <?php if($ulevel===1): ?><a href="/logs.php" class="btn btn-ghost btn-sm">All Logs →</a><?php endif; ?>
    </div>
    <div class="tw">
      <table>
        <thead><tr><th>Action</th><th>User</th><th>Time</th></tr></thead>
        <tbody>
          <?php if(empty($recentLogs)): ?>
          <tr><td colspan="3" style="text-align:center;padding:24px;color:var(--muted)">No activity yet</td></tr>
          <?php else: foreach($recentLogs as $l): ?>
          <tr>
            <td><span class="bx b-admin" style="font-size:9px"><?= htmlspecialchars($l->action) ?></span></td>
            <td style="font-size:11px"><?= htmlspecialchars($l->username??'System') ?></td>
            <td style="font-size:10px;color:var(--muted)"><?= date('d M H:i',strtotime($l->created_at)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>