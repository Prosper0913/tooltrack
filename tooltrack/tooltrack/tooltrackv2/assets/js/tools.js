/* ============================================================
   tools.js — Tools tab: inventory table, kebab actions menu, add/edit/retire, damaged disposal, QR label, CSV export.
   Plain (non-module) script: shares the global scope with the other
   assets/js files so inline onclick="" handlers keep working.
   ============================================================ */

let toolsCurrentPage=1;
let toolSearchTimer=null;
let toolsLoadRequest=0;
let toolEditOriginalQuantity=null;
let toolEditVersion=null;

const TOOLS_PER_PAGE=10;

async function loadTools(){
  clearTimeout(toolSearchTimer);
  const request=++toolsLoadRequest;
  const status=document.getElementById('toolStatusFilter').value;
  const category=document.getElementById('toolCategoryFilter').value;
  const search=document.getElementById('toolSearchFilter').value;
  const params=new URLSearchParams({page:toolsCurrentPage,per_page:TOOLS_PER_PAGE,status,category,search});
  if(!window.ttLiveSync?.rendering)document.getElementById('toolsTableBody').innerHTML='<tr class="empty-row"><td colspan="11"><span class="spinner dark"></span> Loading…</td></tr>';
  try{
    const res=await apiFetch(`${API}/tools.php?${params}`);
    if(request!==toolsLoadRequest)return;
    const last=Math.max(1,Math.ceil((res.total||0)/TOOLS_PER_PAGE));
    if(toolsCurrentPage>last){toolsCurrentPage=last;return loadTools();}
    renderToolsTable(res.data||[]);
    const totalPages=Math.ceil((res.total||0)/TOOLS_PER_PAGE);
    renderPagination('toolsPaginationInfo','toolsPaginationBtns',toolsCurrentPage,totalPages,TOOLS_PER_PAGE,res.total||0,p=>{toolsCurrentPage=p;loadTools();});
  }catch(_){if(request!==toolsLoadRequest)return;document.getElementById('toolsTableBody').innerHTML='<tr class="empty-row"><td colspan="11">Failed to load tools.</td></tr>';}
}

const CAT_ICONS={Utensils:'fa-solid fa-utensils',Cookware:'fas fa-fire-burner','Measuring Tools':'fas fa-ruler',Accessories:'fas fa-toolbox',Dinnerware:'fas fa-plate-wheat',Cutleries:'fas fa-utensils',Glassware:'fas fa-martini-glass'};

// Each unit is in exactly one state:
//   Total Quantity = Good Condition + Minor Wear + Damaged + Missing + Current Loans
//   Available      = Good Condition + Minor Wear   (what can be lent right now)
// "Borrowed Items" is HISTORICAL (all borrow transactions of the tool); "Current Loans" is what is out now.
function renderToolsTable(tools){
  closeToolMenu(); toolMenuCache.clear();
  const tbody=document.getElementById('toolsTableBody');
  if(!tools.length){tbody.innerHTML='<tr class="empty-row"><td colspan="11">No tools found.</td></tr>';return;}
  const isAdmin = window.CURRENT_ROLE === 'Admin';
  const n=v=>Number(v)||0;
  const warn=(v,color)=>n(v)>0?`<strong style="color:${color}">${n(v)}</strong>`:'0';
  tools.forEach(t=>toolMenuCache.set(+t.id,t));
  tbody.innerHTML=tools.map(t=>`
    <tr${t.is_active==0?' style="opacity:.55"':''}>
      <td data-label="Tool"><div class="tool-item">
        <div class="tool-icon"><i class="${CAT_ICONS[t.category]||'fas fa-box'}"></i></div>
        <div class="tool-info"><h4>${escapeHtml(t.name)}${t.is_active==0?' <span class="status-badge low-stock" style="margin-left:6px"><span class="status-dot"></span>Retired</span>':''}</h4><span title="${escapeHtml(t.description||'')}">${escapeHtml(t.category)}</span>
          <div style="margin-top:6px"><span class="status-badge ${escapeHtml(t.status)}"><span class="status-dot"></span>${statusLabel(t.status)}</span></div></div>
      </div></td>
      <td data-label="Code"><code>${escapeHtml(t.code)}</code></td>
      <td data-label="Total Quantity">${n(t.quantity)}</td>
      <td data-label="Good Condition">${n(t.good_qty)}</td>
      <td data-label="Minor Wear">${n(t.minor_qty)}</td>
      <td data-label="Damaged">${warn(t.damaged_qty,'var(--danger,#dc2626)')}</td>
      <td data-label="Missing">${warn(t.missing_qty,'#d97706')}</td>
      <td data-label="Unit Price">₱${Number(t.price||0).toFixed(2)}</td>
      <td data-label="Active Borrowers" title="Different people who currently have this tool out (open loans only)">${n(t.active_borrowers)}</td>
      <td data-label="Current Loans">${n(t.current_loans)}</td>
      <td data-label="Actions" class="tool-actions-td">${stockActionButton(t)}<button type="button" class="kebab-btn" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${escapeHtml(t.name)}" title="Actions" onclick="openToolMenu(event,${+t.id})"><i class="fas fa-ellipsis-vertical"></i></button></td>
    </tr>`).join('');
}

