
const API = 'api';

function showToast(msg, type='success'){
  const icons={success:'fas fa-check-circle',error:'fas fa-times-circle',warning:'fas fa-exclamation-triangle'};
  const t=document.createElement('div');
  t.className=`toast ${type}`;
  t.innerHTML=`<i class="${icons[type]||icons.success} toast-icon"></i><span>${msg}</span>`;
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
    const data=await res.json();
    if(!data.success) throw new Error(data.message||'Request failed');
    return data;
  }catch(err){
    showToast(err.message,'error');
    throw err;
  }
}

function getInitials(name){
  return (name||'').split(' ').filter(Boolean).map(n=>n[0]).join('').toUpperCase().substring(0,2)||'?';
}

function timeAgo(dateStr){
  const s=Math.floor((Date.now()-new Date(dateStr))/1000);
  if(s<60)return 'just now';
  if(s<3600)return Math.floor(s/60)+' min ago';
  if(s<86400)return Math.floor(s/3600)+' hr ago';
  return Math.floor(s/86400)+' days ago';
}

function downloadCSV(rows, filename){
  const csv=rows.map(r=>r.map(v=>`"${String(v??'').replace(/"/g,'""')}"`).join(',')).join('\n');
  const a=document.createElement('a');
  a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv);
  a.download=filename; a.click();
}

function renderPagination(infoId, btnsId, current, total, perPage, totalRecords, onPage){
  document.getElementById(infoId).textContent=
    total===0 ? 'No records found'
    : `Showing ${(current-1)*perPage+1}–${Math.min(current*perPage,totalRecords)} of ${totalRecords}`;
  const c=document.getElementById(btnsId);
  if(total<=1){c.innerHTML='';return;}
  let html=`<button class="page-btn" ${current===1?'disabled':''} onclick="(${onPage.toString()})(${current-1})"><i class="fas fa-chevron-left"></i></button>`;
  for(let i=1;i<=total;i++){
    if(i===1||i===total||Math.abs(i-current)<=1) html+=`<button class="page-btn ${i===current?'active':''}" onclick="(${onPage.toString()})(${i})">${i}</button>`;
    else if(Math.abs(i-current)===2) html+=`<button class="page-btn" disabled>…</button>`;
  }
  html+=`<button class="page-btn" ${current===total?'disabled':''} onclick="(${onPage.toString()})(${current+1})"><i class="fas fa-chevron-right"></i></button>`;
  c.innerHTML=html;
}

const PAGE_TITLES={dashboard:'Dashboard',tools:'Tools Management',borrowers:'Borrowers Directory',borrow:'Borrow Tool',return:'Return Tool',reports:'Reports & Analytics','sync-section':'Sync a Section',users:'User Accounts'};

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

function navigateTo(page){
  closeSidebar(); // on mobile, picking a page should close the drawer

  // Camera stays open (and the browser's camera indicator stays lit)
  // if you switch tabs without hitting Stop first — stop it here so
  // leaving the page always releases the camera.
  if(borrowScanning && page!=='borrow') stopBorrowScanner();
  if(returnScanning && page!=='return') stopReturnScanner();

  document.querySelectorAll('.nav-item').forEach(i=>i.classList.remove('active'));
  document.querySelector(`.nav-item[data-page="${page}"]`)?.classList.add('active');
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.getElementById(`${page}Page`)?.classList.add('active');
  document.getElementById('pageTitle').textContent=PAGE_TITLES[page]||'';
  document.getElementById('breadcrumbPage').textContent=PAGE_TITLES[page]||'';
  if(page==='dashboard') loadDashboard();
  if(page==='tools')     loadTools();
  if(page==='borrowers') loadBorrowers();
  if(page==='borrow')    { loadBorrowerSelect(); loadBorrowHistory(); loadToolsForBorrow(); }
  if(page==='return')    { loadReturnHistory(); loadActiveBorrowsForReturn(); }
  if(page==='reports')   loadReports();
  if(page==='users')     loadUsers();
}

function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}
document.querySelectorAll('.modal-overlay').forEach(o=>{
  o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('active');});
});

document.getElementById('currentDate').textContent=new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});


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
    const [overdueRes,lowStockRes]=await Promise.all([
      apiFetch(`${API}/reports.php?type=overdue`),
      apiFetch(`${API}/tools.php?status=low-stock&per_page=50`)
    ]);
    const overdueRows=overdueRes.data.rows||[]; // [txn_id, code, name, borrower, id_number, due_date, created_at]
    const lowStock=lowStockRes.data||[];
    const count=overdueRows.length+lowStock.length;

    if(count>0){badge.textContent=count>9?'9+':count;badge.style.display='flex';}
    else{badge.style.display='none';}

    if(!count){
      list.innerHTML='<div style="padding:20px;text-align:center;color:var(--gray-400);font-size:13px">All caught up — nothing overdue or low on stock.</div>';
      return;
    }
    let html='';
    overdueRows.forEach(r=>{
      html+=`<div class="notif-item" style="padding:10px 8px;border-radius:8px;cursor:pointer" onclick="navigateTo('borrow');toggleNotifPanel(true)">
        <div style="font-size:13px;font-weight:600;color:var(--red-600,#dc2626)"><i class="fas fa-exclamation-triangle" style="margin-right:6px"></i>${r[2]} overdue</div>
        <div style="font-size:12px;color:var(--gray-500)">${r[3]} · due ${r[5]}</div>
      </div>`;
    });
    lowStock.forEach(t=>{
      html+=`<div class="notif-item" style="padding:10px 8px;border-radius:8px;cursor:pointer" onclick="navigateTo('tools');toggleNotifPanel(true)">
        <div style="font-size:13px;font-weight:600;color:var(--amber-600,#d97706)"><i class="fas fa-box-open" style="margin-right:6px"></i>${t.name} low on stock</div>
        <div style="font-size:12px;color:var(--gray-500)">${t.available} available (min ${t.min_stock})</div>
      </div>`;
    });
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


let borrowChart=null, monthlyChart=null, categoryChart=null;


const CHART_COLORS={
  purple:'#6d28d9',
  purpleLight:'#a78bfa',
  purpleFill:'rgba(109,38,217,.12)',
  green:'#10b981',
  greenFill:'rgba(16,185,129,.10)',
  amber:'#f59e0b',
  red:'#ef4444',
  violet:'#8b5cf6'
};

