<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) > 2) { header('Location: /dashboard.php'); exit; }
$pageTitle='Extend Duration'; $activeNav='extend';
require_once __DIR__.'/app/Views/Layout/header.php';

$msg=''; $msgType='ok';

$scopeNames = null;
if ($ulevel > 1) {
    $codes = array_column((array)db()->all("SELECT code FROM referral_codes WHERE created_by=?",[$uname]),'code');
    $names = [$uname];
    if ($codes) {
        $ph = implode(',', array_fill(0, count($codes), '?'));
        foreach (db()->all("SELECT username FROM users WHERE ref_code_used IN ($ph)", $codes) as $r) $names[] = $r->username;
    }
    $scopeNames = array_values(array_unique($names));
}

if ($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrf($_POST['csrf']??'')) {
    $sec=trim($_POST['section']??'');
    $durInput=strtoupper(trim($_POST['duration']??''));
    if (!preg_match('/^(\d+)[HD]$/',$durInput,$m)) { $msg='Invalid format. Use e.g. 30D or 12H.'; $msgType='err'; }
    else {
        $val=(int)$m[1]; $unit=substr($durInput,-1);
        $hrs = $unit==='H' ? $val : $val*24;
        if ($sec==='all') {
            if ($scopeNames === null) {
                db()->q("UPDATE keys_code SET duration=duration+?, expired_date=DATE_ADD(expired_date,INTERVAL ? HOUR)",[$hrs,$hrs]);
            } else {
                $ph = implode(',', array_fill(0, count($scopeNames), '?'));
                db()->q("UPDATE keys_code SET duration=duration+?, expired_date=DATE_ADD(expired_date,INTERVAL ? HOUR) WHERE registrator IN ($ph)",
                    array_merge([$hrs,$hrs], $scopeNames));
            }
            logAct($_SESSION['uid'],$uname,'EXTEND_ALL',"Extended by {$durInput}".($scopeNames?' (own scope)':''));
            $msg=($scopeNames?"Your keys":"All keys")." extended by {$durInput}!"; $msgType='ok';
        } elseif($sec==='single') {
            $keyStr=trim($_POST['user_key']??'');
            $krow=db()->one("SELECT id, registrator FROM keys_code WHERE user_key=?",[$keyStr]);
            if (!$krow) { $msg='Key not found.'; $msgType='err'; }
            elseif ($scopeNames !== null && !in_array($krow->registrator, $scopeNames, true)) {
                $msg='You can only extend your own or your resellers'."'".' keys.'; $msgType='err';
            } else {
                db()->q("UPDATE keys_code SET duration=duration+?,expired_date=DATE_ADD(expired_date,INTERVAL ? HOUR) WHERE id=?",
                    [$hrs,$hrs,$krow->id]);
                logAct($_SESSION['uid'],$uname,'EXTEND_KEY',"Key:{$keyStr} +{$durInput}");
                $msg="Key extended by {$durInput}!"; $msgType='ok';
            }
        }
    }
}
?>
<?php if($msg): ?><div class="alert a-<?= $msgType ?>"><?= $msgType==='ok'?'':'' ?> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<div class="g2">
  <div class="card"><div class="card-h"><span class="ct"><?= $scopeNames===null ? 'Extend ALL Keys' : 'Extend My Keys' ?></span></div><div class="card-b">
    <div class="alert a-warn"><?= $scopeNames===null
        ? 'This extends the duration of ALL keys in the system!'
        : 'This extends all your keys and your resellers\' keys.' ?></div>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="section" value="all">
      <div class="fg"><label class="fl">Duration to Add</label><input class="fi fi-mono" name="duration" placeholder="30D or 12H" required></div>
      <div class="fi-hint" style="margin-bottom:14px">Format: <code>30D</code> = 30 Days, <code>12H</code> = 12 Hours</div>
      <button type="submit" class="btn btn-warn btn-full"><?= $scopeNames===null ? 'EXTEND ALL KEYS' : 'EXTEND MY KEYS' ?></button>
    </form>
  </div></div>
  <div class="card"><div class="card-h"><span class="ct">Extend Single Key</span></div><div class="card-b">
    <form method="POST"><?= csrfField() ?><input type="hidden" name="section" value="single">
      <div class="fg"><label class="fl">License Key</label><input class="fi fi-mono" name="user_key" placeholder="HB-XXXX-XXXX-XXXX-XXXX" required></div>
      <div class="fg"><label class="fl">Duration to Add</label><input class="fi fi-mono" name="duration" placeholder="7D or 24H" required></div>
      <button type="submit" class="btn btn-acc btn-full">EXTEND THIS KEY</button>
    </form>
  </div></div>
</div>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>