// ── Tools table: single ⋮ actions menu ────────────────────────
// One floating menu (position:fixed, so the scrolling table never clips it) shared by all rows.
// Same actions and same permission rules as the old inline buttons:
//   QR + Edit: everyone · Retire/Reactivate: Admin.
const toolMenuCache=new Map();

let toolMenuEl=null, toolMenuBtn=null;

function toolMenuItems(t){
  const isAdmin=window.CURRENT_ROLE==='Admin';
  const items=[
    {icon:'fa-clock-rotate-left',label:'Written Off / Lost History',run:()=>{additionalReportStates.loss_history.search=t.code;viewReport('loss_history','Written Off / Lost History');}},
    {icon:'fa-qrcode',label:'QR Code',run:()=>showQR(t.code,t.name)},
    {icon:'fa-edit',label:'Edit',run:()=>editTool(+t.id)}
  ];
  if(isAdmin){
    const reactivate=t.is_active==0;
    items.push({sep:true},{icon:'fa-power-off',label:reactivate?'Reactivate':'Retire',danger:!reactivate,run:()=>toggleToolActive(+t.id,reactivate,t.name)});
    if(+t.damaged_qty>0)items.push({sep:true},{icon:'fa-dumpster',label:'Dispose Tool',danger:true,run:()=>openToolDisposal(+t.id)});
  }
  return items;
}

function closeToolMenu(refocus){
  if(toolMenuEl) toolMenuEl.hidden=true;
  if(toolMenuBtn){ toolMenuBtn.setAttribute('aria-expanded','false'); if(refocus) toolMenuBtn.focus(); }
  toolMenuBtn=null;
}

function openToolMenu(ev,id){
  ev.stopPropagation();
  const btn=ev.currentTarget, t=toolMenuCache.get(+id);
  if(!t) return;
  if(toolMenuBtn===btn){ closeToolMenu(true); return; }      // second click toggles it shut
  closeToolMenu();
  if(!toolMenuEl){
    toolMenuEl=document.createElement('div');
    toolMenuEl.className='tool-menu'; toolMenuEl.setAttribute('role','menu'); toolMenuEl.hidden=true;
    document.body.appendChild(toolMenuEl);
  }
  const items=toolMenuItems(t);
  toolMenuEl.innerHTML=items.map((it,i)=>it.sep?'<div class="tool-menu-sep" role="separator"></div>'
    :`<button type="button" role="menuitem" class="tool-menu-item${it.danger?' danger':''}" data-i="${i}"><i class="fas ${it.icon}"></i><span>${escapeHtml(it.label)}</span></button>`).join('');
  toolMenuEl._items=items;
  toolMenuEl.hidden=false;
  toolMenuBtn=btn; btn.setAttribute('aria-expanded','true');
  // place under the button, right-aligned; flip above / clamp when it would leave the viewport
  const r=btn.getBoundingClientRect(), w=toolMenuEl.offsetWidth, h=toolMenuEl.offsetHeight, m=8;
  let left=Math.min(Math.max(m,r.right-w),window.innerWidth-w-m);
  let top=r.bottom+4; if(top+h>window.innerHeight-m) top=Math.max(m,r.top-h-4);
  toolMenuEl.style.left=left+'px'; toolMenuEl.style.top=top+'px';
  const first=toolMenuEl.querySelector('.tool-menu-item'); if(first) first.focus();
}

