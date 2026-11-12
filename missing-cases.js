/* ============================================================
   missing-cases.js — Missing Tool cases: report, confirm / reject, recover.
   Plain (non-module) script sharing the global scope with the other assets/js files.
   Student charges for a confirmed loss are created in the Damage & Loss Charges form.
   ============================================================ */
const mtNum=id=>{const n=Number(document.getElementById(id)?.value);return Number.isInteger(n)?n:0;};

const mtVal=id=>(document.getElementById(id)?.value||'').trim();

/* ── Report Missing Tool ──────────────────────────────────── */
let mtReportLoanId=null, mtReportToken='', mtBusy=false, mtCase=null;

function mtNewToken(){
  return (window.crypto&&crypto.randomUUID)?crypto.randomUUID().replace(/-/g,''):('t'+Date.now().toString(36)+Math.random().toString(36).slice(2,14));
}

function openMissingReport(loanId){
  const t=mtLoanCache.get(String(loanId));
  if(!t){showToast('Could not find that borrow record — refresh and try again.','error');return;}
  if(t.pending_report_id){showToast('A report is already waiting for confirmation on this borrow.','warning');openMissingCase(t.pending_report_id);return;}
  const out=mtOutstanding(t);
  if(out<1){showToast('Nothing is outstanding on this borrow.','error');return;}
  mtReportLoanId=t.id;
  mtReportToken=mtNewToken();      // one token per opened form: a double click or a retry can never create two cases
  document.getElementById('mr_loanSummary').innerHTML=
    `<strong>${escapeHtml(t.tool_name||t.tool_code)}</strong> <code>${escapeHtml(t.tool_code||'')}</code><br>
     <span>${escapeHtml(t.txn_id)} · borrowed ${out} of ${t.qty} unit(s) outstanding · due ${escapeHtml(t.due_date||'—')}${t.overdue_days>0?` · <b>${t.overdue_days} day(s) overdue</b>`:''}</span>`;
  document.getElementById('mr_borrower').value=t.borrower||'Unknown';
  const q=document.getElementById('mr_qty'); q.max=out; q.value=1;
  document.getElementById('mr_qtyHint').textContent=out>1?`Partial loss is fine: enter only the units that are missing (1–${out}).`:'1 unit outstanding.';
  const d=document.getElementById('mr_date'); d.max=localToday(); d.min=String(t.created_at||'').slice(0,10); d.value=localToday();
  document.getElementById('mr_reason').value='';
  document.getElementById('mr_notes').value='';
  openModal('missingReportModal');
}

async function submitMissingReport(){
  if(mtBusy) return;
  const qty=mtNum('mr_qty'), date=mtVal('mr_date'), reason=mtVal('mr_reason'), notes=mtVal('mr_notes');
  const t=mtLoanCache.get(String(mtReportLoanId));
  const out=t?mtOutstanding(t):0;
  if(!mtReportLoanId){showToast('No borrow record selected.','error');return;}
  if(qty<1){showToast('Quantity missing must be at least 1.','error');return;}
  if(out&&qty>out){showToast(`Only ${out} unit(s) are outstanding on this borrow.`,'error');return;}
  if(!date){showToast('Select the date the tool was found missing.','error');return;}
  if(date>localToday()){showToast('Date missing cannot be in the future.','error');return;}
  if(reason.length<3){showToast('Please give a reason.','error');return;}
  mtBusy=true; setLoading('mr_submitBtn',true);
  try{
    const res=await chargeFetch(`${API}/missing_tools.php`,{method:'POST',body:JSON.stringify({
      action:'report',borrow_txn_id:mtReportLoanId,qty,missing_date:date,reason,investigation_notes:notes,request_token:mtReportToken})});
    showToast(res.data.duplicate?'This report was already submitted.':`Report ${res.data.report_no} submitted — an Admin must confirm it.`,res.data.duplicate?'warning':'success');
    closeModal('missingReportModal');
    mtRefreshAll();
  }catch(_){ /* apiFetch already toasted; keep the form open and the SAME token so a retry is safe */ }
  finally{ mtBusy=false; setLoading('mr_submitBtn',false); }
}

