/* ============================================================
   borrowers.js — Borrowers tab: directory (server-side paging/sort/filter), add/edit, history modal, Update Sections,
   CSV export, and the shared borrower typeahead used by the Borrow and Return pages.
   Plain (non-module) script: shares the global scope with the other
   assets/js files so inline onclick="" handlers keep working.
   ============================================================ */

/* ───────────────────────────────────────────────────────────
   7. BORROWERS DIRECTORY (CRUD)
   GET  api/borrowers.php?page=&type=&search=
   POST api/borrowers.php  body: { full_name, id_number, type, email, phone }
   PUT  api/borrowers.php  body: { id, full_name, id_number, type, email, phone }
   DELETE api/borrowers.php?id=N
─────────────────────────────────────────────────────────── */
let borrowersCurrentPage=1;

let BORROWERS_PER_PAGE=10;

                       // changed by the "Rows" dropdown
let borrowersSort={key:'full_name',dir:'asc'};

   // server-side sort (whitelisted in borrowers.php)
let borrowersReqSeq=0;

                           // guards against out-of-order responses

// Paging, searching, filtering and sorting all happen in the database
// (borrowers.php), so the browser only ever holds ONE page of rows no matter
// how many borrowers exist.
function borrowerFilterValues(){
  return {
    type:document.getElementById('borrowerTypeFilter').value,
    status:document.getElementById('borrowerStatusFilter').value,
    course:document.getElementById('borrowerCourseFilter').value,
    section:document.getElementById('borrowerSectionFilter').value,
    search:document.getElementById('borrowerSearchFilter').value.trim()
  };
}

// ── Course/Strand + Section filters ─────────────────────────────
// Options come from the same enrollment data as the Add/Edit form (+ FPST and TVL always).
// Picking a course fills the Section list with that course's sections only.
let borrowerFilterInit=false;

