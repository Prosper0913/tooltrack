/* ============================================================
   student-import.js — Borrowers tab (Admin/Staff): student masterlist import (preview -> confirm).
   Plain (non-module) script: shares the global scope with the other
   assets/js files so inline onclick="" handlers keep working.
   ============================================================ */

/* ───────────────────────────────────────────────────────────
   STUDENT MASTERLIST IMPORT (Admin/Staff) — api/student_import.php
   Flow: choose file → server-side preview (writes nothing) → pick
   safe-update options → confirm → server re-validates and commits.
   Everything below is self-contained (smi* names); no existing
   function is modified.
─────────────────────────────────────────────────────────── */
const SMI={file:null,data:null,filter:'all',page:1,perPage:25,busy:false,mapOverride:{},q:''};

// Fields the importer can map. Only these are ever read from the file; every other column is ignored.
// 'req' = the import cannot run without it (Full Name may instead come from Last + First [+ Middle]).
const SMI_MAP_FIELDS=[
  {key:'id',      label:'Student ID',      req:true},
  {key:'name',    label:'Full Name'},
  {key:'last',    label:'Last Name',       sub:true},
  {key:'first',   label:'First Name',      sub:true},
  {key:'middle',  label:'Middle Name',     sub:true},
  {key:'course',label:'Program (Course / Strand)'},
  {key:'year',label:'Year Level'},
  {key:'section',label:'Section / Batch'},
  {key:'phone',label:'Phone Number'}
];

// Valid year levels per course — must match normalizeStudent() / smi_options() on the server.
const SMI_YEARS={FPST:[{v:1,l:'1st Year'},{v:2,l:'2nd Year'}],TVL:[{v:11,l:'Grade 11'},{v:12,l:'Grade 12'}],TESDA:[]};

const SMI_BAD_STATES=['invalid','duplicate','conflict','blocked'];

const SMI_FIELD_NAME={name:'name',phone:'phone',section:'year/section',course:'program',reactivate:'reactivate'};

function smiEl(id){return document.getElementById(id);}

function smiShowStep(n){
  [1,2,3].forEach(i=>smiEl('smiStep'+i).style.display=(i===n)?'':'none');
  smiEl('smiPreviewBtn').style.display=(n===1)?'':'none';
  smiEl('smiCommitBtn').style.display=(n===2)?'':'none';
  smiEl('smiBackBtn').style.display=(n===2)?'':'none';
  smiEl('smiAgainBtn').style.display=(n===3)?'':'none';
  smiEl('smiCancelBtn').textContent=(n===3)?'Close':'Cancel';
  document.querySelectorAll('.smi-stepper .smi-st').forEach((el,i)=>{ el.classList.toggle('on',i+1===n); el.classList.toggle('done',i+1<n); });
}

function openStudentImportModal(){
  SMI.file=null; SMI.data=null; SMI.filter='all'; SMI.page=1; SMI.busy=false; SMI.mapOverride={};
  smiEl('smiFile').value='';
  smiEl('smiCourse').value=''; smiEl('smiSection').value=''; smiCourseChanged(); smiEl('smiAcadYear').value='';
  SMI.q=''; smiEl('smiSearch').value=''; SMI.perPage=25; smiEl('smiPerPage').value='25'; smiEl('smiMapBox').open=false;
  smiUpdateFile();
  smiShowStep(1);
  openModal('smiModal');
}

function closeStudentImportModal(){
  SMI.file=null; SMI.data=null;          // drop the file and parsed rows from browser memory
  closeModal('smiModal');
}

function smiFileChosen(){
  const inp=smiEl('smiFile'); const f=inp.files[0]||null;
  if(f&&!/\.(csv|xlsx)$/i.test(f.name)){ showToast('Please choose a .csv or .xlsx file.','error'); inp.value=''; SMI.file=null; }
  else if(f&&f.size>5*1024*1024){ showToast('That file is bigger than 5 MB.','error'); inp.value=''; SMI.file=null; }
  else {
    SMI.file=f; SMI.mapOverride={};
  }
  smiUpdateFile();
}