/* ── Case list (Missing Cases, on the Damage & Loss Charges page) ──────────────────────────────── */
const MT_STATUS={pending:['borrowed','Pending confirmation'],confirmed:['missing','Missing'],resolved:['resolved','Resolved'],rejected:['neutral','Rejected']};

let mtCasesPage=1;

async function loadMissingSummary(){
  const box=document.getElementById('missingSummaryChips'); if(!box) return;
  try{
    const s=(await chargeFetch(`${API}/missing_tools.php?summary=1`)).data;
    const chip=(n,label,cls)=>`<span class="status-badge ${n>0?cls:'neutral'}"><span class="status-dot"></span>${n} ${label}</span>`;
    box.innerHTML=chip(s.overdue_loans,'overdue','overdue')+chip(s.followup_loans,`follow-up (${s.followup_days}+ days)`,'followup')+
      chip(s.pending_confirmation,'awaiting confirmation','borrowed')+chip(s.unresolved_cases,'missing, unresolved','missing');
  }catch(_){ box.innerHTML=''; }
}

async function loadMissingCases(page){
  const body=document.getElementById('missingCasesBody'); if(!body) return;
  mtCasesPage=(typeof page==='number')?page:1;
  ensureHistoryPager('missingCasesTableContainer','missingCases');
  const status=document.getElementById('missingCasesFilter').value;
  const params=new URLSearchParams({status,page:mtCasesPage,per_page:HISTORY_PER_PAGE});
  try{
    const res=await chargeFetch(`${API}/missing_tools.php?${params}`);
    const rows=res.data||[];
    if(!rows.length){ body.innerHTML='<tr class="empty-row"><td colspan="7">No missing-tool cases here.</td></tr>'; }
    else body.innerHTML=rows.map(r=>{
      const [cls,label]=MT_STATUS[r.status]||['neutral',r.status];
      const qtyTxt=`${r.qty_reported} reported<br>${r.qty_missing} confirmed missing<br>${r.qty_recovered} recovered${r.qty_replaced?` · ${r.qty_replaced} closed earlier`:''}<br><b>${r.open_qty} outstanding</b>`;
      return `<tr>
        <td data-label="Case"><code>${escapeHtml(r.report_no)}</code><div class="mt-sub">Loan ${escapeHtml(r.borrow_txn_ref||'—')}</div></td>
        <td data-label="Tool">${escapeHtml(r.tool_name||'—')} <code>${escapeHtml(r.tool_code||'')}</code></td>
        <td data-label="Borrower">${escapeHtml(r.borrower||'—')}</td>
        <td data-label="Quantity">${qtyTxt}</td>
        <td data-label="Status"><span class="status-badge ${cls}"><span class="status-dot"></span>${label}</span></td>
        <td data-label="Reported">${escapeHtml(r.reported_by_name||'—')}<div class="mt-sub">${rrFmtDT(r.reported_at)}</div></td>
        <td data-label="Actions"><button class="action-btn" title="View Case" aria-label="View case ${escapeHtml(r.report_no)}" onclick="openMissingCase(${r.id})"><i class="fas fa-folder-open" aria-hidden="true"></i><span class="mc-view-label">View Case</span></button></td>
      </tr>`;}).join('');
    renderPagination('missingCasesPagerInfo','missingCasesPagerBtns',mtCasesPage,Math.ceil((res.total||0)/HISTORY_PER_PAGE),HISTORY_PER_PAGE,res.total||0,p=>loadMissingCases(p));
  }catch(_){ body.innerHTML='<tr class="empty-row"><td colspan="7">Failed to load cases.</td></tr>'; }
}

// Everything on screen that a missing-tool action can change.
function mtRefreshAll(){
  loadMissingCases(mtCasesPage); loadMissingSummary();
  loadBorrowHistory(); if(typeof raReload==='function') raReload();
  if(typeof loadTools==='function') loadTools();          // stock counts changed
  if(typeof loadNotifications==='function') loadNotifications();
}

