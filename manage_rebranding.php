<?php

require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    requireLogin();
    header('Content-Type: application/json');
    $ulv   = (int)($_SESSION['ulevel'] ?? 99);
    $uid   = $_SESSION['uid']   ?? 0;
    $uname = $_SESSION['uname'] ?? '';
    if ($ulv !== 1) { echo json_encode(['ok'=>false,'msg'=>'Owner only']); exit; }
    if (!verifyCsrf($_POST['csrf'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token']); exit; }

    $sec    = $_POST['section'] ?? '';
    $libDir = __DIR__.'/server/libs/';
    $apkDir = __DIR__.'/server/apks/';

    switch ($sec) {


        case 'set_base':
            $b = trim($_POST['base_url'] ?? '');
            if ($b !== '') {
                if (!preg_match('#^https?://[^\s/]+#i', $b)) {
                    echo json_encode(['ok'=>false,'msg'=>'Enter a full URL, e.g. https://sub.yourdomain.com']); exit;
                }
                $b = rtrim($b, '/');
            }
            setSetting('panel_base_url', $b);
            logAct($uid, $uname, 'SET_BASE_URL', $b ?: '(default)');
            echo json_encode(['ok'=>true,'msg'=>$b ? "Base domain set to {$b}" : 'Base domain reset to default.']);
            exit;


        case 'lib_killer':
            $kb = trim($_POST['kill_below'] ?? '');
            if (!preg_match('/^\d+(\.\d+){0,3}$/', $kb)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid version format (e.g. 4.2)']); exit;
            }
            $lr = trim($_POST['kill_redirect'] ?? '');
            if ($lr !== '' && !preg_match('#^https?://#i', $lr)) {
                echo json_encode(['ok'=>false,'msg'=>'Redirect URL must start with http:// or https://']); exit;
            }
            setSetting('lib_kill_below', $kb);
            setSetting('lib_kill_enabled', '1');
            setSetting('lib_kill_redirect', $lr);
            logAct($uid, $uname, 'LIB_KILL_SET', "Kill below v$kb | Redirect: $lr");
            echo json_encode(['ok'=>true,'msg'=>"Lib Killer ACTIVATED! All libs below v{$kb} will crash on next check."]);
            exit;


        case 'modlib_save':
            $mv = trim($_POST['mod_version'] ?? '');
            if (!preg_match('/^\d+(\.\d+){0,3}$/', $mv)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid version (e.g. 1.2)']); exit;
            }
            $mk = ($_POST['mod_kill'] ?? '0') === '1' ? '1' : '0';
            $mr = trim($_POST['mod_redirect'] ?? '');
            if ($mr !== '' && !preg_match('#^https?://#i', $mr)) {
                echo json_encode(['ok'=>false,'msg'=>'Redirect URL must start with http:// or https://']); exit;
            }
            setSetting('modlib_version', $mv);
            setSetting('modlib_kill_enabled', $mk);
            setSetting('modlib_kill_redirect', $mr);
            logAct($uid, $uname, 'MODLIB_SAVE', "v$mv kill=$mk redir=$mr");
            echo json_encode(['ok'=>true,'msg'=>$mk==='1'
                ? 'Mod Lib KILL-ALL active — every mod lib will crash.'
                : "Mod version set to v{$mv}. Any older mod lib now crashes."]);
            exit;


        case 'lib_killer_reset':
            setSetting('lib_kill_enabled', '0');
            setSetting('lib_kill_below', '0.0');
            logAct($uid, $uname, 'LIB_KILL_RESET', '');
            echo json_encode(['ok'=>true,'msg'=>'Lib Killer disabled. All versions allowed.']);
            exit;


        case 'set_lib_version':
            $v = trim($_POST['new_lib_version'] ?? '');
            if (!preg_match('/^\d+(\.\d+){0,3}$/', $v)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid version (e.g. 4.2)']); exit;
            }
            setSetting('lib_version', $v);
            if (!is_dir($libDir)) mkdir($libDir, 0755, true);
            file_put_contents($libDir.'version.txt', $v);
            logAct($uid, $uname, 'LIB_VERSION_SET', "v$v");
            echo json_encode(['ok'=>true,'msg'=>"Lib version set to v{$v} and version.txt updated."]);
            exit;


        case 'apk_killer':
            $on  = ($_POST['kill_on'] ?? '0') === '1' ? '1' : '0';
            $kv  = trim($_POST['kill_version'] ?? '');
            $km  = trim($_POST['kill_msg'] ?? 'This version has been expired. Please update your application.');
            $kr  = trim($_POST['kill_redirect'] ?? '');
            if ($kr !== '' && !preg_match('#^https?://#i', $kr)) {
                echo json_encode(['ok'=>false,'msg'=>'Redirect URL must start with http:// or https://']); exit;
            }
            setSetting('apk_kill_enabled', $on);
            setSetting('apk_kill_version_target', $kv);
            setSetting('apk_kill_message', $km);
            setSetting('apk_kill_redirect', $kr);
            logAct($uid, $uname, 'APK_KILL_'.($on?'ON':'OFF'), "Target: $kv | Redirect: $kr");
            $status = $on ? 'APK Killer ACTIVATED' : 'APK Killer disabled';
            echo json_encode(['ok'=>true,'msg'=>$status.' — settings saved!']);
            exit;


        case 'set_apk_version':
            $v = trim($_POST['new_apk_version'] ?? '');
            if (!preg_match('/^\d+(\.\d+){0,3}$/', $v)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid version (e.g. 1.5)']); exit;
            }
            setSetting('apk_version', $v);
            if (!is_dir($apkDir)) mkdir($apkDir, 0755, true);
            file_put_contents($apkDir.'version.txt', $v);
            logAct($uid, $uname, 'APK_VERSION_SET', "v$v");
            echo json_encode(['ok'=>true,'msg'=>"APK version set to v{$v} and version.txt updated."]);
            exit;


        case 'delete_lib':
            $fn = basename($_POST['fname'] ?? '');
            if (!$fn || str_contains($fn,'..')) { echo json_encode(['ok'=>false,'msg'=>'Invalid file']); exit; }
            $fp = $libDir.$fn;
            if (!file_exists($fp)) { echo json_encode(['ok'=>false,'msg'=>'File not found']); exit; }
            if (!@unlink($fp)) {
                $e = error_get_last();
                echo json_encode(['ok'=>false,'msg'=>'Delete failed: '.($e['message'] ?? 'permission denied. chmod 755 server/libs/ and try again.')]);
                exit;
            }
            logAct($uid, $uname, 'LIB_DELETE', "File: $fn");
            echo json_encode(['ok'=>true,'msg'=>"Deleted: $fn"]);
            exit;


        case 'delete_apk':
            $fn = basename($_POST['fname'] ?? '');
            if (!$fn || str_contains($fn,'..')) { echo json_encode(['ok'=>false,'msg'=>'Invalid file']); exit; }
            $fp = $apkDir.$fn;
            if (!file_exists($fp)) { echo json_encode(['ok'=>false,'msg'=>'File not found']); exit; }
            if (!@unlink($fp)) {
                $e = error_get_last();
                echo json_encode(['ok'=>false,'msg'=>'Delete failed: '.($e['message'] ?? 'permission denied. chmod 755 server/apks/ and try again.')]);
                exit;
            }
            logAct($uid, $uname, 'APK_DELETE', "File: $fn");
            echo json_encode(['ok'=>true,'msg'=>"Deleted: $fn"]);
            exit;

        default:
            echo json_encode(['ok'=>false,'msg'=>'Unknown section']); exit;
    }
}

$uploadMsg     = '';
$uploadMsgType = 'ok';
$activeTab     = 'lib';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $cl  = (int)$_SERVER['CONTENT_LENGTH'];
    $max = ini_get('post_max_size');
    $uploadMsg = "Upload too large (".round($cl/1048576,1)."MB). Server limit is {$max}. "
               . "Raise upload_max_filesize / post_max_size (see .user.ini) or ask your host.";
    $uploadMsgType = 'err';
    $activeTab = 'apk';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST) && empty($_POST['ajax'])) {
    if (empty($_SESSION['uid'])) { header('Location: /index.php'); exit; }


    if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }

    $activeTab = $_POST['active_tab'] ?? 'lib';


    $upErr = function($code){
        return [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize. Raise it in cPanel MultiPHP INI Editor.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded — retry.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server has no temp folder (contact host).',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the temp file (contact host).',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        ][$code] ?? ('Upload error code: '.$code);
    };

    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $uploadMsg = 'Session/CSRF token expired — reload the page and try again.'; $uploadMsgType = 'err';
    } else {
        $libDir = __DIR__.'/server/libs/';
        $apkDir = __DIR__.'/server/apks/';

        if (!empty($_FILES['lib_file']['name'])) {

            $activeTab = 'lib';
            $f   = $_FILES['lib_file'];
            $nm  = basename($f['name']);
            $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
            $allowed = ['so','zip','tar','gz','lib','dll','a','o','bin'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $uploadMsg = $upErr($f['error']); $uploadMsgType = 'err';
            } elseif (!in_array($ext, $allowed)) {
                $uploadMsg = 'File type not allowed. Allowed: '.implode(', ', $allowed); $uploadMsgType = 'err';
            } elseif ($f['size'] > 50*1024*1024) {
                $uploadMsg = 'File too large. Max 50MB.'; $uploadMsgType = 'err';
            } else {
                if (!is_dir($libDir)) @mkdir($libDir, 0775, true);
                if (!is_writable($libDir)) {
                    $uploadMsg = 'server/libs/ is not writable. In cPanel File Manager set its permissions to 0755 (or 0775).';
                    $uploadMsgType = 'err';
                } elseif (move_uploaded_file($f['tmp_name'], $libDir.$nm)) {
                    $newVer = trim($_POST['lib_new_ver'] ?? '');
                    if (preg_match('/^\d+(\.\d+){0,3}$/', $newVer)) {
                        setSetting('lib_version', $newVer);
                        file_put_contents($libDir.'version.txt', $newVer);
                    }
                    logAct($_SESSION['uid'], $_SESSION['uname']??'', 'LIB_UPLOAD',
                        "File: $nm | Ver: $newVer | Size: ".round($f['size']/1024)."KB");
                    $uploadMsg = "Library '{$nm}' uploaded! Version set to v{$newVer}.";
                } else {
                    $uploadMsg = 'Upload failed. Run: chmod 755 server/libs/'; $uploadMsgType = 'err';
                }
            }

        } elseif (!empty($_FILES['apk_file']['name'])) {

            $activeTab = 'apk';
            $f   = $_FILES['apk_file'];
            $nm  = basename($f['name']);
            $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $uploadMsg = $upErr($f['error']); $uploadMsgType = 'err';
            } elseif ($ext !== 'apk') {
                $uploadMsg = 'Only .apk files allowed.'; $uploadMsgType = 'err';
            } elseif ($f['size'] > 200*1024*1024) {
                $uploadMsg = 'File too large. Max 200MB.'; $uploadMsgType = 'err';
            } else {
                if (!is_dir($apkDir)) @mkdir($apkDir, 0775, true);
                if (!is_writable($apkDir)) {
                    $uploadMsg = 'server/apks/ is not writable. In cPanel File Manager set its permissions to 0755 (or 0775).';
                    $uploadMsgType = 'err';
                } elseif (move_uploaded_file($f['tmp_name'], $apkDir.$nm)) {
                    $newVer = trim($_POST['apk_new_ver'] ?? '');
                    if (preg_match('/^\d+(\.\d+){0,3}$/', $newVer)) {
                        setSetting('apk_version', $newVer);
                        file_put_contents($apkDir.'version.txt', $newVer);

                        $curUrl = getSetting('apk_url','');
                        if (empty($curUrl)) {
                            setSetting('apk_url', panelBase()
                                .'/server/apks/'.urlencode($nm));
                        }
                    }
                    logAct($_SESSION['uid'], $_SESSION['uname']??'', 'APK_UPLOAD',
                        "File: $nm | Ver: $newVer | Size: ".round($f['size']/1024/1024,1)."MB");
                    $uploadMsg = "APK '{$nm}' uploaded! Version set to v{$newVer}.";
                } else {
                    $uploadMsg = 'Upload failed. Run: chmod 755 server/apks/'; $uploadMsgType = 'err';
                }
            }
        }
    }
}

requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$pageTitle = 'Manage Rebranding';
$activeNav = 'rebranding';
require_once __DIR__.'/app/Views/Layout/header.php';

$initSettings = [
    'lib_version'             => '1.0',
    'lib_kill_below'          => '0.0',
    'lib_kill_enabled'        => '0',
    'apk_kill_enabled'        => '0',
    'apk_kill_version_target' => '',
    'apk_kill_message'        => 'This version has been expired. Please update your application.',
    'apk_kill_redirect'       => '',
    'lib_kill_redirect'       => '',
    'modlib_version'          => '1.0',
    'modlib_kill_enabled'     => '0',
    'modlib_kill_redirect'    => '',
];
foreach ($initSettings as $k => $v) {
    db()->q("INSERT IGNORE INTO panel_settings(skey,svalue) VALUES(?,?)", [$k, $v]);
}

$libVer     = getSetting('lib_version', '1.0');
$libKillOn  = getSetting('lib_kill_enabled', '0') === '1';
$libKillBw  = getSetting('lib_kill_below', '0.0');
$libKillRdr = getSetting('lib_kill_redirect', '');
$modVer     = getSetting('modlib_version', '1.0');
$modKillOn  = getSetting('modlib_kill_enabled', '0') === '1';
$modRdr     = getSetting('modlib_kill_redirect', '');
$apkVer     = getSetting('apk_version', '1.0.0');
$apkKillOn  = getSetting('apk_kill_enabled', '0') === '1';
$apkKillVer = getSetting('apk_kill_version_target', '');
$apkKillMsg = getSetting('apk_kill_message', 'This version has been expired. Please update your application.');
$apkKillRdr = getSetting('apk_kill_redirect', '');
$apkUrl     = getSetting('apk_url', '');
$panelBase  = panelBase();
$panelBaseSet = getSetting('panel_base_url', '');