// ── Pre-upload class selection (Course → Year Level, Section) ──
function smiMeta(){
  const course=smiEl('smiCourse').value, year=smiEl('smiYear').value, section=smiEl('smiSection').value.replace(/\s+/g,' ').trim();
  let err='';
  if(course&&!Object.hasOwn(SMI_YEARS,course)) err='Select FPST, TVL or TESDA.';
  else if(year&&course!=='TESDA'&&!SMI_YEARS[course]?.some(y=>String(y.v)===year)) err='Select a valid Year Level.';
  else if(section.length>80) err='Section must be at most 80 characters.';
  return {course,year,section,err};
}
function smiCourseChanged(){
  const c=smiEl('smiCourse').value, sel=smiEl('smiYear');
  sel.innerHTML=c?'<option value="">— select —</option>'+SMI_YEARS[c].map(y=>`<option value="${y.v}">${y.l}</option>`).join(''):'<option value="">Select a course first</option>';
  sel.disabled=!c||c==='TESDA';
  smiMetaChanged();
}
function smiMetaChanged(){
  const m=smiMeta();
  const touched=smiEl('smiCourse').value||smiEl('smiSection').value;
  smiEl('smiMetaErr').textContent=(m.err&&touched&&!SMI.file)?m.err:'';
  smiEl('smiDrop').classList.toggle('locked',!!m.err);
  smiUpdateFile();
}
function smiPickFile(){
  const m=smiMeta();
  if(m.err){ smiEl('smiMetaErr').textContent=m.err+' Choose the class before uploading a file.'; showToast(m.err,'error'); return; }
  smiEl('smiFile').click();
}
function smiUpdateFile(){
  const f=SMI.file;
  smiEl('smiDrop').style.display=f?'none':'';
  smiEl('smiFileCard').style.display=f?'':'none';
  if(f){
    smiEl('smiFileName').textContent=f.name;
    smiEl('smiFileSize').textContent=f.size<1048576?Math.max(1,Math.round(f.size/1024))+' KB':(f.size/1048576).toFixed(1)+' MB';
  }
  smiEl('smiPreviewBtn').disabled=!f||!!smiMeta().err;
}
function smiClearFile(){ smiEl('smiFile').value=''; SMI.file=null; SMI.mapOverride={}; smiUpdateFile(); }
function smiDragOver(e){ e.preventDefault(); smiEl('smiDrop').classList.add('over'); }
function smiDragLeave(){ smiEl('smiDrop').classList.remove('over'); }
function smiDrop(e){
  e.preventDefault(); smiDragLeave();
  const m=smiMeta();
  if(m.err){ smiEl('smiMetaErr').textContent=m.err+' Choose the class before uploading a file.'; showToast(m.err,'error'); return; }
  const f=e.dataTransfer&&e.dataTransfer.files[0]; if(!f) return;
  try{ const dt=new DataTransfer(); dt.items.add(f); smiEl('smiFile').files=dt.files; }catch(_){}
  smiFileChosen();
}
function smiAgain(){ SMI.data=null; smiClearFile(); smiShowStep(1); }
function smiPerPageChanged(){ SMI.perPage=parseInt(smiEl('smiPerPage').value,10)||25; SMI.page=1; smiRender(); }
function smiSearchChanged(){ SMI.q=smiEl('smiSearch').value.trim().toLowerCase(); SMI.page=1; smiRender(); }

function smiNameFormat(){ return document.querySelector('input[name="smiNameFmt"]:checked').value; }

function smiOptions(){
  const m=smiMeta();
  return {
    course:m.course, year_level:m.year, section:m.section,     // defaults for absent file columns; server validates each row
    policy:document.querySelector('input[name="smiPolicy"]:checked').value,
    overwrite_fields:[...document.querySelectorAll('.smiOw:checked')].map(c=>c.value),
    name_format:smiNameFormat(),
    reactivate:smiEl('smiReactivate').checked,
    mapping:SMI.mapOverride,                                   // {field: header | ''} — only what Admin changed
    academic_year:smiEl('smiAcadYear').value.trim()            // label for the audit log only, e.g. 2026-2027
  };
}

