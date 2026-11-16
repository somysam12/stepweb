<?php

$file = basename($_GET['f'] ?? '');
if (!$file || !preg_match('/\.(png|jpg|jpeg|gif|webp)$/i', $file)) {
    http_response_code(404); exit;
}
$path = dirname(dirname(dirname(__DIR__))).'/writable/uploads/logos/'.$file;
if (!file_exists($path)) { http_response_code(404); exit; }
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
header('Content-Type: '.($mimes[$ext]??'image/png'));
header('Cache-Control: public, max-age=86400');
readfile($path);