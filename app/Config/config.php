<?php

define('DB_HOST',    'localhost');
define('DB_NAME',    'battleg1_arkcheat');
define('DB_USER',    'battleg1_arkcheat');
define('DB_PASS',    '844121LuvKush');
define('DB_CHARSET', 'utf8mb4');

define('PANEL_URL',  'https://arkcheat.battleground-hub.shop/');
define('SERVER_URL', 'https://arkcheat.battleground-hub.shop/server/');

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES',    15);
define('SESSION_LIFETIME',   3600);
define('CSRF_TOKEN_LEN',     32);
define('DDOS_REQ_LIMIT',     120);
define('DDOS_WINDOW_SEC',    60);

date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime',  SESSION_LIFETIME);

    if (isset($_COOKIE['hb_remember']) && !isset($_COOKIE['PHPSESSID'])) {
        $remData = json_decode(base64_decode($_COOKIE['hb_remember']??''), true);
        if ($remData && !empty($remData['until']) && $remData['until'] > time()) {
            ini_set('session.gc_maxlifetime', 86400);
            session_set_cookie_params(86400);
        }
    }
    session_start();
}

error_reporting(0);
ini_set('display_errors', 0);