async function smiPost(action){
  const fd=new FormData();
  fd.append('file',SMI.file);
  fd.append('options',JSON.stringify(smiOptions()));
  try{
    const res=await fetch(`${API}/student_import.php?action=${action}`,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'FPSTToolTrack'}});
    let data;
    try{ data=await res.json(); }
    catch(_){ throw new Error(res.status===401||res.status===403?'Session expired or not allowed — please log in again.':`Server error (${res.status}). Please try again.`); }
    if(!data.success) throw new Error(data.message||'Request failed');
    return data.data;
  }catch(err){ showToast(err.message,'error'); throw err; }
}

async function smiPreview(){
  if(!SMI.file||SMI.busy) return;
  const mErr=smiMeta().err; if(mErr){ smiEl('smiMetaErr').textContent=mErr; showToast(mErr,'error'); return; }
  SMI.busy=true;
  const btn=smiEl('smiPreviewBtn'); const orig=btn.innerHTML;
  btn.disabled=true; btn.innerHTML='<span class="spinner"></span> Reading…';
  try{
    SMI.data=await smiPost('preview');
    SMI.filter='all'; SMI.page=1; smiEl('smiFilter').value='all';
    // Names with commas are very likely "Last, First" — suggest converting, but don't force it.
    const d=SMI.data;
    smiEl('smiCommaHint').textContent=d.comma_names>0
      ? `${d.comma_names} of ${d.counts.total} names contain a comma (looks like “Last, First”).` : '';
    if(d.comma_names>d.counts.total/2 && smiNameFormat()==='as_is'){
      document.querySelector('input[name="smiNameFmt"][value="last_first"]').checked=true;
      SMI.busy=false; btn.innerHTML=orig; return await smiPreview();     // re-run once with the suggested format
    }
    smiShowStep(2);
    SMI.busy=false;
    smiRender();
  }catch(_){ }
  finally{ SMI.busy=false; btn.innerHTML=orig; btn.disabled=!SMI.file; }
}

function smiBack(){ smiShowStep(1); }

// Column-mapping panel: one dropdown per importable field, listing the file's own headers.
// Auto-detected on the server (registrar / teacher headers differ); Admin can correct any of them.
function smiRenderMapping(){
  const d=SMI.data; if(!d) return;
  const map=d.mapping||{}; const headers=d.headers||[];
  smiEl('smiMapGrid').innerHTML=SMI_MAP_FIELDS.map(f=>{
    const cur=map[f.key]||'';
    const opts=`<option value="">${f.req?'— choose column —':'— not in file —'}</option>`
      +headers.map(h=>`<option value="${escapeHtml(h)}"${h===cur?' selected':''}>${escapeHtml(h)}</option>`).join('');
    return `<label class="smi-map-item${f.sub?' sub':''}"><span>${f.label}${f.req?' *':''}</span>
      <select class="filter-select" data-field="${f.key}" onchange="smiMapChanged(this)">${opts}</select></label>`;
  }).join('');
  const miss=d.missing||[];
  smiEl('smiMapWarn').innerHTML=miss.length
    ? `<i class="fas fa-triangle-exclamation"></i> Pick a column for: <b>${miss.map(escapeHtml).join(', ')}</b> to continue.` : '';
}

async function smiMapChanged(sel){
  SMI.mapOverride[sel.dataset.field]=sel.value;      // '' = explicitly "not in file"
  if(!SMI.file||SMI.busy) return;
  SMI.busy=true;
  try{ SMI.data=await smiPost('preview'); SMI.page=1; smiRender(); }catch(_){}
  finally{ SMI.busy=false; }
}