async function loadDashboard(){
  try{
    const res=await apiFetch(`${API}/dashboard.php`);
    const d=res.data;

    document.getElementById('dashTotal').textContent=d.total_tools??'—';
    document.getElementById('dashAvailable').textContent=d.available_tools??'—';
    document.getElementById('dashBorrowed').textContent=d.borrowed_tools??'—';
    document.getElementById('dashBorrowers').textContent=d.total_borrowers??'—';
    document.getElementById('lowStockCount').textContent=d.low_stock_count??0;
    document.getElementById('borrowBadge').textContent=d.active_borrows??0;
    document.getElementById('borrowBadge').style.display=(d.active_borrows>0)?'inline-block':'none';

    // Most borrowed
    const rankClasses=['gold','silver','bronze'];
    const mbEl=document.getElementById('dashMostBorrowed');
    if(!d.most_borrowed||!d.most_borrowed.length){
      mbEl.innerHTML='<div class="empty-state"><i class="fas fa-box-open"></i><p>No data yet.</p></div>';
    } else {
      mbEl.innerHTML=d.most_borrowed.slice(0,5).map((item,i)=>`
        <div class="borrowed-item">
          <div class="borrowed-rank ${rankClasses[i]||''}">${i+1}</div>
          <div class="tool-icon"><i class="fas fa-tools"></i></div>
          <div class="borrowed-info">
            <div class="borrowed-name">${item.name}</div>
            <div class="progress-bar"><div class="progress-fill" style="width:${item.pct||0}%"></div></div>
          </div>
          <div class="borrowed-count">${item.count}</div>
        </div>`).join('');
    }

    // Recent transactions
    const txEl=document.getElementById('dashRecentTx');
    if(!d.recent_transactions||!d.recent_transactions.length){
      txEl.innerHTML='<div class="empty-state"><i class="fas fa-exchange-alt"></i><p>No transactions yet.</p></div>';
    } else {
      txEl.innerHTML=d.recent_transactions.slice(0,5).map(t=>`
        <div class="transaction-item">
          <div class="transaction-icon ${t.type}"><i class="fas fa-arrow-${t.type==='borrow'?'right':'left'}"></i></div>
          <div class="transaction-details">
            <div class="transaction-title">${t.tool_name||t.tool_code} ${t.type==='borrow'?'borrowed':'returned'}</div>
            <div class="transaction-meta">${t.type==='borrow'?'by '+t.borrower:t.condition+' condition'}</div>
          </div>
          <div class="transaction-time">${timeAgo(t.created_at)}</div>
        </div>`).join('');
    }

    // Low stock
    const alertEl=document.getElementById('dashAlerts');
    if(!d.low_stock_items||!d.low_stock_items.length){
      alertEl.innerHTML='<div class="empty-state"><i class="fas fa-check-circle"></i><p>No low stock items.</p></div>';
    } else {
      alertEl.innerHTML=d.low_stock_items.map(t=>`
        <div class="alert-item">
          <i class="fas fa-exclamation-triangle"></i>
          <div class="alert-info">
            <div class="alert-title">${t.name}</div>
            <div class="alert-desc">Only ${t.available} remaining • Min: ${t.min_stock}</div>
          </div>
        </div>`).join('');
    }

    // Weekly chart
    if(borrowChart)borrowChart.destroy();
    borrowChart=new Chart(document.getElementById('borrowChart').getContext('2d'),{
      type:'line',
      data:{
        labels:d.weekly_labels||['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
        datasets:[
          {label:'Borrows',data:d.weekly_borrows||[],borderColor:CHART_COLORS.purple,backgroundColor:CHART_COLORS.purpleFill,fill:true,tension:.4,borderWidth:2},
          {label:'Returns',data:d.weekly_returns||[],borderColor:CHART_COLORS.green,backgroundColor:CHART_COLORS.greenFill,fill:true,tension:.4,borderWidth:2}
        ]
      },
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{usePointStyle:true,padding:20}}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'}},x:{grid:{display:false}}}}
    });
  }catch(_){}
}

let toolsCurrentPage=1;
const TOOLS_PER_PAGE=10;

async function loadTools(){
  const status=document.getElementById('toolStatusFilter').value;
  const category=document.getElementById('toolCategoryFilter').value;
  const search=document.getElementById('toolSearchFilter').value;
  const params=new URLSearchParams({page:toolsCurrentPage,per_page:TOOLS_PER_PAGE,status,category,search});
  document.getElementById('toolsTableBody').innerHTML='<tr class="empty-row"><td colspan="8"><span class="spinner dark"></span> Loading…</td></tr>';
  try{
    const res=await apiFetch(`${API}/tools.php?${params}`);
    renderToolsTable(res.data||[]);
    const totalPages=Math.ceil((res.total||0)/TOOLS_PER_PAGE);
    renderPagination('toolsPaginationInfo','toolsPaginationBtns',toolsCurrentPage,totalPages,TOOLS_PER_PAGE,res.total||0,p=>{toolsCurrentPage=p;loadTools();});
  }catch(_){document.getElementById('toolsTableBody').innerHTML='<tr class="empty-row"><td colspan="8">Failed to load tools.</td></tr>';}
}

const CAT_ICONS={Utensils:'fa-solid fa-utensils',Cookware:'fas fa-fire-burner','Measuring Tools':'fas fa-ruler',Accessories:'fas fa-toolbox',Dinnerware:'fas fa-plate-wheat',Cutleries:'fas fa-utensils',Glassware:'fas fa-martini-glass'};

