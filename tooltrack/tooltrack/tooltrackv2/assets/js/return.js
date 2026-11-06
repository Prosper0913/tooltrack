/* ============================================================
   return.js — Return tab: Find Borrower (ID scan / borrower search), Return by Student (return_batch, conditions, missing units)
   and the Return Transaction History.
   Plain (non-module) script: shares the global scope with the other
   assets/js files so inline onclick="" handlers keep working.
   ============================================================ */

// One token per form fill: a double click can never record two returns.
function rtNewToken(){ return 'RB'+Date.now().toString(36)+Math.random().toString(36).slice(2,10); }

/* ───────────────────────────────────────────────────────────
   FIND BORROWER (Return page) — camera ID scan and borrower search. Both just
   find the borrower and hand them to "Return by Student", which does the return.
   Inactive borrowers are NOT blocked: someone deactivated after borrowing can
   still bring a tool back.
───────────────────────────────────────────────────────────*/
let returnIdQr=null, returnIdScanning=false;

let returnIdLastCode='', returnIdLastAt=0, returnIdLookupBusy=false;

let returnIdPending={code:'',at:0};

const returnIdStatus=(t,ty)=>setScanStatus('returnIdScanStatus','returnIdScanStatusText',t,ty);

function returnIdInfo(t){ const el=document.getElementById('returnIdScanInfo'); if(el) el.textContent=t||''; }

async function startReturnIdScanner(){
  if(returnIdScanning) return;
  returnIdStatus('Requesting camera access…','scanning');
  try{
    await openCamera(returnIdQr,'returnIdCameraSelect',
      onReturnIdCameraDecode,
      ()=>{
        document.getElementById('returnIdReader').style.display='block';
        document.getElementById('returnIdStartBtn').style.display='none';
        document.getElementById('returnIdStopBtn').style.display='flex';
      },
      {qrbox:(w,h)=>({width:Math.floor(w*.85),height:Math.max(80,Math.floor(Math.min(h*.6,180)))})});
    returnIdScanning=true;
    returnIdStatus('Scanning…','scanning');
  }catch(err){
    returnIdStatus(cameraErrorText(err),'error');
    resetReturnIdScannerUI();
  }
}

function stopReturnIdScannerAsync(){
  if(!returnIdScanning){ resetReturnIdScannerUI(); return Promise.resolve(); }
  return returnIdQr.stop().catch(()=>{}).then(()=>{ returnIdScanning=false; resetReturnIdScannerUI(); });
}

function stopReturnIdScanner(){ stopReturnIdScannerAsync(); }

function resetReturnIdScannerUI(){
  document.getElementById('returnIdReader').style.display='none';
  document.getElementById('returnIdStartBtn').style.display='flex';
  document.getElementById('returnIdStopBtn').style.display='none';
}

// Same misread guard as the Borrow page (short fragments rejected, odd formats need two matching reads).
function onReturnIdCameraDecode(raw){
  const code=cleanScannedId(raw);
  if(code.length<ID_MIN_CAMERA_LEN){
    returnIdStatus('Scanning… (read "'+code+'" — too short, hold steady and fill the frame)','scanning');
    return;
  }
  if(/^\d{8,10}$/.test(code)){ returnIdPending={code:'',at:0}; handleReturnIdScan(code,'camera'); return; }
  const now=Date.now();
  if(returnIdPending.code===code && now-returnIdPending.at<=ID_CONFIRM_MS){
    returnIdPending={code:'',at:0};
    handleReturnIdScan(code,'camera');
  }else{
    returnIdPending={code,at:now};
  }
}


