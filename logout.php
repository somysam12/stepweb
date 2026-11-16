<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
if(isLoggedIn()) logAct($_SESSION['uid'],$_SESSION['uname'],'LOGOUT','');
session_unset(); session_destroy();
header('Location: /'); exit;