document.addEventListener('click',e=>{
  if(!toolMenuEl||toolMenuEl.hidden) return;
  const item=e.target.closest&&e.target.closest('.tool-menu-item');
  if(item&&toolMenuEl.contains(item)){
    const it=toolMenuEl._items[+item.dataset.i];
    closeToolMenu();
    if(it&&it.run) it.run();
  } else if(!toolMenuEl.contains(e.target)) closeToolMenu();
});

document.addEventListener('keydown',e=>{
  if(!toolMenuEl||toolMenuEl.hidden) return;
  if(e.key==='Escape'){ e.preventDefault(); closeToolMenu(true); return; }
  if(e.key==='Tab'){ closeToolMenu(); return; }
  if(e.key==='ArrowDown'||e.key==='ArrowUp'){
    e.preventDefault();
    const els=[...toolMenuEl.querySelectorAll('.tool-menu-item')], i=els.indexOf(document.activeElement);
    els[(i+(e.key==='ArrowDown'?1:-1)+els.length)%els.length].focus();
  }
});

window.addEventListener('resize',()=>closeToolMenu());

window.addEventListener('scroll',e=>{ if(toolMenuEl&&!toolMenuEl.hidden&&!toolMenuEl.contains(e.target)) closeToolMenu(); },true);

let toolDisposalState=null,disposalHistoryPage=1;
function newDisposalToken(){return crypto.randomUUID?crypto.randomUUID().replaceAll('-',''):Array.from(crypto.getRandomValues(new Uint8Array(24)),b=>b.toString(16).padStart(2,'0')).join('');}
async function openToolDisposal(id,caseId=null,quantity=1){
  if(window.CURRENT_ROLE!=='Admin'){showToast('Only Admin may dispose damaged inventory.','error');return;}
  try{
    const [toolResult,caseResult]=await Promise.all([apiFetch(`${API}/tools.php?id=${id}`),apiFetch(`${API}/tools.php?disposal_cases=1&tool_id=${id}`)]);
    const t=toolResult.data,cases=caseResult.data||[];if(!t||+t.damaged_qty<1){showToast('This tool has no damaged units eligible for disposal.','warning');return;}
    toolDisposalState={tool:t,cases,token:newDisposalToken(),busy:false};
    document.getElementById('disposeToolId').value=String(t.id);
    document.getElementById('disposeToolSummary').innerHTML=`<strong>${escapeHtml(t.name)}</strong> (<code>${escapeHtml(t.code)}</code>)<br>${+t.damaged_qty} damaged unit(s) eligible. Available, borrowed and missing units cannot be disposed here.`;
    const qty=document.getElementById('disposeToolQty');qty.min='1';qty.max=String(t.damaged_qty);qty.value='1';
    document.getElementById('disposeToolReason').value='';
    const picker=document.getElementById('disposeToolCase');
    picker.innerHTML='<option value="">Historical damaged stock without a case</option>'+cases.map(c=>`<option value="${+c.id}">Case #${+c.id} · ${escapeHtml(c.responsible_name_snapshot||'Borrower unavailable')} · ${+c.eligible_quantity} eligible units</option>`).join('');
    picker.value=caseId?String(caseId):'';
    picker.onchange=syncDisposalEligibility;syncDisposalEligibility();qty.value=String(quantity);
    document.getElementById('disposeToolError').textContent='';openModal('toolDisposalModal');
  }catch(e){showToast(e.message||'Could not load tool disposal details. Refresh and retry.','error');}
}
function syncDisposalEligibility(){
 const s=toolDisposalState;if(!s)return;
 const c=s.cases.find(c=>String(c.id)===document.getElementById('disposeToolCase').value);
 s.eligible=c?+c.eligible_quantity:Math.max(0,+s.tool.damaged_qty-s.cases.filter(c=>c.workflow_mode!=='legacy').reduce((n,c)=>n+(+c.eligible_quantity),0));
 document.getElementById('disposeToolQty').max=String(s.eligible);
 document.getElementById('disposeToolSummary').textContent=`${s.tool.name} (${s.tool.code}): ${s.eligible} eligible damaged units${c?' in case #'+c.id: ' without an unresolved case'}. Disposal reduces total owned; availability stays unchanged.`;
}
function reviewToolDisposal(){
  const s=toolDisposalState;if(!s||s.busy)return;
  const qtyText=document.getElementById('disposeToolQty').value,qty=Number(qtyText),reason=document.getElementById('disposeToolReason').value.trim(),error=document.getElementById('disposeToolError');
  if(!/^\d+$/.test(qtyText)||!Number.isSafeInteger(qty)||qty<1||qty>s.eligible){error.textContent='Enter a whole quantity within the selected eligible balance.';return;}
  if(!reason||reason.length>500){error.textContent='Enter a reason of 1 to 500 characters.';return;}
  s.payload={action:'dispose_damaged',id:+s.tool.id,quantity:qty,reason,case_id:document.getElementById('disposeToolCase').value||null,request_token:s.token};
  const selected=s.cases.find(c=>String(c.id)===String(s.payload.case_id));if(selected)s.payload.case_version=+selected.payment_version;
  document.getElementById('disposeConfirmText').textContent=`Permanently remove ${qty} damaged unit(s) of ${s.tool.name} (${s.tool.code}) from active inventory? Borrower and incident history will be preserved. Reason: ${reason}`;
  closeModal('toolDisposalModal');openModal('toolDisposalConfirmModal');
}
async function confirmToolDisposal(){
  const s=toolDisposalState;if(!s||s.busy||!s.payload)return;
  s.busy=true;const btn=document.getElementById('confirmToolDisposalBtn');btn.disabled=true;document.getElementById('disposeConfirmError').textContent='';
  try{
    const result=(await apiFetch(`${API}/tools.php`,{method:'POST',body:JSON.stringify(s.payload)})).data;
    showToast(result.duplicate?'Disposal was already recorded.':'Damaged units disposed and recorded.','success');
    toolDisposalState=null;closeModal('toolDisposalConfirmModal');loadTools();
    if(typeof rpRefreshInventory==='function')rpRefreshInventory();
    if(document.getElementById('toolDisposalHistoryModal').classList.contains('active'))loadToolDisposalHistory(1);
  }catch(e){document.getElementById('disposeConfirmError').textContent=e.message||'Disposal was not confirmed. Retry this same request.';}
  finally{s.busy=false;btn.disabled=false;}
}
async function openToolDisposalHistory(){
  if(window.CURRENT_ROLE!=='Admin')return;
  document.getElementById('disposeHistorySearch').value='';document.getElementById('disposeHistoryFrom').value='';document.getElementById('disposeHistoryTo').value='';
  openModal('toolDisposalHistoryModal');await loadToolDisposalHistory(1);
}
async function loadToolDisposalHistory(page=1){
  disposalHistoryPage=Math.max(1,page);const body=document.getElementById('disposeHistoryBody');if(!body)return;
  body.innerHTML='<tr><td colspan="8">Loading disposal history...</td></tr>';
  const q=new URLSearchParams({disposed_history:'1',page:disposalHistoryPage,per_page:25,search:document.getElementById('disposeHistorySearch').value.trim(),from:document.getElementById('disposeHistoryFrom').value,to:document.getElementById('disposeHistoryTo').value});
  try{
    const d=await apiFetch(`${API}/tools.php?${q}`);body.innerHTML=d.data.rows.length?d.data.rows.map(x=>`<tr><td>${escapeHtml(x.created_at)}</td><td><b>${escapeHtml(x.tool_name_snapshot)}</b><small>${escapeHtml(x.tool_code_snapshot)}</small></td><td>${+x.quantity}</td><td>${escapeHtml(x.reason)}</td><td>${x.linked_case_id?'#'+(+x.linked_case_id):'—'}</td><td>${escapeHtml(x.borrower_name||'—')}</td><td>${escapeHtml(x.performed_by)}</td><td>${+x.stock_entry_id||'—'}</td></tr>`).join(''):'<tr><td colspan="8">No disposals match these filters.</td></tr>';
    document.getElementById('disposeHistoryCount').textContent=`${d.data.total} disposal record(s)`;document.getElementById('disposeHistoryPage').textContent=`Page ${d.data.page} of ${Math.max(1,Math.ceil(d.data.total/d.data.per_page))}`;
    document.getElementById('disposeHistoryPrev').disabled=d.data.page<=1;document.getElementById('disposeHistoryNext').disabled=d.data.page*d.data.per_page>=d.data.total;
  }catch(e){body.innerHTML=`<tr><td colspan="8">${escapeHtml(e.message||'Could not load disposal history.')}</td></tr>`;}
}