/* ── One case: details, audit trail and the next allowed actions ── */
const MT_EVENT_LABEL={reported:'Reported',confirmed:'Confirmed missing',rejected:'Report rejected',charge_recorded:'Charge case recorded',replacement_requested:'Charge case recorded',
  recovered:'Original recovered',resolved:'Case resolved'};

async function openMissingCase(id){
  document.getElementById('mc_title').textContent='Missing Tool Case';
  document.getElementById('mc_body').innerHTML='<div class="mt-loading"><span class="spinner dark"></span> Loading…</div>';
  openModal('missingActionModal');
  await renderMissingCase(id);
}

async function renderMissingCase(id){
  const body=document.getElementById('mc_body');
  let r;
  try{ r=(await chargeFetch(`${API}/missing_tools.php?id=${id}`)).data; }
  catch(_){ body.innerHTML='<div class="mt-loading">Could not load this case.</div>'; return; }
  mtCase=r;
  const [cls,label]=MT_STATUS[r.status]||['neutral',r.status];
  document.getElementById('mc_title').textContent=`Missing Tool Case ${r.report_no}`;
  const admin=MT_IS_ADMIN();
  const notFound=r.qty_missing-r.qty_recovered;          // originals not yet found
  const kv=(k,v)=>`<div class="mt-kv"><span>${k}</span><b>${v}</b></div>`;

  const summary=`<div class="mt-grid">
    ${kv('Status',`<span class="status-badge ${cls}"><span class="status-dot"></span>${label}</span>`)}
    ${kv('Tool',`${escapeHtml(r.tool_name||'—')} <code>${escapeHtml(r.tool_code||'')}</code>`)}
    ${kv('Responsible borrower',`${escapeHtml(r.borrower||'—')}${r.borrower_id_number?` (${escapeHtml(r.borrower_id_number)})`:''}`)}
    ${kv('Borrow record',`${escapeHtml(r.borrow_txn_ref||'—')} · due ${escapeHtml(bhFormatDate(r.due_date))}`)}
    ${kv('Date missing',escapeHtml(bhFormatDate(r.missing_date)))}
    ${kv('Reported',r.qty_reported)}
    ${kv('Confirmed Missing',r.qty_missing)}
    ${kv('Recovered',r.qty_recovered)}
    ${r.qty_replaced?kv('Closed by earlier record',r.qty_replaced):''}
    ${kv('Outstanding',r.open_qty)}
    ${r.qty_surplus?kv('Recovered beyond open units',r.qty_surplus):''}
    ${kv('Reported at',rrFmtDT(r.reported_at))}
    ${r.confirmed_at?kv('Decision at',rrFmtDT(r.confirmed_at)):''}
    ${r.resolved_at?kv('Resolved at',rrFmtDT(r.resolved_at)):''}
    ${kv('Reason',escapeHtml(r.reason))}
    ${kv('Investigation notes',r.investigation_notes?escapeHtml(r.investigation_notes):'—')}
  </div>`;

  const timeline=`<h4 class="mt-h">Audit trail</h4><ul class="mt-timeline">${(r.events||[]).map(e=>
    `<li><b>${escapeHtml(MT_EVENT_LABEL[e.action]||e.action)}</b>${e.qty?` · ${e.qty} unit(s)`:''}
       <div class="mt-sub">${escapeHtml(e.user_name||'—')} · ${rrFmtDT(e.created_at)}${e.detail?` — ${escapeHtml(e.detail)}`:''}</div></li>`).join('')||'<li>No events.</li>'}</ul>`;

  const form=(title,fields,buttons)=>`<div class="mt-form"><h4 class="mt-h">${title}</h4>${fields}<div class="mt-btnrow">${buttons}</div></div>`;
  const btn=(txt,fn,cls='btn-blue')=>`<button class="btn ${cls}" onclick="${fn}">${txt}</button>`;
  let actions='';

  if(r.status==='pending'){
    actions = admin
      ? form('Confirm or reject this report',
          `<label class="field-label">Units to confirm missing (reported: ${r.qty_reported})</label>
           <input type="number" class="form-input-full" id="mc_confirmQty" min="1" max="${r.qty_reported}" value="${r.qty_reported}">
           <label class="field-label">Notes (required to reject)</label>
           <input type="text" class="form-input-full" id="mc_decisionNotes" maxlength="500" placeholder="Decision notes…">`,
          btn('Confirm as Missing',`mtConfirm(${r.id},${r.qty_reported})`,'btn-danger')+btn('Reject Report',`mtReject(${r.id})`,'btn-gray'))
      : '<p class="mt-note">Waiting for an Admin to confirm. Stock does not change until then.</p>';
  }else if(r.status==='confirmed'||r.status==='resolved'){
    // Student damage/loss charges are created in Damage & Loss Charges; show the linked cases here.
    const cases=(r.charge_cases||[]);
    if(cases.length){
      actions+=`<div class="mt-form"><h4 class="mt-h">Linked charge cases</h4>${cases.map(q=>`<div class="mt-req"><div><b>Case #${q.id}</b> · ${q.quantity_needed} unit(s) · ${q.amount_due===null?'Charge not set':rpPeso(q.amount_due)}<div class="mt-sub">${rrFmtDT(q.created_at)}</div></div><div class="mt-btnrow">${btn('View case',`viewChargeCase(${q.id})`)}</div></div>`).join('')}</div>`;
    }
    if(r.status==='confirmed' && r.borrower_type==='Student' && !cases.length){
      actions+=form('Record loss charge',
        `<p class="mt-note">Students pay for lost tools. This opens a charge case for the confirmed loss; the Admin or Staff then sets the charge.</p>
         <label class="field-label">Incident units (max ${r.open_qty})</label>
         <input type="number" class="form-input-full" id="mc_chargeQty" min="1" max="${r.open_qty}" value="${r.open_qty}">
         <label class="field-label">Notes</label><input type="text" class="form-input-full" id="mc_chargeNotes" maxlength="500">`,
        btn('Record Loss Charge',`mtRecordCharge(${r.id})`));
    }
    // Recovery of the original (if older records already closed some units, the extra unit adds to total stock)
    if(admin && notFound>0){
      const replacedNet=r.qty_replaced-r.qty_surplus;
      actions+=form('Original tool found again',
        `<label class="field-label">Units found (up to ${notFound})</label>
         <input type="number" class="form-input-full" id="mc_recQty" min="1" max="${notFound}" value="${Math.min(notFound,Math.max(1,r.open_qty||notFound))}">
         <label class="field-label">Condition now</label>
         <select class="form-input-full" id="mc_recTo"><option value="good">Good</option><option value="minor">Minor wear</option><option value="damaged">Damaged (not lendable)</option></select>
         ${replacedNet>0?`<label class="mt-check"><input type="checkbox" id="mc_recSurplus"> ${replacedNet} unit(s) were closed by an earlier historical record: add the found unit to total stock</label>`:''}
         <label class="field-label">Notes</label><input type="text" class="form-input-full" id="mc_recNotes" maxlength="500">`,
        btn('Record Recovery',`mtRecover(${r.id},${r.open_qty},${notFound})`));
    }
    if(r.status==='resolved' && !actions) actions='<p class="mt-note">This case is resolved: every missing unit was recovered or closed by an earlier record.</p>';
  }else{
    actions=`<p class="mt-note">This report was rejected${r.decision_notes?`: ${escapeHtml(r.decision_notes)}`:''}. Stock was not changed.</p>`;
  }
  body.innerHTML=summary+`<div class="mt-actions">${actions}</div>`+timeline;
}

