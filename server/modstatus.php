<?php

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';

$modVer   = getSetting('modlib_version', '1.0');
$modKill  = getSetting('modlib_kill_enabled', '0') === '1';
$modRdr   = getSetting('modlib_kill_redirect', '');
$cv       = trim($_GET['cv'] ?? $_POST['cv'] ?? '');

$maint    = getSetting('maintenance_mode','0') === '1';
$maintMsg = getSetting('maint_message','Server is under maintenance. Please try again later.');

$killed = false; $message = '';
if ($maint) {
    $killed = true; $message = $maintMsg;
} elseif ($modKill) {
    $killed = true; $message = 'This mod version has been disabled. Please update from our channel.';
} elseif ($cv !== '' && version_compare($cv, $modVer, '<')) {
    $killed = true; $message = 'A new mod version is out. This build is no longer supported — update from our channel.';
}

if (!$killed) { echo 'ON'; exit; }

$out = 'OFF:' . $message;
if ($modRdr !== '') $out .= "\n" . $modRdr;
echo $out;