async function handleReturnIdScan(raw,source){
  const code=cleanScannedId(raw);
  if(!code) return;
  const now=Date.now();
  if(returnIdLookupBusy) return;
  if(code===returnIdLastCode && now-returnIdLastAt<ID_REPEAT_MS) return;
  returnIdLastCode=code; returnIdLastAt=now; returnIdLookupBusy=true;
  returnIdStatus('Scanning…','scanning'); returnIdInfo('Looking up '+code+'…');
  try{
    const params=new URLSearchParams({page:1,per_page:5,lite:1,id_number:code});
    const rows=(await apiFetch(`${API}/borrowers.php?${params}`)).data||[];
    const b=rows.find(r=>cleanScannedId(r.id_number)===code);     // exact match only
    if(b){
      borrowerResultsById.set(String(b.id),b);
      const who=[b.full_name,b.id_number,b.course?`${b.course} ${b.section_name||''}`.trim():b.type].join(' · ');
      await raLoadBorrower(b);                                    // Return by Student panel
      const n=raLoans.length;
      returnIdStatus(n?'Borrower found':'Borrower found — nothing to return',n?'success':'error');
      returnIdInfo(who+(n?` — ${n} open ${n===1?'loan':'loans'}.`:' — has no active borrows.')+(b.is_active==0?' (Inactive)':''));
    }else{
      returnIdStatus('Not found','error');
      returnIdInfo('No borrower with ID '+code+'.');
    }
    if(source==='camera') stopReturnIdScanner();                  // got a result → release the camera
  }catch(_){
    returnIdStatus('Lookup failed — try again','error');
    returnIdLastCode='';
  }finally{
    returnIdLookupBusy=false;
  }
}

function clearReturnIdScan(){
  returnIdStatus('','');
  returnIdInfo('');
  returnIdLastCode='';
}

/* ── Return by Student (Return all / Partial return) ───────────────────────────────────────────
   Student → every open loan → units per condition → ONE all-or-nothing submission
   (api/transactions.php type=return_batch). The server re-validates everything on locked rows.
   Good / Minor Wear → back to available stock · Damaged → kept in total, NOT available ·
   Missing → a Missing Tool case, NOT added to stock. A row left at 0 simply stays outstanding. */
let raStudent=null, raLoans=[], raToken=null, raBusy=false;

const raCand=new Map();

const RA_KEYS=['good','minor','damaged','missing'];

const RA_LABEL={good:'Good',minor:'Minor Wear',damaged:'Damaged',missing:'Missing'};

const raEl=id=>document.getElementById(id);

function raVal(el){ const v=(el.value||'').trim(); return v===''?0:(/^\d+$/.test(v)?parseInt(v,10):NaN); }

function raHideCandidates(){ const c=raEl('raCandidates'); c.style.display='none'; c.innerHTML=''; raCand.clear(); }

async function raFind(){
  const inp=raEl('raSearch'), q=inp.value.trim();
  if(!q||raBusy) return;
  raEl('raFindBtn').disabled=true;
  try{
    const code=cleanScannedId(q);
    let b=null;
    if(code){
      const rows=(await apiFetch(`${API}/borrowers.php?${new URLSearchParams({page:1,per_page:5,lite:1,id_number:code})}`)).data||[];
      b=rows.find(r=>cleanScannedId(r.id_number)===code)||null;        // exact ID match only
    }
    if(!b){
      const rows=(await apiFetch(`${API}/borrowers.php?${new URLSearchParams({page:1,per_page:8,lite:1,search:q})}`)).data||[];
      if(rows.length===1) b=rows[0];
      else if(rows.length>1){ raShowCandidates(rows); return; }
      else{ raHideCandidates(); showToast(`No student found for “${q}”.`,'error'); return; }
    }
    inp.value=''; raHideCandidates();
    await raLoadBorrower(b);
  }catch(_){ /* apiFetch already showed the message */ }
  finally{ raEl('raFindBtn').disabled=false; }
}

function raShowCandidates(rows){
  raCand.clear();
  const c=raEl('raCandidates');
  c.innerHTML=rows.map(r=>{ raCand.set(String(r.id),r);
    return `<button type="button" class="ra-cand" onclick="raPick('${String(r.id).replace(/[^0-9]/g,'')}')"><span><b>${escapeHtml(r.full_name)}</b> <small>${escapeHtml(r.id_number||'')}</small></span><small>${escapeHtml(r.type||'')}${r.is_active==0?' · Inactive':''}</small></button>`; }).join('');
  c.style.display='flex';
}

async function raPick(id){
  const b=raCand.get(String(id)); if(!b) return;
  raHideCandidates(); raEl('raSearch').value='';
  try{ await raLoadBorrower(b); }catch(_){}
}

// Fresh from the server every time (what is outstanding right now), with a new duplicate-protection token.
async function raLoadBorrower(b){
  if(raBusy) return;                    // a submission is saving: never swap the form underneath it
  const res=await apiFetch(`${API}/transactions.php?type=student_loans&borrower_id=${encodeURIComponent(b.id)}`);
  raStudent=res.data.borrower; raLoans=res.data.loans||[]; raToken=rtNewToken();
  raRender();
}