function renderToolsTable(tools){
  const tbody=document.getElementById('toolsTableBody');
  if(!tools.length){tbody.innerHTML='<tr class="empty-row"><td colspan="8">No tools found.</td></tr>';return;}
  const isAdmin = window.CURRENT_ROLE === 'Admin';
  tbody.innerHTML=tools.map(t=>`
    <tr${t.is_active==0?' style="opacity:.55"':''}>
      <td data-label="Tool"><div class="tool-item">
        <div class="tool-icon"><i class="${CAT_ICONS[t.category]||'fas fa-box'}"></i></div>
        <div class="tool-info"><h4>${t.name}${t.is_active==0?' <span class="status-badge low-stock" style="margin-left:6px"><span class="status-dot"></span>Retired</span>':''}</h4><span>${t.description||t.category}</span></div>
      </div></td>
      <td data-label="Code"><code>${t.code}</code></td>
      <td data-label="Category">${t.category}</td>
      <td data-label="Total Qty">${t.quantity}</td>
      <td data-label="Available">${t.available}</td>
      <td data-label="Min Stock">${t.min_stock}</td>
      <td data-label="Status"><span class="status-badge ${t.status}"><span class="status-dot"></span>${statusLabel(t.status)}</span></td>
      <td data-label="Actions"><div class="action-btns">
        <button class="action-btn view" title="QR Code" onclick="showQR('${t.code}','${t.name.replace(/'/g,"\\'")}')"><i class="fas fa-qrcode"></i></button>
        <button class="action-btn edit" title="Edit" onclick="editTool(${t.id})"><i class="fas fa-edit"></i></button>
        ${isAdmin?`<button class="action-btn" title="${t.is_active==0?'Reactivate':'Retire'}" onclick="toggleToolActive(${t.id},${t.is_active==0?'true':'false'},'${t.name.replace(/'/g,"\\'")}')"><i class="fas fa-power-off"></i></button>`:''}
      </div></td>
    </tr>`).join('');
}

// Admin-only: retire/reactivate a tool. No hard delete — deleting a
// tool used to cascade-delete every past transaction for it.
async function toggleToolActive(id, makeActive, name){
  if(!makeActive){
    document.getElementById('deleteConfirmText').textContent=`Retire "${name}"? It will be hidden from the Borrow flow but its history is kept, and you can reactivate it anytime.`;
    document.getElementById('confirmDeleteBtn').onclick=async()=>{
      try{
        await apiFetch(`${API}/tools.php`,{method:'PATCH',body:JSON.stringify({id,is_active:false})});
        showToast(`"${name}" retired.`);
        closeModal('confirmDeleteModal');
        loadTools();
      }catch(_){}
    };
    openModal('confirmDeleteModal');
    return;
  }
  try{
    await apiFetch(`${API}/tools.php`,{method:'PATCH',body:JSON.stringify({id,is_active:true})});
    showToast(`"${name}" reactivated.`);
    loadTools();
  }catch(_){}
}

function statusLabel(s){return{available:'Available',borrowed:'Borrowed','low-stock':'Low Stock'}[s]||s;}

function applyToolFilters(){toolsCurrentPage=1;loadTools();}

function clearToolFilters(){
  document.getElementById('toolStatusFilter').value='';
  document.getElementById('toolCategoryFilter').value='';
  document.getElementById('toolSearchFilter').value='';
  toolsCurrentPage=1; loadTools();
}

function openAddToolModal(){
  document.getElementById('editToolId').value='';
  document.getElementById('toolModalTitle').textContent='Add New Tool';
  document.getElementById('saveToolBtn').textContent='Add Tool';
  ['t_name','t_code','t_qty','t_min','t_desc'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('t_category').value='';
  openModal('toolModal');
}

async function editTool(id){
  try{
    const res=await apiFetch(`${API}/tools.php?id=${id}`);
    const t=res.data;
    document.getElementById('editToolId').value=t.id;
    document.getElementById('toolModalTitle').textContent='Edit Tool';
    document.getElementById('saveToolBtn').textContent='Save Changes';
    document.getElementById('t_name').value=t.name;
    document.getElementById('t_code').value=t.code;
    document.getElementById('t_category').value=t.category;
    document.getElementById('t_qty').value=t.quantity;
    document.getElementById('t_min').value=t.min_stock;
    document.getElementById('t_desc').value=t.description||'';
    openModal('toolModal');
  }catch(_){}
}

async function saveTool(){
  const id=document.getElementById('editToolId').value;
  const body={
    name:document.getElementById('t_name').value.trim(),
    code:document.getElementById('t_code').value.trim(),
    category:document.getElementById('t_category').value,
    quantity:parseInt(document.getElementById('t_qty').value)||0,
    min_stock:parseInt(document.getElementById('t_min').value)||0,
    description:document.getElementById('t_desc').value.trim()
  };
  if(!body.name||!body.code||!body.category||!body.quantity||!body.min_stock){showToast('Please fill in all required fields.','error');return;}
  if(id) body.id=parseInt(id);
  setLoading('saveToolBtn',true);
  try{
    await apiFetch(`${API}/tools.php`,{method:id?'PUT':'POST',body:JSON.stringify(body)});
    showToast(id?`"${body.name}" updated.`:`"${body.name}" added.`,'success');
    closeModal('toolModal');
    loadTools();
  }catch(_){}finally{setLoading('saveToolBtn',false);}
}

let currentQrToolLabel = '', currentQrToolCode = '';

function showQR(code, name){
  currentQrToolCode = code;
  currentQrToolLabel = name || '';
  const container = document.getElementById('qrCodeContainer');
  container.innerHTML = '';
  // The scanner (startBorrowScanner/startReturnScanner) drops the raw
  // decoded text straight into the Tool Code field, so this must
  // encode exactly the tool's `code` value — nothing else.
  new QRCode(container, {
    text: code,
    width: 200,
    height: 200,
    correctLevel: QRCode.CorrectLevel.M
  });
  document.getElementById('qrToolLabel').textContent = name || '';
  document.getElementById('qrToolCode').textContent = code;
  openModal('qrModal');
}

function printQR(){
  const canvas = document.querySelector('#qrCodeContainer canvas');
  const img = canvas ? canvas.toDataURL('image/png') : (document.querySelector('#qrCodeContainer img')?.src || '');
  const w = window.open('', '_blank', 'width=400,height=500');
  w.document.write(`
    <html><head><title>${currentQrToolCode} QR Label</title>
    <style>
      body{font-family:sans-serif;text-align:center;padding:24px}
      img{width:200px;height:200px}
      h3{margin:12px 0 2px}
      p{margin:0;color:#666;font-family:monospace}
    </style></head>
    <body>
      <img src="${img}">
      <h3>${currentQrToolLabel}</h3>
      <p>${currentQrToolCode}</p>
      <script>window.onload=()=>{window.print();}<\/script>
    </body></html>
  `);
  w.document.close();
}

async function exportToolsCSV(){
  try{
    const res=await apiFetch(`${API}/tools.php?per_page=9999`);
    const rows=[['Name','Code','Category','Quantity','Available','Min Stock','Status']];
    (res.data||[]).forEach(t=>rows.push([t.name,t.code,t.category,t.quantity,t.available,t.min_stock,statusLabel(t.status)]));
    downloadCSV(rows,'tools_inventory.csv');
    showToast('Tools inventory exported.','success');
  }catch(_){}
}

/* ───────────────────────────────────────────────────────────
   7. BORROWERS DIRECTORY (CRUD)
   GET  api/borrowers.php?page=&type=&search=
   POST api/borrowers.php  body: { full_name, id_number, type, email, phone }
   PUT  api/borrowers.php  body: { id, full_name, id_number, type, email, phone }
   DELETE api/borrowers.php?id=N
─────────────────────────────────────────────────────────── */
let borrowersCurrentPage=1;
const BORROWERS_PER_PAGE=10;

async function loadBorrowers(){
  const type=document.getElementById('borrowerTypeFilter').value;
  const search=document.getElementById('borrowerSearchFilter').value;
  const params=new URLSearchParams({page:borrowersCurrentPage,per_page:BORROWERS_PER_PAGE,type,search});
  document.getElementById('borrowerTableBody').innerHTML='<tr class="empty-row"><td colspan="9"><span class="spinner dark"></span> Loading…</td></tr>';
  try{
    const res=await apiFetch(`${API}/borrowers.php?${params}`);
    renderBorrowersTable(res.data||[]);
    const totalPages=Math.ceil((res.total||0)/BORROWERS_PER_PAGE);
    renderPagination('borrowersPaginationInfo','borrowersPaginationBtns',borrowersCurrentPage,totalPages,BORROWERS_PER_PAGE,res.total||0,p=>{borrowersCurrentPage=p;loadBorrowers();});
  }catch(_){document.getElementById('borrowerTableBody').innerHTML='<tr class="empty-row"><td colspan="7">Failed to load borrowers.</td></tr>';}
}

function renderBorrowersTable(borrowers){
  const tbody=document.getElementById('borrowerTableBody');
  if(!borrowers.length){tbody.innerHTML='<tr class="empty-row"><td colspan="9">No borrowers found.</td></tr>';return;}
  const isAdmin = window.CURRENT_ROLE === 'Admin';
  tbody.innerHTML=borrowers.map(b=>`
    <tr${b.is_active==0?' style="opacity:.55"':''}>
      <td data-label="Borrower"><div class="tool-item" style="cursor:pointer" onclick="viewBorrowerHistory(${b.id})" title="View history">
        <div class="user-avatar" style="width:40px;height:40px;font-size:13px">${getInitials(b.full_name)}</div>
        <div class="tool-info"><h4>${b.full_name}${b.is_active==0?' <span class="status-badge low-stock" style="margin-left:6px"><span class="status-dot"></span>Inactive</span>':''}</h4><span>${b.type}</span></div>
      </div></td>
      <td data-label="ID Number"><code>${b.id_number}</code></td>
      <td data-label="Type">${b.type}</td>
      <td data-label="Course">${b.course||'—'}</td>
      <td data-label="Section">${b.section_name||'—'}</td>
      <td data-label="Contact">${b.email||'—'}</td>
      <td data-label="Active Borrows"><span class="status-badge ${b.active_borrows>0?'borrowed':'available'}"><span class="status-dot"></span>${b.active_borrows} item${b.active_borrows!==1?'s':''}</span></td>
      <td data-label="Total Borrows">${b.total_borrows}</td>
      <td data-label="Actions"><div class="action-btns">
        <button class="action-btn view" title="View History" onclick="viewBorrowerHistory(${b.id})"><i class="fas fa-clock-rotate-left"></i></button>
        ${isAdmin?`
        <button class="action-btn edit" title="Edit" onclick="editBorrower(${b.id})"><i class="fas fa-edit"></i></button>
        <button class="action-btn" title="${b.is_active==0?'Activate':'Deactivate'}" onclick="toggleBorrowerActive(${b.id},${b.is_active==0?'true':'false'})"><i class="fas fa-power-off"></i></button>
        `:''}
      </div></td>
    </tr>`).join('');
}

// ── Borrower history/detail modal ──────────────────────────────
async function viewBorrowerHistory(id){
  document.getElementById('bhName').textContent='Loading…';
  document.getElementById('bhMeta').textContent='';
  document.getElementById('bhStats').innerHTML='';
  document.getElementById('bhHistoryBody').innerHTML='<tr class="empty-row"><td colspan="6"><span class="spinner dark"></span> Loading…</td></tr>';
  openModal('borrowerHistoryModal');
  try{
    const res=await apiFetch(`${API}/borrowers.php?id=${id}&history=1`);
    const {borrower,history,stats}=res.data;

    document.getElementById('bhName').textContent=borrower.full_name;
    document.getElementById('bhMeta').textContent=
      `${borrower.type} • ${borrower.id_number}${borrower.course?` • ${borrower.course} ${borrower.section_name||''}`:''}${borrower.is_active==0?' • Inactive':''}`;

    const statChip=(label,val,cls)=>`<span class="status-badge ${cls}" style="margin-right:8px"><span class="status-dot"></span>${val} ${label}</span>`;
    document.getElementById('bhStats').innerHTML=
      statChip('returned on time',stats.on_time,'available')+
      statChip('returned late',stats.late,stats.late>0?'low-stock':'available')+
      statChip('overdue now',stats.overdue_now,stats.overdue_now>0?'borrowed':'available')+
      statChip('damaged/minor wear on return',stats.damaged_or_minor,stats.damaged_or_minor>0?'low-stock':'available');

    if(!history.length){
      document.getElementById('bhHistoryBody').innerHTML='<tr class="empty-row"><td colspan="6">No borrow/return activity yet.</td></tr>';
      return;
    }
    const flagBadge=(r)=>{
      if(r.type==='return') return `<span class="status-badge ${r.condition==='damaged'?'low-stock':(r.condition==='minor'?'low-stock':'available')}"><span class="status-dot"></span>${r.condition||'—'}</span>`;
      if(r.flag==='late') return '<span class="status-badge low-stock"><span class="status-dot"></span>Returned Late</span>';
      if(r.flag==='overdue') return '<span class="status-badge borrowed"><span class="status-dot"></span>Overdue</span>';
      if(r.flag==='on_time') return '<span class="status-badge available"><span class="status-dot"></span>On Time</span>';
      return `<span class="status-badge available"><span class="status-dot"></span>${r.status}</span>`;
    };
    document.getElementById('bhHistoryBody').innerHTML=history.map(r=>`
      <tr>
        <td data-label="Event"><span class="status-badge ${r.type==='borrow'?'borrowed':'available'}"><span class="status-dot"></span>${r.type==='borrow'?'Borrow':'Return'}</span></td>
        <td data-label="Tool">${r.tool_name||'—'} <code style="font-size:11px">${r.tool_code||''}</code></td>
        <td data-label="Qty">${r.qty}</td>
        <td data-label="Due / Returned">${r.type==='borrow'?(r.due_date||'—'):(r.returned_at?new Date(r.returned_at).toLocaleDateString():'—')}</td>
        <td data-label="Status">${flagBadge(r)}</td>
        <td data-label="Date">${new Date(r.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</td>
      </tr>`).join('');
  }catch(_){
    document.getElementById('bhHistoryBody').innerHTML='<tr class="empty-row"><td colspan="6">Failed to load history.</td></tr>';
  }
}

// Admin-only: manual activate/deactivate, separate from CMS-driven sync.
async function toggleBorrowerActive(id, makeActive){
  try{
    await apiFetch(`${API}/borrowers.php`,{method:'PATCH',body:JSON.stringify({id,is_active:makeActive})});
    showToast(makeActive?'Borrower activated.':'Borrower deactivated.');
    loadBorrowers();
  }catch(_){}
}

function applyBorrowerFilters(){borrowersCurrentPage=1;loadBorrowers();}

function clearBorrowerFilters(){
  document.getElementById('borrowerTypeFilter').value='';
  document.getElementById('borrowerSearchFilter').value='';
  borrowersCurrentPage=1; loadBorrowers();
}

async function loadBorrowerEnrollmentOptions(selectedCourse='', selectedSectionId=''){
  const courseSel=document.getElementById('b_course');
  const sectionSel=document.getElementById('b_section');
  if(!courseSel||!sectionSel)return;
  try{
    const res=await apiFetch(`${API}/borrower_enrollments.php`);
    const rows=res.data||[];
    const courses=[...new Set(rows.map(r=>r.course).filter(Boolean))];
    courseSel.innerHTML='<option value="">Select course</option>'+courses.map(c=>`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
    sectionSel.innerHTML='<option value="">Select section</option>';
    courseSel.onchange=()=>{
      const course=courseSel.value;
      const sections=rows.filter(r=>r.course===course);
      sectionSel.innerHTML='<option value="">Select section</option>'+sections.map(r=>`<option value="${r.cms_section_id}" data-course="${escapeHtml(r.course)}" data-name="${escapeHtml(r.section_name)}">${escapeHtml(r.section_name)}</option>`).join('');
    };
    if(selectedCourse){
      courseSel.value=selectedCourse;
      courseSel.onchange();
      if(selectedSectionId) sectionSel.value=String(selectedSectionId);
    }
  }catch(_){
    courseSel.innerHTML='<option value="">Unable to load courses</option>';
    sectionSel.innerHTML='<option value="">Unable to load sections</option>';
  }
}

function escapeHtml(value){
  return String(value??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
}

function openAddBorrowerModal(){
  document.getElementById('editBorrowerId').value='';
  document.getElementById('editEnrollmentId').value='';
  document.getElementById('borrowerModalTitle').textContent='Add New Borrower';
  document.getElementById('saveBorrowerBtn').textContent='Add Borrower';
  ['b_name','b_idnum','b_email','b_phone'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('b_type').value='';
  loadBorrowerEnrollmentOptions();
  document.getElementById('b_course').value='';
  document.getElementById('b_section').innerHTML='<option value="">Select section</option>';
  openModal('borrowerModal');
}

async function editBorrower(id){
  try{
    const [borrowerRes,enrollmentRes]=await Promise.all([
      apiFetch(`${API}/borrowers.php?id=${id}`),
      apiFetch(`${API}/borrower_enrollments.php?borrower_id=${id}`)
    ]);
    const b=borrowerRes.data;
    const enrollments=enrollmentRes.data||[];
    const manual=enrollments.find(e=>String(e.cms_subject_id)==='0') || enrollments[0] || null;
    document.getElementById('editBorrowerId').value=b.id;
    document.getElementById('editEnrollmentId').value=manual?.id||'';
    document.getElementById('borrowerModalTitle').textContent='Edit Borrower';
    document.getElementById('saveBorrowerBtn').textContent='Save Changes';
    document.getElementById('b_name').value=b.full_name||'';
    document.getElementById('b_idnum').value=b.id_number||'';
    document.getElementById('b_type').value=b.type||'';
    document.getElementById('b_email').value=b.email||'';
    document.getElementById('b_phone').value=b.phone||'';
    await loadBorrowerEnrollmentOptions(manual?.course||b.course||'', manual?.cms_section_id||b.cms_section_id||'');
    openModal('borrowerModal');
  }catch(_){ }
}

async function saveBorrower(){
  const id=document.getElementById('editBorrowerId').value;
  const body={
    full_name:document.getElementById('b_name').value.trim(),
    id_number:document.getElementById('b_idnum').value.trim(),
    type:document.getElementById('b_type').value,
    email:document.getElementById('b_email').value.trim(),
    phone:document.getElementById('b_phone').value.trim()
  };
  const course=document.getElementById('b_course').value;
  const section=document.getElementById('b_section');
  const sectionId=parseInt(section.value||'0',10);
  const sectionName=section.options[section.selectedIndex]?.dataset?.name||section.options[section.selectedIndex]?.text||'';
  if(!body.full_name||!body.id_number||!body.type){showToast('Please fill in all required fields.','error');return;}
  if(!course||!sectionId){showToast('Please select a course and section.','error');return;}
  if(id) body.id=parseInt(id,10);
  setLoading('saveBorrowerBtn',true);
  try{
    const borrowerRes=await apiFetch(`${API}/borrowers.php`,{method:id?'PUT':'POST',body:JSON.stringify(body)});
    const borrowerId=id?parseInt(id,10):borrowerRes.data.id;
    const enrollmentId=document.getElementById('editEnrollmentId').value;
    const enrollmentBody={borrower_id:borrowerId,cms_section_id:sectionId,course,section_name:sectionName};
    if(enrollmentId) enrollmentBody.id=parseInt(enrollmentId,10);
    await apiFetch(`${API}/borrower_enrollments.php`,{method:enrollmentId?'PUT':'POST',body:JSON.stringify(enrollmentBody)});
    showToast(id?`"${body.full_name}" updated.`:`"${body.full_name}" added.`,'success');
    closeModal('borrowerModal');
    loadBorrowers();
    loadBorrowerSelect();
  }catch(_){ }finally{setLoading('saveBorrowerBtn',false);}
}

async function exportBorrowersCSV(){
  try{
    const res=await apiFetch(`${API}/borrowers.php?per_page=9999`);
    const rows=[['Name','ID Number','Type','Email','Phone','Active Borrows','Total Borrows']];
    (res.data||[]).forEach(b=>rows.push([b.full_name,b.id_number,b.type,b.email||'',b.phone||'',b.active_borrows,b.total_borrows]));
    downloadCSV(rows,'borrowers_directory.csv');
    showToast('Borrowers directory exported.','success');
  }catch(_){}
}

/* ───────────────────────────────────────────────────────────
   8. BORROW-PAGE BORROWER SELECT
─────────────────────────────────────────────────────────── */
async function loadBorrowerSelect(){
  try{
    const res=await apiFetch(`${API}/borrowers.php?per_page=9999`);
    const sel=document.getElementById('borrowerSelect');
    sel.innerHTML='<option value="">Select Borrower…</option>';
    (res.data||[]).forEach(b=>{
      const o=document.createElement('option');
      o.value=b.id; o.textContent=`${b.full_name} — ${b.id_number}`;
      sel.appendChild(o);
    });
  }catch(_){}
}

/* ───────────────────────────────────────────────────────────
   9. QR SCANNERS (BORROW & RETURN)
─────────────────────────────────────────────────────────── */
let borrowQr=null, borrowScanning=false;
let returnQr=null, returnScanning=false;

function setScanStatus(statusId,textId,text,type){
  const el=document.getElementById(statusId);
  el.className='scan-status '+(type||'');
  el.style.display=type?'flex':'none';
  if(textId)document.getElementById(textId).textContent=text;
}

function loadCameras(selectId){
  return Html5Qrcode.getCameras().then(devices=>{
    const sel=document.getElementById(selectId);
    sel.innerHTML='';
    if(!devices||!devices.length){sel.innerHTML='<option value="">No cameras detected</option>';return devices;}
    devices.forEach((d,i)=>{const o=document.createElement('option');o.value=d.id;o.textContent=d.label||`Camera ${i+1}`;sel.appendChild(o);});
    return devices;
  }).catch(err=>{document.getElementById(selectId).innerHTML='<option value="">Camera access denied</option>';throw err;});
}

async function startBorrowScanner(){
  if(borrowScanning)return;
  const sel=document.getElementById('borrowCameraSelect');
  if(!sel.value){
    // Cameras haven't been enumerated yet — this is the FIRST point
    // the browser's camera permission prompt appears, deliberately
    // deferred until the user actually presses this button rather
    // than on page load.
    setScanStatus('borrowScanStatus','borrowScanStatusText','Requesting camera access…','scanning');
    try{ await loadCameras('borrowCameraSelect'); }
    catch(_){ setScanStatus('borrowScanStatus','borrowScanStatusText','Camera access denied.','error'); return; }
  }
  const camId=sel.value;
  if(!camId){setScanStatus('borrowScanStatus','borrowScanStatusText','No camera available.','error');return;}
  document.getElementById('borrowReader').style.display='block';
  document.getElementById('borrowStartBtn').style.display='none';
  document.getElementById('borrowStopBtn').style.display='flex';
  setScanStatus('borrowScanStatus','borrowScanStatusText','Scanning — point camera at QR code…','scanning');
  borrowQr.start(camId,{fps:15,qrbox:(w,h)=>{const s=Math.min(w,h)*.7;return{width:Math.floor(s),height:Math.floor(s)};},aspectRatio:1},
    decoded=>{document.getElementById('borrowToolId').value=decoded;document.getElementById('clearBorrowToolBtn').classList.add('visible');matchBorrowToolCode(decoded);setScanStatus('borrowScanStatus','borrowScanStatusText','✓ Scanned: '+decoded,'success');stopBorrowScanner();},()=>{}
  ).then(()=>borrowScanning=true).catch(err=>{setScanStatus('borrowScanStatus','borrowScanStatusText','Cannot start: '+err,'error');resetBorrowScannerUI();});
}
function stopBorrowScanner(){if(!borrowScanning){resetBorrowScannerUI();return;}borrowQr.stop().then(()=>{borrowScanning=false;resetBorrowScannerUI();}).catch(()=>{borrowScanning=false;resetBorrowScannerUI();});}
function resetBorrowScannerUI(){document.getElementById('borrowReader').style.display='none';document.getElementById('borrowStartBtn').style.display='flex';document.getElementById('borrowStopBtn').style.display='none';}
function borrowScanUploadedImage(event){
  const file=event.target.files[0];if(!file)return;
  if(borrowScanning)stopBorrowScanner();
  document.getElementById('borrowUploadedQrImg').src=URL.createObjectURL(file);
  document.getElementById('borrowUploadPreview').style.display='flex';
  setScanStatus('borrowScanStatus','borrowScanStatusText','Reading QR from image…','scanning');
  const fs=new Html5Qrcode('borrowReader');
  fs.scanFile(file,true).then(decoded=>{document.getElementById('borrowToolId').value=decoded;document.getElementById('clearBorrowToolBtn').classList.add('visible');matchBorrowToolCode(decoded);setScanStatus('borrowScanStatus','borrowScanStatusText','✓ Scanned from image: '+decoded,'success');}).catch(()=>setScanStatus('borrowScanStatus','borrowScanStatusText','Could not read QR. Try a clearer photo.','error')).finally(()=>event.target.value='');
}

function onBorrowerSelectChange(){
  if(document.getElementById('borrowerSelect').value){
    document.getElementById('borrowerNameInput').value='';
    document.getElementById('borrowerIdNumberInput').value='';
  }
}
function onBorrowerNameInput(){
  const name=document.getElementById('borrowerNameInput').value.trim();
  const idnum=document.getElementById('borrowerIdNumberInput').value.trim();
  if(name||idnum){
    document.getElementById('borrowerSelect').value='';
  }
}

function startReturnScanner(){
  if(returnScanning)return;
  const sel=document.getElementById('returnCameraSelect');
  const go=(camId)=>{
    if(!camId){setScanStatus('returnScanStatus','returnScanStatusText','No camera available.','error');return;}
    document.getElementById('returnReader').style.display='block';
    document.getElementById('returnStartBtn').style.display='none';
    document.getElementById('returnStopBtn').style.display='flex';
    setScanStatus('returnScanStatus','returnScanStatusText','Scanning…','scanning');
    returnQr.start(camId,{fps:15,qrbox:(w,h)=>{const s=Math.min(w,h)*.7;return{width:Math.floor(s),height:Math.floor(s)};},aspectRatio:1},
      decoded=>{document.getElementById('returnToolId').value=decoded;document.getElementById('clearReturnToolBtn').classList.add('visible');matchReturnToolCode(decoded);setScanStatus('returnScanStatus','returnScanStatusText','✓ Scanned: '+decoded,'success');stopReturnScanner();},()=>{}
    ).then(()=>returnScanning=true).catch(err=>{setScanStatus('returnScanStatus','returnScanStatusText','Cannot start: '+err,'error');resetReturnScannerUI();});
  };
  if(!sel.value){
    setScanStatus('returnScanStatus','returnScanStatusText','Requesting camera access…','scanning');
    loadCameras('returnCameraSelect').then(()=>go(sel.value)).catch(()=>setScanStatus('returnScanStatus','returnScanStatusText','Camera access denied.','error'));
    return;
  }
  go(sel.value);
}
function stopReturnScanner(){if(!returnScanning){resetReturnScannerUI();return;}returnQr.stop().then(()=>{returnScanning=false;resetReturnScannerUI();}).catch(()=>{returnScanning=false;resetReturnScannerUI();});}
function resetReturnScannerUI(){document.getElementById('returnReader').style.display='none';document.getElementById('returnStartBtn').style.display='flex';document.getElementById('returnStopBtn').style.display='none';}
function returnScanUploadedImage(event){
  const file=event.target.files[0];if(!file)return;
  if(returnScanning)stopReturnScanner();
  document.getElementById('returnUploadedQrImg').src=URL.createObjectURL(file);
  document.getElementById('returnUploadPreview').style.display='flex';
  setScanStatus('returnScanStatus','returnScanStatusText','Reading QR from image…','scanning');
  const fs=new Html5Qrcode('returnReader');
  fs.scanFile(file,true).then(decoded=>{document.getElementById('returnToolId').value=decoded;document.getElementById('clearReturnToolBtn').classList.add('visible');matchReturnToolCode(decoded);setScanStatus('returnScanStatus','returnScanStatusText','✓ Scanned from image: '+decoded,'success');}).catch(()=>setScanStatus('returnScanStatus','returnScanStatusText','Could not read QR. Try a clearer photo.','error')).finally(()=>event.target.value='');
}
let activeBorrowsCache=[]; // loaded whenever the Return page is opened

async function loadActiveBorrowsForReturn(){
  const sel=document.getElementById('returnSelect');
  sel.innerHTML='<option value="">Loading active borrows…</option>';
  try{
    const res=await apiFetch(`${API}/transactions.php?type=borrow&status=active&per_page=200`);
    activeBorrowsCache=(res.data||[]).filter(t=>(t.qty-t.qty_returned)>0);
    renderReturnSelectOptions(activeBorrowsCache);
  }catch(_){
    sel.innerHTML='<option value="">Failed to load — try again</option>';
  }
}

function renderReturnSelectOptions(list){
  const sel=document.getElementById('returnSelect');
  const prevValue=sel.value;
  if(!list.length){
    sel.innerHTML='<option value="">Nothing currently borrowed</option>';
    return;
  }
  sel.innerHTML='<option value="">Select the item being returned…</option>'+
    list.map(t=>{
      const outstanding=t.qty-t.qty_returned;
      const overdue=t.due_date && t.due_date<new Date().toISOString().slice(0,10);
      return `<option value="${t.id}">${t.tool_name} (${t.tool_code}) — ${t.borrower||'Unknown'} · Qty ${outstanding} · Due ${t.due_date||'—'}${overdue?' ⚠ overdue':''}</option>`;
    }).join('');
  if(list.some(t=>String(t.id)===prevValue)) sel.value=prevValue;
}

function onReturnSelectChange(){
  const id=document.getElementById('returnSelect').value;
  const hint=document.getElementById('returnSelectHint');
  if(!id){hint.textContent='';return;}
  const t=activeBorrowsCache.find(x=>String(x.id)===id);
  if(!t){hint.textContent='';return;}
  const outstanding=t.qty-t.qty_returned;
  document.getElementById('returnToolId').value=t.tool_code;
  document.getElementById('clearReturnToolBtn').classList.toggle('visible',true);
  const qtyInput=document.getElementById('returnQty');
  qtyInput.max=outstanding;
  qtyInput.value=outstanding;
  hint.textContent=`Borrowed by ${t.borrower||'Unknown'} · due ${t.due_date||'—'} · ${outstanding} unit(s) outstanding`;
}

// Called whenever the Tool Code field changes (typed, scanned, or from
// an uploaded QR image) — cross-checks it against the currently loaded
// list of active (not-yet-returned) borrows instead of trusting the
// typed/scanned code blindly. This is what prevents recording a return
// for a tool that was never actually borrowed.
function matchReturnToolCode(code){
  const hint=document.getElementById('returnSelectHint');
  code=(code||'').trim().toLowerCase();
  if(!code){document.getElementById('returnSelect').value='';hint.textContent='';return;}
  const matches=activeBorrowsCache.filter(t=>t.tool_code.toLowerCase()===code);
  if(matches.length===0){
    document.getElementById('returnSelect').value='';
    renderReturnSelectOptions(activeBorrowsCache);
    hint.textContent='⚠ No active borrow found for this tool code — nothing to return.';
  }else if(matches.length===1){
    renderReturnSelectOptions(activeBorrowsCache);
    document.getElementById('returnSelect').value=String(matches[0].id);
    onReturnSelectChange();
  }else{
    // Same tool code borrowed by more than one person at once — let
    // staff pick the right one instead of guessing.
    renderReturnSelectOptions(matches);
    document.getElementById('returnSelect').value='';
    hint.textContent=`⚠ ${matches.length} active borrows share this tool code — pick the correct one below.`;
  }
}

function onReturnToolIdInput(){
  const val=document.getElementById('returnToolId').value;
  document.getElementById('clearReturnToolBtn').classList.toggle('visible',val.length>0);
  matchReturnToolCode(val);
}
function clearReturnToolId(){
  document.getElementById('returnToolId').value='';
  document.getElementById('clearReturnToolBtn').classList.remove('visible');
  document.getElementById('returnUploadPreview').style.display='none';
  setScanStatus('returnScanStatus','returnScanStatusText','','');
  document.getElementById('returnSelect').value='';
  document.getElementById('returnSelectHint').textContent='';
  renderReturnSelectOptions(activeBorrowsCache);
}
let allToolsCache=[]; // loaded whenever the Borrow page is opened

async function loadToolsForBorrow(){
  const sel=document.getElementById('borrowToolSelect');
  sel.innerHTML='<option value="">Loading tools…</option>';
  try{
    const res=await apiFetch(`${API}/tools.php?per_page=200`);
    allToolsCache=(res.data||[]).filter(t=>t.is_active!=0); // retired tools can't be borrowed
    renderBorrowToolOptions();
  }catch(_){
    sel.innerHTML='<option value="">Failed to load — try again</option>';
  }
}

function renderBorrowToolOptions(){
  const sel=document.getElementById('borrowToolSelect');
  const prevValue=sel.value;
  if(!allToolsCache.length){sel.innerHTML='<option value="">No tools found</option>';return;}
  sel.innerHTML='<option value="">Select a tool…</option>'+
    allToolsCache.map(t=>
      `<option value="${t.code}" ${t.available<=0?'disabled':''}>${t.name} (${t.code}) — ${t.available>0?`${t.available} available`:'out of stock'}</option>`
    ).join('');
  if(allToolsCache.some(t=>t.code===prevValue)) sel.value=prevValue;
}

function onBorrowToolSelectChange(){
  const code=document.getElementById('borrowToolSelect').value;
  const hint=document.getElementById('borrowToolSelectHint');
  document.getElementById('borrowToolId').value=code;
  document.getElementById('clearBorrowToolBtn').classList.toggle('visible',code.length>0);
  if(!code){hint.textContent='';return;}
  const t=allToolsCache.find(x=>x.code===code);
  if(!t){hint.textContent='';return;}
  const qtyInput=document.getElementById('borrowQty');
  qtyInput.max=t.available;
  if(parseInt(qtyInput.value)>t.available) qtyInput.value=Math.max(1,t.available);
  hint.textContent=`${t.available} unit(s) available · ${t.category}`;
}

// Called whenever the Tool Code field changes (typed, scanned, or from
// an uploaded QR image) — keeps it in sync with the dropdown above so
// either input method lands you on the same selected tool.
function matchBorrowToolCode(code){
  const hint=document.getElementById('borrowToolSelectHint');
  code=(code||'').trim();
  if(!code){document.getElementById('borrowToolSelect').value='';hint.textContent='';return;}
  const t=allToolsCache.find(x=>x.code.toLowerCase()===code.toLowerCase());
  if(!t){
    document.getElementById('borrowToolSelect').value='';
    hint.textContent='⚠ No matching tool found for this code.';
  }else if(t.available<=0){
    document.getElementById('borrowToolSelect').value='';
    hint.textContent=`⚠ "${t.name}" is out of stock — nothing available to borrow.`;
  }else{
    document.getElementById('borrowToolSelect').value=t.code;
    onBorrowToolSelectChange();
  }
}

function onBorrowToolIdInput(){
  const val=document.getElementById('borrowToolId').value;
  document.getElementById('clearBorrowToolBtn').classList.toggle('visible',val.length>0);
  matchBorrowToolCode(val);
}
function clearBorrowToolId(){
  document.getElementById('borrowToolId').value='';
  document.getElementById('clearBorrowToolBtn').classList.remove('visible');
  document.getElementById('borrowUploadPreview').style.display='none';
  setScanStatus('borrowScanStatus','borrowScanStatusText','','');
  document.getElementById('borrowToolSelect').value='';
  document.getElementById('borrowToolSelectHint').textContent='';
}

/* ───────────────────────────────────────────────────────────
   10. BORROW / RETURN SUBMISSION
   POST api/transactions.php
   Borrow body: { type:'borrow', tool_code, borrower_id, due_date, notes }  → { success, data:{ id, txn_id, tool_name } }
   Return body: { type:'return', tool_code, condition, notes }             → { success, data:{ id, txn_id, tool_name, returned_by } }
─────────────────────────────────────────────────────────── */
async function handleBorrow(){
  const toolCode=document.getElementById('borrowToolId').value.trim();
  const borrowerId=document.getElementById('borrowerSelect').value;
  const borrowerText=document.getElementById('borrowerSelect').options[document.getElementById('borrowerSelect').selectedIndex]?.text||'';
  const borrowerName=document.getElementById('borrowerNameInput').value.trim();
  const borrowerIdNumber=document.getElementById('borrowerIdNumberInput').value.trim();
  const due=document.getElementById('borrowDueDate').value;
  const notes=document.getElementById('borrowNotes').value.trim();
  const qty=parseInt(document.getElementById('borrowQty').value)||1;
  if(!toolCode){showToast('Please scan a QR code or enter a Tool Code.','error');return;}
  if(!borrowerId && !borrowerName){showToast('Please select a borrower or enter a borrower name.','error');return;}
  if(!borrowerId && borrowerName && !borrowerIdNumber){showToast('Please enter an ID Number for the new borrower.','error');return;}
  if(!due){showToast('Please select a due date.','error');return;}

  setLoading('borrowSubmitBtn',true);
  try{
    let finalBorrowerId=borrowerId;
    let displayName=borrowerText.split('—')[0].trim();

    // New borrower typed in → create the record first, then use its id
    if(!finalBorrowerId){
    const borrowerType=document.getElementById('borrowerTypeInput').value || 'Guest';
    const newBorrower=await apiFetch(`${API}/borrowers.php`,{method:'POST',body:JSON.stringify({
    full_name:borrowerName,
    id_number:borrowerIdNumber,
    type:borrowerType
      
  })});
      finalBorrowerId=newBorrower.data.id;
      displayName=borrowerName;
      loadBorrowerSelect(); // refresh dropdown so they appear next time
    }

    const res=await apiFetch(`${API}/transactions.php`,{method:'POST',body:JSON.stringify({type:'borrow',tool_code:toolCode,borrower_id:parseInt(finalBorrowerId),due_date:due,notes,qty})});
    const d=res.data;
    showToast(`Borrow recorded — ${d.txn_id}`,'success');
    addActivityItem('borrowActivityFeed','borrow',`<strong>${d.tool_name||toolCode}</strong> borrowed by ${displayName}`,`${new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})} • Due: ${due}`);
    loadBorrowHistory();
    clearBorrowToolId();
    document.getElementById('borrowerSelect').value='';
    document.getElementById('borrowerNameInput').value='';
    document.getElementById('borrowerIdNumberInput').value='';
    document.getElementById('borrowerTypeInput').value='';
    document.getElementById('borrowNotes').value='';
    loadToolsForBorrow(); // availability just changed — refresh the dropdown
  }catch(_){}finally{setLoading('borrowSubmitBtn',false);}
}

async function handleReturn(){
  const borrowTxnId=document.getElementById('returnSelect').value;
  const toolCode=document.getElementById('returnToolId').value.trim();
  const condition=document.getElementById('returnCondition').value;
  const notes=document.getElementById('returnNotes').value.trim();
  const qty=parseInt(document.getElementById('returnQty').value)||1;
  if(!borrowTxnId){showToast('Select the borrowed item you\'re returning from the list — it must match an active borrow.','error');return;}
  setLoading('returnSubmitBtn',true);
  try{
    const res=await apiFetch(`${API}/transactions.php`,{method:'POST',body:JSON.stringify({type:'return',borrow_txn_id:borrowTxnId,tool_code:toolCode,condition,notes,qty})});
    const d=res.data;
    const condLabels={good:'Good condition',minor:'Minor wear',damaged:'Damaged'};
    showToast(`Return recorded — ${d.txn_id}`,'success');
    addActivityItem('returnActivityFeed','return',`<strong>${d.tool_name||toolCode}</strong> returned — ${condLabels[condition]||condition}`,`${new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})} • Notes: ${notes||'—'}`);
    loadReturnHistory();
    loadBorrowHistory(); // refresh borrow history status
    clearReturnToolId();
    document.getElementById('returneeName').value='';
    document.getElementById('returnCondition').value='good';
    document.getElementById('returnNotes').value='';
    loadActiveBorrowsForReturn(); // this item is (partially or fully) returned now — refresh the list
  }catch(_){}finally{setLoading('returnSubmitBtn',false);}
}

function addActivityItem(feedId, type, title, sub){
  const feed=document.getElementById(feedId);
  if(feed.querySelector('.empty-state'))feed.innerHTML='';
  const item=document.createElement('div');
  item.className='activity-item';
  item.innerHTML=`<div class="icon-circle ${type}"><i class="fas fa-sign-${type==='borrow'?'out':'in'}-alt"></i></div><div class="activity-info"><p>${title}</p><small>${sub}</small></div>`;
  feed.prepend(item);
}

/* ───────────────────────────────────────────────────────────
   11. TRANSACTION HISTORIES
   GET api/transactions.php?type=borrow&status=&page=
   GET api/transactions.php?type=return&condition=&page=
─────────────────────────────────────────────────────────── */
async function loadBorrowHistory(){
  const status=document.getElementById('borrowHistoryFilter').value;
  const params=new URLSearchParams({type:'borrow',status,per_page:50});
  try{
    const res=await apiFetch(`${API}/transactions.php?${params}`);
    const tbody=document.getElementById('borrowHistoryBody');
    const rows=res.data||[];
    if(!rows.length){tbody.innerHTML='<tr class="empty-row"><td colspan="7">No borrow transactions yet.</td></tr>';return;}
    tbody.innerHTML=rows.map(t=>`
      <tr>
        <td data-label="Txn ID"><code>${t.txn_id}</code></td>
        <td data-label="Tool Code">${t.tool_code}</td>
        <td data-label="Tool Name">${t.tool_name||'—'}</td>
        <td data-label="Borrower">${t.borrower||'—'}</td>
        <td data-label="Date & Time">${new Date(t.created_at).toLocaleString()}</td>
        <td data-label="Due Date">${t.due_date||'—'}</td>
        <td data-label="Status"><span class="status-badge ${t.status==='active'?'borrowed':'returned'}"><span class="status-dot"></span>${t.status==='active'?'Active':'Returned'}</span></td>
      </tr>`).join('');

    // Update sidebar badge
    const active=rows.filter(t=>t.status==='active').length;
    document.getElementById('borrowBadge').textContent=active;
    document.getElementById('borrowBadge').style.display=active>0?'inline-block':'none';
  }catch(_){}
}

async function loadReturnHistory(){
  const condition=document.getElementById('returnHistoryFilter').value;
  const params=new URLSearchParams({type:'return',condition,per_page:50});
  try{
    const res=await apiFetch(`${API}/transactions.php?${params}`);
    const tbody=document.getElementById('returnHistoryBody');
    const rows=res.data||[];
    if(!rows.length){tbody.innerHTML='<tr class="empty-row"><td colspan="6">No return transactions yet.</td></tr>';return;}
    const condBadge={good:'available',minor:'returned',damaged:'low-stock'};
    const condLabel={good:'Good',minor:'Minor Wear',damaged:'Damaged'};
    tbody.innerHTML=rows.map(t=>`
      <tr>
        <td data-label="Txn ID"><code>${t.txn_id}</code></td>
        <td data-label="Tool Code">${t.tool_code}</td>
        <td data-label="Returned By">${t.returned_by||t.borrower||'—'}</td>
        <td data-label="Date & Time">${new Date(t.created_at).toLocaleString()}</td>
        <td data-label="Condition"><span class="status-badge ${condBadge[t.condition]||'returned'}"><span class="status-dot"></span>${condLabel[t.condition]||t.condition}</span></td>
        <td data-label="Notes">${t.notes||'—'}</td>
      </tr>`).join('');
  }catch(_){}
}

/* ───────────────────────────────────────────────────────────
   12. REPORTS
   GET api/reports.php?type=inventory|transactions|borrowers|overdue → file download
   GET api/reports.php?type=monthly  → { monthly_labels[], monthly_borrows[], monthly_returns[] }
   GET api/reports.php?type=category → { labels[], values[] }
─────────────────────────────────────────────────────────── */
async function loadReports(){
  try{
    const [monthly, category] = await Promise.all([
      apiFetch(`${API}/reports.php?type=monthly`),
      apiFetch(`${API}/reports.php?type=category`)
    ]);
    const md=monthly.data;
    const cd=category.data;

    if(monthlyChart)monthlyChart.destroy();
    monthlyChart=new Chart(document.getElementById('monthlyChart').getContext('2d'),{
      type:'bar',
      data:{labels:md.labels||[],datasets:[{label:'Borrows',data:md.borrows||[],backgroundColor:CHART_COLORS.purple,borderRadius:8},{label:'Returns',data:md.returns||[],backgroundColor:CHART_COLORS.green,borderRadius:8}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{usePointStyle:true,padding:20}}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'}},x:{grid:{display:false}}}}
    });

    if(categoryChart)categoryChart.destroy();
    categoryChart=new Chart(document.getElementById('categoryChart').getContext('2d'),{
      type:'doughnut',
      data:{labels:cd.labels||[],datasets:[{data:cd.values||[],backgroundColor:[CHART_COLORS.purple,CHART_COLORS.violet,CHART_COLORS.purpleLight,CHART_COLORS.amber,CHART_COLORS.green],borderWidth:0,borderRadius:4}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{usePointStyle:true,padding:20}}},cutout:'70%'}
    });
  }catch(_){}
}

let currentReportType = null;

async function viewReport(type, title){
  currentReportType = type;
  document.getElementById('reportModalTitle').textContent = title;
  document.getElementById('reportModalCount').textContent = '';
  document.getElementById('reportTableHead').innerHTML = '';
  document.getElementById('reportTableBody').innerHTML = '<tr class="empty-row"><td><span class="spinner dark"></span> Loading…</td></tr>';
  openModal('reportModal');
  try{
    const res = await apiFetch(`${API}/reports.php?type=${type}`);
    const {columns, rows} = res.data;
    document.getElementById('reportTableHead').innerHTML = `<tr>${columns.map(c=>`<th>${c}</th>`).join('')}</tr>`;
    if(!rows.length){
      document.getElementById('reportTableBody').innerHTML = `<tr class="empty-row"><td colspan="${columns.length}">No data for this report.</td></tr>`;
    }else{
      // On-screen only (the CSV export hits reports.php directly and gets
      // the full untouched value) — a raw "2026-08-21 14:03:08" datetime
      // takes up a lot of horizontal room in a table, especially the
      // Transaction report which has three date-ish columns. Shorten
      // anything that looks like a MySQL date/datetime for display.
      const fmt=(v)=>{
        if(v===null||v==='') return '—';
        if(typeof v==='string' && /^\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}:\d{2})?$/.test(v)){
          const d=new Date(v.replace(' ','T'));
          if(!isNaN(d)) return v.includes(':')
            ? d.toLocaleString('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'})
            : d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
        }
        return v;
      };
      document.getElementById('reportTableBody').innerHTML = rows.map(r=>
        `<tr>${r.map((v,i)=>`<td data-label="${columns[i]}">${fmt(v)}</td>`).join('')}</tr>`
      ).join('');
    }
    document.getElementById('reportModalCount').textContent = `${rows.length} record${rows.length!==1?'s':''}`;
  }catch(_){
    document.getElementById('reportTableBody').innerHTML = '<tr class="empty-row"><td>Failed to load report.</td></tr>';
  }
}

function exportCurrentReport(){
  if(!currentReportType) return;
  window.open(`${API}/reports.php?type=${currentReportType}&download=1`,'_blank');
}

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

/* ───────────────────────────────────────────────────────────
   USER ACCOUNTS (Admin only — page/nav are hidden server-side
   for Staff, and api/users.php enforces it independently)
─────────────────────────────────────────────────────────── */
async function loadUsers(){
  const tbody=document.getElementById('usersTableBody');
  if(!tbody) return; // page not rendered for this role
  tbody.innerHTML='<tr class="empty-row"><td colspan="5"><span class="spinner dark"></span> Loading…</td></tr>';
  try{
    const res=await apiFetch(`${API}/users.php`);
    renderUsersTable(res.data||[]);
  }catch(_){tbody.innerHTML='<tr class="empty-row"><td colspan="5">Failed to load users.</td></tr>';}
}

function renderUsersTable(users){
  const tbody=document.getElementById('usersTableBody');
  if(!users.length){tbody.innerHTML='<tr class="empty-row"><td colspan="5">No users found.</td></tr>';return;}
  tbody.innerHTML=users.map(u=>`
    <tr>
      <td data-label="Name">${u.name}</td>
      <td data-label="Username"><code>${u.username}</code></td>
      <td data-label="Role"><span class="status-badge ${u.role==='Admin'?'low-stock':'available'}"><span class="status-dot"></span>${u.role}</span></td>
      <td data-label="Created">${new Date(u.created_at).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'})}</td>
      <td data-label="Actions"><div class="action-btns">
        <button class="action-btn delete" title="Delete" onclick="deleteUser(${u.id},'${u.name.replace(/'/g,"\\'")}')"><i class="fas fa-trash"></i></button>
      </div></td>
    </tr>`).join('');
}

function openAddUserModal(){
  document.getElementById('u_name').value='';
  document.getElementById('u_username').value='';
  document.getElementById('u_password').value='';
  document.getElementById('u_role').value='Staff';
  openModal('userModal');
}

async function saveUser(){
  const name=document.getElementById('u_name').value.trim();
  const username=document.getElementById('u_username').value.trim();
  const password=document.getElementById('u_password').value;
  const role=document.getElementById('u_role').value;
  if(!name||!username||!password){showToast('All fields are required.','error');return;}
  if(password.length<8){showToast('Password must be at least 8 characters.','error');return;}
  setLoading('saveUserBtn',true);
  try{
    await apiFetch(`${API}/users.php`,{method:'POST',body:JSON.stringify({name,username,password,role})});
    showToast('User created.');
    closeModal('userModal');
    loadUsers();
  }catch(_){}
  setLoading('saveUserBtn',false);
}

function deleteUser(id,name){
  document.getElementById('deleteConfirmText').textContent=`Delete user "${name}"? This cannot be undone.`;
  document.getElementById('confirmDeleteBtn').onclick=async()=>{
    try{
      await apiFetch(`${API}/users.php?id=${id}`,{method:'DELETE'});
      showToast('User deleted.');
      closeModal('confirmDeleteModal');
      loadUsers();
    }catch(_){}
  };
  openModal('confirmDeleteModal');
}

/* ───────────────────────────────────────────────────────────
   14. BOOTSTRAP
─────────────────────────────────────────────────────────── */
window.addEventListener('load',()=>{
  borrowQr=new Html5Qrcode('borrowReader');
  returnQr=new Html5Qrcode('returnReader');
  // Deliberately NOT calling loadCameras() here — Html5Qrcode.getCameras()
  // triggers the browser's camera permission prompt, and we only want
  // that happening when the user actually presses "Camera" (see
  // startBorrowScanner/startReturnScanner), not on every page load.
  document.getElementById('borrowCameraSelect').innerHTML='<option value="">Press \'Camera\' to enable</option>';
  document.getElementById('returnCameraSelect').innerHTML='<option value="">Press \'Camera\' to enable</option>';
  const due=new Date(Date.now()+7*24*60*60*1000);
  document.getElementById('borrowDueDate').valueAsDate=due;
  loadCurrentUser();
  loadDashboard();
  loadNotifications();
});