// ── Admin: move units out of Damaged (repaired + cleared) or Missing (found again).
let conditionTool=null;

async function openConditionModal(id){
  try{
    conditionTool=(await apiFetch(`${API}/tools.php?id=${id}`)).data;
  }catch(_){return;}
  const t=conditionTool, d=+t.damaged_qty||0, m=+t.missing_qty||0;
  if(!d&&!m){showToast('This tool has no damaged or missing units.','warning');return;}
  document.getElementById('cond_toolId').value=t.id;
  document.getElementById('cond_summary').innerHTML=`<strong>${escapeHtml(t.name)}</strong> (${escapeHtml(t.code)}) — ${d} damaged, ${m} missing. Damaged units stay out of lending until you mark them repaired.`;
  const act=document.getElementById('cond_action');
  act.innerHTML=(d?'<option value="repair">Repaired and cleared for lending</option>':'')+(m?'<option value="recover">Missing unit found again</option>':'');
  document.getElementById('cond_qty').value=1;
  document.getElementById('cond_notes').value='';
  onConditionActionChange();
  openModal('conditionModal');
}

function onConditionActionChange(){
  const t=conditionTool; if(!t) return;
  const action=document.getElementById('cond_action').value;
  const max=action==='repair'?(+t.damaged_qty||0):(+t.missing_qty||0);
  const qty=document.getElementById('cond_qty'); qty.max=max; if(+qty.value>max) qty.value=max||1;
  const opts=[['good','Good condition'],['minor','Minor wear']];
  if(action==='recover') opts.push(['damaged','Damaged (needs repair)']);
  document.getElementById('cond_to').innerHTML=opts.map(([v,l])=>`<option value="${v}">${l}</option>`).join('');
}