function bumpVer(string $v): string {
    $p = explode('.', $v);
    $p[count($p)-1] = (int)end($p) + 1;
    return implode('.', $p);
}
$libVerNext = bumpVer($libVer);
$apkVerNext = bumpVer($apkVer);
$modVerNext = bumpVer($modVer);

$libDir = __DIR__.'/server/libs/';
$libs   = [];
if (is_dir($libDir)) {
    foreach (scandir($libDir) as $f) {
        if (in_array($f, ['.','..', '.htaccess', 'version.txt'])) continue;
        $p = $libDir.$f;
        if (is_file($p)) $libs[] = ['name'=>$f,'size'=>filesize($p),'time'=>filemtime($p)];
    }
    usort($libs, fn($a,$b)=>$b['time']-$a['time']);
}

$apkDir = __DIR__.'/server/apks/';
$apks   = [];
if (is_dir($apkDir)) {
    foreach (scandir($apkDir) as $f) {
        if (in_array($f, ['.','..', '.htaccess', 'version.txt'])) continue;
        $p = $apkDir.$f;
        if (is_file($p)) $apks[] = ['name'=>$f,'size'=>filesize($p),'time'=>filemtime($p)];
    }
    usort($apks, fn($a,$b)=>$b['time']-$a['time']);
}

function fmtSz(int $b): string {
    if ($b >= 1048576) return round($b/1048576,1).'MB';
    if ($b >= 1024)    return round($b/1024,0).'KB';
    return $b.'B';
}
function iniBytes(string $v): int {
    $v = trim($v); if ($v==='') return 0;
    $n = (int)$v; $u = strtolower(substr($v,-1));
    if ($u==='g') $n *= 1024*1024*1024;
    elseif ($u==='m') $n *= 1024*1024;
    elseif ($u==='k') $n *= 1024;
    return $n;
}
$umf = ini_get('upload_max_filesize');
$pms = ini_get('post_max_size');
$limitBytes  = min(iniBytes($umf), iniBytes($pms));
$limitOkApk  = $limitBytes >= 200*1024*1024;
$limitOkLib  = $limitBytes >= 50*1024*1024;

$libKillUrl = $panelBase.'/server/lib_version.php';
$apkKillUrl   = $panelBase.'/server/apk_kill.php';
$modKillUrl   = $panelBase.'/server/modlib_version.php';
$libVerTxtUrl = $panelBase.'/server/libs/version.txt';
$apkVerTxtUrl = $panelBase.'/server/apks/version.txt';
$secLibUrl    = $panelBase.'/secure/lib';
$secUnlockUrl = $panelBase.'/secure/unlock';
$csrf         = csrfToken();
$sv           = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';

function urlRow(string $label, string $url, string $sv): string {
    $u = htmlspecialchars($url);
    $a = htmlspecialchars(addslashes($url));
    return '<div style="margin-bottom:10px">'
      .'<div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px">'.htmlspecialchars($label).'</div>'
      .'<div class="code-box">'.$u
      .'<button class="cb-copy" onclick="copyText(\''.$a.'\',\''.htmlspecialchars($label).'\')">Copy</button>'
      .'</div></div>';
}
?>