async function raReload(){ if(raStudent){ try{ await raLoadBorrower(raStudent); }catch(_){} } }

function raReset(){
  if(raBusy)return;
  raStudent=null; raLoans=[]; raToken=null; raHideCandidates();
  raEl('raStudent').innerHTML='<small>Select a borrower to see their active loans.</small>';
  raEl('raPanel').style.display='none'; raEl('raEmpty').style.display='';
  rtSyncMobileUI();
}

function raRender(){
  raEl('raEmpty').style.display='none'; raEl('raPanel').style.display='';
  const s=raStudent, units=raLoans.reduce((n,l)=>n+l.outstanding,0);
  raEl('raStudent').innerHTML=`<div><b>${escapeHtml(s.full_name)}</b> <small>${escapeHtml(s.id_number||'')} · ${escapeHtml(s.type||'')}${s.is_active==0?' · Inactive':''}</small></div>`
    +`<div><small>${raLoans.length} item(s) · ${units} unit(s) outstanding</small> <button type="button" class="ra-link" onclick="raReset()">Clear</button></div>`;
  const none=raEl('raNone'), form=raEl('raForm');
  if(!raLoans.length){
    none.style.display=''; none.textContent=`${s.full_name} has nothing outstanding — every borrowed item is already returned.`;
    form.style.display='none'; rtSyncMobileUI(); return;
  }
  none.style.display='none'; form.style.display='';
  raEl('raReturnee').value=s.full_name; raEl('raNotes').value='';
  raEl('raMissingReason').value=''; raEl('raMissingDate').value=localToday();
  raEl('raBody').innerHTML=raLoans.map(l=>{
    const done=[]; if(l.qty_returned>0) done.push(`${l.qty_returned} returned`); if(l.qty_missing>0) done.push(`${l.qty_missing} missing`);
    const due=l.due_date?(Number(l.is_overdue)?`<span class="ra-badge late">${escapeHtml(loanOverdueLabel(l))}</span>`:`<span class="ra-badge">Due ${escapeHtml(l.due_date)}</span>`):'';
    const input=k=>{
      const dis=(k==='missing'&&l.pending_report_id)?' disabled title="A missing-tool report is already waiting for confirmation on this loan"':'';
      const label=escapeHtml(`${RA_LABEL[k]} quantity for ${l.tool_name||'this tool'}`);
      return `<td data-label="${RA_LABEL[k]}"><div class="rt-qty-control"><button type="button" class="rt-mobile-only rt-qty-step" onclick="rtStepQty(${l.id},'${k}',-1)" aria-label="Decrease ${label}"${dis}>−</button><input class="ra-q" type="number" inputmode="numeric" min="0" max="${l.outstanding}" step="1" value="0" id="ra_${l.id}_${k}" oninput="raRecalc()" aria-label="${label}"${dis}><button type="button" class="rt-mobile-only rt-qty-step" onclick="rtStepQty(${l.id},'${k}',1)" aria-label="Increase ${label}"${dis}>+</button></div></td>`;
    };
    return `<tr id="raRow_${l.id}">
      <td data-label="Item"><div class="ra-item-head"><input type="checkbox" class="ra-pick" id="ra_pick_${l.id}" onchange="raPickRow(${l.id},this.checked)" aria-label="Return all ${l.outstanding} outstanding unit(s) of ${escapeHtml(l.tool_name||'this item')}"><strong>${escapeHtml(l.tool_name||'—')}</strong>${due}</div><small>${escapeHtml(l.tool_code||'')} · ${escapeHtml(l.txn_id||'')}${l.pending_report_id?' · missing report pending':''}</small><div class="rt-loan-meta"><span>Due ${escapeHtml(l.due_time?bhFormatDate(`${l.due_date} ${l.due_time}`,true):bhFormatDate(l.due_date))}</span><span class="rt-return-status" id="rtReturnStatus_${l.id}">Not selected</span></div></td>
      <td data-label="Borrowed">${l.qty}${done.length?`<small>${done.join(' · ')}</small>`:''}</td>
      <td data-label="Outstanding"><strong>${l.outstanding}</strong></td>
      ${input('good')}${input('minor')}${input('damaged')}${input('missing')}
      <td data-label="Left after" id="raLeft_${l.id}">${l.outstanding}</td>
      <td><button type="button" class="ra-mini" onclick="raFillRow(${l.id})">All Good</button></td></tr>`;
  }).join('');
  raRecalc();
}

