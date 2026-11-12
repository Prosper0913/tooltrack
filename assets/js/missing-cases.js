/* ============================================================
   missing-cases.js — Missing Tool cases: report, confirm / reject, recover.
   Plain (non-module) script sharing the global scope with the other assets/js files.
   Uses the existing missing report, confirmation and recovery workflow.
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

/* ── Case list (Missing Cases, on the Damage & Loss Tracking page) ──────────────────────────────── */
const MT_STATUS={pending:['borrowed','Pending confirmation'],confirmed:['missing','Missing'],resolved:['resolved','Resolved'],rejected:['neutral','Rejected']};

// Everything on screen that a missing-tool action can change.
function mtRefreshAll(){
  loadChargeCases(); if(typeof refreshToolStockViews==='function')refreshToolStockViews();
  loadBorrowHistory(); if(typeof raReload==='function') raReload();
  if(typeof loadNotifications==='function') loadNotifications();
}

/* ── One case: details, audit trail and the next allowed actions ── */
const MT_EVENT_LABEL={reported:'Reported',confirmed:'Confirmed missing',rejected:'Report rejected',incident_linked:'Incident linked',charge_recorded:'Historical charge case recorded',replacement_requested:'Historical charge case recorded',
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
  const [cls,oldLabel]=MT_STATUS[r.status]||['neutral',r.status]; const label=r.outcome||oldLabel;
  document.getElementById('mc_title').textContent=`Missing Tool Case ${r.report_no}`;
  const admin=MT_IS_ADMIN();
  const notFound=Number(r.qty_written_off||0)>0?r.open_qty:r.qty_missing-r.qty_recovered;
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
    ${kv('Reported by',escapeHtml(r.reported_by_name||'Not recorded'))}
    ${r.confirmed_at?kv('Decision at',rrFmtDT(r.confirmed_at)):''}
    ${r.resolved_at?kv('Resolved at',rrFmtDT(r.resolved_at)):''}
    ${kv('Reason',escapeHtml(r.reason))}
    ${kv('Investigation notes',r.investigation_notes?escapeHtml(r.investigation_notes):'—')}
  </div>`;

  const timeline=`<h4 class="mt-h">Audit trail</h4><ul class="mt-timeline">${(r.events||[]).map(e=>
    `<li><b>${escapeHtml(MT_EVENT_LABEL[e.action]||e.action)}</b>${e.qty?` · ${e.qty} unit(s)`:''}
       <div class="mt-sub">${escapeHtml(e.user_name||'—')} · ${rrFmtDT(e.created_at)}${e.detail?` — ${escapeHtml(e.detail)}`:''}</div></li>`).join('')||'<li>No events.</li>'}</ul>`;

  const form=(title,fields,buttons)=>`<div class="mt-form"><h4 class="mt-h">${title}</h4>${fields}<div class="mt-btnrow">${buttons}</div></div>`;
  const btn=(txt,fn,cls='btn-blue')=>`<button class="btn ${cls}" onclick="return ${fn}">${txt}</button>`;
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
    const cases=r.incident_cases||[];
    if(cases.length)actions+=`<div class="mt-form"><h4 class="mt-h">Physical resolution</h4>${cases.map(q=>`<div class="mt-req"><div>${Number(q.quantity_needed)} unit(s) · ${escapeHtml(q.inventory_status)}</div><button class="card-btn secondary" onclick="closeModal('missingActionModal');rpView(${Number(q.id)})">Manage Inventory</button></div>`).join('')}</div>`;
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
  if(['confirmed','resolved'].includes(r.status))await lossMount('missing',Number(id),body);
 incidentDecorate('missingActionModal',body,{caseId:r.report_no,tool:r.tool_name,sku:r.tool_code,status:r.outcome||r.status,kind:'lost',original:r.qty_reported,remaining:r.open_qty,recovered:r.qty_recovered,writtenoff:r.qty_written_off,age_days:Math.max(0,Math.floor((Date.now()-parseDbDate(r.missing_date).getTime())/86400000)),closed:r.status==='resolved'||r.status==='rejected',closed_at:r.resolved_at||(r.status==='rejected'?r.confirmed_at:null)});
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
  }catch(error){incidentError('missingActionModal',error.message||'Could not save. Your entries have been retained.');}
  finally{ mtBusy=false; if(done){incidentSaved('missingActionModal');await renderMissingCase(id);mtRefreshAll();} }
  return done;
}

function mtConfirm(id,reported){
  const toolName=mtCase?mtCase.tool_name:'this tool';
  const qty=mtNum('mc_confirmQty');
  if(qty<1||qty>reported){showToast(`Enter between 1 and ${reported} unit(s).`,'error');return;}
  return mtDo(id,'confirm',{qty,notes:mtVal('mc_decisionNotes')},
    `Confirm ${qty} unit(s) of ${toolName} as MISSING?\n\nThey move out of "borrowed" into "missing" stock. Total stock does not change.`);
}

function mtReject(id){
  const notes=mtVal('mc_decisionNotes');
  if(notes.length<3){showToast('Please enter a reason for rejecting the report.','error');return;}
  return mtDo(id,'reject',{notes},'Reject this report? No stock will change.');
}

function mtRecover(id,open,notFound){
  const qty=mtNum('mc_recQty');
  if(qty<1||qty>notFound){showToast(`Enter between 1 and ${notFound} unit(s).`,'error');return;}
  const surplus=Math.max(0,qty-Math.max(0,open));
  const allow=!!document.getElementById('mc_recSurplus')?.checked;
  if(surplus>0&&!allow){showToast(`${surplus} of these unit(s) were already closed by an earlier record. Tick the box to add them to total stock.`,'error');return;}
  return mtDo(id,'recover',{qty,to:mtVal('mc_recTo'),allow_surplus:allow,notes:mtVal('mc_recNotes')},
    `Record ${qty} unit(s) as found?`+(surplus>0?`\n\n${surplus} unit(s) were already closed by an earlier record, so total stock INCREASES by ${surplus}.`:'\n\nThey leave "missing" stock. Total stock does not change.'));
}


