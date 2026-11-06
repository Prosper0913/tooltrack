/* Damage & Loss Charges: incident form, case details, charge setting, payment recording and verification.
   Students pay the school; nothing here changes inventory. */
let rpCsrf='',rpMetaPromise=null,rpSourcesData=[],rpCurrent=null,rpBusy=false,rpCreateToken='',rpSourceRequest=0;
const rpRetryTokens=new Map();
const rrFmtDT=value=>bhFormatDate(value,true);
const rpToken=()=>Array.from(crypto.getRandomValues(new Uint8Array(18)),x=>x.toString(16).padStart(2,'0')).join('');
const RP_STATUS={legacy:'Legacy — no charge assessed',not_applicable:'No charge (historical record)',under_review:'Under Review',awaiting_payment:'Unpaid',partially_paid:'Partially Paid',verification_pending:'For Verification',confirmed:'Paid',waived:'No Charge / Not Responsible',closed:'Closed'};
const rpPeso=value=>value===null||value===undefined?'Not assessed':new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(value);
async function chargeFetch(url,options={}){
  if(['replacement_requests.php'].some(file=>url.includes(file))&&options.method&&options.method!=='GET'){
    if(!rpCsrf){if(!rpMetaPromise)rpMetaPromise=apiFetch(`${API}/replacement_requests.php?payment_meta=1`).then(r=>rpCsrf=r.data.csrf).finally(()=>rpMetaPromise=null);await rpMetaPromise;}
    const body=JSON.parse(options.body||'{}');body.csrf=rpCsrf;const retryKey=options.method+JSON.stringify(body);
    if(!body.request_token){if(!rpRetryTokens.has(retryKey))rpRetryTokens.set(retryKey,rpToken());body.request_token=rpRetryTokens.get(retryKey);}
    const result=await apiFetch(url,{...options,body:JSON.stringify(body)});rpRetryTokens.delete(retryKey);return result;
  }return apiFetch(url,options);
}
async function rpOpenIncident(){
  if(typeof bqSetMode==='function')bqSetMode(false);
  document.getElementById('rpIncidentReason').value='damaged';document.getElementById('rpIncidentNotes').value='';document.getElementById('rpSourceSearch').value='';rpCreateToken=rpToken();openModal('rpIncidentModal');await rpLoadSources();
}
async function rpLoadSources(){
  const request=++rpSourceRequest;const select=document.getElementById('rpIncidentSource');select.innerHTML='<option value="">Loading evidence…</option>';
  try{const data=(await chargeFetch(`${API}/replacement_requests.php?sources=1&reason=${document.getElementById('rpIncidentReason').value}`)).data||[];if(request!==rpSourceRequest)return;rpSourcesData=data;rpFilterSources();}
  catch(_){if(request!==rpSourceRequest)return;select.innerHTML='<option value="">Could not load evidence. Reopen this form.</option>';}
}
function rpFilterSources(){
  const select=document.getElementById('rpIncidentSource'),keep=select.value,q=document.getElementById('rpSourceSearch').value.trim().toLocaleLowerCase();
  const rows=rpSourcesData.filter(s=>`${s.full_name} ${s.id_number} ${s.tool_name} ${s.tool_code} ${s.reference}`.toLocaleLowerCase().includes(q));
  select.innerHTML='<option value="">'+(rows.length?'Choose student, tool and evidence':'No eligible evidence matches. Confirm missing reports first.')+'</option>'+rows.map(s=>`<option value="${s.source_id}">${escapeHtml(s.full_name)} (${escapeHtml(s.id_number)}) · ${escapeHtml(s.tool_name)} · ${escapeHtml(s.reference)} · ${s.remaining_units} unit(s)</option>`).join('');
  if(rows.some(s=>String(s.source_id)===keep))select.value=keep;rpSourceChanged();
}
function rpSourceChanged(){
  const s=rpSourcesData.find(r=>String(r.source_id)===document.getElementById('rpIncidentSource').value),qty=document.getElementById('rpIncidentQty');qty.max=s?s.remaining_units:1;qty.value=Math.min(Math.max(1,parseInt(qty.value)||1),s?s.remaining_units:1);
  document.getElementById('rpIncidentEvidence').textContent=s?`${s.full_name} · ${s.id_number} · ${s.tool_code} · ${s.reference}. Up to ${s.remaining_units} unit(s) are unassigned.`:'Only genuine damaged returns and confirmed missing cases for students are eligible. Creating an incident does not charge the student.';
}
async function rpSubmitIncident(){
  const s=rpSourcesData.find(r=>String(r.source_id)===document.getElementById('rpIncidentSource').value);if(!s){showToast('Select eligible student/tool evidence.','error');return;}
  const qty=Number(document.getElementById('rpIncidentQty').value);if(!Number.isInteger(qty)||qty<1||qty>s.remaining_units){showToast('Enter a valid incident quantity.','error');return;}
  if(rpBusy)return;rpBusy=true;setLoading('rpIncidentSubmit',true);
  try{await chargeFetch(`${API}/replacement_requests.php`,{method:'POST',body:JSON.stringify({action:'create_incident',reason:document.getElementById('rpIncidentReason').value,source_id:Number(s.source_id),tool_id:Number(s.tool_id),responsible_borrower_id:Number(s.borrower_id),quantity_needed:qty,notes:document.getElementById('rpIncidentNotes').value,request_token:rpCreateToken})});showToast('Incident recorded. Set the charge to continue.');closeModal('rpIncidentModal');loadChargeCases(1);loadMissingCases();}
  catch(_){}finally{rpBusy=false;setLoading('rpIncidentSubmit',false);}
}
async function rpView(id){
 openModal('rpDetailsModal');const box=document.getElementById('rpDetailsBody');box.innerHTML='<p>Loading charge history…</p>';rpCurrent=null;
 try{
  const d=(await chargeFetch(API+'/replacement_requests.php?payment_history='+Number(id))).data;
  if(d.request.parent_case_id)return rpView(Number(d.request.parent_case_id));
  rpCurrent=d;const r=d.request;
  const admin=window.CURRENT_ROLE==='Admin',payment=r.workflow_mode==='payment',pending=d.claims.find(c=>c.status==='pending'),hasHistory=d.claims.some(c=>['confirmed','voided'].includes(c.status));
  const btn=(text,action,claim=0)=>'<button type="button" class="card-btn primary" onclick="rpSave(\''+action+'\','+Number(claim)+')">'+text+'</button>';
  const incident=r.reason==='mixed'?'Damage & Loss':(r.reason==='lost'?'Loss':'Damage');
  const pendingTotal=d.claims.filter(c=>c.status==='pending').reduce((sum,c)=>sum+Number(c.amount),0);
  let controls='';
  if(payment){
   controls='<div class="rp-block" id="cwpayment"><h4>Payment Information</h4><p class="rp-note">Students pay the school for damaged or lost tools. Record each payment, then verify it against the school receipt.</p>';
   const bulk=!!d.items?.length;
   if(admin||!hasHistory)controls+='<label class="field-label" for="rpCharge">Total charge (PHP)</label><input type="number" min="0.01" step="0.01" id="rpCharge" class="form-input-full" value="'+escapeHtml(r.amount_due||'')+'"'+(bulk?' readonly title="Calculated from item unit charges."':'')+'>';
   if(Number(r.balance)>0&&!pending&&!Number(r.needs_financial_review)){
    controls+='<label class="field-label" for="rpClaimAmount">Payment amount (PHP), up to '+rpPeso(r.balance)+'</label><input class="form-input-full" type="number" step="0.01" min="0.01" max="'+escapeHtml(r.balance)+'" id="rpClaimAmount" value="'+escapeHtml(r.balance)+'"><label class="field-label" for="rpReceipt">School receipt / reference</label><input class="form-input-full" id="rpReceipt" maxlength="80" placeholder="Receipt number"><label class="field-label" for="rpPaymentDate">Payment date</label><input class="form-input-full" id="rpPaymentDate" type="date" min="'+escapeHtml(String(r.charge_assessed_at||r.created_at).slice(0,10))+'" max="'+localToday()+'" value="'+localToday()+'">';
   }
   if(pending)controls+='<p class="rp-warning">Awaiting verification: '+rpPeso(pending.amount)+' · '+escapeHtml(pending.payment_date)+'</p><label class="mt-check"><input type="checkbox" id="rpReceived"> I confirm this payment was actually received.</label>';
   controls+='<label class="field-label" for="rpActionNotes">Assessment / confirmation / correction notes</label><textarea class="form-input-full" id="rpActionNotes" rows="3" maxlength="500"></textarea><div class="rp-actions">';
   if(admin||!hasHistory)controls+=bulk?(admin?'<button type="button" class="card-btn primary" onclick="bqCorrectCharges()">Save Item Charge Corrections</button>':''):btn(hasHistory?'Correct charge (Admin)':'Set charge','review_charge');
   if(Number(r.balance)>0&&!pending&&!Number(r.needs_financial_review))controls+=btn('Record payment','claim_payment');
   if(pending&&!Number(r.needs_financial_review))controls+=btn('Confirm payment','confirm_payment',pending.id)+btn('Reject entry','reject_payment',pending.id);
   if(admin&&Number(r.needs_financial_review))controls+=btn('Complete recovery review','acknowledge_recovery');
   controls+='</div></div>';
  }
  const history=d.claims.map(c=>'<div class="rp-history-item"><b>'+escapeHtml(c.receipt_number)+' · '+rpPeso(c.amount)+' · '+escapeHtml(c.status)+'</b><small>Payment date: '+escapeHtml(c.payment_date)+' · Recorded by '+escapeHtml(c.submitted_by_name)+'</small>'+(c.verified_by_name?'<small>Verified by '+escapeHtml(c.verified_by_name)+' ('+escapeHtml(c.verified_by_role||'Historical role unavailable')+') · '+rrFmtDT(c.verified_at)+' · '+escapeHtml(c.verification_notes||'')+'</small>':'')+(c.status==='voided'?'<small>Voided by '+escapeHtml(c.voided_by_name)+' · '+rrFmtDT(c.voided_at)+' · '+escapeHtml(c.void_reason)+'</small>':'')+(admin&&c.status==='confirmed'?btn('Void / correct payment','void_payment',c.id):'')+'</div>').join('')||'<p class="rp-note">No payment entries.</p>';
  const events=d.events.map(e=>'<div class="rp-history-item"><b>'+escapeHtml(e.action.replaceAll('_',' '))+'</b><small>'+escapeHtml(e.user_name)+' ('+escapeHtml(e.user_role)+') · '+rrFmtDT(e.created_at)+'</small><small>'+escapeHtml(e.notes||'')+'</small></div>').join('');
  const itemsBlock=d.items?.length?'<section class="rp-block" id="bqCharges"><h4>Charges by Tool</h4><div class="bq-charge-lines">'+d.items.map(item=>`<article class="rp-history-item"><b>${escapeHtml(item.tool_name_snapshot)} · ${escapeHtml(item.tool_code||'')}</b><small>${item.reason==='lost'?'Lost':'Damaged'} · ${Number(item.quantity_needed)} × ${rpPeso(item.assessed_unit_charge)} = ${rpPeso(item.line_subtotal)}</small><small>${item.sources.map(s=>escapeHtml(s.borrowing_reference||s.missing_reference||s.return_reference||'')+' · '+escapeHtml(s.missing_reference||s.return_reference||'')).join('<br>')}</small>${admin?`<label class="field-label" for="bqCorrection${Number(item.id)}">Correct unit charge (PHP)</label><input id="bqCorrection${Number(item.id)}" class="form-input-full bq-correction" type="number" min="0.01" step="0.01" value="${escapeHtml(item.assessed_unit_charge)}" data-item="${Number(item.id)}">`:''}</article>`).join('')+'</div></section>':'';
  box.innerHTML='<div class="rp-summary"><div><span>Case #'+Number(r.id)+'</span><b>'+escapeHtml(r.student_name||r.responsible_name_snapshot||'Historical borrower')+'</b><small>'+escapeHtml(r.tool_name_snapshot)+' · '+escapeHtml(incident)+' · '+Number(r.incident_quantity||r.quantity_needed)+' unit(s)</small></div><div><span>Charge</span><b>'+rpPeso(r.amount_due)+'</b><small>Paid '+rpPeso(r.amount_paid)+' · Balance '+rpPeso(r.balance)+'</small><b>'+escapeHtml(payment?r.payment_status:(RP_STATUS[r.financial_status]||r.financial_status))+'</b></div></div>'
   +(Number(r.needs_financial_review)?'<p class="rp-warning">Original recovered: Admin financial review required. Payments have not been changed.</p>':'')
   +'<section class="rp-block" id="cwIncident"><h4>Incident Information</h4><p class="rp-note">Student: '+escapeHtml(r.student_name||r.responsible_name_snapshot||'')+'<br>Student ID: '+escapeHtml(r.student_id||'Historical ID unavailable')+'<br>Tool: '+escapeHtml(r.tool_name_snapshot)+' · SKU: '+escapeHtml(r.tool_code||'Unavailable')+'<br>Incident date: '+escapeHtml(String(r.incident_date||r.created_at))+'<br>Description: '+escapeHtml(r.notes||'No description recorded.')+'</p><p class="rp-note">'+d.sources.map(s=>escapeHtml(s.return_reference||s.missing_reference||'Historical evidence')+' · '+Number(s.quantity)+' unit(s)').join('<br>')+'</p></section>'
   +'<p class="rp-note">Assessed: '+rpPeso(r.amount_due)+' · Verified paid: '+rpPeso(r.amount_paid)+' · Outstanding: '+rpPeso(r.balance)+' · Pending verification: '+rpPeso(pendingTotal)+'</p>'
   +itemsBlock+controls+'<div class="rp-block" id="cwpaymentHistory"><h4>Payment history</h4>'+history+'</div><div class="rp-block"><h4>Audit history</h4>'+events+'</div>';
 }catch(_){box.innerHTML='<p>Could not load the case. Close and reopen it.</p>';}
}
async function rpSave(action,claimId=0){
 if(rpBusy||!rpCurrent)return;const r=rpCurrent.request,value=id=>document.getElementById(id)?.value||'';
 if(action==='confirm_payment'&&!document.getElementById('rpReceived')?.checked){showToast('Confirm the payment was actually received.','error');return;}
 if(action==='void_payment'&&!confirm('Void this confirmed payment? It remains in history and the balance will be recalculated. To correct it, record a new payment with its own reference.'))return;
 const body={id:Number(r.id),action,version:Number(r.payment_version),notes:value('rpActionNotes')};
 if(action==='review_charge')Object.assign(body,{responsibility:'liable',amount_due:value('rpCharge')});
 if(action==='claim_payment')Object.assign(body,{amount:value('rpClaimAmount'),payment_date:value('rpPaymentDate'),receipt_number:value('rpReceipt')});
 if(['confirm_payment','reject_payment','void_payment'].includes(action))Object.assign(body,{claim_id:claimId,received_confirmed:!!document.getElementById('rpReceived')?.checked});
 rpBusy=true;document.querySelectorAll('#rpDetailsBody button').forEach(b=>b.disabled=true);
 try{await chargeFetch(API+'/replacement_requests.php',{method:'PATCH',body:JSON.stringify(body)});showToast('Charge / payment updated.');await rpView(r.id);loadChargeCases();if(typeof loadChargeReport==='function')loadChargeReport();}
 catch(_){}finally{rpBusy=false;document.querySelectorAll('#rpDetailsBody button').forEach(b=>b.disabled=false);}
}