function raFillRow(id){
  const l=raLoans.find(x=>x.id===id); if(!l) return;
  RA_KEYS.forEach(k=>{ const e=raEl(`ra_${id}_${k}`); if(e) e.value=(k==='good')?l.outstanding:0; });
  raRecalc();
}

function raFillAllGood(){ raLoans.forEach(l=>raFillRow(l.id)); }

function raClearQty(){ raLoans.forEach(l=>RA_KEYS.forEach(k=>{ const e=raEl(`ra_${l.id}_${k}`); if(e) e.value=0; })); raRecalc(); }

// The mode is DERIVED from what is entered, never stored, so the toggle, banner, button and confirm
// dialog can never disagree: "full" = every outstanding unit of every loan is accounted for.
let raFull=false;

// Units entered for one loan (NaN when a field is not a whole number).
function raRowSum(l){ let s=0; for(const k of RA_KEYS){ const v=raVal(raEl(`ra_${l.id}_${k}`)); if(Number.isNaN(v)) return NaN; s+=v; } return s; }

// "Return all" fills every row with its outstanding units as Good (then fix any exceptions by hand);
// "Partial return" starts a fresh selection at 0. Choosing the mode you are already in changes nothing.
function raSetMode(mode){
  if(!raLoans.length) return;
  if(mode==='full'){ if(!raFull) raFillAllGood(); }
  else if(raFull) raClearQty();
}

// Tick box on a row: tick = return all of that item as Good, untick = leave it outstanding.
function raPickRow(id,checked){
  if(checked){ raFillRow(id); return; }
  RA_KEYS.forEach(k=>{ const e=raEl(`ra_${id}_${k}`); if(e) e.value=0; });
  raRecalc();
}

// Validates every row, updates the totals / stock impact, returns an error string ('' = fine).
function raRecalc(){
  const tot={good:0,minor:0,damaged:0,missing:0}; let err='', left=0, fullRows=0, stayRows=0, allOut=0;
  raLoans.forEach(l=>{
    let sum=0, bad=false;
    RA_KEYS.forEach(k=>{
      const v=raVal(raEl(`ra_${l.id}_${k}`));
      if(Number.isNaN(v)){ bad=true; if(!err) err=`${l.tool_name}: ${RA_LABEL[k]} must be a whole number, 0 or more.`; }
      else{ sum+=v; tot[k]+=v; }
    });
    if(!bad && sum>l.outstanding){ bad=true; err=err||`${l.tool_name}: ${sum} unit(s) entered but only ${l.outstanding} are outstanding.`; }
    raEl('raRow_'+l.id).classList.toggle('ra-bad',bad);
    const after=raEl('raLeft_'+l.id); after.textContent=bad?'—':String(l.outstanding-sum);
    const state=raEl('rtReturnStatus_'+l.id);
    if(state){state.textContent=bad?'Check quantities':sum===0?'Not selected':sum===l.outstanding?'All accounted for':'Partial return';state.classList.toggle('rt-selected',!bad&&sum>0);}
    if(!bad) left+=l.outstanding-sum;
    allOut+=l.outstanding;
    if(!bad){ if(sum===l.outstanding) fullRows++; else stayRows++; }
    const pk=raEl('ra_pick_'+l.id);
    if(pk){ pk.checked=!bad&&sum===l.outstanding; pk.indeterminate=!bad&&sum>0&&sum<l.outstanding; }
  });
  const total=tot.good+tot.minor+tot.damaged+tot.missing;
  const parts=RA_KEYS.filter(k=>tot[k]>0).map(k=>`${RA_LABEL[k]} ${tot[k]}`);
  raFull=!err&&raLoans.length>0&&fullRows===raLoans.length;
  raEl('raTotalLine').textContent=total?`Returning ${total} of ${allOut} outstanding unit(s): ${parts.join(' · ')}`+(left>0?` — ${left} unit(s) on ${stayRows} item(s) will stay outstanding`:' — nothing left outstanding'):'Choose “Return all”, tick the items that came back, or enter quantities.';
  // mode toggle, banner and submit button all follow the same derived state
  raEl('raModeFull').setAttribute('aria-pressed',String(raFull));
  raEl('raModePartial').setAttribute('aria-pressed',String(!raFull));
  const banner=raEl('raModeBanner');
  if(err||total<1){ banner.className='ra-banner hint'; banner.textContent=err?'Fix the highlighted quantities to continue.':'Nothing selected yet. “Return all” fills every item; in “Partial return” tick only the items that came back.'; }
  else if(raFull){ banner.className='ra-banner full'; banner.textContent=`Full return — every outstanding unit of all ${raLoans.length} item(s) is accounted for.`; }
  else{ banner.className='ra-banner partial'; banner.textContent=`Partial return — ${stayRows} item(s) / ${left} unit(s) will stay on loan and can be returned later.`; }
  if(!raBusy) raEl('raSubmitBtn').innerHTML=total<1||err?'<i class="fas fa-check-double"></i> Confirm Return'
    :raFull?'<i class="fas fa-check-double"></i> Confirm Full Return':'<i class="fas fa-list-check"></i> Confirm Partial Return';
  const imp=[];
  if(tot.good+tot.minor>0) imp.push(`+${tot.good+tot.minor} back to available stock`+(tot.minor?` (${tot.minor} tracked as worn)`:''));
  if(tot.damaged>0) imp.push(`${tot.damaged} damaged — kept in total, NOT available`);
  if(tot.missing>0) imp.push(`${tot.missing} missing — recorded as a loss, NOT added to stock (${window.CURRENT_ROLE==='Admin'?'confirmed at once':'waits for Admin confirmation'})`);
  raEl('raImpact').textContent=imp.join(' · ');
  raEl('raMissingExtra').style.display=tot.missing>0?'':'none';
  raEl('raErr').textContent=err;
  raEl('raSubmitBtn').disabled=raBusy||!!err||total<1;
  rtSyncMobileUI(tot,left,total,err);
  return err;
}

