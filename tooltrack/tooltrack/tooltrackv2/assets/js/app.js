/* ============================================================
   app.js — Shared initialization and common utilities: toast/loading helpers, API fetch, pagination, CSV,
   modal helpers, navigation (navigateTo), auth bootstrap, notifications, account settings,
   shared loan/missing-tool helpers, global search. LOAD THIS FIRST.
   Plain (non-module) script: shares the global scope with the other
   assets/js files so inline onclick="" handlers keep working.
   ============================================================ */

const API = 'api';

function showToast(msg, type='success'){
  const icons={success:'fas fa-check-circle',error:'fas fa-times-circle',warning:'fas fa-exclamation-triangle'};
  const t=document.createElement('div');
  t.className=`toast ${type}`;
  const ic=document.createElement('i'); ic.className=`${icons[type]||icons.success} toast-icon`;
  const sp=document.createElement('span'); sp.textContent=msg;   // textContent: error messages can contain user-typed text
  t.append(ic,sp);
  document.getElementById('toastContainer').appendChild(t);
  setTimeout(()=>t.remove(),3500);
}

function setLoading(btnId, loading){
  const btn=document.getElementById(btnId);
  if(!btn)return;
  if(loading){ btn.disabled=true; btn.dataset.orig=btn.innerHTML; btn.innerHTML=`<span class="spinner"></span> Saving…`; }
  else { btn.disabled=false; btn.innerHTML=btn.dataset.orig||btn.innerHTML; }
}

async function apiFetch(url, options={}){
  try{
    const res=await fetch(url,{headers:{'Content-Type':'application/json'},...options});
    let data;
    try{ data=await res.json(); }
    catch(_){ throw new Error(res.status===401||res.status===403?'Session expired or not allowed — please log in again.':`Server error (${res.status}). Please try again.`); }
    if(!data.success) throw new Error(data.message||'Request failed');
    return data;
  }catch(err){
    showToast(err.message,'error');
    throw err;
  }
}

// Walks every page of a paginated endpoint (the API caps a page at 100-200 rows,
// so per_page=9999 silently returned only the first page).
async function fetchAllPages(baseUrl,{perPage=100,maxPages=50}={}){
  const all=[]; const sep=baseUrl.includes('?')?'&':'?';
  for(let page=1;page<=maxPages;page++){
    const r=await apiFetch(`${baseUrl}${sep}page=${page}&per_page=${perPage}`);
    const rows=r.data||[];
    all.push(...rows);
    if(!rows.length||page*perPage>=(r.total||0)) break;
  }
  return all;
}

// MySQL gives "YYYY-MM-DD HH:MM:SS" — Safari/iOS can't parse the space; use a T.
function parseDbDate(v){ return new Date(String(v||'').replace(' ','T')); }

// Today as YYYY-MM-DD in the USER's time zone (toISOString() is UTC, which is
// the wrong day for 8 hours every morning in the Philippines).
function localToday(){ const d=new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; }

function getInitials(name){
  return (name||'').split(' ').filter(Boolean).map(n=>n[0]).join('').toUpperCase().substring(0,2)||'?';
}

function timeAgo(dateStr){
  const s=Math.floor((Date.now()-parseDbDate(dateStr))/1000);
  if(s<60)return 'just now';
  if(s<3600)return Math.floor(s/60)+' min ago';
  if(s<86400)return Math.floor(s/3600)+' hr ago';
  return Math.floor(s/86400)+' days ago';
}