async function smiNameFmtChanged(){      // Parsing and update policies share the server's validation.
  if(!SMI.file||SMI.busy) return;
  SMI.busy=true;
  const controls=[...document.querySelectorAll('#smiStep2 input,#smiStep2 select,#smiCommitBtn')].map(el=>({el,disabled:el.disabled}));
  controls.forEach(({el})=>el.disabled=true);
  let refreshed=false;
  try{ SMI.data=await smiPost('preview'); SMI.page=1; refreshed=true; }
  catch(_){SMI.data=null;smiEl('smiSummary').textContent='Preview could not be refreshed. Retry Preview before importing.';}
  finally{
    controls.forEach(({el,disabled})=>el.disabled=disabled); SMI.busy=false;
    if(refreshed)smiRender();else smiEl('smiCommitBtn').disabled=true;
  }
}
// What WILL happen to one row under the currently selected options (mirrors smi_apply() in PHP;
// the server re-computes this authoritatively on commit).
function smiClassify(r,o){
  if(SMI_BAD_STATES.includes(r.state)) return {kind:'problem',changes:[]};
  if(r.state==='new') return {kind:'new',changes:[]};
  if(o.policy==='skip') return {kind:'nochange',changes:[],skipped:true};
  const ch=[];
  ['name','phone','section','course'].forEach(f=>{
    const st=r.fields&&r.fields[f];
    if(st==='fill'||(st==='change'&&o.policy==='overwrite'&&o.overwrite_fields.includes(f))) ch.push(f);
  });
  if(o.reactivate&&r.active===false) ch.push('reactivate');
  return {kind:ch.length?'update':'nochange',changes:ch};
}

function smiSummary(){
  const o=smiOptions(); const s={new:0,update:0,nochange:0,problem:0,warn:0};
  SMI.data.rows.forEach(r=>{ const c=smiClassify(r,o); s[c.kind]++; if(r.warnings.length&&c.kind!=='problem') s.warn++; });
  return s;
}

function smiNotes(r,c,o){
  const out=[];
  r.errors.forEach(e=>out.push(`<div class="smi-issue err"><i class="fas fa-circle-xmark"></i> ${escapeHtml(e)}</div>`));
  if(r.state==='existing'&&c.kind==='update'){
    const parts=[];
    c.changes.forEach(f=>{
      if(f==='reactivate'){ parts.push('reactivate'); return; }
      const was=r.current?r.current[f]:'';
      const now=r[f]||'';
      parts.push(was?`${SMI_FIELD_NAME[f]}: ${escapeHtml(was)} → ${escapeHtml(now)}`:`${SMI_FIELD_NAME[f]}: fill ${escapeHtml(now)}`);
    });
    out.push(`<div class="smi-issue">${parts.join('<br>')}</div>`);
  }
  if(r.state==='existing'&&c.kind==='nochange'&&!c.skipped) out.push('<div class="smi-issue">Already up to date for the selected fields.</div>');
  r.warnings.forEach(w=>out.push(`<div class="smi-issue warn"><i class="fas fa-triangle-exclamation"></i> ${escapeHtml(w)}</div>`));
  return out.join('')||'—';
}

function smiBadge(c,r){
  if(c.kind==='new')    return '<span class="status-badge available"><span class="status-dot"></span>Add new</span>';
  if(c.kind==='update') return '<span class="status-badge borrowed"><span class="status-dot"></span>Update</span>';
  if(c.kind==='nochange')return `<span class="status-badge neutral"><span class="status-dot"></span>${c.skipped?'Existing — skipped':'No change'}</span>`;
  const label={invalid:'Invalid',duplicate:'Duplicate in file',conflict:'Conflict in file',blocked:'ID in use'}[r.state]||'Problem';
  return `<span class="status-badge low-stock"><span class="status-dot"></span>${label}</span>`;
}

function smiFilterChanged(){ SMI.filter=smiEl('smiFilter').value; SMI.page=1; smiRender(); }

