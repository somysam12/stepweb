<?php

require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$uname = $_SESSION['uname'] ?? 'User';

$formats = ['classic','compact','dash','underscore','short','tiny'];

if ($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrf($_POST['csrf']??'')) {

    $prefix = preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['key_prefix'] ?? 'HB'));
    if ($prefix === '') $prefix = 'HB';
    $prefix = substr($prefix, 0, 20);

    $format = $_POST['key_format'] ?? 'classic';
    if (!in_array($format, $formats, true)) $format = 'classic';

    setSetting('key_prefix', $prefix);
    setSetting('key_format', $format);
    logAct($_SESSION['uid'],$uname,'UPDATE_KEY_FORMAT',"Prefix:$prefix Format:$format");
    header('Location: /keyformat.php?saved=1'); exit;
}

$prefix = getSetting('key_prefix','HB');
$format = getSetting('key_format','classic');
if (!in_array($format, $formats, true)) $format = 'classic';
$saved  = isset($_GET['saved']);

$formatMeta = [
    'classic'    => ['Classic',    'Dashed groups, uppercase'],
    'compact'    => ['Compact',    'Prefix + digits + letters, no separators'],
    'dash'       => ['Dashed',     'Prefix-digits-letters'],
    'underscore' => ['Underscore', 'Prefix_digits_letters'],
    'short'      => ['Short',      'Prefix + 4 characters'],
    'tiny'       => ['Tiny',       'Shortest, 6 random chars (no prefix)'],
];

$pageTitle = 'Key Format';
$activeNav = 'keyformat';
require_once __DIR__.'/app/Views/Layout/header.php';
?>
<?php if($saved): ?><div class="alert a-ok">Key format saved! New keys look like: <code><?= htmlspecialchars(keyFormatSample($prefix,$format)) ?></code></div><?php endif; ?>

<div class="g2">
  <div class="card">
    <div class="card-h"><span class="ct">Key Format Settings</span><span class="owner-only">Owner Only</span></div>
    <div class="card-b">
      <div style="text-align:center;margin-bottom:22px">
        <div style="font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Live Preview</div>
        <div class="kfmt" style="font-size:18px;letter-spacing:1px;word-break:break-all" id="previewFmt"><?= htmlspecialchars(keyFormatSample($prefix,$format)) ?></div>
      </div>

      <form method="POST">
        <?= csrfField() ?>
        <div class="fg">
          <label class="fl">Key Prefix <span style="color:var(--muted);font-weight:400">(Letters &amp; numbers, case kept, max 20)</span></label>
          <input class="fi fi-mono" type="text" name="key_prefix" id="prefixInput"
            value="<?= htmlspecialchars($prefix) ?>"
            placeholder="e.g. ArkCheat, VIP, KEY"
            maxlength="20"
            oninput="updatePreview()"
            style="font-size:17px;letter-spacing:1px;text-align:center;font-weight:800;color:var(--acc)">
          <div class="fi-hint">Case is preserved — <code>ArkCheat</code> stays <code>ArkCheat</code>.</div>
        </div>

        <div class="fg">
          <label class="fl">Key Format Style</label>
          <div id="fmtGrid" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <?php foreach($formatMeta as $fk=>$fm): ?>
            <label class="fmt-opt<?= $format===$fk?' sel':'' ?>" data-fmt="<?= $fk ?>" onclick="selectFmt('<?= $fk ?>')"
              style="cursor:pointer;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg2);transition:.15s;display:block">
              <input type="radio" name="key_format" value="<?= $fk ?>" <?= $format===$fk?'checked':'' ?> style="display:none">
              <div style="font-weight:700;font-size:12px;color:var(--text)"><?= htmlspecialchars($fm[0]) ?></div>
              <div style="font-size:10px;color:var(--muted);margin:3px 0 6px"><?= htmlspecialchars($fm[1]) ?></div>
              <div class="fmt-sample" style="font-family:var(--mono);font-size:10px;color:var(--acc2);word-break:break-all"><?= htmlspecialchars(keyFormatSample($prefix,$fk)) ?></div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <button type="submit" class="btn btn-acc btn-full">SAVE KEY FORMAT</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><span class="ct">Key Stats</span></div>
    <div class="card-b">
      <?php
      $total  = db()->one("SELECT COUNT(*) c FROM keys_code")->c??0;
      $active = db()->one("SELECT COUNT(*) c FROM keys_code WHERE status=1 AND (expired_date IS NULL OR expired_date>NOW())")->c??0;
      $unused = db()->one("SELECT COUNT(*) c FROM keys_code WHERE device_count=0 AND status=1")->c??0;
      $exp    = db()->one("SELECT COUNT(*) c FROM keys_code WHERE expired_date IS NOT NULL AND expired_date<NOW()")->c??0;
      ?>
      <div class="srow"><span>Total Keys</span><span class="bx b-active"><?= $total ?></span></div>
      <div class="srow"><span>Active</span><span class="bx b-active"><?= $active ?></span></div>
      <div class="srow"><span>Unused</span><span class="bx b-unused"><?= $unused ?></span></div>
      <div class="srow"><span>Expired</span><span class="bx b-exp"><?= $exp ?></span></div>
      <div class="srow"><span>Current Prefix</span><code><?= htmlspecialchars($prefix) ?></code></div>
      <div class="srow"><span>Current Format</span><code><?= htmlspecialchars($format) ?></code></div>
    </div>
  </div>
</div>

<style>
.fmt-opt.sel{border-color:var(--acc)!important;background:rgba(0,229,160,.08)!important;box-shadow:0 0 0 1px var(--acc) inset}
</style>
<script>

function sampleFor(prefix, fmt){
  switch(fmt){
    case 'compact':    return prefix+'2384grhefgjh';
    case 'dash':       return prefix+'-832937-dhd';
    case 'underscore': return prefix+'_72387_ieujre';
    case 'short':      return prefix+'A7b2';
    case 'tiny':       return 'X7b2K9';
    default:           return prefix+'-A1B2-C3D4-E5F6-G7H8';
  }
}
function currentFmt(){
  const r = document.querySelector('input[name="key_format"]:checked');
  return r ? r.value : 'classic';
}
function updatePreview(){
  let p = document.getElementById('prefixInput').value.replace(/[^A-Za-z0-9]/g,'').substring(0,20) || 'HB';
  document.getElementById('previewFmt').textContent = sampleFor(p, currentFmt());
  document.querySelectorAll('.fmt-opt').forEach(el=>{
    const s = el.querySelector('.fmt-sample');
    if(s) s.textContent = sampleFor(p, el.dataset.fmt);
  });
}
function selectFmt(fmt){
  document.querySelectorAll('.fmt-opt').forEach(el=>el.classList.toggle('sel', el.dataset.fmt===fmt));
  const radio = document.querySelector('input[name="key_format"][value="'+fmt+'"]');
  if(radio) radio.checked = true;
  updatePreview();
}
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>