async function raSubmit(){
  if(raBusy||!raStudent) return;
  const err=raRecalc(); if(err){ showToast(err,'error'); return; }
  const returnee=raEl('raReturnee').value.trim();
  if(!returnee){ showToast('Returnee name is required — who is handing the tools back?','error'); return; }
  const items=[], tot={good:0,minor:0,damaged:0,missing:0}, lines=[];
  raLoans.forEach(l=>{
    const q={}; let s=0;
    RA_KEYS.forEach(k=>{ q[k]=raVal(raEl(`ra_${l.id}_${k}`)); s+=q[k]; tot[k]+=q[k]; });
    if(s>0){ items.push({borrow_txn_id:l.id,...q}); lines.push(`• ${l.tool_name} — ${RA_KEYS.filter(k=>q[k]>0).map(k=>`${RA_LABEL[k]} ${q[k]}`).join(', ')}`); }
  });
  if(!items.length){ showToast('Enter a quantity for at least one item.','error'); return; }
  const reason=raEl('raMissingReason').value.trim(), mdate=raEl('raMissingDate').value;
  if(tot.missing>0){
    if(reason.length<3){ showToast('Please give a reason for the missing unit(s) (at least 3 characters).','error'); return; }
    if(mdate&&mdate>localToday()){ showToast('Date missing cannot be in the future.','error'); return; }
  }
  const stay=raLoans.map(l=>({l,left:l.outstanding-raRowSum(l)})).filter(x=>x.left>0);
  const head=stay.length
    ? `PARTIAL RETURN for ${raStudent.full_name} — ${stay.length} item(s) will stay on loan:\n`
      +stay.slice(0,8).map(x=>`  • ${x.l.tool_name} — ${x.left} of ${x.l.outstanding} still out`).join('\n')+(stay.length>8?`\n  • …and ${stay.length-8} more`:'')
    : `FULL RETURN for ${raStudent.full_name} — nothing will stay outstanding.`;
  const preview=items.map(i=>{
    const l=raLoans.find(l=>l.id===i.borrow_txn_id),left=l.outstanding-RA_KEYS.reduce((n,k)=>n+i[k],0);
    const other=raLoans.filter(x=>x.id!==l.id&&x.tool_id===l.tool_id).reduce((n,x)=>n+x.outstanding-raRowSum(x),0);
    return `${l.tool_name} (${l.txn_id}): ${RA_KEYS.filter(k=>i[k]>0).map(k=>`${i[k]} ${RA_LABEL[k]}`).join(', ')}. ${left===0&&!i.missing?'This loan will close.':`${left} unprocessed unit(s) stay on this loan; missing units follow confirmation.`}${other?` Other ${l.tool_name} loans still have ${other} unit(s) outstanding.`:''}`;
  }).join('\n');
  if(!confirm(`${head}\n\n${preview}\n\nReturning now:\n${lines.join('\n')}\n\n`
      +(tot.damaged>0?'Damaged units will NOT be available for borrowing.\n':'')
      +(tot.missing>0?'Missing units are recorded as a loss and will not be added to stock.\n':'')
      +'All items are saved together — if anything is wrong nothing is saved.')) return;

  const body={type:'return_batch',borrower_id:raStudent.id,returnee_name:returnee,notes:raEl('raNotes').value.trim(),request_token:raToken,items};
  if(tot.missing>0){ body.missing_reason=reason; body.missing_date=mdate||undefined; }
  raBusy=true; setLoading('raSubmitBtn',true);
  try{
    const res=await apiFetch(`${API}/transactions.php`,{method:'POST',body:JSON.stringify(body)});
    const d=res.data;
    if(d.duplicate){ showToast('This return was already recorded — nothing was changed.','warning'); }
    else{
      const t=d.totals||tot;
      showToast(`Return recorded — ${d.loans_in_batch} item(s), ${t.good+t.minor+t.damaged+t.missing} unit(s)`+(d.loans_closed?`, ${d.loans_closed} fully closed`:'')+'.','success');
      if((d.items||[]).some(i=>i.is_late)) showToast('⚠ Some of these items were returned late.','error');
      if(t.damaged>0) showToast(`${t.damaged} damaged unit(s) kept in the total but not available for borrowing.`,'error');
      const miss=(d.items||[]).filter(i=>i.missing);
      if(miss.length) showToast(miss.map(i=>`${i.missing.report_no} (${i.missing.qty})`).join(', ')+(miss[0].missing.status==='confirmed'?' recorded as missing.':' reported — waiting for Admin confirmation.'),'warning');
    }
    loadReturnHistory(); loadBorrowHistory();
    rtLoadOverview();if(typeof refreshToolStockViews==='function')refreshToolStockViews();
    loadNotifications();
    if(typeof loadChargeCases==='function')loadChargeCases();
    raBusy=false;                        // raLoadBorrower refuses to run while a submit is in flight, so release it first
    await raLoadBorrower(raStudent);     // what is still outstanding + a fresh token
  }catch(_){ /* apiFetch showed the server's message; the same token stays valid for a safe retry */ }
  finally{ raBusy=false; setLoading('raSubmitBtn',false); if(raStudent&&raLoans.length) raRecalc(); }
}