function smiRender(){
  const d=SMI.data; if(!d) return;
  smiRenderMapping();
  const o=smiOptions(); const sum=smiSummary();
  smiEl('smiReactivate').disabled=(o.policy==='skip');
  document.querySelectorAll('.smiOw').forEach(c=>c.disabled=(o.policy!=='overwrite'));

  const mt=smiMeta(); const yl=(SMI_YEARS[mt.course]||[]).find(y=>String(y.v)===mt.year);
  smiEl('smiColsInfo').innerHTML=`<b>${d.counts.total}</b> rows read. Using columns: ${(d.columns_used||[]).map(escapeHtml).join(', ')||'—'}. `
    +`Applied to every student: <b>${escapeHtml(mt.course)} · ${escapeHtml(yl?yl.l:'')} · Section ${escapeHtml(mt.section)}</b>.`
    +(d.ignored_total?` Ignored (not read or stored): ${d.ignored.map(escapeHtml).join(', ')}${d.ignored_total>d.ignored.length?'…':''}.`:'');

  const card=(key,icon,cls,n,label)=>`<button type="button" class="smi-card ${cls} ${SMI.filter===key?'on':''}" onclick="smiSetFilter('${key}')"><i class="fas ${icon}"></i><b>${n}</b><span>${label}</span></button>`;
  smiEl('smiChips').innerHTML=card('new','fa-user-plus','ok',sum.new,'new students')+card('update','fa-pen','info',sum.update,'to update')
    +card('nochange','fa-equals','mute',sum.nochange,'no change')+card('problem','fa-circle-xmark','bad',sum.problem,'problems (skipped)')
    +card('warn','fa-triangle-exclamation','warn',sum.warn,'to double-check')+card('all','fa-list','mute',d.counts.total,'all rows');
  const miss=d.missing||[];
  let msg=miss.length?'<i class="fas fa-triangle-exclamation"></i> I couldn\'t find the <b>'+miss.map(escapeHtml).join(', ')+'</b> column. Open “Wrong column picked?” below and choose it.'
    :(sum.new+sum.update)?`<i class="fas fa-circle-check"></i> Ready to import: <b>${sum.new}</b> new and <b>${sum.update}</b> updated student(s). Nothing is saved until you press <b>Import</b>.`
    :'<i class="fas fa-circle-info"></i> Nothing to import with the current choices.';
  if(sum.problem&&!miss.length) msg+=` <b>${sum.problem}</b> row(s) have problems and will be skipped.`;
  smiEl('smiSummary').innerHTML=msg;
  if(miss.length) smiEl('smiMapBox').open=true;
  smiEl('smiOwBox').style.display=(o.policy==='overwrite')?'':'none';
  smiEl('smiProblemCsvBtn').style.display=sum.problem?'':'none';

  const rows=d.rows.filter(r=>{
    const c=smiClassify(r,o);
    if(SMI.q&&!(`${r.id} ${r.name}`.toLowerCase().includes(SMI.q))) return false;
    if(SMI.filter==='all') return true;
    if(SMI.filter==='warn') return r.warnings.length>0&&c.kind!=='problem';
    return c.kind===SMI.filter;
  });
  const pages=Math.max(1,Math.ceil(rows.length/SMI.perPage));
  if(SMI.page>pages) SMI.page=pages;
  const slice=rows.slice((SMI.page-1)*SMI.perPage,SMI.page*SMI.perPage);
  smiEl('smiRows').innerHTML=slice.length?slice.map(r=>{
    const c=smiClassify(r,o);
    return `<tr${c.kind==='problem'?' style="background:rgba(220,38,38,.06)"':''}>
      <td data-label="Row">${r.row}</td>
      <td data-label="Student ID"><code>${escapeHtml(r.id)}</code></td>
      <td data-label="Full Name">${escapeHtml(r.name||'—')}</td>
      <td data-label="Phone">${escapeHtml(r.phone||'—')}</td>
      <td data-label="Program">${escapeHtml(r.course||'—')}</td>
      <td data-label="Section">${escapeHtml(r.section||'—')}</td>
      <td data-label="Year">${({1:'1st Year',2:'2nd Year',11:'Grade 11',12:'Grade 12'})[r.year]||r.year||'—'}</td>
      <td data-label="Action">${smiBadge(c,r)}</td>
      <td data-label="Notes">${smiNotes(r,c,o)}</td></tr>`;
  }).join(''):'<tr class="empty-row"><td colspan="9">No students match. Try another filter or search.</td></tr>';
  renderPagination('smiPageInfo','smiPageBtns',SMI.page,pages,SMI.perPage,rows.length,p=>{SMI.page=p;smiRender();});

  const actionable=sum.new+sum.update;
  const cb=smiEl('smiCommitBtn');
  cb.disabled=(actionable===0||SMI.busy);
  cb.textContent=actionable?`Import ${actionable} record${actionable!==1?'s':''}`:'Nothing to import';
}

