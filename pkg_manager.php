<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLevel(1); // Owner only

// ── AJAX handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token']); exit;
    }
    $action = $_POST['action'] ?? '';
    $kid    = (int)($_POST['kid'] ?? 0);

    switch ($action) {

        case 'reset_pkg':
            if ($kid <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid key ID']); exit; }
            db()->q("UPDATE keys_code SET loader_pkg=NULL, is_used=0, first_used=NULL WHERE id=?", [$kid]);
            logAct($_SESSION['uid'], $_SESSION['uname']??'', 'RESET_PKG', "ID:$kid");
            echo json_encode(['ok'=>true,'msg'=>'Package binding reset. Next login will auto-bind the new APK package.']);
            exit;

        case 'set_pkg':
            if ($kid <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid key ID']); exit; }
            $pkg = trim($_POST['pkg'] ?? '');
            if ($pkg === '') { echo json_encode(['ok'=>false,'msg'=>'Package name cannot be empty']); exit; }
            if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/i', $pkg)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid format — must be like: com.example.app']); exit;
            }
            db()->q(
                "UPDATE keys_code SET loader_pkg=?, is_used=1, first_used=COALESCE(first_used, NOW()) WHERE id=?",
                [$pkg, $kid]
            );
            logAct($_SESSION['uid'], $_SESSION['uname']??'', 'SET_PKG', "ID:$kid pkg=$pkg");
            echo json_encode(['ok'=>true,'msg'=>"Package set to: $pkg"]);
            exit;

        case 'reset_all_pkg':
            db()->q("UPDATE keys_code SET loader_pkg=NULL, is_used=0, first_used=NULL");
            logAct($_SESSION['uid'], $_SESSION['uname']??'', 'RESET_ALL_PKG', 'all keys');
            echo json_encode(['ok'=>true,'msg'=>'All package bindings reset.']);
            exit;

        case 'list':
            $search = trim($_POST['search'] ?? '');
            $filter = $_POST['filter'] ?? 'all';
            $where  = '1=1';
            $params = [];
            if ($search !== '') {
                $where   .= " AND (user_key LIKE ? OR COALESCE(loader_pkg,'') LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if ($filter === 'bound')   $where .= " AND loader_pkg IS NOT NULL AND loader_pkg != ''";
            if ($filter === 'unbound') $where .= " AND (loader_pkg IS NULL OR loader_pkg = '')";
            $rows = db()->all(
                "SELECT id, user_key, loader_pkg, is_used, status, expired_date, registrator
                 FROM keys_code WHERE $where ORDER BY id DESC LIMIT 300",
                $params
            ) ?? [];
            echo json_encode(['ok'=>true,'rows'=> array_map(fn($r)=>[
                'id'       => $r->id,
                'key_disp' => substr($r->user_key,0,22).'…',
                'full_key' => $r->user_key,
                'pkg'      => $r->loader_pkg ?? '',
                'is_used'  => (int)$r->is_used,
                'status'   => (int)$r->status,
                'expired'  => $r->expired_date ?? '',
                'reg'      => $r->registrator,
            ], $rows)]);
            exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ── Page render ────────────────────────────────────────────────────────────
$pageTitle = 'Package Manager';
$activeNav = 'pkg_manager';
require_once __DIR__.'/app/Views/Layout/header.php';

$csrf    = csrfToken();
$total   = (int)(db()->one("SELECT COUNT(*) c FROM keys_code")->c ?? 0);
$bound   = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE loader_pkg IS NOT NULL AND loader_pkg!=''")->c ?? 0);
$unbound = $total - $bound;
$topPkgs = db()->all(
    "SELECT loader_pkg, COUNT(*) cnt FROM keys_code
     WHERE loader_pkg IS NOT NULL AND loader_pkg!=''
     GROUP BY loader_pkg ORDER BY cnt DESC LIMIT 6"
) ?? [];
?>
<style>
.pm-stat{background:var(--bg2,rgba(255,255,255,.04));border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center}
.pm-stat-n{font-size:26px;font-weight:800;font-family:var(--mono);color:var(--acc)}
.pm-stat-l{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:4px}
.pm-row{display:flex;gap:8px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);flex-wrap:wrap}
.pm-row:last-child{border-bottom:none}
.pm-key{font-family:var(--mono);font-size:11px;color:var(--acc);flex:0 0 175px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pm-pkg{font-family:var(--mono);font-size:11px;flex:1;min-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pm-pkg.empty{color:var(--muted);font-style:italic}
.pm-badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:9px;font-weight:700;font-family:var(--mono);white-space:nowrap}
.active{background:rgba(0,229,160,.14);color:#00e5a0}
.blocked{background:rgba(255,80,80,.14);color:#ff5050}
.bound-b{background:rgba(100,160,255,.14);color:#64a0ff}
.free-b{background:rgba(255,200,0,.12);color:#ffc800}
.pm-actions{display:flex;gap:5px;flex-shrink:0}
.pm-btn{padding:4px 10px;border-radius:6px;font-size:10px;font-family:var(--mono);font-weight:700;border:1px solid var(--border);cursor:pointer;background:var(--bg2,rgba(255,255,255,.04));color:var(--text);white-space:nowrap;transition:opacity .15s}
.pm-btn:hover{opacity:.75}
.pm-btn.danger{border-color:rgba(255,80,80,.4);color:#ff5050}
.pm-btn.green{border-color:rgba(0,229,160,.4);color:#00e5a0}
.pm-input{background:var(--bg2,rgba(255,255,255,.04));border:1px solid var(--border);border-radius:7px;padding:7px 11px;font-family:var(--mono);font-size:12px;color:var(--text);outline:none;width:100%;box-sizing:border-box}
.pm-input:focus{border-color:var(--acc)}
.pm-empty{text-align:center;padding:32px;color:var(--muted);font-size:13px}
.pkg-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(0,229,160,.07);border:1px solid rgba(0,229,160,.18);border-radius:20px;padding:3px 10px;font-family:var(--mono);font-size:11px;color:var(--acc);margin:3px}
.pkg-cnt{background:var(--acc);color:#000;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:800}
/* Modal */
.pm-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;align-items:center;justify-content:center}
.pm-modal.show{display:flex}
.pm-modal-box{background:var(--bg1,#0f1117);border:1px solid var(--border);border-radius:14px;padding:24px;width:92%;max-width:400px}
.pm-modal-title{font-size:14px;font-weight:700;margin-bottom:16px}
.pm-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;margin-top:12px}
</style>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px">
  <div class="pm-stat"><div class="pm-stat-n"><?=$total?></div><div class="pm-stat-l">Total Keys</div></div>
  <div class="pm-stat"><div class="pm-stat-n" style="color:#64a0ff"><?=$bound?></div><div class="pm-stat-l">Pkg Bound</div></div>
  <div class="pm-stat"><div class="pm-stat-n" style="color:#ffc800"><?=$unbound?></div><div class="pm-stat-l">Not Bound</div></div>
</div>

<!-- Active package bindings -->
<?php if($topPkgs): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-h"><span class="ct">📦 Active Packages in DB</span></div>
  <div style="display:flex;flex-wrap:wrap">
    <?php foreach($topPkgs as $p): ?>
    <span class="pkg-chip"><?=htmlspecialchars($p->loader_pkg)?><span class="pkg-cnt"><?=$p->cnt?></span></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Key List -->
<div class="card">
  <div class="card-h" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span class="ct">🔑 Keys &amp; Bindings</span>
    <button class="pm-btn danger" onclick="resetAllPkg()">⚠ Reset ALL Pkg Bindings</button>
  </div>

  <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
    <input class="pm-input" id="pmSearch" placeholder="Search by key or package name…" style="flex:1;min-width:150px" oninput="loadList()">
    <select class="pm-input" id="pmFilter" style="flex:0 0 130px;width:130px" onchange="loadList()">
      <option value="all">All Keys</option>
      <option value="bound">Pkg Bound</option>
      <option value="unbound">Not Bound</option>
    </select>
  </div>

  <div id="pmList"><div class="pm-empty">Loading…</div></div>
</div>

<!-- Set Pkg Modal -->
<div class="pm-modal" id="setPkgModal">
  <div class="pm-modal-box">
    <div class="pm-modal-title">✏️ Set Package Name</div>
    <div class="pm-label">Key</div>
    <div style="font-family:var(--mono);font-size:11px;color:var(--acc);word-break:break-all;padding:6px 0" id="mKeyDisplay"></div>
    <div class="pm-label">Package Name</div>
    <input class="pm-input" id="mPkgInput" placeholder="e.g. com.safeloader" style="margin-top:5px">
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
      <button class="pm-btn" onclick="closeModal()">Cancel</button>
      <button class="pm-btn green" onclick="confirmSetPkg()">💾 Save</button>
    </div>
  </div>
</div>

<script>
const CSRF = <?=json_encode($csrf)?>;
let _modalKid = 0;

function ajax(data, cb) {
    data.ajax = '1'; data.csrf = CSRF;
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    fetch('', {method:'POST',body:fd}).then(r=>r.json()).then(cb).catch(()=>cb({ok:false,msg:'Network error'}));
}

function loadList() {
    document.getElementById('pmList').innerHTML = '<div class="pm-empty">Loading…</div>';
    ajax({action:'list', search:document.getElementById('pmSearch').value, filter:document.getElementById('pmFilter').value}, res => {
        if (!res.ok) { document.getElementById('pmList').innerHTML='<div class="pm-empty">Error loading list.</div>'; return; }
        if (!res.rows || !res.rows.length) { document.getElementById('pmList').innerHTML='<div class="pm-empty">No keys found.</div>'; return; }
        document.getElementById('pmList').innerHTML = res.rows.map(r => `
<div class="pm-row">
  <div class="pm-key" title="${r.full_key}">${r.key_disp}</div>
  <div class="pm-pkg ${r.pkg?'':'empty'}" title="${r.pkg||''}">${r.pkg||'(not bound)'}</div>
  <span class="pm-badge ${r.status==1?'active':'blocked'}">${r.status==1?'ON':'OFF'}</span>
  <span class="pm-badge ${r.pkg?'bound-b':'free-b'}">${r.pkg?'Bound':'Free'}</span>
  <div class="pm-actions">
    <button class="pm-btn green" onclick="openSetPkg(${r.id},'${r.full_key.replace(/'/g,"\\'")}','${(r.pkg||'').replace(/'/g,"\\'")}')">Set Pkg</button>
    <button class="pm-btn danger" onclick="resetPkg(${r.id})">Reset</button>
  </div>
</div>`).join('');
    });
}

function resetPkg(kid) {
    if (!confirm('Reset package binding for this key?\n\nNext login will auto-bind whatever APK contacts the panel.')) return;
    ajax({action:'reset_pkg', kid}, res => { alert(res.msg); if(res.ok) loadList(); });
}

function openSetPkg(kid, fullKey, currentPkg) {
    _modalKid = kid;
    document.getElementById('mKeyDisplay').textContent = fullKey;
    document.getElementById('mPkgInput').value = currentPkg || '';
    document.getElementById('setPkgModal').classList.add('show');
    setTimeout(() => document.getElementById('mPkgInput').focus(), 100);
}
function closeModal() {
    document.getElementById('setPkgModal').classList.remove('show');
    _modalKid = 0;
}
function confirmSetPkg() {
    const pkg = document.getElementById('mPkgInput').value.trim();
    if (!pkg) { alert('Package name cannot be empty'); return; }
    ajax({action:'set_pkg', kid:_modalKid, pkg}, res => { alert(res.msg); if(res.ok){ closeModal(); loadList(); } });
}
function resetAllPkg() {
    if (!confirm('⚠️ RESET ALL package bindings?\n\nEvery key in DB will accept any APK on next login.\nThis cannot be undone.')) return;
    ajax({action:'reset_all_pkg'}, res => { alert(res.msg); if(res.ok) loadList(); });
}
document.getElementById('setPkgModal').addEventListener('click', function(e){ if(e.target===this) closeModal(); });

loadList();
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
