<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$pageTitle='Panel Alert'; $activeNav='panelalert';
require_once __DIR__.'/app/Views/Layout/header.php';
require_once __DIR__.'/app/Helpers/icons.php';

$msg=''; $msgType='ok';
if($_SERVER['REQUEST_METHOD']==='POST'&&verifyCsrf($_POST['csrf']??'')){
    $token=trim($_POST['tg_bot_token']??'');
    $chatId=trim($_POST['tg_chat_id']??'');
    $enabled=isset($_POST['tg_enabled'])?'1':'0';
    setSetting('tg_bot_token',$token);
    setSetting('tg_chat_id',$chatId);
    setSetting('tg_alerts_enabled',$enabled);
    logAct($_SESSION['uid'],$uname,'UPDATE_TG_ALERT','');


    if(!empty($_POST['test_alert'])&&$token&&$chatId){
        $testMsg="<b>Panel Alert Test</b>\n".str_repeat('─',28)."\n";
        $testMsg.="<b>Status:</b> Connected\n";
        $testMsg.="<b>Panel:</b> ".getSetting('panel_name','HB Panel')."\n";
        $testMsg.="<b>Time:</b> ".date('d M Y, h:i:s A');
        sendTgAlert($testMsg);
        $msg='Settings saved & test alert sent!'; $msgType='ok';
    } else {
        $msg='Alert settings saved!'; $msgType='ok';
    }
}

$tgToken  = getSetting('tg_bot_token','');
$tgChatId = getSetting('tg_chat_id','');
$tgEnabled= getSetting('tg_alerts_enabled','0')==='1';
?>
<?php if($msg): ?><div class="alert a-<?=$msgType?>"><?=svgIcon('check',14)?> <?=htmlspecialchars($msg)?></div><?php endif; ?>

<div class="g2">
  <div class="card">
    <div class="card-h">
      <span class="ct"><?=svgIcon('telegram',14)?> Telegram Alert Settings</span>
      <span class="owner-only"><?=svgIcon('shield',12)?> Owner Only</span>
    </div>
    <div class="card-b">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px;background:rgba(0,148,255,.06);border:1px solid rgba(0,148,255,.15);border-radius:10px;margin-bottom:18px">
        <div>
          <div style="font-weight:700;font-size:13px">Telegram Alerts</div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">Send alerts to your Telegram bot</div>
        </div>
        <label class="tog"><input type="checkbox" id="tgEnabledTog" <?=$tgEnabled?'checked':''?>><span class="togsl"></span></label>
      </div>

      <form method="POST">
        <?=csrfField()?>
        <input type="hidden" name="tg_enabled" id="tgEnabledHidden" value="<?=$tgEnabled?'1':'0'?>">

        <div class="fg">
          <label class="fl"><?=svgIcon('key',12)?> Bot Token</label>
          <div style="display:flex;align-items:center;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden">
            <span style="padding:0 12px;color:var(--muted);flex-shrink:0"><?=svgIcon('key',15)?></span>
            <input class="fi fi-mono" type="text" name="tg_bot_token" value="<?=htmlspecialchars($tgToken)?>" placeholder="1234567890:AAF..." style="border:none;border-radius:0;padding-left:0">
          </div>
          <div class="fi-hint">Get from @BotFather on Telegram</div>
        </div>

        <div class="fg">
          <label class="fl"><?=svgIcon('mail',12)?> Chat ID</label>
          <div style="display:flex;align-items:center;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden">
            <span style="padding:0 12px;color:var(--muted);flex-shrink:0"><?=svgIcon('mail',15)?></span>
            <input class="fi fi-mono" type="text" name="tg_chat_id" value="<?=htmlspecialchars($tgChatId)?>" placeholder="7182189844" style="border:none;border-radius:0;padding-left:0">
          </div>
          <div class="fi-hint">Your Telegram user/group Chat ID</div>
        </div>

        <div style="display:flex;gap:10px">
          <button type="submit" class="btn btn-acc" style="flex:1"><?=svgIcon('check',13)?> Save Settings</button>
          <button type="submit" name="test_alert" value="1" class="btn btn-blue" style="flex:1"><?=svgIcon('telegram',13)?> Test Alert</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><span class="ct"><?=svgIcon('info',14)?> Alert Events</span></div>
    <div class="card-b">
      <div class="srow"><span style="display:flex;align-items:center;gap:6px"><?=svgIcon('link',13)?> Referral Created</span><span class="bx b-active">Active</span></div>
      <div class="srow"><span style="display:flex;align-items:center;gap:6px"><?=svgIcon('ban',13)?> DDoS Attack Blocked</span><span class="bx b-active">Active</span></div>

      <div class="sep" style="margin:16px 0"></div>

      <div style="background:rgba(0,148,255,.05);border:1px solid rgba(0,148,255,.12);border-radius:10px;padding:14px;font-size:11px;color:var(--muted2);line-height:1.8">
        <?=svgIcon('info',12)?> <strong style="color:var(--acc2)">How to setup:</strong><br>
        1. Create bot via <strong>@BotFather</strong><br>
        2. Copy the <strong>Bot Token</strong><br>
        3. Get your <strong>Chat ID</strong> from @userinfobot<br>
        4. Paste both above and Save<br>
        5. Click <strong>Test Alert</strong> to verify
      </div>

      <div style="margin-top:14px;padding:12px;background:rgba(0,229,160,.05);border:1px solid rgba(0,229,160,.12);border-radius:10px">
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Alert Status</div>
        <div style="display:flex;align-items:center;gap:8px;font-family:var(--mono);font-size:13px;font-weight:700;color:<?=$tgEnabled?'var(--acc)':'var(--danger)'?>">
          <span style="width:8px;height:8px;border-radius:50%;background:currentColor;display:inline-block"></span>
          <?=$tgEnabled?'ALERTS ACTIVE':'ALERTS DISABLED'?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById("tgEnabledTog").addEventListener("change",function(){
  document.getElementById("tgEnabledHidden").value=this.checked?"1":"0";
});
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>