function smiSetFilter(k){ SMI.filter=k; smiEl('smiFilter').value=k; SMI.page=1; smiRender(); }

async function smiCommit(){
  if(!SMI.data||SMI.busy) return;
  const o=smiOptions(); const s=smiSummary();
  const yl=(SMI_YEARS[o.course]||[]).find(y=>String(y.v)===o.year);
  let msg=`Import now?\n\nEach row uses its mapped Program, Year Level and Section. Defaults fill missing columns only.${o.course?`\nDefaults: ${o.course} · ${yl?yl.l:''} · ${o.section||'no section'}`:''}\n\n• Add ${s.new} new student(s)\n• Update ${s.update} existing student(s)\n• Skip ${s.problem} problem row(s)\n\nNothing is deleted, and borrowing history is not touched.`;
  if(o.policy==='overwrite'&&o.overwrite_fields.some(f=>['section','course'].includes(f))) msg+='\n\nYear level, section and course/program of matched students will be replaced by the values in the file.';
  if(o.policy==='overwrite'&&o.overwrite_fields.includes('name')) msg+='\n\n⚠ You chose to overwrite existing FULL NAMES. Check the "very different" name warnings first.';
  if(!confirm(msg)) return;

  SMI.busy=true;
  const btn=smiEl('smiCommitBtn'); const orig=btn.innerHTML;
  btn.disabled=true; btn.innerHTML='<span class="spinner"></span> Importing…';
  try{
    const r=await smiPost('commit');
    const box=(n,l)=>`<div><b>${n}</b>${l}</div>`;
    smiEl('smiResult').innerHTML=box(r.created,'added')+box(r.updated,'updated')+box(r.unchanged+r.skipped_existing_policy,'existing, unchanged')
      +box(r.skipped_problem+r.skipped_race,'skipped')+(r.reactivated?box(r.reactivated,'reactivated'):'');
    smiEl('smiResultMsg').textContent=`${r.created} new and ${r.updated} existing student(s) were saved${r.skipped_problem?`; ${r.skipped_problem} problem row(s) were skipped`:''}.`;
    smiShowStep(3);
    showToast(`Import finished: ${r.created} added, ${r.updated} updated.`);
    SMI.data=null; SMI.file=null;
    if(typeof loadBorrowers==='function') loadBorrowers();
    if(typeof refreshBorrowerFilterOptions==='function') refreshBorrowerFilterOptions();
    if(typeof loadBorrowerSelect==='function') loadBorrowerSelect();
  }catch(_){ btn.innerHTML=orig; btn.disabled=false; }
  finally{ SMI.busy=false; }
}

// Problem rows → CSV (row number, ID, name, reason). No phone numbers. Cells that could be read as
// spreadsheet formulas are prefixed with an apostrophe.
function smiDownloadProblems(){
  if(!SMI.data) return;
  const o=smiOptions();
  const safe=v=>{ let s=String(v??''); if(/^[=+\-@\t\r]/.test(s)) s="'"+s; return `"${s.replace(/"/g,'""')}"`; };
  const lines=['Row,Student ID,Full Name,Problem'];
  SMI.data.rows.forEach(r=>{ if(smiClassify(r,o).kind==='problem') lines.push([r.row,r.id,r.name,r.errors.join(' ')].map(safe).join(',')); });
  const blob=new Blob(['\uFEFF'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
  const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='masterlist_problem_rows.csv';
  document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(a.href),1000);
}
