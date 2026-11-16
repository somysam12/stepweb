<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
$panelName=getSetting('panel_name','HB Panel');
?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>Forgot Password</title><link rel="stylesheet" href="/assets/css/panel.css?v=10"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet"></head><body>
<div class="login-scene"><div class="orb1"></div><div class="orb2"></div>
<div style="max-width:420px;width:100%;position:relative;z-index:10">
<div class="login-card"><div class="login-top"><div class="brand-name"><?=htmlspecialchars($panelName)?></div><div class="brand-sub">Contact your admin to reset password</div></div>
<div class="login-body"><div class="alert a-info">Password reset is managed by the panel owner/admin. Please contact them directly.</div>
<a href="/" class="btn btn-acc btn-full">← Back to Login</a></div></div></div></div>
</body></html>