<style>
.rb-tabs{display:flex;gap:4px;margin-bottom:20px;padding:4px;background:var(--bg2);border-radius:10px;border:1px solid var(--border)}
.rb-tab{flex:1;padding:9px 8px;border:none;background:transparent;color:var(--muted);font-family:var(--mono);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-radius:7px;cursor:pointer;transition:all .2s;white-space:nowrap}
.rb-tab:hover{background:var(--bg3);color:var(--text)}
.rb-tab.active{background:var(--acc);color:#000}
.rb-tab.active.danger{background:#ff4d6d;color:#fff}
.rb-pane{display:none}
.rb-pane.active{display:block}
.kill-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:20px;font-family:var(--mono);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.kill-on{background:rgba(255,77,109,.12);border:1px solid rgba(255,77,109,.3);color:#ff4d6d}
.kill-off{background:rgba(45,212,80,.08);border:1px solid rgba(45,212,80,.2);color:#2dd450}
.kill-dot{width:6px;height:6px;border-radius:50%;background:currentColor}
.code-box{background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:12px 14px;font-family:var(--mono);font-size:11px;color:var(--acc);word-break:break-all;position:relative}
.code-box .cb-copy{position:absolute;top:8px;right:8px;padding:3px 8px;font-size:10px;background:var(--bg3);border:1px solid var(--border);border-radius:4px;cursor:pointer;color:var(--muted);font-family:var(--mono)}
.code-box .cb-copy:hover{color:var(--text)}
.ver-badge{display:inline-flex;align-items:center;gap:6px;background:var(--bg2);border:1px solid var(--acc);border-radius:20px;padding:4px 12px;font-family:var(--mono);font-size:13px;font-weight:700;color:var(--acc)}
.warn-box{background:rgba(255,184,0,.05);border:1px solid rgba(255,184,0,.2);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--warn);line-height:1.6}
.info-box{background:rgba(99,102,241,.05);border:1px solid rgba(99,102,241,.2);border-radius:8px;padding:12px 14px;font-size:12px;color:#a5b4fc;line-height:1.6}
.step-num{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--acc);color:#000;font-size:10px;font-weight:900;flex-shrink:0}

.kill-switch{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--bg2);border:1px solid var(--border);border-radius:12px}
.kill-switch.on{border-color:rgba(255,77,109,.4);background:rgba(255,77,109,.06)}
.ks-track{position:relative;width:56px;height:30px;border-radius:30px;background:var(--bg3);border:1px solid var(--border);cursor:pointer;flex-shrink:0;transition:all .25s}
.ks-track.on{background:#ff4d6d;border-color:#ff4d6d;box-shadow:0 0 16px rgba(255,77,109,.4)}
.ks-knob{position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;transition:all .25s;box-shadow:0 2px 6px rgba(0,0,0,.4)}
.ks-track.on .ks-knob{left:27px}
.ks-label{font-family:var(--mono);font-size:13px;font-weight:800;letter-spacing:.5px}
.ks-label .st-on{color:#ff4d6d}.ks-label .st-off{color:var(--muted)}
</style>

<?php if ($uploadMsg): ?>
  <div class="alert a-<?=$uploadMsgType?>" style="margin-bottom:16px"><?=htmlspecialchars($uploadMsg)?></div>
<?php endif; ?>

<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;padding:12px 16px;border-radius:12px;
     background:<?=$limitOkLib?'rgba(45,212,80,.06)':'rgba(255,77,109,.07)'?>;
     border:1px solid <?=$limitOkLib?'rgba(45,212,80,.25)':'rgba(255,77,109,.3)'?>">
  <div style="font-size:11px;font-family:var(--mono);color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Server Upload Limit</div>
  <div style="font-family:var(--mono);font-size:14px;font-weight:800;color:<?=$limitOkLib?'#2dd450':'#ff4d6d'?>">
    <?=fmtSz($limitBytes)?>
  </div>
  <div style="font-size:11px;color:var(--muted)">
    (upload_max_filesize=<?=htmlspecialchars($umf)?>, post_max_size=<?=htmlspecialchars($pms)?>)
  </div>
  <div style="flex:1"></div>
  <div style="font-size:11px;font-family:var(--mono)">
    Lib&nbsp;<?=$limitOkLib?'<span style="color:#2dd450">OK</span>':'<span style="color:#ff4d6d">LOW</span>'?>
    &nbsp;·&nbsp;
    APK&nbsp;<?=$limitOkApk?'<span style="color:#2dd450">OK</span>':'<span style="color:#ff4d6d">LOW</span>'?>
  </div>
</div>
<?php if (!$limitOkLib): ?>
<div class="warn-box" style="margin-bottom:16px">
  <strong>Upload limit too low</strong> — uploads bigger than <?=fmtSz($limitBytes)?> will fail.
  The <code>.user.ini</code> can take ~5 min to apply. If it stays low, set it in
  <strong>cPanel → MultiPHP INI Editor</strong> (select your domain): set
  <code>upload_max_filesize = 210M</code> and <code>post_max_size = 220M</code>, then reload this page.
</div>
<?php endif; ?>

<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;padding:16px 18px;border-radius:14px;background:linear-gradient(135deg,rgba(0,229,160,.07),rgba(0,148,255,.04));border:1px solid rgba(0,229,160,.15)">
  <div style="width:42px;height:42px;border-radius:12px;background:rgba(0,229,160,.12);border:1px solid rgba(0,229,160,.3);display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <svg viewBox="0 0 24 24" <?=$sv?> width="20" height="20"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
  </div>
  <div style="flex:1;min-width:0">
    <div style="font-weight:800;font-size:15px;color:var(--text)">Rebranding Control Center</div>
    <div style="font-size:12px;color:var(--muted)">Upload &amp; version your lib and APK · arm the kill-switches · set a redirect on kill.</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <span class="ver-badge">Lib v<?=htmlspecialchars($libVer)?></span>
    <span class="ver-badge">APK v<?=htmlspecialchars($apkVer)?></span>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-h"><span class="ct"><svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> Base Domain</span></div>
  <div class="card-b">
    <div class="info-box" style="margin-bottom:12px">
      Set this if you deploy the panel on a different domain/subdomain. Every link on this page and the
      Heartbeat page (connect, kill, secure, version) rebuilds from it automatically. Leave blank to use
      the default <code><?=htmlspecialchars(rtrim(PANEL_URL,'/'))?></code>.
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <input type="text" class="fi fi-mono" id="baseUrlInp" style="flex:1;min-width:220px"
             value="<?=htmlspecialchars($panelBaseSet)?>"
             placeholder="https://sub.yourdomain.com">
      <button class="btn btn-acc" onclick="saveBase()">Save Domain</button>
    </div>
    <div style="margin-top:8px;font-size:11px;color:var(--muted)">Active base now: <code><?=htmlspecialchars($panelBase)?></code></div>
  </div>
</div>

<div class="rb-tabs" id="rbTabs">
  <button data-tab="lib"     class="rb-tab<?=$activeTab==='lib'?' active':''?>" onclick="rbTab('lib',this)">Lib Manager</button>
  <button data-tab="libkill" class="rb-tab<?=$activeTab==='libkill'?' active danger':''?>" onclick="rbTab('libkill',this)">Lib Killer</button>
  <button data-tab="apk"     class="rb-tab<?=$activeTab==='apk'?' active':''?>" onclick="rbTab('apk',this)">APK Manager</button>
  <button data-tab="apkkill" class="rb-tab<?=$activeTab==='apkkill'?' active danger':''?>" onclick="rbTab('apkkill',this)">APK Killer</button>
  <button data-tab="modkill" class="rb-tab<?=$activeTab==='modkill'?' active danger':''?>" onclick="rbTab('modkill',this)">Mod Lib Kill</button>
</div>

<div id="rb-lib" class="rb-pane<?=$activeTab==='lib'?' active':''?>">
  <div class="g-left">


    <div class="card">
      <div class="card-h">
        <span class="ct">
          <svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Upload Library
        </span>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:11px;color:var(--muted)">Current version</span>
          <span class="ver-badge">v<?=htmlspecialchars($libVer)?></span>
          <span class="owner-only">Owner Only</span>
        </div>
      </div>
      <div class="card-b">
        <div class="info-box" style="margin-bottom:16px">
          Upload your compiled <code>.so</code> lib + set the new version. The panel writes <code>server/libs/version.txt</code> automatically and updates the kill-check endpoint.
        </div>
        <form method="POST" enctype="multipart/form-data">
          <?=csrfField()?>
          <input type="hidden" name="active_tab" value="lib">
          <div class="fg">
            <label class="fl">Library File <span style="color:var(--muted)">.so · .zip · .gz · .bin · .lib — Max 50MB</span></label>
            <div class="logo-drop" onclick="document.getElementById('libFileInp').click()" id="libDrop">
              <div id="libPrevWrap" style="margin-bottom:10px;display:none">
                <div id="libFileName" style="font-family:var(--mono);font-size:13px;color:var(--acc);font-weight:700"></div>
                <div id="libFileSize" style="font-size:11px;color:var(--muted);margin-top:4px"></div>
              </div>
              <svg viewBox="0 0 24 24" <?=$sv?> style="width:32px;height:32px;opacity:.3;margin-bottom:8px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              <div style="font-size:11px;color:var(--muted);font-family:var(--mono);text-transform:uppercase">Click to select lib file</div>
              <input type="file" id="libFileInp" name="lib_file" accept=".so,.zip,.tar,.gz,.lib,.dll,.a,.o,.bin" onchange="prevLib(this)" style="display:none">
            </div>
          </div>
          <div class="fg">
            <label class="fl">New Version <span style="color:var(--muted)">(will be saved to version.txt + DB)</span></label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="text" class="fi" name="lib_new_ver" id="libNewVer"
                     value="<?=htmlspecialchars($libVerNext)?>"
                     placeholder="e.g. <?=htmlspecialchars($libVerNext)?>"
                     style="flex:1">
              <button type="button" class="btn btn-ghost btn-sm"
                      onclick="document.getElementById('libNewVer').value='<?=htmlspecialchars($libVerNext)?>'">
                Auto +1
              </button>
            </div>
            <div style="margin-top:6px;font-size:11px;color:var(--muted)">
              Current: <code>v<?=htmlspecialchars($libVer)?></code> → Suggested next: <code>v<?=htmlspecialchars($libVerNext)?></code>
            </div>
          </div>
          <button type="submit" class="btn btn-acc btn-full" style="margin-top:4px">
            <svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            UPLOAD LIBRARY
          </button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:18px 0">


        <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Force Version (no file upload)</div>
        <div style="display:flex;gap:8px" id="setLibVerForm">
          <input type="text" class="fi" id="setLibVerInp" value="<?=htmlspecialchars($libVerNext)?>" placeholder="<?=htmlspecialchars($libVerNext)?>" style="flex:1">
          <button class="btn btn-ghost btn-sm" onclick="setLibVer()">Set Version</button>
        </div>
      </div>
    </div>


    <div class="card">
      <div class="card-h"><span class="ct">Lib Endpoints &amp; Links</span></div>
      <div class="card-b">
        <div class="info-box" style="margin-bottom:14px">
          Direct lib download is <strong>blocked</strong>. The app must first hit
          <code>/secure/unlock</code> with a valid key, then download from <code>/secure/lib</code>.
          A leaked link alone <strong>cannot</strong> download the lib.
        </div>
        <?= urlRow('Lib version.txt', $libVerTxtUrl, $sv) ?>
        <?= urlRow('Secure lib download endpoint', $secLibUrl, $sv) ?>
        <?= urlRow('Lib unlock endpoint', $secUnlockUrl, $sv) ?>
        <?= urlRow('Lib kill-check endpoint', $libKillUrl, $sv) ?>
      </div>
    </div>


    <div class="card">
      <div class="card-h">
        <span class="ct">Uploaded Libraries (<?=count($libs)?>)</span>
        <span style="font-size:10px;color:var(--muted);font-family:var(--mono)"><?=$panelBase?>/server/libs/</span>
      </div>
      <?php if (empty($libs)): ?>
        <div style="text-align:center;padding:40px;color:var(--muted)">
          <svg viewBox="0 0 24 24" <?=$sv?> style="width:32px;height:32px;opacity:.3;margin-bottom:8px"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
          <div style="font-size:12px">No libraries uploaded yet</div>
        </div>
      <?php else: ?>
        <div class="tw">
          <table>
            <thead><tr><th>Filename</th><th>Size</th><th>Uploaded</th><th>URL</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($libs as $lib): ?>
              <tr>
                <td><code style="font-size:11px"><?=htmlspecialchars($lib['name'])?></code></td>
                <td style="font-family:var(--mono);font-size:11px"><?=fmtSz((int)$lib['size'])?></td>
                <td style="font-size:10px;color:var(--muted)"><?=date('d M H:i',(int)$lib['time'])?></td>
                <td>
                  <button class="btn btn-ghost btn-xs"
                    onclick="copyText('<?=$secLibUrl?>?f=<?=urlencode($lib['name'])?>','Secure URL')">Copy Secure URL</button>
                </td>
                <td>
                  <button class="btn btn-red btn-xs"
                    onclick="rbDelLib('<?=htmlspecialchars(addslashes($lib['name']))?>')">Delete</button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<div id="rb-libkill" class="rb-pane<?=$activeTab==='libkill'?' active':''?>">
  <div class="g-left">


    <div class="card" style="border-color:<?=$libKillOn?'rgba(255,77,109,.3)':'var(--border)'?>">
      <div class="card-h">
        <span class="ct">
          <svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          Lib Killer Status
        </span>
        <span class="kill-badge <?=$libKillOn?'kill-on':'kill-off'?>">
          <span class="kill-dot"></span>
          <?=$libKillOn?'ACTIVE — Killing v&lt; '.$libKillBw:'DISABLED'?>
        </span>
      </div>
      <div class="card-b">
        <?php if ($libKillOn): ?>
        <div class="warn-box" style="margin-bottom:14px">
          <strong>Lib Killer is ACTIVE.</strong> Any lib with version &lt; <code>v<?=htmlspecialchars($libKillBw)?></code>
          will receive <code>"kill":1</code> from the server and crash immediately on next check.
        </div>
        <?php endif; ?>

        <div style="margin-bottom:16px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Kill Check URL (embed this in your lib)</div>
          <div class="code-box">
            <?=htmlspecialchars($libKillUrl)?>
            <button class="cb-copy" onclick="copyText('<?=htmlspecialchars($libKillUrl)?>','Kill URL')">Copy</button>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:6px">
            Your lib sends <code>GET ?cv=YOUR_LIB_VERSION</code> to this URL on startup.
            Server returns XOR(0xA5)+Base64 encoded JSON.
          </div>
        </div>


        <div class="fg">
          <label class="fl">Kill All Libs Below Version</label>
          <div style="display:flex;gap:8px">
            <input type="text" class="fi" id="libKillBwInp"
                   value="<?=htmlspecialchars($libKillBw)?>"
                   placeholder="e.g. <?=htmlspecialchars($libVerNext)?>"
                   style="flex:1">
            <button class="btn btn-red" onclick="setLibKiller()" style="white-space:nowrap">
              Activate Kill
            </button>
          </div>
          <div style="margin-top:6px;font-size:11px;color:var(--muted)">
            Example: set <code>4.2</code> → all libs compiled with version <code>&lt; 4.2</code> will crash.
            Current lib version: <code>v<?=htmlspecialchars($libVer)?></code>
          </div>
        </div>

        <div class="fg">
          <label class="fl">Redirect URL on Kill <span style="color:var(--muted)">(optional — killed lib opens this, e.g. Telegram)</span></label>
          <input type="text" class="fi" id="libKillRdrInp"
                 value="<?=htmlspecialchars($libKillRdr)?>"
                 placeholder="https://t.me/YourChannel">
          <div style="margin-top:6px;font-size:11px;color:var(--muted)">Sent as <code>redirect_url</code> so the app opens it when the lib is killed.</div>
        </div>

        <?php if ($libKillOn): ?>
        <button class="btn btn-ghost btn-full" onclick="resetLibKiller()" style="margin-top:8px">
          Disable Lib Killer
        </button>
        <?php endif; ?>
      </div>
    </div>


    <div class="card">
      <div class="card-h"><span class="ct">How Lib Killer Works</span></div>
      <div class="card-b" style="font-size:12px;line-height:1.8;color:var(--muted)">
        <div style="display:flex;flex-direction:column;gap:10px">
          <div style="display:flex;gap:10px;align-items:flex-start">
            <span class="step-num">1</span>
            <span>You compile your <code>.so</code> lib with version code <strong>4.1</strong> embedded. The lib's kill-check URL is <code>lib_version.php?cv=4.1</code></span>
          </div>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <span class="step-num">2</span>
            <span>On lib startup, it fetches the URL → gets XOR-encoded response → decodes → checks <code>kill</code> flag</span>
          </div>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <span class="step-num">3</span>
            <span>You set kill-below = <strong>4.2</strong> on this panel → server sees <code>4.1 &lt; 4.2</code> → returns <code>kill:1</code></span>
          </div>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <span class="step-num">4</span>
            <span>Lib receives <code>kill:1</code> → calls <code>abort()</code> / null-dereference → <strong>permanent crash</strong> (server always returns kill:1)</span>
          </div>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <span class="step-num">5</span>
            <span><strong>Anti-Frida:</strong> Lib checks <code>/proc/self/status TracerPid</code>, <code>/proc/self/maps</code> for Frida strings, and port 27042. Any detection = instant crash same as kill</span>
          </div>
        </div>
        <div class="warn-box" style="margin-top:12px">
          See the <strong>Implementation Guide</strong> (IMPLEMENT_GUIDE.md) for full C++ and Java code to add in your lib/APK.
        </div>
      </div>
    </div>

  </div>
</div>

<div id="rb-apk" class="rb-pane<?=$activeTab==='apk'?' active':''?>">
  <div class="g-left">


    <div class="card">
      <div class="card-h">
        <span class="ct">
          <svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Upload APK
        </span>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:11px;color:var(--muted)">Current APK version</span>
          <span class="ver-badge">v<?=htmlspecialchars($apkVer)?></span>
          <span class="owner-only">Owner Only</span>
        </div>
      </div>
      <div class="card-b">
        <div class="info-box" style="margin-bottom:16px">
          Upload new APK build. Panel writes <code>server/apks/version.txt</code> and updates DB version.
          Your APK checks this version to show update dialog.
        </div>
        <form method="POST" enctype="multipart/form-data">
          <?=csrfField()?>
          <input type="hidden" name="active_tab" value="apk">
          <div class="fg">
            <label class="fl">APK File <span style="color:var(--muted)">.apk only — Max 200MB</span></label>
            <div class="logo-drop" onclick="document.getElementById('apkFileInp').click()" id="apkDrop">
              <div id="apkPrevWrap" style="margin-bottom:10px;display:none">
                <div id="apkFileName" style="font-family:var(--mono);font-size:13px;color:var(--acc);font-weight:700"></div>
                <div id="apkFileSize" style="font-size:11px;color:var(--muted);margin-top:4px"></div>
              </div>
              <svg viewBox="0 0 24 24" <?=$sv?> style="width:32px;height:32px;opacity:.3;margin-bottom:8px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              <div style="font-size:11px;color:var(--muted);font-family:var(--mono);text-transform:uppercase">Click to select APK</div>
              <input type="file" id="apkFileInp" name="apk_file" accept=".apk" onchange="prevApk(this)" style="display:none">
            </div>
          </div>
          <div class="fg">
            <label class="fl">New Version <span style="color:var(--muted)">(saved to version.txt + DB)</span></label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="text" class="fi" name="apk_new_ver" id="apkNewVer"
                     value="<?=htmlspecialchars($apkVerNext)?>"
                     placeholder="<?=htmlspecialchars($apkVerNext)?>"
                     style="flex:1">
              <button type="button" class="btn btn-ghost btn-sm"
                      onclick="document.getElementById('apkNewVer').value='<?=htmlspecialchars($apkVerNext)?>'">
                Auto +1
              </button>
            </div>
            <div style="margin-top:6px;font-size:11px;color:var(--muted)">
              Current: <code>v<?=htmlspecialchars($apkVer)?></code> → Suggested next: <code>v<?=htmlspecialchars($apkVerNext)?></code>
            </div>
          </div>
          <button type="submit" class="btn btn-acc btn-full" style="margin-top:4px">
            <svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            UPLOAD APK
          </button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:18px 0">

        <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Force Version (no upload)</div>
        <div style="display:flex;gap:8px">
          <input type="text" class="fi" id="setApkVerInp" value="<?=htmlspecialchars($apkVerNext)?>" style="flex:1">
          <button class="btn btn-ghost btn-sm" onclick="setApkVer()">Set Version</button>
        </div>

        <?php if ($apkUrl): ?>
        <div style="margin-top:14px">
          <div style="font-size:11px;color:var(--muted);margin-bottom:6px">Current APK Download URL</div>
          <div class="code-box" style="font-size:10px">
            <?=htmlspecialchars($apkUrl)?>
            <button class="cb-copy" onclick="copyText('<?=htmlspecialchars($apkUrl)?>','APK URL')">Copy</button>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>


    <div class="card">
      <div class="card-h"><span class="ct">APK Endpoints &amp; Links</span></div>
      <div class="card-b">
        <?= urlRow('APK version.txt', $apkVerTxtUrl, $sv) ?>
        <?= urlRow('APK kill / update-check endpoint', $apkKillUrl, $sv) ?>
      </div>
    </div>


    <div class="card">
      <div class="card-h">
        <span class="ct">Uploaded APKs (<?=count($apks)?>)</span>
        <span style="font-size:10px;color:var(--muted);font-family:var(--mono)"><?=$panelBase?>/server/apks/</span>
      </div>
      <?php if (empty($apks)): ?>
        <div style="text-align:center;padding:40px;color:var(--muted)">
          <svg viewBox="0 0 24 24" <?=$sv?> style="width:32px;height:32px;opacity:.3;margin-bottom:8px"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
          <div style="font-size:12px">No APKs uploaded yet</div>
        </div>
      <?php else: ?>
        <div class="tw">
          <table>
            <thead><tr><th>Filename</th><th>Size</th><th>Uploaded</th><th>Download URL</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($apks as $apk): ?>
              <tr>
                <td><code style="font-size:11px"><?=htmlspecialchars($apk['name'])?></code></td>
                <td style="font-family:var(--mono);font-size:11px"><?=fmtSz((int)$apk['size'])?></td>
                <td style="font-size:10px;color:var(--muted)"><?=date('d M H:i',(int)$apk['time'])?></td>
                <td>
                  <button class="btn btn-ghost btn-xs"
                    onclick="copyText('<?=$panelBase?>/server/apks/<?=urlencode($apk['name'])?>','URL')">Copy URL</button>
                </td>
                <td>
                  <button class="btn btn-red btn-xs"
                    onclick="rbDelApk('<?=htmlspecialchars(addslashes($apk['name']))?>')">Delete</button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<div id="rb-apkkill" class="rb-pane<?=$activeTab==='apkkill'?' active':''?>">
  <div class="g-left">


    <div class="card" style="border-color:<?=$apkKillOn?'rgba(255,77,109,.3)':'var(--border)'?>">
      <div class="card-h">
        <span class="ct">
          <svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          APK Killer Control
        </span>
        <span class="kill-badge <?=$apkKillOn?'kill-on':'kill-off'?>">
          <span class="kill-dot"></span>
          <?=$apkKillOn?'ACTIVE':'DISABLED'?>
        </span>
      </div>
      <div class="card-b">
        <?php if ($apkKillOn): ?>
        <div class="warn-box" style="margin-bottom:14px">
          <strong>APK Killer is ACTIVE.</strong> Target version(s): <code><?=htmlspecialchars($apkKillVer)?:'-'?></code>.
          Affected APKs will show the kill popup and <strong>disable all functionality permanently</strong>.
        </div>
        <?php endif; ?>

        <div style="margin-bottom:16px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Kill Check URL (embed in your APK)</div>
          <div class="code-box">
            <?=htmlspecialchars($apkKillUrl)?>
            <button class="cb-copy" onclick="copyText('<?=htmlspecialchars($apkKillUrl)?>','APK Kill URL')">Copy</button>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:6px">
            APK sends <code>GET ?cv=YOUR_APK_VERSION</code> to this URL on startup.
          </div>
        </div>

        <div class="fg">
          <label class="fl">Enable APK Killer</label>
          <div class="kill-switch<?=$apkKillOn?' on':''?>" id="apkKillSwitch">
            <div class="ks-track<?=$apkKillOn?' on':''?>" id="apkKillTrack" onclick="toggleApkKillBtn()">
              <div class="ks-knob"></div>
            </div>
            <div class="ks-label" id="apkKillLbl">
              <?php if($apkKillOn): ?><span class="st-on">KILLER ACTIVE</span>
              <?php else: ?><span class="st-off">KILLER OFF</span><?php endif; ?>
            </div>
          </div>
          <input type="hidden" id="apkKillState" value="<?=$apkKillOn?'1':'0'?>">
        </div>

        <div class="fg">
          <label class="fl">Target APK Version(s) to Kill
            <span style="color:var(--muted)">(comma-separated or * for all)</span>
          </label>
          <input type="text" class="fi" id="apkKillVerInp"
                 value="<?=htmlspecialchars($apkKillVer)?>"
                 placeholder="e.g. 1.4,1.3  or  *  for all">
        </div>

        <div class="fg">
          <label class="fl">Kill Popup Message <span style="color:var(--muted)">(shown in APK when killed)</span></label>
          <textarea class="fi" id="apkKillMsgInp" rows="3" placeholder="Your APK version has been expired..."><?=htmlspecialchars($apkKillMsg)?></textarea>
        </div>

        <div class="fg">
          <label class="fl">Redirect URL on Kill <span style="color:var(--muted)">(optional — killed users open this link)</span></label>
          <input type="text" class="fi" id="apkKillRdrInp"
                 value="<?=htmlspecialchars($apkKillRdr)?>"
                 placeholder="e.g. https://t.me/YourChannel  or  https://arkcheat...shop/update">
          <div style="margin-top:6px;font-size:11px;color:var(--muted)">
            When set, the APK opens this URL (Telegram, update page, etc.) after showing the kill popup.
          </div>
        </div>

        <button class="btn btn-red btn-full" onclick="saveApkKiller()" style="margin-top:4px">
          SAVE APK KILLER SETTINGS
        </button>
      </div>
    </div>


    <div class="card">
      <div class="card-h"><span class="ct">How APK Killer Works</span></div>
      <div class="card-b" style="font-size:12px;line-height:1.8;color:var(--muted)">
        <div style="display:flex;flex-direction:column;gap:10px">
          <div style="display:flex;gap:10px;align-items:flex-start">
            <span class="step-num">1</span>
            <span>Your APK has the kill-check URL embedded (XOR obfuscated). On every app launch it calls <code>apk_kill.php?cv=VERSION</code></span>
          </div>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <span class="step-num">2</span>
            <span>APK decodes XOR+Base64 response → checks <code>kill</code> flag. If <code>kill:1</code> → shows non-dismissable popup with your custom message</span>
          </div>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <span class="step-num">3</span>
            <span>All UI elements disabled, back press blocked, app becomes completely unusable</span>
          </div>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <span class="step-num">4</span>
            <span><strong>No bypass possible</strong> — server always returns <code>kill:1</code> for that version. Cracked APKs can't ship a working bypass to users</span>
          </div>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <span class="step-num">5</span>
            <span>For <strong>Force Update</strong>: when APK version is older than panel's current APK version, <code>force_update:1</code> is returned → APK shows update dialog</span>
          </div>
        </div>
        <div class="warn-box" style="margin-top:12px">
          See <strong>IMPLEMENT_GUIDE.md</strong> for complete Java/Android code to put in your APK.
        </div>
      </div>
    </div>

  </div>
</div>

<div id="rb-modkill" class="rb-pane<?=$activeTab==='modkill'?' active':''?>">
  <div class="g-left">
    <div class="card" style="border-color:<?=$modKillOn?'rgba(255,77,109,.3)':'var(--border)'?>">
      <div class="card-h">
        <span class="ct">
          <svg viewBox="0 0 24 24" <?=$sv?> width="14" height="14"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          Mod Lib Kill
        </span>
        <span class="kill-badge <?=$modKillOn?'kill-on':'kill-off'?>"><span class="kill-dot"></span><?=$modKillOn?'KILL-ALL ON':'VERSION MODE'?></span>
      </div>
      <div class="card-b">
        <div class="info-box" style="margin-bottom:14px">
          The mod lib <strong>only fetches its version</strong> from the URL below — it is never downloaded from here.
          <strong>Bump the version</strong> and every mod lib on an older version gets <code>kill=1</code> and crashes.
          Set a redirect (e.g. Telegram) and killed mod libs open it before crashing.
        </div>

        <div style="margin-bottom:16px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Mod version check URL (embed in mod lib)</div>
          <div class="code-box"><?=htmlspecialchars($modKillUrl)?><button class="cb-copy" onclick="copyText('<?=htmlspecialchars($modKillUrl)?>','Mod URL')">Copy</button></div>
          <div style="font-size:11px;color:var(--muted);margin-top:6px">Mod lib sends <code>GET ?cv=ITS_VERSION</code>. Server returns XOR(0xA5)+Base64 JSON.</div>
        </div>

        <div class="fg">
          <label class="fl">Current Mod Version <span style="color:var(--muted)">(anything older than this crashes)</span></label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="text" class="fi" id="modVerInp" value="<?=htmlspecialchars($modVer)?>" placeholder="e.g. <?=htmlspecialchars($modVerNext)?>" style="flex:1">
            <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('modVerInp').value='<?=htmlspecialchars($modVerNext)?>'">Auto +1</button>
          </div>
          <div style="margin-top:6px;font-size:11px;color:var(--muted)">Current: <code>v<?=htmlspecialchars($modVer)?></code> → Bump to <code>v<?=htmlspecialchars($modVerNext)?></code> to kill all older mod libs.</div>
        </div>

        <div class="fg">
          <label class="fl">Redirect URL on Kill <span style="color:var(--muted)">(optional — Telegram etc.)</span></label>
          <input type="text" class="fi" id="modRdrInp" value="<?=htmlspecialchars($modRdr)?>" placeholder="https://t.me/YourChannel">
        </div>

        <div class="fg">
          <label class="fl">Kill ALL mod libs now <span style="color:var(--muted)">(ignores version — every mod lib crashes)</span></label>
          <div class="kill-switch<?=$modKillOn?' on':''?>" id="modKillSwitch">
            <div class="ks-track<?=$modKillOn?' on':''?>" id="modKillTrack" onclick="toggleModKill()"><div class="ks-knob"></div></div>
            <div class="ks-label" id="modKillLbl">
              <?php if($modKillOn): ?><span class="st-on">KILL-ALL ACTIVE</span><?php else: ?><span class="st-off">VERSION MODE</span><?php endif; ?>
            </div>
          </div>
          <input type="hidden" id="modKillState" value="<?=$modKillOn?'1':'0'?>">
        </div>

        <button class="btn btn-red btn-full" onclick="saveModKill()">SAVE MOD LIB KILL</button>
      </div>
    </div>
  </div>
</div>

<script>
const RB_CSRF = '<?=htmlspecialchars($csrf,ENT_QUOTES)?>';

function rbTab(name, btn) {
  document.querySelectorAll('.rb-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.rb-tab').forEach(b => b.classList.remove('active','danger'));
  const pane = document.getElementById('rb-'+name);
  if (pane) pane.classList.add('active');
  const isDanger = name === 'libkill' || name === 'apkkill' || name === 'modkill';
  if (!btn) btn = document.querySelector('.rb-tab[data-tab="'+name+'"]');
  if (btn) { btn.classList.add('active'); if (isDanger) btn.classList.add('danger'); }
  location.hash = name;
}

document.addEventListener('DOMContentLoaded', function () {
  const h = (location.hash || '').replace('#','');
  if (h && document.getElementById('rb-'+h)) rbTab(h, null);
});

function prevLib(inp) {
  if (!inp.files || !inp.files[0]) return;
  const f = inp.files[0];
  document.getElementById('libPrevWrap').style.display = 'block';
  document.getElementById('libFileName').textContent   = '' + f.name;
  document.getElementById('libFileSize').textContent   = (f.size/1024).toFixed(0) + ' KB';
}
function prevApk(inp) {
  if (!inp.files || !inp.files[0]) return;
  const f = inp.files[0];
  document.getElementById('apkPrevWrap').style.display = 'block';
  document.getElementById('apkFileName').textContent   = '' + f.name;
  document.getElementById('apkFileSize').textContent   = (f.size/1024/1024).toFixed(1) + ' MB';
}

async function rbAjax(section, extra) {
  const body = new URLSearchParams({ ajax:'1', csrf:RB_CSRF, section, ...extra });
  const r    = await fetch('manage_rebranding.php', { method:'POST', body });
  const txt  = await r.text();
  try { return JSON.parse(txt); }
  catch(e) { return {ok:false, msg:'Server error: '+txt.substring(0,200)}; }
}

async function saveBase() {
  const b = document.getElementById('baseUrlInp').value.trim();
  const d = await rbAjax('set_base', { base_url: b });
  toast(d.msg, d.ok ? 's' : 'e');
  if (d.ok) setTimeout(()=>location.reload(), 900);
}

async function setLibVer() {
  const v = document.getElementById('setLibVerInp').value.trim();
  if (!v) return;
  const d = await rbAjax('set_lib_version', { new_lib_version: v });
  toast(d.msg, d.ok ? 's' : 'e');
}

async function setApkVer() {
  const v = document.getElementById('setApkVerInp').value.trim();
  if (!v) return;
  const d = await rbAjax('set_apk_version', { new_apk_version: v });
  toast(d.msg, d.ok ? 's' : 'e');
}

async function setLibKiller() {
  const kb = document.getElementById('libKillBwInp').value.trim();
  const kr = document.getElementById('libKillRdrInp')?.value.trim() || '';
  if (!kb) { toast('Enter a version first','e'); return; }
  if (!confirm(`Kill ALL libs below v${kb}?\nThis will crash ALL existing users on older builds!`)) return;
  const d = await rbAjax('lib_killer', { kill_below: kb, kill_redirect: kr });
  toast(d.msg, d.ok ? 's' : 'e');
  if (d.ok) { location.hash = 'libkill'; setTimeout(()=>location.reload(), 1200); }
}
async function resetLibKiller() {
  if (!confirm('Disable Lib Killer? Old libs will work again.')) return;
  const d = await rbAjax('lib_killer_reset', {});
  toast(d.msg, d.ok ? 's' : 'e');
  if (d.ok) { location.hash = 'libkill'; setTimeout(()=>location.reload(), 1200); }
}

function toggleApkKillBtn() {
  const inp   = document.getElementById('apkKillState');
  const track = document.getElementById('apkKillTrack');
  const wrap  = document.getElementById('apkKillSwitch');
  const lbl   = document.getElementById('apkKillLbl');
  const isOn  = inp.value === '1';
  inp.value = isOn ? '0' : '1';
  track.classList.toggle('on', !isOn);
  wrap.classList.toggle('on', !isOn);
  lbl.innerHTML = isOn
    ? '<span class="st-off">KILLER OFF</span>'
    : '<span class="st-on">KILLER ACTIVE</span>';
}
async function saveApkKiller() {
  const on  = document.getElementById('apkKillState').value;
  const kv  = document.getElementById('apkKillVerInp').value.trim();
  const km  = document.getElementById('apkKillMsgInp').value.trim();
  const kr  = document.getElementById('apkKillRdrInp').value.trim();
  if (on === '1' && !kv) { toast('Enter target version(s) to kill','e'); return; }
  if (on === '1' && !confirm(`Kill APK version(s): "${kv}"?\nAffected users will see the popup and lose app access!`)) return;
  const d = await rbAjax('apk_killer', { kill_on:on, kill_version:kv, kill_msg:km, kill_redirect:kr });
  toast(d.msg, d.ok ? 's' : 'e');
  if (d.ok) { location.hash = 'apkkill'; setTimeout(()=>location.reload(), 1200); }
}

function toggleModKill() {
  const inp=document.getElementById('modKillState');
  const track=document.getElementById('modKillTrack');
  const wrap=document.getElementById('modKillSwitch');
  const lbl=document.getElementById('modKillLbl');
  const isOn=inp.value==='1';
  inp.value=isOn?'0':'1';
  track.classList.toggle('on',!isOn);
  wrap.classList.toggle('on',!isOn);
  lbl.innerHTML=isOn?'<span class="st-off">VERSION MODE</span>':'<span class="st-on">KILL-ALL ACTIVE</span>';
}
async function saveModKill() {
  const mv=document.getElementById('modVerInp').value.trim();
  const mr=document.getElementById('modRdrInp').value.trim();
  const mk=document.getElementById('modKillState').value;
  if(!mv){ toast('Enter a mod version','e'); return; }
  if(mk==='1' && !confirm('Kill ALL mod libs now? Every mod lib will crash immediately.')) return;
  const d=await rbAjax('modlib_save',{mod_version:mv,mod_redirect:mr,mod_kill:mk});
  toast(d.msg, d.ok?'s':'e');
  if(d.ok){ location.hash='modkill'; setTimeout(()=>location.reload(),1200); }
}

async function rbDelLib(name) {
  if (!confirm(`Delete library: ${name}?`)) return;
  try {
    const d = await rbAjax('delete_lib', { fname: name });
    toast(d.msg, d.ok ? 's' : 'e');
    if (d.ok) setTimeout(()=>location.reload(), 800);
  } catch(e) { toast('Delete failed: '+e.message, 'e'); }
}
async function rbDelApk(name) {
  if (!confirm(`Delete APK: ${name}?`)) return;
  try {
    const d = await rbAjax('delete_apk', { fname: name });
    toast(d.msg, d.ok ? 's' : 'e');
    if (d.ok) setTimeout(()=>location.reload(), 800);
  } catch(e) { toast('Delete failed: '+e.message, 'e'); }
}
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>