function toggleReturnHistory(){
  const box=document.getElementById('returnHistoryTableContainer');
  const icon=document.getElementById('returnHistoryChevron');
  const isOpen=!box.classList.contains('collapsed');
  box.classList.toggle('collapsed', isOpen);
  icon.classList.toggle('open', !isOpen);
}

let returnHistoryPage=1;

async function loadReturnHistory(page){
  if(typeof page==='number') returnHistoryPage=page; else returnHistoryPage=1;
  ensureHistoryPager('returnHistoryTableContainer','returnHistory');
  const condition=document.getElementById('returnHistoryFilter').value;
  const params=new URLSearchParams({type:'return',condition,page:returnHistoryPage,per_page:HISTORY_PER_PAGE});
  try{
    const res=await apiFetch(`${API}/transactions.php?${params}`);
    const tbody=document.getElementById('returnHistoryBody');
    const rows=res.data||[];
    const condBadge={good:'available',minor:'returned',damaged:'low-stock',missing:'low-stock'};
    const condLabel={good:'Good',minor:'Minor Wear',damaged:'Damaged',missing:'Missing/Lost'};
    if(!rows.length){tbody.innerHTML='<tr class="empty-row"><td colspan="7">No return transactions yet.</td></tr>';}
    else tbody.innerHTML=rows.map(t=>`
      <tr>
        <td data-label="Txn ID"><code>${escapeHtml(t.txn_id)}</code><button type="button" class="rt-mobile-only rt-history-toggle" aria-expanded="false" aria-controls="rtHistoryWho_${t.id} rtHistoryNotes_${t.id}" onclick="rtToggleHistory(this)">Details <i class="fas fa-chevron-down" aria-hidden="true"></i></button></td>
        <td data-label="Tool Code"><strong class="rt-mobile-only rt-history-tool">${escapeHtml(t.tool_name||t.tool_code)}</strong>${escapeHtml(t.tool_code)}</td>
        <td data-label="Returned By" id="rtHistoryWho_${t.id}">${escapeHtml(t.returnee_name||'—')}</td>
        <td data-label="Date & Time"><span class="rt-desktop-only">${parseDbDate(t.created_at).toLocaleString()}</span><span class="rt-mobile-only">${bhFormatDate(t.returned_at||t.created_at,true)}</span></td>
        <td data-label="Condition"><span class="status-badge ${condBadge[t.condition]||'returned'}"><span class="status-dot"></span>${escapeHtml(condLabel[t.condition]||t.condition)}</span></td>
        <td data-label="Qty"><b>${+t.qty||0}</b>${(+t.batch_units>+t.qty)?`<span class="rt-batch">of ${+t.batch_units} in this return</span>`:''}${t.batch_missing_no?`<span class="rt-batch">+ ${+t.batch_missing_qty} missing · ${escapeHtml(t.batch_missing_no)} (${escapeHtml(t.batch_missing_status)})</span>`:''}</td>
        <td data-label="Notes" id="rtHistoryNotes_${t.id}">${escapeHtml(t.notes||'—')}</td>
      </tr>`).join('');
    renderPagination('returnHistoryPagerInfo','returnHistoryPagerBtns',returnHistoryPage,Math.ceil((res.total||0)/HISTORY_PER_PAGE),HISTORY_PER_PAGE,res.total||0,p=>loadReturnHistory(p));
  }catch(_){}
}

