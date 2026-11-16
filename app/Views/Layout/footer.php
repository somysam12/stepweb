  </div>
</div>
</div>
<div id="toasts" class="toasts"></div>
<script src="/assets/js/panel.js"></script>
<?php if(isset($extraJs)) echo $extraJs; ?>
<script>

(function(){
  const ulevel = <?php echo (int)($_SESSION['ulevel']??99); ?>;
  if(ulevel !== 1) return;

  <?php
  $masterUrl = getSetting('update_server_url','');
  $curVer    = getSetting('panel_version','3.0.0');
  if(!empty($masterUrl)):
  ?>
  const masterUrl = '<?=htmlspecialchars($masterUrl)?>';
  const curVer    = '<?=htmlspecialchars($curVer)?>';


  if(sessionStorage.getItem('upChecked')) {
    if(sessionStorage.getItem('upAvailable')==='1'){
      showUpdateBanner(sessionStorage.getItem('upVer')||'');
    }
    return;
  }

  fetch(masterUrl+'/updates/version.json', {cache:'no-cache'})
    .then(r=>r.json())
    .then(d=>{
      sessionStorage.setItem('upChecked','1');
      if(d.version && compareVer(d.version, curVer) > 0){
        sessionStorage.setItem('upAvailable','1');
        sessionStorage.setItem('upVer', d.version);
        showUpdateBanner(d.version);
      }
    })
    .catch(()=>{});

  function showUpdateBanner(newVer){
    const banner = document.getElementById('updateBanner');
    const dot    = document.getElementById('updateDot');
    const txt    = document.getElementById('updateBannerTxt');
    if(banner){ banner.style.display='flex'; }
    if(dot)   { dot.style.display='inline-flex'; }
    if(txt && newVer){ txt.textContent='New update available: v'+newVer+' → Click to update'; }


    if(!sessionStorage.getItem('upToastShown')){
      sessionStorage.setItem('upToastShown','1');
      setTimeout(()=>{
        toast('Update Available: v'+newVer+' — Go to Panel Update','w', 6000);
      }, 1500);
    }
  }

  function compareVer(a,b){
    const pa=a.split('.').map(Number), pb=b.split('.').map(Number);
    for(let i=0;i<3;i++){ if((pa[i]||0)>(pb[i]||0)) return 1; if((pa[i]||0)<(pb[i]||0)) return -1; }
    return 0;
  }
  <?php endif; ?>
})();
</script>
</body>
</html>