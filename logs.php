<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }

// ── AJAX: delete single log ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_log') {
    verifyCsrf($_POST['csrf'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) { db()->run("DELETE FROM activity_logs WHERE id = ?", [$id]); }
    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: clear all logs ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_logs') {
    verifyCsrf($_POST['csrf'] ?? '');
    db()->run("DELETE FROM activity_logs");
    echo json_encode(['ok' => true]);
    exit;
}

$pageTitle = 'Activity Logs'; $activeNav = 'logs';
require_once __DIR__.'/app/Views/Layout/header.php';

$pp = 30; $pg = max(1,(int)($_GET['p']??1)); $off = ($pg-1)*$pp;
$sq = trim($_GET['q']??'');
$where = ''; $params = [];
if ($sq) { $where = "WHERE (action LIKE ? OR detail LIKE ? OR username LIKE ? OR ip LIKE ?)"; $params = ["%$sq%","%$sq%","%$sq%","%$sq%"]; }
$total = (int)(db()->one("SELECT COUNT(*) c FROM activity_logs $where",$params)->c??0);
$logs  = db()->all("SELECT * FROM activity_logs $where ORDER BY created_at DESC LIMIT $pp OFFSET $off",$params);
$pages = (int)ceil($total/$pp);
$s     = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="card">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Activity Logs
      <span style="color:var(--muted);font-size:11px;font-weight:400;text-transform:none;letter-spacing:0" id="logCount">(<?= number_format($total) ?>)</span>
    </span>
    <div style="display:flex;align-items:center;gap:8px">
      <?php if ($total > 0): ?>
      <button class="btn btn-outline btn-xs" style="color:var(--danger);border-color:var(--danger)" onclick="clearAllLogs()">Clear All</button>
      <?php endif; ?>
      <div class="sbar" style="width:240px">
        <svg viewBox="0 0 24 24" <?=$s?> style="width:13px;height:13px;flex-shrink:0;margin-right:8px;opacity:.4"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="logSearch" placeholder="Search action, user, IP..." value="<?= htmlspecialchars($sq) ?>"
               onkeydown="if(event.key==='Enter'){let u=new URL('/logs.php',location);u.searchParams.set('q',this.value);u.searchParams.set('p','1');location.href=u;}">
      </div>
    </div>
  </div>

  <div class="tw">
    <table id="logsTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Action</th>
          <th>Detail</th>
          <th>User</th>
          <th>IP</th>
          <th>Time</th>
          <th style="width:40px"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">No logs found</td></tr>
        <?php else: $i = $off+1; foreach ($logs as $l): ?>
        <tr data-id="<?= $l->id ?>">
          <td style="color:var(--muted);font-size:11px"><?= $i++ ?></td>
          <td><span class="bx b-admin" style="font-size:9px;padding:3px 8px"><?= htmlspecialchars($l->action) ?></span></td>
          <td style="font-size:11px;color:var(--muted2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($l->detail??'') ?>"><?= htmlspecialchars($l->detail ?? '—') ?></td>
          <td style="font-size:12px;font-weight:600"><?= htmlspecialchars($l->username ?? 'System') ?></td>
          <td style="font-size:11px;color:var(--muted2)"><?= htmlspecialchars($l->ip ?? '—') ?></td>
          <td style="font-size:10px;color:var(--muted);white-space:nowrap"><?= date('d M Y H:i:s', strtotime($l->created_at)) ?></td>
          <td><button class="btn btn-outline btn-xs" style="color:var(--danger);border-color:var(--danger);padding:2px 6px;font-size:9px" onclick="deleteLog(<?= $l->id ?>,this)">Del</button></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php for ($p2=1; $p2<=$pages; $p2++): ?>
    <a href="?p=<?= $p2 ?>&q=<?= urlencode($sq) ?>" class="pg-btn <?= $p2===$pg?'on':'' ?>"><?= $p2 ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]')?.content || '';

function deleteLog(id, btn) {
  if (!confirm('Delete this log entry?')) return;
  btn.disabled = true;
  fetch('/logs.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=delete_log&id=' + id + '&csrf=' + encodeURIComponent(csrf)
  }).then(r => r.json()).then(d => {
    if (d.ok) {
      const row = btn.closest('tr');
      if (row) row.remove();
    }
  }).catch(() => { btn.disabled = false; });
}

function clearAllLogs() {
  if (!confirm('Delete ALL activity logs? This cannot be undone.')) return;
  fetch('/logs.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=clear_logs&csrf=' + encodeURIComponent(csrf)
  }).then(r => r.json()).then(d => {
    if (d.ok) location.reload();
  });
}

(function(){
  const inp = document.getElementById('logSearch');
  if (!inp) return;
  let debounce;
  inp.addEventListener('input', () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
      const q = inp.value.toLowerCase().trim();
      document.querySelectorAll('#logsTable tbody tr').forEach(r => {
        r.style.display = (!q || r.textContent.toLowerCase().includes(q)) ? '' : 'none';
      });
    }, 150);
  });
})();
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