/* Scanner setup for the Return page (was part of the single window.load bootstrap). */
window.addEventListener('load',()=>{
  // ID scanner for the Return page: QR plus the common barcode formats (student IDs are often Code 128 / Code 39).
  const F=(typeof Html5QrcodeSupportedFormats!=='undefined')?Html5QrcodeSupportedFormats:null;
  returnIdQr=new Html5Qrcode('returnIdReader',F?{
    formatsToSupport:[F.QR_CODE,F.CODE_128,F.CODE_39,F.EAN_13],
    useBarCodeDetectorIfSupported:true
  }:undefined);
});

/* Responsive presentation reuses the existing inputs, totals, submit handler and request token. */
const rtMobileMedia=window.matchMedia('(max-width:768px)');
function rtArrangeMobile(){
  const finder=raEl('rtBorrowerFinder'),target=raEl('rtMobileFinder'),anchor=raEl('rtFinderAnchor');
  if(!finder||!target||!anchor)return;
  target.append(finder); // One existing finder stays with the borrower/scanner panel at every width.
}
function rtStepQty(id,key,delta){
  if(raBusy||!RA_KEYS.includes(key))return;
  const loan=raLoans.find(l=>Number(l.id)===Number(id)),input=raEl(`ra_${id}_${key}`);
  if(!loan||!input||input.disabled)return;
  const other=RA_KEYS.filter(k=>k!==key).reduce((sum,k)=>sum+(raVal(raEl(`ra_${id}_${k}`))||0),0);
  input.value=Math.min(Math.max(0,loan.outstanding-other),Math.max(0,(raVal(input)||0)+delta));
  raRecalc();
}
function rtSyncMobileUI(tot={good:0,minor:0,damaged:0,missing:0},left=0,total=0,err=''){
  const review=raEl('rtMobileReview');if(!review)return;
  const empty=raEl('rtOutstandingEmpty');if(empty)empty.hidden=!!raStudent;
  const who=raEl('rtSummaryBorrower');if(who)who.textContent=raStudent?raStudent.full_name+' · '+raLoans.length+' active loan(s)':'Select a borrower to begin.';
  if(!raStudent||!raLoans.length){raEl('raTotalLine').textContent=raStudent?'Nothing outstanding to return.':'No tools selected yet.';raEl('raImpact').textContent='';raEl('raErr').textContent='';}
  raEl('raSubmitBtn').disabled=raBusy||!!err||total<1||!raStudent;
  const selected=raLoans.filter(l=>raRowSum(l)>0);
  review.innerHTML=selected.length?`<h4>Return summary</h4><div class="rt-review-counts">${RA_KEYS.map(k=>`<span>${RA_LABEL[k]} <b>${tot[k]}</b></span>`).join('')}</div><ul>${selected.map(l=>`<li><b>${escapeHtml(l.tool_name||l.tool_code)}</b><span>${RA_KEYS.filter(k=>raVal(raEl(`ra_${l.id}_${k}`))>0).map(k=>`${RA_LABEL[k]} ${raVal(raEl(`ra_${l.id}_${k}`))}`).join(' · ')}</span><small>${Math.max(0,l.outstanding-raRowSum(l))} still on loan after this return</small></li>`).join('')}</ul><p>${err?escapeHtml(err):`${total} unit(s) selected · ${left} unit(s) remain on loan`}</p>`:'<h4>Return summary</h4><p>Select tools and enter the quantities being returned.</p>';
  const active=!raStudent?0:total>0&&!err?2:1;
  ['rtStepFind','rtStepSelect','rtStepConfirm'].forEach((id,i)=>{const step=raEl(id);if(!step)return;step.classList.toggle('rt-step-done',i<active);if(i===active)step.setAttribute('aria-current','step');else step.removeAttribute('aria-current');});
  if(!raBusy){const button=raEl('raSubmitBtn');const desktop=button.querySelector('.rt-desktop-only');const content=desktop?desktop.innerHTML:button.innerHTML;button.innerHTML=`<span class="rt-desktop-only">${content}</span><span class="rt-mobile-only"><i class="fas fa-check-double" aria-hidden="true"></i> Confirm Return</span>`;}
}
function rtToggleHistory(button){
  const expanded=button.closest('tr').classList.toggle('rt-expanded');
  button.setAttribute('aria-expanded',String(expanded));
  button.innerHTML=`${expanded?'Hide details':'Details'} <i class="fas fa-chevron-${expanded?'up':'down'}" aria-hidden="true"></i>`;
}
rtMobileMedia.addEventListener('change',rtArrangeMobile);
rtArrangeMobile();