async function saveCondition(){
  const id=parseInt(document.getElementById('cond_toolId').value);
  const action=document.getElementById('cond_action').value;
  const qty=parseInt(document.getElementById('cond_qty').value);
  const to=document.getElementById('cond_to').value;
  const notes=document.getElementById('cond_notes').value.trim();
  if(!id||!action){return;}
  if(!qty||qty<1){showToast('Quantity must be at least 1.','error');return;}
  setLoading('saveConditionBtn',true);
  try{
    await apiFetch(`${API}/tools.php`,{method:'PATCH',body:JSON.stringify({id,action,qty,to,notes})});
    showToast(action==='repair'?`${qty} unit(s) cleared for lending.`:`${qty} missing unit(s) recorded as found.`);
    closeModal('conditionModal');
    loadTools();
  }catch(_){}finally{setLoading('saveConditionBtn',false);}
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

function statusLabel(s){return{available:'Available',borrowed:'Borrowed','low-stock':'Low Stock','out-of-stock':'Out of Stock'}[s]||s;}

function searchToolsAsYouType(){
  clearTimeout(toolSearchTimer);
  ++toolsLoadRequest;
  toolsCurrentPage=1;
  toolSearchTimer=setTimeout(()=>loadTools(),250);
}

function applyToolFilters(){toolsCurrentPage=1;loadTools();}

function clearToolFilters(){
  document.getElementById('toolStatusFilter').value='';
  document.getElementById('toolCategoryFilter').value='';
  document.getElementById('toolSearchFilter').value='';
  toolsCurrentPage=1; loadTools();
}

let toolSkuPreviewRequest=0;
async function updateToolSkuPreview(){
  const request=++toolSkuPreviewRequest;
  if(document.getElementById('editToolId').value)return;
  const input=document.getElementById('t_code'),category=document.getElementById('t_category').value;
  const hint=document.getElementById('toolSkuHint');
  input.value='';input.placeholder=category?'Generating SKU…':'Select a category';
  hint.textContent='Select a category to preview the SKU. The final SKU is assigned when you save.';
  if(!category)return;
  try{
    const res=await apiFetch(`${API}/tools.php?sku_preview=1&category=${encodeURIComponent(category)}`);
    if(request!==toolSkuPreviewRequest||document.getElementById('editToolId').value||document.getElementById('t_category').value!==category)return;
    input.value=res.data.code;
    hint.textContent='SKU preview. The final unique SKU is assigned when you save.';
  }catch(_){if(request===toolSkuPreviewRequest&&!document.getElementById('editToolId').value){input.placeholder='Generated when saved';hint.textContent='The server will generate the final SKU when you save.';}}
}
function openAddToolModal(){
  toolEditOriginalQuantity=null;
  document.getElementById('editToolId').value='';
  document.getElementById('toolModalTitle').textContent='Add New Tool';
  document.getElementById('saveToolBtn').textContent='Add Tool';
['t_name','t_code','t_qty','t_min','t_price','t_desc'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('t_category').value='';
  updateToolSkuPreview();
  openModal('toolModal');
}

async function editTool(id){
  try{
    const res=await apiFetch(`${API}/tools.php?id=${id}`);
    const t=res.data;
    toolEditVersion=t.edit_version;
    document.getElementById('editToolId').value=t.id;
    document.getElementById('toolModalTitle').textContent='Edit Tool';
    document.getElementById('saveToolBtn').textContent='Save Changes';
    document.getElementById('t_name').value=t.name;
    ++toolSkuPreviewRequest;
    document.getElementById('t_code').value=t.code;
    document.getElementById('toolSkuHint').textContent='This SKU is permanent. Category and stock changes keep the same SKU.';
    document.getElementById('t_category').value=t.category;
    document.getElementById('t_qty').value=t.quantity;
    toolEditOriginalQuantity=Number(t.quantity);
    document.getElementById('t_min').value=t.min_stock;
    document.getElementById('t_desc').value=t.description||'';
    document.getElementById('t_price').value=t.price??0;
    openModal('toolModal');
  }catch(_){}
}

async function saveTool(){
  const id=document.getElementById('editToolId').value;
const body={
  name:document.getElementById('t_name').value.trim(),
  category:document.getElementById('t_category').value,
  quantity:parseInt(document.getElementById('t_qty').value)||0,
  min_stock:parseInt(document.getElementById('t_min').value)||0,
  price:parseFloat(document.getElementById('t_price').value),
  description:document.getElementById('t_desc').value.trim()
};
  // Code is system-generated on create; on edit it's carried along
  // unchanged (the field is read-only) so the PUT endpoint — which
  // validates immutability — gets the same code back.
  if(id) body.code=document.getElementById('t_code').value.trim();
  if(!body.name||!body.category||!body.quantity||!body.min_stock){showToast('Please fill in all required fields.','error');return;}
  if(isNaN(body.price)||body.price<0){showToast('Please enter a valid Unit Price (0 or more).','error');return;}
  if(id){body.id=parseInt(id);body.edit_version=toolEditVersion;if(toolEditOriginalQuantity!==null)body.previous_quantity=toolEditOriginalQuantity;}
  setLoading('saveToolBtn',true);
  try{
    const saved=await apiFetch(`${API}/tools.php`,{method:id?'PUT':'POST',body:JSON.stringify(body)});
    showToast(id?`"${body.name}" updated.`:`"${body.name}" added — SKU ${saved.data.code}.`,'success');
    closeModal('toolModal');
    refreshToolStockViews();
  }catch(_){}finally{setLoading('saveToolBtn',false);}
}

let currentQrToolLabel = '', currentQrToolCode = '';

function showQR(code, name){
  currentQrToolCode = code;
  currentQrToolLabel = name || '';
  const container = document.getElementById('qrCodeContainer');
  container.innerHTML = '';
  // The scanner (startBorrowScanner) drops the raw
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
    <html><head><title>${escapeHtml(currentQrToolCode)} QR Label</title>
    <style>
      body{font-family:sans-serif;text-align:center;padding:24px}
      img{width:200px;height:200px}
      h3{margin:12px 0 2px}
      p{margin:0;color:#666;font-family:monospace}
    </style></head>
    <body>
      <img src="${img}">
      <h3>${escapeHtml(currentQrToolLabel)}</h3>
      <p>${escapeHtml(currentQrToolCode)}</p>
      <script>window.onload=()=>{window.print();}<\/script>
    </body></html>
  `);
  w.document.close();
}

async function exportToolsCSV(){
  try{
    showToast('Preparing export…','warning');
    const all=await fetchAllPages(`${API}/tools.php`,{perPage:200,maxPages:100});   // up to 20,000 tools
    const rows=[['Name','Code','Category','Total Quantity','Good Condition','Minor Wear','Damaged','Missing','Available','Min Stock','Unit Price','Status','Borrowed Items (units, all time)','Borrow Transactions (all time)','Current Loans']];
    all.forEach(t=>rows.push([t.name,t.code,t.category,t.quantity,t.good_qty,t.minor_qty,t.damaged_qty,t.missing_qty,t.available,t.min_stock,t.price,statusLabel(t.status),t.units_borrowed_total,t.times_borrowed,t.current_loans]));
    downloadCSV(rows,'tools_inventory.csv');
    showToast(`Exported ${all.length} tool(s).`,'success');
  }catch(_){}
}

// Reuse one modal and the existing tool record. Token remains stable after uncertain network errors.
let stockTool=null,stockToken='',stockBusy=false,stockLoadSequence=0,stockReview=null;
function stockActionButton(tool){
 if(window.CURRENT_ROLE!=='Admin')return '';
 return `<button type="button" class="tool-stock-action" onclick="openAddStock(${Number(tool.id)})" ${tool.is_active==0?'disabled title="Reactivate before adding stock"':''}><i class="fas fa-plus" aria-hidden="true"></i> Add Stock</button>`;
}
function newStockToken(){return window.crypto?.randomUUID?crypto.randomUUID():('stock'+Date.now().toString(36)+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2));}
async function openAddStock(id){
 if(stockBusy)return;const request=++stockLoadSequence;
 try{
  const tool=(await apiFetch(`${API}/tools.php?id=${id}`)).data;
  if(request!==stockLoadSequence)return;
  stockTool=tool;stockToken=newStockToken();
  document.getElementById('as_name').textContent=tool.name;
  document.getElementById('as_sku').textContent=tool.code;
  document.getElementById('as_total').textContent=tool.quantity;
  document.getElementById('as_available').textContent=tool.available;
  document.getElementById('as_qty').value='0';document.getElementById('as_notes').value='';
  editStockAddition();openModal('addStockModal');document.getElementById('as_qty').focus();
 }catch(_){}
}
function discardStockAddition(){
 ++stockLoadSequence;stockTool=null;stockToken='';stockReview=null;
 document.getElementById('as_qty').value='0';document.getElementById('as_notes').value='';
}
function setStockAddition(qty){
 if(stockBusy)return;
 const input=document.getElementById('as_qty'),current=input.value===''?0:Number(input.value);
 input.value=current+qty;updateStockPreview();
}
function stockAdditionQuantity(){const raw=document.getElementById('as_qty').value;return /^[1-9]\d*$/.test(raw)?Number(raw):NaN;}
function updateStockPreview(){
 if(!stockTool)return;
 const raw=document.getElementById('as_qty').value;
 const qty=raw===''||raw==='0'?0:stockAdditionQuantity();
 const previewValid=Number.isSafeInteger(qty)&&qty>=0&&qty<=100000&&Number(stockTool.quantity)+qty<=100000;
 const valid=previewValid&&qty>0;
 const enabled=valid&&stockTool.is_active!=0;
 document.getElementById('as_new_total').textContent=previewValid?Number(stockTool.quantity)+qty:'—';
 document.getElementById('as_new_available').textContent=previewValid?Number(stockTool.available)+qty:'—';
 document.getElementById('as_error').textContent=stockTool.is_active==0?'Reactivate this tool before adding stock.':valid?'':'Enter a positive whole quantity; the updated total cannot exceed 100,000.';
 const button=document.getElementById('as_save');button.disabled=stockBusy||!enabled;
 if(!stockBusy)button.textContent='Review Stock';
 return enabled;
}
function stockReviewStep(review){
 document.getElementById('as_entry').hidden=review;
 document.getElementById('as_review').hidden=!review;
 document.getElementById('as_save').hidden=review;
 document.getElementById('as_back').hidden=!review;
 document.getElementById('as_submit').hidden=!review;
}
function editStockAddition(){
 if(stockBusy)return;
 stockReview=null;stockReviewStep(false);updateStockPreview();
 document.getElementById('as_qty').focus();
}
function reviewStockAddition(){
 if(stockBusy||!stockTool||!updateStockPreview())return;
 stockReview={quantity:stockAdditionQuantity(),remarks:document.getElementById('as_notes').value.trim(),
  expected_stock:{quantity:Number(stockTool.quantity),available:Number(stockTool.available)}};
 renderStockReview();stockReviewStep(true);
 document.getElementById('as_error').textContent='';
 document.getElementById('as_submit').focus();
}
function renderStockReview(){
 const r=stockReview;
 document.getElementById('as_review_qty').textContent=`+${r.quantity}`;
 document.getElementById('as_review_total').textContent=`${r.expected_stock.quantity} → ${r.expected_stock.quantity+r.quantity}`;
 document.getElementById('as_review_available').textContent=`${r.expected_stock.available} → ${r.expected_stock.available+r.quantity}`;
 document.getElementById('as_review_notes').textContent=r.remarks||'None';
 document.getElementById('as_submit').disabled=stockTool.is_active==0||r.expected_stock.quantity+r.quantity>100000;
}
function refreshToolStockViews(){
 const work=[loadTools()];
 if(typeof loadDashboard==='function')work.push(loadDashboard());
 if(typeof loadToolsForBorrow==='function')work.push(loadToolsForBorrow());
 return Promise.allSettled(work);
}
async function saveAddedStock(){
 if(stockBusy||!stockTool||!stockReview||document.getElementById('as_submit').disabled)return;
 const body={action:'add_stock',id:Number(stockTool.id),...stockReview,request_token:stockToken};
 const toolName=stockTool.name;
 stockBusy=true;const controls=[...document.querySelectorAll('#addStockModal input,#addStockModal button')];controls.forEach(el=>el.disabled=true);
 document.getElementById('as_submit').textContent='Submitting…';
 document.getElementById('as_error').textContent='';
 try{
  const result=(await apiFetch(`${API}/tools.php`,{method:'POST',body:JSON.stringify(body)})).data;
  if(result.stock_changed){
   stockTool=result.tool;
   stockReview.expected_stock={quantity:Number(stockTool.quantity),available:Number(stockTool.available)};
   document.getElementById('as_total').textContent=stockTool.quantity;
   document.getElementById('as_available').textContent=stockTool.available;
   renderStockReview();
   document.getElementById('as_error').textContent='Stock changed since your review. Review the refreshed figures before submitting again.';
   return;
  }
  showToast(`Added ${body.quantity} unit${body.quantity===1?'':'s'} to ${toolName}.`,'success');
  closeModal('addStockModal');await refreshToolStockViews();
 }catch(error){
  // Keep the token so retrying an uncertain response cannot add stock twice.
  document.getElementById('as_error').textContent=error.message||'Stock could not be saved. Please try again.';
 }
 finally{
  stockBusy=false;controls.forEach(el=>el.disabled=false);
  document.getElementById('as_submit').textContent='Submit Stock';
  if(stockReview)renderStockReview();
 }
}
// Keep page/modal scrolling from stepping a focused Tools number field.
// Blur before the browser's wheel default action, without cancelling normal scrolling.
document.addEventListener('wheel',()=>{
 const input=document.activeElement;
 if(input instanceof HTMLInputElement && input.type==='number' && input.closest('#toolsPage,#toolModal,#addStockModal')) input.blur();
},{capture:true,passive:true});
