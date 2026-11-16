<?php

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';
require_once dirname(__DIR__).'/app/Config/secrets.php';

function reject(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $msg;
    exit;
}

$key   = $_GET['k'] ?? $_POST['k'] ?? $_SERVER['HTTP_X_LICENSE_KEY'] ?? '';
$nonce = $_GET['n'] ?? $_POST['n'] ?? $_SERVER['HTTP_X_NONCE']       ?? '';
$grant = $_GET['g'] ?? $_POST['g'] ?? $_SERVER['HTTP_X_GRANT']       ?? '';
$file  = $_GET['f'] ?? $_POST['f'] ?? $_SERVER['HTTP_X_LIB']         ?? '';

$key = trim($key); $nonce = trim($nonce); $grant = trim($grant); $file = basename(trim($file));

if ($key === '' || $nonce === '' || $grant === '') reject(403, 'Forbidden');

$expected = hb_grant($key, $nonce);
if (!hash_equals($expected, $grant)) reject(403, 'Forbidden');

$k = db()->one("SELECT * FROM keys_code WHERE user_key=? LIMIT 1", [$key]);
if (!$k)                                                               reject(403, 'Forbidden');
if ((int)$k->status !== 1)                                             reject(403, 'Forbidden');
if (!empty($k->expired_date) && strtotime($k->expired_date) < time()) reject(403, 'Forbidden');

if (getSetting('maintenance_mode','0') === '1') reject(503, 'Maintenance');

$libDir  = dirname(__DIR__).'/server/libs/';
$allowed = explode(',', LIB_ALLOWED);

$skip = ['.', '..', '.htaccess', 'version.txt'];
if ($file === '' || !is_file($libDir.$file)) {
    $best = ''; $bestTime = -1;
    if (is_dir($libDir)) foreach (scandir($libDir) as $f) {
        if (in_array($f, $skip, true)) continue;
        if (!is_file($libDir.$f)) continue;
        $t = filemtime($libDir.$f);
        if ($t > $bestTime) { $bestTime = $t; $best = $f; }
    }
    $file = $best;
}

if ($file === '' || !is_file($libDir.$file)) reject(404, 'Not found');
$path = $libDir.$file;
if (!is_file($path)) reject(404, 'Not found');

logAct($k->id ?? null, $k->registrator ?? '', 'LIB_DOWNLOAD', "File: $file");

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.$file.'"');
header('Content-Length: '.filesize($path));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
while (ob_get_level()) ob_end_clean();
readfile($path);
exit;