

function toast(msg, type='s', dur=3000) {
  const c = document.getElementById('toasts');
  if (!c) return;
  const colors = {s:'#00e5a0',e:'#ff4d6d',w:'#ffb800',i:'#0094ff'};
  const icons  = {s:'',e:'',w:'',i:'i'};
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.style.cssText = `border-left:3px solid ${colors[type]||colors.i};animation:toastIn .25s cubic-bezier(.4,0,.2,1)`;
  t.innerHTML = `<span style="color:${colors[type]};font-weight:900;font-size:14px">${icons[type]||icons.i}</span><span style="flex:1">${msg}</span>`;
  c.appendChild(t);

  const remove = () => {
    t.style.transition = 'opacity .25s,transform .25s';
    t.style.opacity = '0';
    t.style.transform = 'translateX(20px)';
    setTimeout(()=>t.remove(), 280);
  };
  t.addEventListener('click', remove);
  setTimeout(remove, dur);
}

function openModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.add('open'); document.body.style.overflow='hidden'; }
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.remove('open'); document.body.style.overflow=''; }
}
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-bg')) { e.target.classList.remove('open'); document.body.style.overflow=''; }
});

function confirmDo(msg, cb) { if (confirm(msg)) cb(); }

function toggleSidebar() {
  const sb = document.querySelector('.sidebar');
  const ov = document.getElementById('mobOverlay');
  if (sb) sb.classList.toggle('open');
  if (ov) ov.classList.toggle('show');
}
document.addEventListener('DOMContentLoaded', () => {
  const ov = document.getElementById('mobOverlay');
  if (ov) ov.addEventListener('click', () => {
    document.querySelector('.sidebar')?.classList.remove('open');
    ov.classList.remove('show');
  });
});

function togglePass(inputId, btn) {
  const inp = document.getElementById(inputId);
  if (!inp) return;
  if (inp.type==='password') { inp.type='text'; btn.textContent=''; }
  else { inp.type='password'; btn.textContent=''; }
}

function copyText(text, label='') {
  navigator.clipboard.writeText(text)
    .then(()=>toast(label ? `${label} copied!` : 'Copied!','s',2000))
    .catch(()=>{
      const ta=document.createElement('textarea');
      ta.value=text; ta.style.position='fixed'; ta.style.opacity='0';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
      toast('Copied!','s',2000);
    });
}

async function apiPost(url, data={}) {
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  try {
    const r = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':csrf},
      body: JSON.stringify({...data, csrf})
    });
    return await r.json();
  } catch(e) { return {ok:false,msg:'Network error'}; }
}

async function formPost(url, data={}) {
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  const body = new URLSearchParams({...data, csrf});
  try {
    const r = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: body.toString()
    });
    const text = await r.text();
    try {
      return JSON.parse(text);
    } catch(e) {
      console.error('Non-JSON response from',url,':', text.substring(0,200));
      return {ok:false, msg:'Server error. Check console.'};
    }
  } catch(e) {
    console.error('Fetch error:', e);
    return {ok:false, msg:'Connection error: '+e.message};
  }
}

function initTableSearch(inputId, tableId) {
  const inp = document.getElementById(inputId);
  const tbody = document.querySelector(`#${tableId} tbody`);
  if (!inp||!tbody) return;
  inp.addEventListener('input', () => {
    const q = inp.value.toLowerCase();
    tbody.querySelectorAll('tr').forEach(row=>{
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}

function initLogoUpload() {
  const inp = document.getElementById('logoFile');
  const prev = document.getElementById('logoPrev');
  const wrap = document.getElementById('logoPrevWrap');
  if (!inp) return;
  inp.addEventListener('change', e => {
    const f = e.target.files[0];
    if (!f) return;
    if (!f.type.startsWith('image/')) { toast('Only image files allowed!','e'); return; }
    if (f.size > 2*1024*1024) { toast('Max file size 2MB','e'); return; }
    const reader = new FileReader();
    reader.onload = ev => {
      if (prev) prev.src = ev.target.result;
      if (wrap) wrap.style.display='block';
    };
    reader.readAsDataURL(f);
  });
}

function refreshRefCode() {
  const chars='ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  let code='';
  for(let i=0;i<6;i++) code+=chars[Math.floor(Math.random()*chars.length)];
  const el=document.getElementById('refPreview');
  const inp=document.getElementById('refCodeInput');
  if(el){ el.style.opacity='0'; setTimeout(()=>{ el.textContent=code; el.style.opacity='1'; },150); el.style.transition='opacity .15s'; }
  if(inp) inp.value=code;
  return code;
}

function generateKeyPreview() {
  const chars='ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  let k='HB-';
  for(let g=0;g<4;g++){ for(let i=0;i<4;i++) k+=chars[Math.floor(Math.random()*chars.length)]; if(g<3) k+='-'; }
  return k;
}

function updateEstimate(priceMap) {
  const devices = parseInt(document.getElementById('maxDevices')?.value)||1;
  const durSel = document.getElementById('durationSel');
  const estInp = document.getElementById('estimation');
  if (!durSel||!estInp||!priceMap) return;
  const unit = parseFloat(priceMap[durSel.value]||0);
  const total = (devices*unit).toFixed(2);
  estInp.value = `$ ${total}`;
}

function startStatsRefresh(interval=30000) {
  setInterval(async()=>{
    try{
      const r=await fetch('/api/stats.php');
      const d=await r.json();
      if(d.ok&&d.stats) updateStatCards(d.stats);
    }catch(e){}
  }, interval);
}
function updateStatCards(stats) {
  const map={'statKeys':'total_keys','statUsers':'total_users','statRefs':'total_refs','statBal':'total_bal'};
  for(const [id,key] of Object.entries(map)) {
    const el=document.getElementById(id);
    if(el&&stats[key]!==undefined) el.textContent=stats[key];
  }
}

document.addEventListener('DOMContentLoaded',()=>{
  initLogoUpload();
  if(document.getElementById('statKeys')) startStatsRefresh();
  initTableSearch('keySearch','keysTable');
  initTableSearch('userSearch','usersTable');
  initTableSearch('refSearch','refTable');
});

function spawnParticles(containerId, count=22) {
  const c=document.getElementById(containerId);
  if(!c) return;
  for(let i=0;i<count;i++){
    const p=document.createElement('div');
    p.className='pt';
    const s=Math.random()*3+.5;
    p.style.cssText=`left:${Math.random()*100}%;width:${s}px;height:${s}px;background:rgba(0,229,160,${Math.random()*.4+.1});animation-duration:${Math.random()*15+12}s;animation-delay:${Math.random()*10}s`;
    c.appendChild(p);
  }
}