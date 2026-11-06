let lossState=null,lossBusy=false;
async function lossMount(source,id,container){
 const d=(await chargeFetch(`${API}/writeoffs.php?source=${source}&id=${id}`)).data;
 lossState={source,id,d,container};document.querySelectorAll('#lossPanel').forEach(panel=>panel.remove());
 const admin=window.CURRENT_ROLE==='Admin',esc=escapeHtml;
 const history=d.requests.map(w=>`<article class="rp-history-item"><b>Request #${Number(w.id)} · ${Number(w.quantity)} units · ${esc(w.status)}</b><p>${esc(w.reason)}</p><p>${esc(w.investigation_notes)}</p><small>Requested by ${esc(w.requester_name)} · ${esc(rrFmtDT(w.requested_at))}</small>${w.decided_at?`<p>${esc(w.decision_name)} · ${esc(rrFmtDT(w.decided_at))} ${esc(w.rejection_reason||'')}</p>`:''}<p>Later recovered: ${Number(w.recovered_quantity)} · Accountability: ${esc(w.accountability_status)}</p>${w.events.map(e=>`<p>${esc(e.action)} · ${Number(e.quantity)} units · ${esc(e.condition_name||'')} · ${esc(e.user_name)} · ${esc(rrFmtDT(e.created_at))}<br>${esc(e.notes)}</p>`).join('')}</article>`).join('');
 const button=(label,action,wid=0,max=d.eligible)=>`<button type="button" class="card-btn secondary" data-loss-action="${action}" data-loss-wid="${Number(wid)}" data-max="${Number(max)}" onclick="return lossSave('${action}',${Number(wid)})">${label}</button>`;
 let buttons=Number(d.eligible)>0&&!Number(d.pending_quantity)?button(admin?'Write Off as Lost':'Request Write-off',admin?'direct':'request'):'';
 for(const w of d.requests){if(admin&&w.status==='pending')buttons+=button('Approve write-off #'+Number(w.id),'approve',w.id)+button('Reject write-off #'+Number(w.id),'reject',w.id);if(admin&&w.status==='approved'&&Number(w.quantity)>Number(w.recovered_quantity))buttons+=button('Record recovery after write-off #'+Number(w.id),'recover',w.id,Number(w.quantity)-Number(w.recovered_quantity));}
 container.insertAdjacentHTML('beforeend',`<div id="lossPanel"><section class="iu-overview-extra"><p>${Number(d.eligible)>0?`Missing for ${Number(d.age_days)} days${d.needs_review?' · Needs Review':''}.`:''} Review reminder: ${Number(d.review_days)} days.</p><p>Gross written off: ${Number(d.gross_written_off)} · Later recovered: ${Number(d.later_recovered)} · Net outstanding loss: ${Number(d.net_loss)}</p><p><b>Borrower accountability:</b> ${d.requests.some(w=>w.status==='approved')?'Review required — separate from stock closure.':'Unchanged; retained with the original incident.'}</p><a class="card-btn secondary" href="${API}/reports.php?type=loss_history&download=1">Written Off / Lost History (CSV)</a></section><section class="iu-history-extra"><h4>Write-off decisions and later recoveries</h4>${history||'<p>No write-off requests recorded.</p>'}</section>${buttons?`<section class="rp-block iu-action-group"><h4>Loss action</h4>${d.requests.filter(w=>w.status==='pending').map(w=>`<p class="iu-pending-note">Pending request #${Number(w.id)}: ${Number(w.quantity)} units · ${esc(w.reason)}<br>${esc(w.investigation_notes)}</p>`).join('')}<p>Approval removes missing units from active stock. Availability and borrower accountability remain unchanged.</p><div data-loss-field="quantity"><label class="field-label" for="lossQty">Quantity (eligible missing: ${Number(d.eligible)})</label><input class="form-input-full" id="lossQty" type="number" min="1" step="1" value="1"></div><div data-loss-field="reason"><label class="field-label" for="lossReason">Reason</label><textarea class="form-input-full" id="lossReason" maxlength="2000"></textarea></div><div data-loss-field="notes"><label class="field-label" for="lossNotes">Investigation / recovery inspection notes</label><textarea class="form-input-full" id="lossNotes" maxlength="2000"></textarea></div><div data-loss-field="condition"><label class="field-label" for="lossCondition">Inspected recovery condition</label><select class="form-input-full" id="lossCondition"><option value="">Select inspected condition</option><option value="good">Good</option><option value="minor">Minor wear</option><option value="damaged">Damaged — unavailable, create damage case</option></select></div><div class="rp-actions">${buttons}</div></section>`:''}</div>`);
}
async function lossSave(action,writeoff_id=0){
 if(lossBusy||!lossState)return;
 const {source,id,container}=lossState;
 const quantity=Number(document.getElementById('lossQty')?.value||0),reason=document.getElementById('lossReason')?.value.trim()||'',notes=document.getElementById('lossNotes')?.value.trim()||'',condition=document.getElementById('lossCondition')?.value||'';
 const modal=container.closest('.modal-overlay')?.id;
 const invalid=message=>{showToast(message,'error');if(typeof incidentError==='function')incidentError(modal,message);};
 if(!['approve','reject'].includes(action)&&(!Number.isInteger(quantity)||quantity<1)){invalid('Enter a positive whole-number quantity.');return;}
 if(['request','direct','reject'].includes(action)&&!reason){invalid('A reason is required.');return;}
 if(['request','recover'].includes(action)&&!notes){invalid('Investigation / inspection notes are required.');return;}
 if(action==='recover'&&!condition){invalid('Select the inspected condition.');return;}
 lossBusy=true;
 try{
  const signature=JSON.stringify({source,id,action,writeoff_id,quantity,reason,notes,condition});
  const retry=lossState.retry?.signature===signature?lossState.retry:null;
  const fresh=(await chargeFetch(`${API}/writeoffs.php?source=${source}&id=${id}`)).data;
  const qty=action==='approve'?Number(fresh.requests.find(w=>Number(w.id)===writeoff_id)?.quantity||0):quantity;
  if(['approve','direct'].includes(action)){
   if(!retry&&(qty<1||qty>Number(fresh.eligible))){invalid('Eligible missing quantity changed. Refresh and review the case.');return;}
   const preview=retry?.preview||fresh;
   const approved=typeof incidentConfirmWriteoff==='function'?await incidentConfirmWriteoff(preview,qty):confirm(`Write off ${qty} missing units of ${preview.tool.name}? Active total will change from ${preview.tool.quantity} to ${Number(preview.tool.quantity)-qty}. Available quantity will remain ${preview.tool.available}. The loss record and borrower accountability will be retained.`);if(!approved)return;
  }
  if(action==='recover'&&!confirm(`Restore ${qty} written-off units after inspection as ${condition}? This adds ${qty} to active stock. Borrower accountability needs a separate review.`))return;
  container.querySelectorAll('button').forEach(b=>b.disabled=true);
  if(lossState.retry?.signature!==signature)lossState.retry={signature,preview:fresh,payload:{source,id,action,writeoff_id,quantity,reason,notes,condition,expected_total:Number(fresh.tool.quantity),expected_available:Number(fresh.tool.available),request_token:rpToken()}};
  await chargeFetch(`${API}/writeoffs.php`,{method:'POST',body:JSON.stringify(lossState.retry.payload)});
  if(typeof incidentSaved==='function')incidentSaved(modal);showToast('Loss action recorded.');
  if(source==='missing'&&modal!=='rpDetailsModal')await renderMissingCase(id);else await rpView(modal==='rpDetailsModal'?rpCurrent.request.id:id);
  mtRefreshAll();rpRefreshInventory();
 }catch(error){if(typeof incidentError==='function')incidentError(modal,error.message||'Could not save. Your entries have been retained.');}
 finally{lossBusy=false;container.querySelectorAll('button').forEach(b=>b.disabled=false);}
}