// POST one action; the case view is ALWAYS re-read from the server afterwards (even after an error).
async function mtDo(id,action,payload,confirmMsg){
  if(mtBusy) return false;
  if(confirmMsg && !confirm(confirmMsg)) return false;
  mtBusy=true; document.querySelectorAll('#mc_body button, #mc_body input, #mc_body select').forEach(e=>e.disabled=true);
  let done=false;
  try{
    const res=await chargeFetch(`${API}/missing_tools.php`,{method:'POST',body:JSON.stringify({action,id,...payload})});
    done=true;
    const d=res.data||{};
    showToast({confirm:'Confirmed as missing — stock updated.',reject:'Report rejected.',
      recover:d.surplus_added?`Recovered — ${d.surplus_added} surplus unit(s) added to total stock.`:'Recovery recorded — stock updated.'}[action]||'Saved.');
    if(d.resolved) showToast('Case resolved.');
  }catch(_){ /* toast already shown */ }
  finally{ mtBusy=false; await renderMissingCase(id); mtRefreshAll(); }
  return done;
}

function mtConfirm(id,reported){
  const toolName=mtCase?mtCase.tool_name:'this tool';
  const qty=mtNum('mc_confirmQty');
  if(qty<1||qty>reported){showToast(`Enter between 1 and ${reported} unit(s).`,'error');return;}
  mtDo(id,'confirm',{qty,notes:mtVal('mc_decisionNotes')},
    `Confirm ${qty} unit(s) of ${toolName} as MISSING?\n\nThey move out of "borrowed" into "missing" stock. Total stock does not change.`);
}