/* Return Management presentation; all actions call the existing return/scanner handlers. */
let rtView='return',rtOverviewGeneration=0;
function rtSetView(view,load=true){
 rtView=view==='history'?'history':'return';const history=rtView==='history';
 if(history&&returnIdScanning)stopReturnIdScanner();
 raEl('rtWorkspace').hidden=history;raEl('rtHome').hidden=history;raEl('returnHistorySection').hidden=!history;
 raEl('rtReturnTab').setAttribute('aria-pressed',String(!history));raEl('rtHistoryTab').setAttribute('aria-pressed',String(history));
 if(history){raEl('returnHistoryTableContainer').classList.remove('collapsed');raEl('returnHistoryChevron').classList.add('open');if(load)loadReturnHistory();}
}
function rtQuick(action){
 rtSetView('return');
 const target=raEl(action==='scan'?'returnIdScanZone':action==='tools'&&raStudent?'returnAllCard':'rtBorrowerCard');
 target.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
 if(action==='scan')startReturnIdScanner();
 else if(action==='tools'&&raStudent)raEl('rtToolsHeading').focus({preventScroll:true});
 else raEl('raSearch').focus({preventScroll:true});
}
async function rtLoadOverview(){
 const generation=++rtOverviewGeneration;
 try{
  const d=(await apiFetch(API+'/transactions.php?type=return_overview')).data;if(generation!==rtOverviewGeneration)return;
  raEl('rtUnitsOnLoan').textContent=d.units_on_loan;raEl('rtOverdueLoans').textContent=d.overdue_loans;raEl('rtReturnedToday').textContent=d.returned_today;
 }catch(_){if(generation===rtOverviewGeneration)['rtUnitsOnLoan','rtOverdueLoans','rtReturnedToday'].forEach(id=>raEl(id).textContent='—');}
}
function rtOnOpen(){rtSetView(rtView);rtLoadOverview();rtSyncMobileUI();}