async function refreshBorrowerFilterOptions(){
  const cSel=document.getElementById('borrowerCourseFilter');
  const sSel=document.getElementById('borrowerSectionFilter');
  if(!cSel||!sSel) return;
  const keepCourse=cSel.value, keepSection=sSel.value;
  await loadEnrollmentRows();
  const courses=BORROWER_COURSES;
  cSel.innerHTML='<option value="">All</option>'+courses.map(c=>`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
  cSel.value=courses.includes(keepCourse)?keepCourse:'';
  fillBorrowerSectionFilter(keepSection);
}

function fillBorrowerSectionFilter(selected=''){
  const cSel=document.getElementById('borrowerCourseFilter');
  const sSel=document.getElementById('borrowerSectionFilter');
  const course=cSel.value;
  if(!course){ sSel.innerHTML='<option value="">All</option>'; sSel.disabled=true; return; }
  const names=[...collectSections(course)].map(([n])=>n);
  sSel.innerHTML='<option value="">All</option>'+names.map(n=>`<option value="${escapeHtml(n)}">${escapeHtml(n)}</option>`).join('');
  sSel.value=names.includes(selected)?selected:'';
  sSel.disabled=false;
}

function onBorrowerCourseFilterChange(){
  fillBorrowerSectionFilter('');      // a section from the old course makes no sense in the new one
  applyBorrowerFilters();
}

async function loadBorrowers(){
  if(!borrowerFilterInit){ borrowerFilterInit=true; refreshBorrowerFilterOptions(); }
  const tbody=document.getElementById('borrowerTableBody');
  const params=new URLSearchParams({page:borrowersCurrentPage,per_page:BORROWERS_PER_PAGE,sort:borrowersSort.key,dir:borrowersSort.dir,...borrowerFilterValues()});
  const seq=++borrowersReqSeq;
  if(!window.ttLiveSync?.rendering)tbody.innerHTML='<tr class="empty-row"><td colspan="10"><span class="spinner dark"></span> Loading…</td></tr>';
  try{
    const res=await apiFetch(`${API}/borrowers.php?${params}`);
    if(seq!==borrowersReqSeq) return;            // a newer click/keystroke already took over
    const total=res.total||0;
    const totalPages=Math.ceil(total/BORROWERS_PER_PAGE);
    if(totalPages>0 && borrowersCurrentPage>totalPages){   // e.g. a filter shrank the list while on page 9
      borrowersCurrentPage=totalPages;
      return loadBorrowers();
    }
    renderBorrowersTable(res.data||[]);
    renderPagination('borrowersPaginationInfo','borrowersPaginationBtns',borrowersCurrentPage,totalPages,BORROWERS_PER_PAGE,total,p=>{borrowersCurrentPage=p;loadBorrowers();});
    updateBorrowerSortIndicators();
  }catch(_){
    if(seq===borrowersReqSeq) tbody.innerHTML='<tr class="empty-row"><td colspan="10">Failed to load borrowers.</td></tr>';
  }
}

function sortBorrowers(key){
  if(borrowersSort.key===key) borrowersSort.dir=borrowersSort.dir==='asc'?'desc':'asc';
  else borrowersSort={key,dir:'asc'};
  borrowersCurrentPage=1;
  loadBorrowers();
}

function updateBorrowerSortIndicators(){
  document.querySelectorAll('#borrowersPage th[data-sort]').forEach(th=>{
    const i=th.querySelector('i'); if(!i) return;
    const on=th.dataset.sort===borrowersSort.key;
    i.className='fas '+(on?(borrowersSort.dir==='asc'?'fa-sort-up':'fa-sort-down'):'fa-sort');
    i.style.opacity=on?'1':'.4';
  });
}

// Green = all of this borrower's active items are on schedule; red =
// at least one is overdue; neutral gray = nothing currently out. This
// replaces the old logic, which just turned green the instant
// active_borrows hit 0 — useful for "nothing out" but useless for
// "are they late," which is the thing staff actually needs to catch.
function activeBorrowsBadge(b){
  const n=b.active_borrows||0;
  const overdue=b.overdue_count||0;
  if(n===0) return `<span class="status-badge neutral"><span class="status-dot"></span>No active items</span>`;
  if(overdue>0) return `<span class="status-badge low-stock"><span class="status-dot"></span>${overdue} overdue of ${n}</span>`;
  return `<span class="status-badge available"><span class="status-dot"></span>${n} item${n!==1?'s':''} · On Time</span>`;
}

function renderBorrowersTable(borrowers){
  const tbody=document.getElementById('borrowerTableBody');
  if(!borrowers.length){borrowerMenuCache.clear();closeBorrowerMenu();tbody.innerHTML='<tr class="empty-row"><td colspan="10">No borrowers found.</td></tr>';return;}
  const isAdmin = window.CURRENT_ROLE === 'Admin';
  // Names are free text, so everything that comes from the database goes through escapeHtml().
  borrowerMenuCache.clear();
  borrowers.forEach(b=>borrowerMenuCache.set(Number(b.id),b));
  closeBorrowerMenu();
  tbody.innerHTML=borrowers.map(b=>`
    <tr${b.is_active==0?' style="opacity:.55"':''}>
      <td data-label="Borrower"><div class="tool-item" style="cursor:pointer" onclick="viewBorrowerHistory(${b.id})" title="View history">
        <div class="user-avatar" style="width:40px;height:40px;font-size:13px">${escapeHtml(getInitials(b.full_name))}</div>
        <div class="tool-info"><h4>${escapeHtml(b.full_name)}${b.is_active==0?' <span class="status-badge low-stock" style="margin-left:6px"><span class="status-dot"></span>Inactive</span>':''}${(b.flagged_count>0||b.open_missing_count>0)?' <i class="fas fa-flag" title="Has damaged/missing history or an open missing-tool case" style="color:var(--danger,#dc2626);font-size:11px;margin-left:6px"></i>':''}</h4><span>${escapeHtml(b.type)}</span></div>
      </div></td>
      <td data-label="ID Number"><code>${escapeHtml(b.id_number)}</code></td>
      <td data-label="Type">${escapeHtml(b.type)}</td>
      <td data-label="Course">${escapeHtml(b.course||'—')}</td>
      <td data-label="Section">${escapeHtml(b.section_name||'—')}</td>
      <td data-label="Year Level">${escapeHtml(b.year_level||'—')}</td>
      <td data-label="Contact">${escapeHtml(b.phone||b.email||'—')}</td>
      <td data-label="Active Borrows">${activeBorrowsBadge(b)}</td>
      <td data-label="Total Borrows">${Number(b.total_borrows)||0}</td>
      <td data-label="Actions"><button type="button" class="kebab-btn" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${escapeHtml(b.full_name)}" title="Actions" onclick="openBorrowerMenu(event,${Number(b.id)})"><i class="fas fa-ellipsis-vertical"></i></button></td>
    </tr>`).join('');
}

// ── Borrowers Directory: three-dot actions menu ────────────────
// Only the presentation changes. Existing history, edit and active-status handlers remain intact.
const borrowerMenuCache=new Map();
let borrowerMenuEl=null, borrowerMenuBtn=null;

function closeBorrowerMenu(refocus=false){
  if(borrowerMenuEl) borrowerMenuEl.hidden=true;
  if(borrowerMenuBtn){
    borrowerMenuBtn.setAttribute('aria-expanded','false');
    if(refocus&&borrowerMenuBtn.isConnected) borrowerMenuBtn.focus();
  }
  borrowerMenuBtn=null;
}

function openBorrowerMenu(event,id){
  event.stopPropagation();
  const btn=event.currentTarget, b=borrowerMenuCache.get(Number(id));
  if(!b) return;
  if(borrowerMenuBtn===btn){closeBorrowerMenu(true);return;}
  closeBorrowerMenu();
  if(!borrowerMenuEl){
    borrowerMenuEl=document.createElement('div');
    borrowerMenuEl.className='tool-menu'; // Reuse the Tools tab's existing menu styling.
    borrowerMenuEl.setAttribute('role','menu');
    borrowerMenuEl.hidden=true;
    document.body.appendChild(borrowerMenuEl);
  }
  const items=[
    {icon:'fa-clock-rotate-left',label:'View History',run:()=>viewBorrowerHistory(Number(b.id))}
  ];
  if(window.CURRENT_ROLE==='Admin'){
    items.push({icon:'fa-edit',label:'Edit',run:()=>editBorrower(Number(b.id))});
    const activate=b.is_active==0;
    items.push({sep:true},{icon:'fa-power-off',label:activate?'Activate':'Deactivate',danger:!activate,run:()=>toggleBorrowerActive(Number(b.id),activate)});
  }
  borrowerMenuEl.innerHTML=items.map((item,i)=>item.sep?'<div class="tool-menu-sep" role="separator"></div>':
    `<button type="button" role="menuitem" class="tool-menu-item${item.danger?' danger':''}" data-i="${i}"><i class="fas ${item.icon}"></i><span>${item.label}</span></button>`).join('');
  borrowerMenuEl._items=items;
  borrowerMenuEl.hidden=false;
  borrowerMenuBtn=btn;
  btn.setAttribute('aria-expanded','true');
  const r=btn.getBoundingClientRect(), w=borrowerMenuEl.offsetWidth, h=borrowerMenuEl.offsetHeight, margin=8;
  borrowerMenuEl.style.left=Math.min(Math.max(margin,r.right-w),Math.max(margin,window.innerWidth-w-margin))+'px';
  borrowerMenuEl.style.top=(r.bottom+4+h>window.innerHeight-margin?Math.max(margin,r.top-h-4):r.bottom+4)+'px';
  borrowerMenuEl.querySelector('.tool-menu-item')?.focus();
}

document.addEventListener('click',event=>{
  if(!borrowerMenuEl||borrowerMenuEl.hidden) return;
  const target=event.target.closest?.('.tool-menu-item');
  if(target&&borrowerMenuEl.contains(target)){
    const item=borrowerMenuEl._items[Number(target.dataset.i)];
    closeBorrowerMenu();
    item?.run?.();
  }else if(!borrowerMenuEl.contains(event.target)) closeBorrowerMenu();
});
document.addEventListener('keydown',event=>{
  if(!borrowerMenuEl||borrowerMenuEl.hidden) return;
  if(event.key==='Escape'){event.preventDefault();closeBorrowerMenu(true);}
  else if(event.key==='Tab') closeBorrowerMenu();
  else if(event.key==='ArrowDown'||event.key==='ArrowUp'){
    event.preventDefault();
    const buttons=[...borrowerMenuEl.querySelectorAll('.tool-menu-item')];
    const current=buttons.indexOf(document.activeElement);
    buttons[(current+(event.key==='ArrowDown'?1:-1)+buttons.length)%buttons.length]?.focus();
  }
});
window.addEventListener('resize',()=>closeBorrowerMenu());
window.addEventListener('scroll',event=>{
  if(borrowerMenuEl&&!borrowerMenuEl.hidden&&!borrowerMenuEl.contains(event.target)) closeBorrowerMenu();
},true);

// ── Borrower history/detail modal ──────────────────────────────
// Database timestamps use the application's local wall time; do not reinterpret them as UTC.
function bhFormatDate(value, includeTime=false){
  if(!value) return '—';
  const date=parseDbDate(value);
  if(Number.isNaN(date.getTime())) return '—';
  const pad=n=>String(n).padStart(2,'0');
  const numeric=`${pad(date.getMonth()+1)}/${pad(date.getDate())}/${date.getFullYear()}`;
  if(!includeTime) return numeric;
  const hour=date.getHours();
  return `${numeric} ${hour%12||12}:${pad(date.getMinutes())} ${hour>=12?'PM':'AM'}`;
}

let bhBorrowerId=0,bhGeneration=0;
function loadBorrowerProfileHistory(page=1){return viewBorrowerHistory(bhBorrowerId,page,false);}
async function viewBorrowerHistory(id,page=1,reset=true){
 bhBorrowerId=id;const generation=++bhGeneration;
 if(reset){document.getElementById('bhSearch').value='';document.getElementById('bhFilter').value='all';}
  document.getElementById('bhName').textContent='Loading…';
  document.getElementById('bhMeta').textContent='';
  document.getElementById('bhStats').innerHTML='';
  document.getElementById('bhIncidents').textContent='Loading incidents…';
  document.getElementById('bhHistoryBody').innerHTML='<tr class="empty-row"><td colspan="7"><span class="spinner dark"></span> Loading…</td></tr>';
  openModal('borrowerHistoryModal');
  try{
    const query=new URLSearchParams({id,history:1,history_page:page,history_search:document.getElementById('bhSearch').value,history_status:document.getElementById('bhFilter').value});
    const res=await apiFetch(`${API}/borrowers.php?${query}`);if(generation!==bhGeneration)return;
    const {borrower,history,stats}=res.data;
    const incidents=res.data.incidents;
    document.getElementById('bhIncidents').innerHTML=incidents.rows.length?incidents.rows.map(r=>{
      const handler=r.source==='missing'?`openMissingCase(${Number(r.id)})`:r.source==='return'?`rpViewReturn(${Number(r.id)})`:`rpView(${Number(r.id)})`;
      return `<div class="rp-history-item"><b>${escapeHtml(r.case_id)} · ${escapeHtml(r.tool_name||'Historical tool')}</b><small>${r.incident==='lost'?'Missing':'Damaged'} · ${Number(r.quantity)} unit(s) · ${escapeHtml(r.inventory_status)}</small><button type="button" class="card-btn secondary" onclick="closeModal('borrowerHistoryModal');${handler}">View Incident</button></div>`;
    }).join('')+(incidents.total>incidents.rows.length?`<button type="button" class="card-btn secondary" onclick="showBorrowerIncidents()">View all borrower incidents (${Number(incidents.total)})</button>`:''):'<p class="rp-note">No damage or loss incidents.</p>';
    document.getElementById('bhMeta').dataset.borrowerNumber=borrower.id_number;

    document.getElementById('bhName').textContent=borrower.full_name;
    document.getElementById('bhMeta').textContent=
      `${borrower.type} • ${borrower.id_number}${borrower.course?` • ${borrower.course} ${borrower.section_name||''}`:''}${borrower.is_active==0?' • Inactive':''}`;

    const statChip=(label,val,cls)=>`<span class="status-badge ${cls}" style="margin-right:8px"><span class="status-dot"></span>${val} ${label}</span>`;
    document.getElementById('bhStats').innerHTML=
      statChip('borrowing transactions',stats.total_borrows,'neutral')+statChip('active units',stats.active_units,'borrowed')+statChip('damaged incidents',stats.damaged_incidents,'low-stock')+statChip('missing incidents',stats.missing_incidents,'missing')+
      statChip('returned on time',stats.on_time,'available')+
      statChip('returned late',stats.late,stats.late>0?'low-stock':'available')+
      statChip('overdue now',stats.overdue_now,stats.overdue_now>0?'borrowed':'available')+
      statChip('damaged/minor wear on return',stats.damaged_or_minor,stats.damaged_or_minor>0?'low-stock':'available');

    const pg=res.data.history_pagination;renderPagination('bhPagerInfo','bhPagerBtns',pg.page,Math.max(1,Math.ceil(pg.total/pg.per_page)),pg.per_page,pg.total,p=>loadBorrowerProfileHistory(p));
    if(!history.length){
      document.getElementById('bhHistoryBody').innerHTML='<tr class="empty-row"><td colspan="7">No borrow/return activity yet.</td></tr>';
      return;
    }
    const flagBadge=(r)=>{
      if(r.type==='return') return `<span class="status-badge ${(r.condition==='damaged'||r.condition==='missing')?'low-stock':(r.condition==='minor'?'low-stock':'available')}"><span class="status-dot"></span>${r.condition||'—'}</span>`;
      if(r.flag==='late') return '<span class="status-badge low-stock"><span class="status-dot"></span>Returned Late</span>';
      if(r.flag==='overdue') return '<span class="status-badge borrowed"><span class="status-dot"></span>Overdue</span>';
      if(r.flag==='on_time') return '<span class="status-badge available"><span class="status-dot"></span>On Time</span>';
      return `<span class="status-badge available"><span class="status-dot"></span>${r.status}</span>`;
    };
    document.getElementById('bhHistoryBody').innerHTML=history.map(r=>`
      <tr><td data-label="Transaction ID"><code>${escapeHtml(r.txn_id)}</code><small>${r.type==='borrow'?'Borrow':'Return'}</small></td>
      <td data-label="Tool">${escapeHtml(r.tool_name||'—')}<small>${escapeHtml(r.tool_code||'')}</small></td><td data-label="Quantity">${Number(r.qty)}${r.type==='borrow'&&Number(r.outstanding)>0?`<small>${Number(r.outstanding)} outstanding</small>`:''}</td>
      <td data-label="Borrowed Date">${escapeHtml(bhFormatDate(r.borrowed_date,true))}</td><td data-label="Due Date">${escapeHtml(bhFormatDate(r.due_date?(r.due_date+' '+(r.due_time||'23:59:59')):null,true))}</td>
      <td data-label="Returned Date">${r.returned_at?escapeHtml(bhFormatDate(r.returned_at,true)):'—'}</td><td data-label="Status">${r.type==='borrow'&&r.status==='active'?loanStateBadge(r):flagBadge(r)}</td></tr>`).join('');
  }catch(_){
    document.getElementById('bhHistoryBody').innerHTML='<tr class="empty-row"><td colspan="7">Failed to load history.</td></tr>';
    document.getElementById('bhIncidents').textContent='Could not load incidents.';
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

function applyBorrowerFilters(){
  clearTimeout(borrowerFilterTimer);
  borrowersCurrentPage=1;
  loadBorrowers();
}

// Live search: wait until typing pauses so every keystroke isn't a request.
let borrowerFilterTimer=null;

function onBorrowerFilterInput(){
  clearTimeout(borrowerFilterTimer);
  borrowerFilterTimer=setTimeout(applyBorrowerFilters,350);
}

function changeBorrowerPageSize(){
  BORROWERS_PER_PAGE=parseInt(document.getElementById('borrowerPageSize').value)||10;
  borrowersCurrentPage=1;
  loadBorrowers();
}

function clearBorrowerFilters(){
  clearTimeout(borrowerFilterTimer);
  document.getElementById('borrowerTypeFilter').value='';
  document.getElementById('borrowerStatusFilter').value='';
  document.getElementById('borrowerCourseFilter').value='';
  fillBorrowerSectionFilter('');
  document.getElementById('borrowerSearchFilter').value='';
  borrowersSort={key:'full_name',dir:'asc'};
  borrowersCurrentPage=1;
  loadBorrowers();
}

// Rows behind the Course/Section dropdowns of the Add/Edit Borrower form.
let borrowerFormRows=[];

// Departments / strands a borrower can belong to. Always offered in the Add/Edit
// Borrower form, even before anyone is enrolled in them. Add more names here later.
const BORROWER_COURSES=['FPST','TVL','TESDA'];

// While editing: the borrower's real type (locked after creation). null = adding.
let borrowerEditType=null;

async function loadEnrollmentRows(){
  try{ borrowerFormRows=(await apiFetch(`${API}/borrower_enrollments.php`)).data||[]; }
  catch(_){ borrowerFormRows=[]; }
}

async function loadBorrowerFormOptions(selectedCourse='', selectedSection='', selectedYear=''){
  const courseSel=document.getElementById('b_course');
  const sectionSel=document.getElementById('b_section');
  if(!courseSel||!sectionSel)return;
  await loadEnrollmentRows();
  const courses=[...new Set([...BORROWER_COURSES,...borrowerFormRows.map(r=>r.course).filter(Boolean)])];
  if(selectedCourse && !courses.includes(selectedCourse)) courses.push(selectedCourse);
  courseSel.innerHTML='<option value="">None</option>'+courses.map(c=>`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
  courseSel.value=selectedCourse||'';
  fillBorrowerSections(selectedSection);
  fillBorrowerYear(selectedYear);
}

// Academic level is independent of the section's name.
const BORROWER_YEARS=['1st Year','2nd Year','Grade 11','Grade 12'];
function fillBorrowerYear(selected=''){
  const sel=document.getElementById('b_year');if(!sel)return;
  const course=document.getElementById('b_course').value.toUpperCase();
  const years=course==='TESDA'?[]:course==='TVL'?['Grade 11','Grade 12']:['1st Year','2nd Year'];
  if(course==='TESDA')selected='';
  const legacy=selected&&!years.includes(selected)?`<option value="${escapeHtml(selected)}">${escapeHtml(selected)}</option>`:'';
  sel.innerHTML=`<option value="">${course==='TESDA'?'Not applicable for TESDA':'Select year / grade'}</option>`+years.map(y=>`<option value="${y}">${y}</option>`).join('')+legacy;
  sel.value=selected||'';
}
// Section names offered for a course → Map(name → CMS id, 0 = typed name).
// The name is the identity: typed sections all share cms_section_id 0.
function collectSections(course, extraNames=[]){
  const byName=new Map();
  borrowerFormRows.forEach(r=>{
    if(r.course!==course||!r.section_name)return;
    const id=+r.cms_section_id||0;
    if(!byName.has(r.section_name)||(!byName.get(r.section_name)&&id)) byName.set(r.section_name,id);
  });
  if(String(course).trim().toUpperCase()==='FPST') FPST_SECTIONS.forEach(n=>{ if(!byName.has(n)) byName.set(n,0); });
  extraNames.forEach(n=>{ if(n&&!byName.has(n)) byName.set(n,0); });
  return new Map([...byName].sort((x,y)=>x[0].localeCompare(y[0],undefined,{numeric:true})));
}

function fillBorrowerSections(selectedName=''){
  const sel=document.getElementById('b_section');
  const course=document.getElementById('b_course').value;
  const byName=collectSections(course, course?[selectedName]:[]);
  // Every course (FPST, TVL, …) lets the admin type a new section.
  const canAdd=!!course;
  sel.innerHTML='<option value="">Select section</option>'+[...byName].map(([n,id])=>`<option value="${escapeHtml(n)}" data-id="${id}">${escapeHtml(n)}</option>`).join('')
    +(canAdd?'<option value="__new__">＋ Add new section…</option>':'');
  sel.value=course?(selectedName||''):'';
}

// Enables/disables Type, Course and Section to match the mode:
//  • adding   → picking a course makes the person a Student
//  • editing  → Type is locked; only Students have a Course/Section
function applyBorrowerFormState(){
  const courseSel=document.getElementById('b_course');
  const typeSel=document.getElementById('b_type');
  const sectionSel=document.getElementById('b_section');
  const hint=document.getElementById('b_typeHint');
  const yearSel=document.getElementById('b_year');
  document.querySelector('label[for="b_section"]')?.replaceChildren(document.createTextNode(courseSel.value==='TESDA'?'Section / Batch (optional)':'Section *'));
  const studentOpt=[...typeSel.options].find(o=>o.value==='Student');
  // "disabled" (not just "hidden") so every browser, incl. Safari/iOS, really removes it from the list.
  const allowStudent=(on)=>{ if(studentOpt){ studentOpt.disabled=!on; studentOpt.hidden=!on; } };
  const setHint=(t)=>{ if(hint){ hint.textContent=t; hint.style.display=t?'block':'none'; } };

  if(borrowerEditType){
    allowStudent(true);                       // an existing Student must still display as Student
    typeSel.value=borrowerEditType;
    typeSel.disabled=true;
    const isStudent=borrowerEditType==='Student';
    courseSel.disabled=!isStudent;
    sectionSel.disabled=!isStudent||!courseSel.value;
    if(yearSel) yearSel.disabled=sectionSel.disabled||courseSel.value==='TESDA';
    setHint('Type can only be set when a borrower is first created.');
    return;
  }
  courseSel.disabled=false;
  if(courseSel.value){
    // Course chosen -> this person is a Student, set automatically and locked.
    allowStudent(true);
    typeSel.value='Student'; typeSel.disabled=true; sectionSel.disabled=false; if(yearSel) yearSel.disabled=courseSel.value==='TESDA';
    setHint('Type is set to Student automatically when a program is chosen.');
  }else{
    // Course = None -> a Student is not possible; only Faculty / Staff are offered.
    allowStudent(false);
    if(typeSel.value==='Student') typeSel.value='';
    typeSel.disabled=false; sectionSel.disabled=true; if(yearSel) yearSel.disabled=true;
    setHint('No program selected: choose Faculty or Staff. To add a Student, pick a program above.');
  }
}

//GI USOB NI NAHO GUNAD DIRI KUTOB LINE ---
const FPST_SECTIONS = ['1-A','1-B','1-C','1-D','1-E','2-A','2-B','2-C','2-D','2-E'];

function isFpstSelected(courseId = 'b_course') {
  const courseSelect = document.getElementById(courseId);
  const val = (courseSelect.value || '').trim().toUpperCase();
  const txt = (courseSelect.selectedOptions[0]?.textContent || '').trim().toUpperCase();
  return val === 'FPST' || txt === 'FPST';
}

function syncCustomSections(courseId = 'b_course', sectionId = 'b_section') {
  const sectionSelect = document.getElementById(sectionId);

  sectionSelect.querySelectorAll('option[data-custom="1"]').forEach(o => o.remove());
  if (!isFpstSelected(courseId)) return;

  FPST_SECTIONS.forEach(sec => {
    if ([...sectionSelect.options].some(o => o.textContent.trim() === sec)) return;
    const opt = document.createElement('option');
    opt.value = sec;
    opt.textContent = sec;
    opt.dataset.custom = '1';
    sectionSelect.appendChild(opt);
  });
}

function setBorrowerTypeOption(type){
  const typeSel=document.getElementById('b_type');
  typeSel.querySelectorAll('option[data-extra]').forEach(o=>o.remove());
  if(type && ![...typeSel.options].some(o=>o.value===type)){   // e.g. an existing "Guest"
    const o=new Option(type,type); o.dataset.extra='1'; typeSel.add(o);
  }
}

/* ── Name fields: Last / First / Middle ──────────────────────────────────────
   The database keeps ONE column (full_name). The three form fields are combined into it as
   "First M. Last" — the same format the CMS roster sync writes (reformat_name() in index.php) —
   and split back apart when a borrower is opened for editing. */
let borrowerNameOrig=null;   // {full,last,first,middle} of the borrower being edited

function composeBorrowerName(last,first,middle){
  const clean=s=>String(s||'').replace(/\s+/g,' ').trim();
  last=clean(last); first=clean(first); middle=clean(middle);
  const mi=middle ? middle.charAt(0).toLocaleUpperCase()+'.' : '';     // middle name → initial, like the roster sync
  return [first,mi,last].filter(Boolean).join(' ');
}

function splitBorrowerName(full){
  const s=String(full||'').replace(/\s+/g,' ').trim();
  const isInit=w=>/^\p{L}\.?$/u.test(w);
  if(s.includes(',')){                                                  // older entries typed as "Last, First Middle"
    const i=s.indexOf(','), w=s.slice(i+1).trim().split(' ').filter(Boolean);
    let middle=''; if(w.length>1 && isInit(w[w.length-1])) middle=w.pop().replace('.','');
    return {last:s.slice(0,i).trim(),first:w.join(' '),middle};
  }
  const w=s.split(' ').filter(Boolean);
  const mi=w.findIndex((x,i)=>i>0 && i<w.length-1 && isInit(x));        // "First M. Last"
  if(mi>0) return {first:w.slice(0,mi).join(' '),middle:w[mi].replace('.',''),last:w.slice(mi+1).join(' ')};
  if(w.length<2) return {first:w[0]||'',middle:'',last:''};
  return {first:w.slice(0,-1).join(' '),middle:'',last:w[w.length-1]};  // "First Last" (last word = last name)
}

async function openAddBorrowerModal(){
  borrowerEditType=null;
  document.getElementById('editBorrowerId').value='';
  document.getElementById('borrowerModalTitle').textContent='Add New Borrower';
  document.getElementById('saveBorrowerBtn').textContent='Add Borrower';
  borrowerNameOrig=null;
  ['b_last','b_first','b_middle','b_idnum','b_phone'].forEach(id=>document.getElementById(id).value='');
  setBorrowerTypeOption('');
  document.getElementById('b_type').value='';

  await loadBorrowerFormOptions();
  applyBorrowerFormState();
  openModal('borrowerModal');
}

// Edit: name, ID, phone and — for Students — course/section (e.g. 1-C → 2-C
// when they move up a year). Type stays locked after creation.
async function editBorrower(id){
  try{
    // borrowers.php returns the borrower's current course/section with it.
    const b=(await apiFetch(`${API}/borrowers.php?id=${id}`)).data;
    borrowerEditType=b.type||'';

    document.getElementById('editBorrowerId').value=b.id;
    document.getElementById('borrowerModalTitle').textContent='Edit Borrower';
    document.getElementById('saveBorrowerBtn').textContent='Save Changes';
    const nm=splitBorrowerName(b.full_name);
    borrowerNameOrig={full:b.full_name||'',...nm};
    document.getElementById('b_last').value=nm.last;
    document.getElementById('b_first').value=nm.first;
    document.getElementById('b_middle').value=nm.middle;
    document.getElementById('b_idnum').value=b.id_number||'';
    document.getElementById('b_phone').value=b.phone||'';
    document.getElementById('b_typeHint').style.display='block';
    setBorrowerTypeOption(borrowerEditType);

    await loadBorrowerFormOptions(b.course||'', b.section_name||'', b.year_level||'');
    applyBorrowerFormState();
    openModal('borrowerModal');
  }catch(_){ }
}

async function saveBorrower(){
  const id          = document.getElementById('editBorrowerId').value;
  const lastName    = document.getElementById('b_last').value.trim();
  const firstName   = document.getElementById('b_first').value.trim();
  const middleName  = document.getElementById('b_middle').value.trim();
  let   full_name   = composeBorrowerName(lastName,firstName,middleName);
  // Editing and the name fields were not changed → send the stored name back exactly as it was.
  if(id && borrowerNameOrig && full_name===composeBorrowerName(borrowerNameOrig.last,borrowerNameOrig.first,borrowerNameOrig.middle)) full_name=borrowerNameOrig.full;
  const rawIdNumber = document.getElementById('b_idnum').value.trim();
  const phone       = document.getElementById('b_phone').value.trim();
  const course      = document.getElementById('b_course').value;
  const sectionSel  = document.getElementById('b_section');
  const sectionName = sectionSel.value;
  const type        = borrowerEditType || (course ? 'Student' : document.getElementById('b_type').value);

  if(!lastName){ showToast('Last name is required.','error'); return; }
  if(!firstName){ showToast('First name is required.','error'); return; }
  if(!rawIdNumber){ showToast('ID number is required.','error'); return; }
  if(!type){ showToast('Please select a type.','error'); return; }
  if(type==='Student' && !course){ showToast('Select a Program for this Student.','error'); return; }
  if(course && course!=='TESDA' && !sectionName){ showToast('Please select a section.','error'); return; }

  // Same normalization as handleBorrow(): strip dashes/spaces
  const id_number = rawIdNumber.replace(/[\s-]/g,'');
  if(type==='Student' && !(course==='TESDA'?/^\d{1,50}$/:/^\d{8,10}$/).test(id_number)){
    showToast(course==='TESDA'?'TESDA Student ID must be 1 to 50 digits (numbers only).':'Student ID must be 8 to 10 digits (numbers only).','error');
    return;
  }

  if(type==='Student'){
    const year=document.getElementById('b_year').value;
    const allowed=course==='TVL'?['Grade 11','Grade 12']:['1st Year','2nd Year'];
    if(!BORROWER_COURSES.includes(course)||sectionName==='__new__'||(course!=='TESDA'&&!sectionName)){showToast('Select a Program and its required academic details.','error');return;}
    if(course!=='TESDA'&&!allowed.includes(year)){showToast(course==='TVL'?'Choose Grade 11 or Grade 12 for TVL.':'Choose Year 1 or Year 2 for FPST.','error');return;}
  }
  const body = { full_name, id_number, type, phone };
  if(course){
    body.course = course;
    body.section_name = sectionName;
    const yr = document.getElementById('b_year').value;
    if(yr && yr!=='__other__') body.year_level = yr;
    const cmsId = parseInt(sectionSel.selectedOptions[0]?.dataset.id) || 0;   // 0 for typed names like "1-A"
    if(cmsId) body.cms_section_id = cmsId;
  }
  if(id) body.id = parseInt(id);

  setLoading('saveBorrowerBtn', true);
  try{
    await apiFetch(`${API}/borrowers.php`, { method: id ? 'PUT' : 'POST', body: JSON.stringify(body) });
    showToast(id ? `"${full_name}" updated.` : `"${full_name}" added.`);
    closeModal('borrowerModal');
    loadBorrowers();
    refreshBorrowerFilterOptions();
    loadBorrowerSelect();
  }catch(_){
  }finally{
    setLoading('saveBorrowerBtn', false);
  }
}

// Course changed → rebuild its sections and re-apply the form rules.
// Delegated so it survives the <select> being rebuilt.
document.addEventListener('change', (e) => {
  // "＋ Add new section…" → ask for a name and add it to the list
  if (e.target && e.target.id === 'b_section' && e.target.value === '__new__') {
    const sel = e.target;
    const name = (prompt('Enter a section name (e.g. Rizal, Bonifacio, A or B):') || '').trim();
    if(name.length>80){showToast('Section must be at most 80 characters.','error');sel.value='';return;}
    if (!name) { sel.value = ''; return; }
    let opt = [...sel.options].find(o => o.value === name);
    if (!opt) {
      opt = new Option(name, name);
      opt.dataset.id = '0';
      sel.add(opt, sel.querySelector('option[value="__new__"]'));
    }
    sel.value = name;

    return;
  }
  if (e.target && e.target.id === 'b_section') return;
  // "＋ Other…" year level → type it in
  if (e.target && e.target.id === 'b_year' && e.target.value === '__other__') {
    const name = (prompt('Enter the year level (e.g. Grade 11):') || '').trim().slice(0, 30);
    if (!name) { e.target.value = ''; return; }
    fillBorrowerYear(name);
    return;
  }
  if (!e.target || e.target.id !== 'b_course') return;
  fillBorrowerSections();
  fillBorrowerYear('');
  applyBorrowerFormState();
});

/* ───────────────────────────────────────────────────────────
   UPDATE SECTIONS (admin) — review a course's students and give
   each one their own new section. Never "move a whole section":
   after enrollment classmates can land in different sections.
─────────────────────────────────────────────────────────── */
let usStudents=[];

          // active Students that have a course
let usCourse='';

            // course currently shown
const usChanges=new Map();

  // student id → { id, section_name, cms_section_id }

async function openSectionsModal(){
  document.getElementById('usSearch').value='';
  document.getElementById('usResultInfo').textContent='';
  usChanges.clear();
  document.getElementById('usRows').innerHTML='<tr class="empty-row"><td colspan="3"><span class="spinner dark"></span> Loading…</td></tr>';
  usCount();
  openModal('sectionsModal');
  try{
    await loadEnrollmentRows();
    const all=[];
    for(let page=1;;page++){                                  // the API caps a page at 100
      const r=await apiFetch(`${API}/borrowers.php?type=Student&per_page=100&page=${page}&lite=1&status=active`);
      all.push(...(r.data||[]));
      if(!(r.data||[]).length||all.length>=(r.total||0)) break;
    }
    usStudents=all.filter(s=>s.is_active!=0&&s.course);
    const courses=[...new Set(usStudents.map(s=>s.course))].sort();
    if(!courses.length){
      document.getElementById('usCourse').innerHTML='';
      document.getElementById('usSection').innerHTML='';
      document.getElementById('usRows').innerHTML='<tr class="empty-row"><td colspan="3">No students with a section yet.</td></tr>';
      return;
    }
    document.getElementById('usCourse').innerHTML=courses.map(c=>`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
    usCourse='';
    usCourseChanged(true);
  }catch(_){
    document.getElementById('usRows').innerHTML='<tr class="empty-row"><td colspan="3">Failed to load students.</td></tr>';
  }
}

function usCourseChanged(initial=false){
  const courseSel=document.getElementById('usCourse');
  if(!initial && usChanges.size && !confirm(`Discard ${usChanges.size} unsaved change(s)?`)){ courseSel.value=usCourse; return; }
  usChanges.clear(); usCount();
  usCourse=courseSel.value;
  const counts=new Map();
  usStudents.filter(s=>s.course===usCourse).forEach(s=>counts.set(s.section_name,(counts.get(s.section_name)||0)+1));
  const names=[...counts.keys()].sort((x,y)=>String(x).localeCompare(String(y),undefined,{numeric:true}));
  document.getElementById('usSection').innerHTML=
    `<option value="">All sections (${[...counts.values()].reduce((x,y)=>x+y,0)})</option>`+
    names.map(n=>`<option value="${escapeHtml(n)}">${escapeHtml(n)} (${counts.get(n)})</option>`).join('');
  usRender();
}

function usRender(){
  const section=document.getElementById('usSection').value;
  const query=document.getElementById('usSearch').value.trim().toLocaleLowerCase();
  const list=usStudents.filter(s=>s.course===usCourse&&(!section||s.section_name===section)
    &&(!query||String(s.full_name||'').toLocaleLowerCase().includes(query)||String(s.id_number||'').toLocaleLowerCase().includes(query)))
                       .sort((x,y)=>x.full_name.localeCompare(y.full_name));
  const sections=collectSections(usCourse, usStudents.filter(s=>s.course===usCourse).map(s=>s.section_name));
  const tbody=document.getElementById('usRows');
  document.getElementById('usResultInfo').textContent=`${list.length} student${list.length===1?'':'s'}${query?' matching your search':''} in the selected course and section.`;
  if(!list.length){ tbody.innerHTML=`<tr class="empty-row"><td colspan="3">${query?'No students match your search in the selected course and section.':'No students in this section.'}</td></tr>`; return; }
  const US_LIMIT=150; // thousands of <select> rows would freeze the modal
  tbody.innerHTML=list.slice(0,US_LIMIT).map(s=>{
    const picked=usChanges.get(+s.id)?.section_name||'';
    const opts=[...sections].filter(([n])=>n!==s.section_name)
      .map(([n,id])=>`<option value="${escapeHtml(n)}" data-id="${id}"${n===picked?' selected':''}>${escapeHtml(n)}</option>`).join('');
    return `<tr${picked?' style="background:var(--primary-bg)"':''}>
      <td><div class="tool-info"><h4>${escapeHtml(s.full_name)}</h4><span>${escapeHtml(s.id_number)}</span></div></td>
      <td>${escapeHtml(s.section_name||'—')}</td>
      <td><select class="filter-select" data-id="${s.id}" onchange="usPick(this)"><option value="">— no change —</option>${opts}</select></td>
    </tr>`;
  }).join('')+(list.length>US_LIMIT?`<tr class="empty-row"><td colspan="3">Showing ${US_LIMIT} of ${list.length} students — search by name or ID, or choose a section above to narrow the list.</td></tr>`:'');
}

function usPick(sel){
  const id=+sel.dataset.id, name=sel.value;
  if(name) usChanges.set(id,{id,section_name:name,cms_section_id:+sel.selectedOptions[0].dataset.id||0});
  else usChanges.delete(id);
  sel.closest('tr').style.background=name?'var(--primary-bg)':'';
  usCount();
}

function usCount(){
  const btn=document.getElementById('usSaveBtn');
  btn.disabled=!usChanges.size;
  btn.textContent=usChanges.size?`Save changes (${usChanges.size})`:'Save changes';
}

async function usSave(){
  if(!usChanges.size) return;
  setLoading('usSaveBtn',true);
  try{
    const res=await apiFetch(`${API}/borrowers.php`,{method:'PATCH',body:JSON.stringify({course:usCourse,sections:[...usChanges.values()]})});
    showToast(`${res.data.updated} student(s) updated.`);
    closeModal('sectionsModal');
    loadBorrowers();
    refreshBorrowerFilterOptions();
  }catch(_){
  }finally{
    setLoading('usSaveBtn',false);
    usCount();
  }
}

// Exports everything matching the current filters. borrowers.php caps a page at
// 100 rows, so this walks the pages (the old per_page=9999 silently returned
// only the first 100).
async function exportBorrowersCSV(){
  try{
    const f=borrowerFilterValues();
    const rows=[['Name','ID Number','Type','Program','Section','Year Level','Email','Phone','Active Borrows','Total Borrows','Status']];
    const PER=100, MAX_PAGES=500;
    showToast('Preparing export…','warning');
    for(let page=1;page<=MAX_PAGES;page++){
      const params=new URLSearchParams({page,per_page:PER,sort:borrowersSort.key,dir:borrowersSort.dir,lite:1,...f});
      const r=await apiFetch(`${API}/borrowers.php?${params}`);
      const data=r.data||[];
      data.forEach(b=>rows.push([b.full_name,b.id_number,b.type,b.course||'',b.section_name||'',b.year_level||'',b.email||'',b.phone||'',b.active_borrows,b.total_borrows,b.is_active==0?'Inactive':'Active']));
      if(!data.length||page*PER>=(r.total||0)) break;
    }
    downloadCSV(rows,'borrowers_directory.csv');
    showToast(`Exported ${rows.length-1} borrower(s).`,'success');
  }catch(_){}
}

/* ───────────────────────────────────────────────────────────
   8. BORROW-PAGE BORROWER SELECT
─────────────────────────────────────────────────────────── */
// ── Borrower pickers: search the server as you type ──────────────
// Before, every borrower was preloaded with borrowers.php?per_page=9999 — which the
// API silently capped at 100, so borrower #101 onward could never be picked (and
// inactive borrowers were offered on the Borrow page). Now each picker asks
// borrowers.php?lite=1&search=… for the best 20 matches, debounced, and ignores any
// response that arrives after a newer one.
const BORROWER_PICK_LIMIT=20;

const borrowerResultsById=new Map();

   // id → borrower, for rows currently/recently shown
const bdSeqs={}, bdTimers={};

async function searchBorrowersRemote(q,status){
  const params=new URLSearchParams({page:1,per_page:BORROWER_PICK_LIMIT,lite:1,sort:'full_name',search:q,status:status||''});
  return (await apiFetch(`${API}/borrowers.php?${params}`)).data||[];
}

function borrowerTypeahead(listId,query,renderRow,{status='',header=''}={}){
  const box=document.getElementById(listId);
  if(!box) return;
  const msg=t=>`<div style="padding:10px 14px;color:var(--gray-500,#6b7280);font-size:13px">${t}</div>`;
  clearTimeout(bdTimers[listId]);
  box.style.display='block';
  box.innerHTML=header+msg('Searching…');
  bdTimers[listId]=setTimeout(async()=>{
    const seq=(bdSeqs[listId]=(bdSeqs[listId]||0)+1);
    try{
      const list=await searchBorrowersRemote(query,status);
      if(seq!==bdSeqs[listId]) return;           // stale response
      if(borrowerResultsById.size>500) borrowerResultsById.clear();
      list.forEach(b=>borrowerResultsById.set(String(b.id),b));
      box.innerHTML=header+(list.length?list.map(renderRow).join(''):msg('No matching borrowers'))+
        (list.length>=BORROWER_PICK_LIMIT?msg('Showing the first '+BORROWER_PICK_LIMIT+' — keep typing to narrow it down'):'');
    }catch(_){
      if(seq===bdSeqs[listId]) box.innerHTML=header+msg('Search failed — try again');
    }
  },query?250:0);
}

// One dropdown row: "Name — ID · Course Section" (the course/section tells
// apart two students who share a name).
function borrowerRow(onclickFn){
  return b=>`<div onclick="${onclickFn}(${b.id})" style="padding:10px 14px;cursor:pointer;font-size:14px"
      onmouseover="this.style.background='var(--gray-100,#f3f4f6)'" onmouseout="this.style.background=''">
      ${escapeHtml(b.full_name)} — ${escapeHtml(b.id_number)}${b.course?` <span style="color:var(--gray-400,#9ca3af);font-size:12px">· ${escapeHtml(b.course)} ${escapeHtml(b.section_name||'')}</span>`:''}
    </div>`;
}

// Kept so existing callers still work — pickers search on demand now, so there's nothing to preload.
async function loadBorrowerSelect(){}

// Borrowers Directory mobile filter disclosure; existing filter handlers are unchanged.
function toggleBorrowersFilters(button){
  const filters=document.getElementById('borrowersFilters');
  if(!filters)return;
  const expanded=button.getAttribute('aria-expanded')==='true';
  button.setAttribute('aria-expanded',String(!expanded));
  filters.classList.toggle('borrowers-filters-open',!expanded);
}

function showBorrowerIncidents(){
 document.getElementById('cwSearch').value=document.getElementById('bhMeta').dataset.borrowerNumber||'';
 document.getElementById('cwCaseFilter').value='all';document.getElementById('cwIncidentFilter').value='';document.getElementById('cwInventoryFilter').value='';
 closeModal('borrowerHistoryModal');navigateTo('replacements');
}