function mtReject(id){
  const notes=mtVal('mc_decisionNotes');
  if(notes.length<3){showToast('Please enter a reason for rejecting the report.','error');return;}
  mtDo(id,'reject',{notes},'Reject this report? No stock will change.');
}

// A confirmed loss by a student becomes a charge case (payment only; stock is not touched).
async function mtRecordCharge(caseId){
  if(mtBusy) return;
  const qty=mtNum('mc_chargeQty');
  if(qty<1||(mtCase&&qty>mtCase.open_qty)){showToast('Enter a valid number of incident units.','error');return;}
  mtBusy=true; document.querySelectorAll('#mc_body button, #mc_body input, #mc_body select').forEach(e=>e.disabled=true);
  try{
    await chargeFetch(`${API}/replacement_requests.php`,{method:'POST',body:JSON.stringify({action:'create_incident',reason:'lost',source_id:caseId,tool_id:mtCase.tool_id,responsible_borrower_id:mtCase.borrower_id,quantity_needed:qty,notes:mtVal('mc_chargeNotes')})});
    showToast('Loss charge case recorded. Set the charge in Damage & Loss Charges.');
  }catch(_){ /* toast already shown */ }
  finally{ mtBusy=false; await renderMissingCase(caseId); mtRefreshAll(); if(typeof loadChargeCases==='function'&&document.getElementById('rrListBody')) loadChargeCases(); }
}

function mtRecover(id,open,notFound){
  const qty=mtNum('mc_recQty');
  if(qty<1||qty>notFound){showToast(`Enter between 1 and ${notFound} unit(s).`,'error');return;}
  const surplus=Math.max(0,qty-Math.max(0,open));
  const allow=!!document.getElementById('mc_recSurplus')?.checked;
  if(surplus>0&&!allow){showToast(`${surplus} of these unit(s) were already closed by an earlier record. Tick the box to add them to total stock.`,'error');return;}
  mtDo(id,'recover',{qty,to:mtVal('mc_recTo'),allow_surplus:allow,notes:mtVal('mc_recNotes')},
    `Record ${qty} unit(s) as found?`+(surplus>0?`\n\n${surplus} unit(s) were already closed by an earlier record, so total stock INCREASES by ${surplus}.`:'\n\nThey leave "missing" stock. Total stock does not change.'));
}


async function viewChargeCase(id){
  closeModal('missingActionModal');
  if(!document.getElementById('replacementsPage').classList.contains('active')) navigateTo('replacements');
  await rpView(Number(id));
}