function downloadCSV(rows, filename){
  // A cell starting with = + - @ is run as a formula by Excel — prefix it with ' (text).
  const safe=v=>{ v=String(v??''); return (v&&!/^-?\d+(\.\d+)?$/.test(v)&&/^[=+\-@\t\r]/.test(v))?"'"+v:v; };
  const csv=rows.map(r=>r.map(v=>`"${safe(v).replace(/"/g,'""')}"`).join(',')).join('\r\n');
  // Blob instead of a data: URL so large exports don't hit URL length limits;
  // the BOM keeps names with ñ/é intact when opened in Excel.
  const blob=new Blob(['﻿'+csv],{type:'text/csv;charset=utf-8'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a');
  a.href=url; a.download=filename;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(()=>URL.revokeObjectURL(url),1000);
}

function renderPagination(infoId, btnsId, current, total, perPage, totalRecords, onPage){
  document.getElementById(infoId).textContent=
    total===0 ? 'No records found'
    : `Showing ${(current-1)*perPage+1}–${Math.min(current*perPage,totalRecords)} of ${totalRecords}`;
  const c=document.getElementById(btnsId);
  c._onPage=onPage;
  if(!c._bound){            // one delegated listener; no code is pasted into onclick attributes
    c._bound=true;
    c.addEventListener('click',e=>{
      const b=e.target.closest('button[data-page]');
      if(!b||b.disabled||!c._onPage) return;
      c._onPage(parseInt(b.dataset.page,10));
    });
  }
  if(total<=1){c.innerHTML='';return;}
  let html=`<button class="page-btn" data-page="${current-1}" ${current===1?'disabled':''}><i class="fas fa-chevron-left"></i></button>`;
  for(let i=1;i<=total;i++){
    if(i===1||i===total||Math.abs(i-current)<=1) html+=`<button class="page-btn ${i===current?'active':''}" data-page="${i}">${i}</button>`;
    else if(Math.abs(i-current)===2) html+=`<button class="page-btn" disabled>…</button>`;
  }
  html+=`<button class="page-btn" data-page="${current+1}" ${current===total?'disabled':''}><i class="fas fa-chevron-right"></i></button>`;
  c.innerHTML=html;
}

const PAGE_TITLES={dashboard:'Dashboard',tools:'Tools Management',borrowers:'Borrowers Directory',borrow:'Borrowing Management',return:'Return Management',replacements:'Damage & Loss Tracking',reports:'Reports & Analytics','sync-section':'Sync a Section',users:'User Accounts',audit:'Audit Trail'};

document.querySelectorAll('.nav-item[data-page]').forEach(item=>{
  item.addEventListener('click',()=>navigateTo(item.getAttribute('data-page')));
});

function toggleSidebar(){
  document.querySelector('.sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('active');
}

function closeSidebar(){
  document.querySelector('.sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('active');
}

// ── Drag-to-scroll for tables on mobile ──────────────────────────
// Every table on every page (Tools, Borrowers, Reports, etc.) sits
// inside a .table-container, so wiring this up once here makes all
// of them draggable the same way — Tools included, matching how the
// Borrowers table pans sideways. Touch already scrolls natively;
// this adds click-and-drag support with mouse/trackpad too.
function enableDragScroll(el){
  if(!el || el.dataset.dragScrollInit) return;
  el.dataset.dragScrollInit = '1';
  let isDown=false, startX=0, startScroll=0, moved=false;

  el.addEventListener('mousedown', e=>{
    // ignore drags that start on interactive elements (buttons, links, inputs)
    if(e.target.closest('button,a,input,select,textarea,label')) return;
    isDown=true; moved=false;
    startX = e.pageX - el.offsetLeft;
    startScroll = el.scrollLeft;
    el.classList.add('dragging');
  });
  ['mouseleave','mouseup'].forEach(evt=>{
    el.addEventListener(evt, ()=>{
      isDown=false;
      el.classList.remove('dragging');
    });
  });
  el.addEventListener('mousemove', e=>{
    if(!isDown) return;
    e.preventDefault();
    const x = e.pageX - el.offsetLeft;
    const walk = x - startX;
    if(Math.abs(walk) > 4) moved = true;
    el.scrollLeft = startScroll - walk;
  });
  // prevent the drag from also registering as a click on row buttons
  el.addEventListener('click', e=>{
    if(moved){ e.preventDefault(); e.stopPropagation(); }
  }, true);
}

document.addEventListener('DOMContentLoaded', ()=>{
  document.querySelectorAll('.table-container').forEach(enableDragScroll);
});

// In case this script runs after DOMContentLoaded already fired
if(document.readyState === 'complete' || document.readyState === 'interactive'){
  document.querySelectorAll('.table-container').forEach(enableDragScroll);
}

function navigateTo(page){
  if(page!=='reports'&&typeof leaveReportDetail==='function')leaveReportDetail();
  closeSidebar(); // on mobile, picking a page should close the drawer

  // Camera stays open (and the browser's camera indicator stays lit)
  // if you switch tabs without hitting Stop first — stop it here so
  // leaving the page always releases the camera.
  if(borrowScanning && page!=='borrow') stopBorrowScanner();
  if(idScanning && page!=='borrow') stopIdScanner();
  if(returnIdScanning && page!=='return') stopReturnIdScanner();

  document.body.classList.toggle('on-borrow',page==='borrow');
  if(page!=='borrow') brCloseSheets();       // review / history screens belong to the Borrow tab only
  document.querySelectorAll('.nav-item').forEach(i=>i.classList.remove('active'));
  document.querySelector(`.nav-item[data-page="${page}"]`)?.classList.add('active');
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.getElementById(`${page}Page`)?.classList.add('active');
  document.getElementById('pageTitle').textContent=PAGE_TITLES[page]||'';
  document.getElementById('breadcrumbPage').textContent=PAGE_TITLES[page]||'';
  if(page==='dashboard') loadDashboard();
  if(page==='tools')     loadTools();
  if(page==='borrowers') loadBorrowers();
  if(page==='borrow')    { loadBorrowerSelect(); loadToolsForBorrow(); brOnOpen(); if(typeof circulationTab==='function')circulationTab(circulationTabName); }   // the right panel is the Selected Tools cart now (no activity feed)
  if(page==='return')    { rtOnOpen(); if(typeof raReload==='function') raReload(); }
  if(page==='replacements') { loadChargeCases(); }
  if(page==='reports')   { loadReports(); }
  if(page==='users')     loadUsers();
  if(page==='audit')     { auditCurrentPage=1; loadAuditLog(); }
}

function openModal(id){if(['missingActionModal','rpDetailsModal'].includes(id)&&typeof incidentPrepare==='function')incidentPrepare(id);document.getElementById(id).classList.add('active');}

function closeModal(id){
  if(typeof incidentCanClose==='function'&&!incidentCanClose(id))return;
  if(id==='addStockModal'&&typeof discardStockAddition==='function'){
    discardStockAddition();
  }
  document.getElementById(id).classList.remove('active');
  document.getElementById(id).querySelectorAll('.live-sync-warning').forEach(note=>note.remove());
}

document.querySelectorAll('.modal-overlay').forEach(o=>{
  o.addEventListener('click',e=>{
    if(e.target!==o)return;
    if(o.id==='addStockModal'&&typeof stockBusy!=='undefined'&&stockBusy)return;
    closeModal(o.id);
  });
});

document.getElementById('currentDate').textContent=new Date().toLocaleDateString('en-US',{timeZone:'Asia/Manila',year:'numeric',month:'numeric',day:'numeric'});

async function loadCurrentUser(){
  try{
    const res=await fetch(`${API}/auth.php`,{headers:{'Content-Type':'application/json'}});
    const data=await res.json();
    if(!data.success){ window.location.href='login_usa.html'; return; }
    const u=data.data;
    document.getElementById('sidebarInitials').textContent=u.initials||getInitials(u.name);
    document.getElementById('sidebarName').textContent=u.name||'User';
    document.getElementById('sidebarRole').textContent=u.role||'';
  }catch(_){
    window.location.href='login_usa.html';
  }
}

document.getElementById('logoutBtn').addEventListener('click',()=>{
  if(confirm('Are you sure you want to logout?')) window.location.href='logout.php';
});

/* ───────────────────────────────────────────────────────────
   NOTIFICATIONS (overdue borrows + low-stock tools)
─────────────────────────────────────────────────────────── */
async function loadNotifications(){
  const list=document.getElementById('notifList');
  const badge=document.getElementById('notifBadge');
  try{
    // overdue_summary = true count + 20 newest rows (the old call downloaded the WHOLE overdue report)
    const [overdueRes,lowStockRes]=await Promise.all([
      apiFetch(`${API}/reports.php?type=overdue_summary`),
      apiFetch(`${API}/tools.php?status=low-stock&active_only=1&per_page=50`)
    ]);
    const overdueRows=overdueRes.data.rows||[]; // [txn_id, code, name, borrower, id_number, due_date]
    const overdueTotal=overdueRes.data.count||0;
    const activeBadge=document.getElementById('borrowBadge');
    if(activeBadge&&overdueRes.data.active_borrows!==undefined){activeBadge.textContent=overdueRes.data.active_borrows;activeBadge.style.display=overdueRes.data.active_borrows>0?'inline-block':'none';}
    const lowStock=lowStockRes.data||[];
    const lowTotal=lowStockRes.total||lowStock.length;
    const followTotal=overdueRes.data.followup_count||0, pendingMissing=overdueRes.data.pending_missing||0;
    const dueToday=overdueRes.data.due_today_count||0;
    const count=overdueTotal+dueToday+lowTotal+pendingMissing;

    if(count>0){badge.textContent=count>9?'9+':count;badge.style.display='flex';}
    else{badge.style.display='none';}

    if(!count){
      list.innerHTML='<div style="padding:20px;text-align:center;color:var(--gray-400);font-size:13px">All caught up — nothing overdue or low on stock.</div>';
      return;
    }
    let html='';
    if(pendingMissing>0) html+=`<div class="notif-item" style="padding:10px 8px;border-radius:8px;cursor:pointer" onclick="navigateTo('replacements');toggleNotifPanel(true)">
        <div style="font-size:13px;font-weight:600;color:var(--red-600,#dc2626)"><i class="fas fa-magnifying-glass-minus" style="margin-right:6px"></i>${pendingMissing} missing-tool report${pendingMissing===1?'':'s'} to review</div>
        <div style="font-size:12px;color:var(--gray-500)">Open Damage &amp; Loss / Missing Cases</div>
      </div>`;
    (overdueRes.data.due_today_rows||[]).forEach(r=>{
      html+=`<div class="notif-item" style="padding:10px 8px;cursor:pointer" onclick="navigateTo('borrow');circulationTab('active');toggleNotifPanel(true)"><b>${escapeHtml(r[2])} — Due Today</b><div class="mt-sub">${escapeHtml(r[3])} · ${Number(r[6])} unit(s) · due ${escapeHtml(r[4])} ${escapeHtml(r[5]||'end of day')}</div></div>`;
    });
    overdueRows.forEach(r=>{
      html+=`<div class="notif-item" style="padding:10px 8px;border-radius:8px;cursor:pointer" onclick="navigateTo('borrow');circulationTab('overdue');toggleNotifPanel(true)">
        <div style="font-size:13px;font-weight:600;color:var(--red-600,#dc2626)"><i class="fas fa-exclamation-triangle" style="margin-right:6px"></i>${escapeHtml(r[2])} ${(+r[6]||0)>=MT_FOLLOWUP_DAYS?'— follow-up needed':'overdue'}</div>
        <div style="font-size:12px;color:var(--gray-500)">${escapeHtml(r[3])} · ${Number(r[7]||0)} outstanding · due ${escapeHtml(r[5])}${r[6]!=null?` · ${escapeHtml(r[6])} day(s) overdue`:''}</div>
      </div>`;
    });
    if(overdueTotal>overdueRows.length) html+=`<div style="padding:8px;font-size:12px;color:var(--gray-500)">+ ${overdueTotal-overdueRows.length} more overdue — see Reports → Overdue.</div>`;
    lowStock.forEach(t=>{
      html+=`<div class="notif-item" style="padding:10px 8px;border-radius:8px;cursor:pointer" onclick="navigateTo('tools');toggleNotifPanel(true)">
        <div style="font-size:13px;font-weight:600;color:var(--amber-600,#d97706)"><i class="fas fa-box-open" style="margin-right:6px"></i>${escapeHtml(t.name)} low on stock</div>
        <div style="font-size:12px;color:var(--gray-500)">${escapeHtml(t.available)} available (min ${escapeHtml(t.min_stock)})</div>
      </div>`;
    });
    if(lowTotal>lowStock.length) html+=`<div style="padding:8px;font-size:12px;color:var(--gray-500)">+ ${lowTotal-lowStock.length} more low-stock tools.</div>`;
    list.innerHTML=html;
  }catch(_){
    list.innerHTML='<div style="padding:20px;text-align:center;color:var(--gray-400);font-size:13px">Failed to load notifications.</div>';
  }
}

function toggleNotifPanel(forceClose){
  const panel=document.getElementById('notifPanel');
  const willShow = forceClose ? false : (panel.style.display==='none');
  panel.style.display = willShow ? 'block' : 'none';
  if(willShow) loadNotifications();
}

document.addEventListener('click',(e)=>{
  const panel=document.getElementById('notifPanel');
  const btn=document.getElementById('notifBtn');
  if(panel.style.display!=='none' && !panel.contains(e.target) && !btn.contains(e.target)){
    panel.style.display='none';
  }
});

/* ───────────────────────────────────────────────────────────
   SETTINGS (self-service password change)
─────────────────────────────────────────────────────────── */
function openSettingsModal(){
  document.getElementById('s_current').value='';
  document.getElementById('s_new').value='';
  document.getElementById('s_confirm').value='';
  openModal('settingsModal');
}

async function saveSettings(){
  const current=document.getElementById('s_current').value;
  const next=document.getElementById('s_new').value;
  const confirmVal=document.getElementById('s_confirm').value;
  if(!current||!next||!confirmVal){showToast('All fields are required.','error');return;}
  if(next.length<8){showToast('New password must be at least 8 characters.','error');return;}
  if(next!==confirmVal){showToast('New password and confirmation don\'t match.','error');return;}
  setLoading('saveSettingsBtn',true);
  try{
    await apiFetch(`${API}/auth.php`,{method:'PUT',body:JSON.stringify({current_password:current,new_password:next})});
    showToast('Password updated.');
    closeModal('settingsModal');
  }catch(_){}
  setLoading('saveSettingsBtn',false);
}

const CHART_COLORS={
  purple:'#6d28d9',
  purpleFill:'rgba(109,38,217,.12)',
  green:'#10b981',
  greenFill:'rgba(16,185,129,.10)',
  amber:'#f59e0b'
};

function escapeHtml(value){
  return String(value??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
}

// Pre-populates the Borrow/Return "Activity" feed from real recent
// history when the page opens — previously this feed started empty
// every single session and only ever grew from actions taken THIS
// browser session, so anyone who hadn't personally just performed a
// borrow/return (e.g. a freshly-logged-in Staff account) would see
// "no activity yet" regardless of how busy the system actually was.
async function loadRecentActivity(feedId, type){
  const feed=document.getElementById(feedId);
  if(!feed) return;   // panel not on this page (e.g. the Return page no longer has an activity feed)
  try{
    const res=await apiFetch(`${API}/transactions.php?type=${type}&per_page=5`);
    const rows=res.data||[];
    if(!rows.length) return; // leave the existing "No X yet" empty-state as-is
    feed.innerHTML='';
    // API returns newest-first; each addActivityItem() call prepends,
    // so iterate oldest-of-these-5 → newest, and the newest ends up on
    // top like it should.
    rows.slice().reverse().forEach(t=>{
      const when=parseDbDate(t.created_at).toLocaleString([],{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
      if(type==='borrow'){
        addActivityItem(feedId,'borrow',`<strong>${escapeHtml(t.tool_name||t.tool_code)}</strong> borrowed by ${escapeHtml(t.borrower||'—')}`,`${when} • Due: ${escapeHtml(t.due_date||'—')}`);
      }else{
        const condLabels={good:'Good condition',minor:'Minor wear',damaged:'Damaged',missing:'Missing / Lost'};
        addActivityItem(feedId,'return',`<strong>${escapeHtml(t.tool_name||t.tool_code)}</strong> returned by ${escapeHtml(t.returnee_name||'—')} — ${escapeHtml(condLabels[t.condition]||t.condition||'')}`,`${when} • Notes: ${escapeHtml(t.notes||'—')}`);
      }
    });
  }catch(_){}
}

function addActivityItem(feedId, type, title, sub){
  const feed=document.getElementById(feedId);
  if(!feed) return;
  if(feed.querySelector('.empty-state'))feed.innerHTML='';
  const item=document.createElement('div');
  item.className='activity-item';
  item.innerHTML=`<div class="icon-circle ${type}"><i class="fas fa-sign-${type==='borrow'?'out':'in'}-alt"></i></div><div class="activity-info"><p>${title}</p><small>${sub}</small></div>`;
  feed.prepend(item);
}

// Adds a pager (once) inside a history table's container.
function ensureHistoryPager(containerId,key){
  if(document.getElementById(key+'PagerInfo')) return;
  const c=document.getElementById(containerId); if(!c) return;
  c.insertAdjacentHTML('beforeend',`<div class="pagination"><div class="pagination-info" id="${key}PagerInfo"></div><div class="pagination-btns" id="${key}PagerBtns"></div></div>`);
}

const HISTORY_PER_PAGE=50;

/* ───────────────────────────────────────────────────────────
   13. GLOBAL SEARCH
─────────────────────────────────────────────────────────── */
document.getElementById('globalSearch').addEventListener('input',function(){
  const q=this.value.trim();
  if(!q)return;
  clearTimeout(this._t);
  this._t=setTimeout(()=>{
    document.getElementById('toolSearchFilter').value=q;
    navigateTo('tools');
  },400);
});

/* Bootstrap: current user, dashboard, notifications. Scanner setup lives in borrow.js / return.js. */
window.addEventListener('load',()=>{
  loadCurrentUser();
  loadDashboard();
  loadNotifications();
});

/* ───────────────────────────────────────────────────────────
   OVERDUE & MISSING TOOLS
   GET  api/transactions.php?type=borrow&status=overdue|followup|missing   (loan_state, overdue_days, outstanding)
   GET  api/missing_tools.php?summary=1 | ?id=N | ?status=open|pending|confirmed|resolved|rejected
   POST api/missing_tools.php {action: report | confirm | reject | recover, ...}
   The server decides everything (roles, quantities, inventory); this file only collects input and
   shows the result. A missing tool is NEVER recorded through the Return form.
─────────────────────────────────────────────────────────── */
const MT_IS_ADMIN=()=>window.CURRENT_ROLE==='Admin';

const MT_FOLLOWUP_DAYS=7;

   // display only — the server's OVERDUE_FOLLOWUP_DAYS is the rule

// Units of a loan still physically out (server sends it; fall back for older responses).
function mtOutstanding(t){ return t.outstanding!=null ? +t.outstanding : (+t.qty - +t.qty_returned - (+t.qty_missing||0)); }

const mtFmtDT=d=>d?parseDbDate(d).toLocaleString([],{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}):'—';

// Borrowed / Overdue / Follow-up / Missing / Resolved / Returned — one badge everywhere.
const MT_LOAN_STATE={borrowed:['borrowed','Borrowed'],overdue:['overdue','Overdue'],followup:['followup','Follow-up'],
                     missing:['missing','Missing'],resolved:['resolved','Resolved'],returned:['returned','Returned'],due_today:['borrowed','Due Today']};

function loanOverdueLabel(t){
  if(t.due_label)return 'Overdue since '+t.due_label;
  if(!t.due_date)return 'Overdue';
  const due=new Date(t.due_date+'T'+(t.due_time||'23:59:59')+'+08:00');
  return 'Overdue since '+due.toLocaleString('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
}
function loanStateBadge(t){
  const st=t.is_overdue?'overdue':t.circulation_status==='due_today'?'due_today':t.loan_state||(t.status==='active'?'borrowed':'returned');
  const [cls,label]=MT_LOAN_STATE[st]||['neutral',st];
  const days=(st==='overdue'||st==='followup')?` — ${escapeHtml(loanOverdueLabel(t))}`:'';
  return `<span class="status-badge ${cls}"><span class="status-dot"></span>${label}${days}</span>`;
}

// Loans the Report-Missing form can be opened for, keyed by id (filled by the lists below).
const mtLoanCache=new Map();

function mtRememberLoans(rows){ rows.forEach(t=>mtLoanCache.set(String(t.id),t)); }
