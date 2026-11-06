/* Historical filename retained; this module now handles incidents and physical resolution only. */
let rpCsrf='',rpMetaPromise=null,rpCurrent=null,rpBusy=false,rpCreateToken='';
const rpRetryTokens=new Map();
const rrFmtDT=value=>bhFormatDate(value,true);
const rpToken=()=>Array.from(crypto.getRandomValues(new Uint8Array(18)),x=>x.toString(16).padStart(2,'0')).join('');
async function chargeFetch(url,options={}){
 if((url.includes('replacement_requests.php')||url.includes('writeoffs.php'))&&options.method&&options.method!=='GET'){
  if(!rpCsrf){if(!rpMetaPromise)rpMetaPromise=apiFetch(`${API}/replacement_requests.php?meta=1`).then(r=>rpCsrf=r.data.csrf).finally(()=>rpMetaPromise=null);await rpMetaPromise;}
  const body=JSON.parse(options.body||'{}');body.csrf=rpCsrf;const key=options.method+JSON.stringify(body);
  if(!body.request_token){if(!rpRetryTokens.has(key))rpRetryTokens.set(key,rpToken());body.request_token=rpRetryTokens.get(key);}
  const result=await apiFetch(url,{...options,body:JSON.stringify(body)});rpRetryTokens.delete(key);return result;
 }
 return apiFetch(url,options);
}
function rpOpenIncident(){
 rpCreateToken=rpToken();for(const id of ['rpManualTool','rpManualBorrower','rpIncidentNotes'])document.getElementById(id).value='';
 document.getElementById('rpIncidentQty').value=1;document.getElementById('rpIncidentReason').value='damaged';openModal('rpIncidentModal');
}
async function rpSubmitIncident(){
 if(rpBusy)return;rpBusy=true;setLoading('rpIncidentSubmit',true);
 try{await chargeFetch(API+'/replacement_requests.php',{method:'POST',body:JSON.stringify({action:'manual_incident',tool_code:document.getElementById('rpManualTool').value,borrower_identifier:document.getElementById('rpManualBorrower').value,reason:document.getElementById('rpIncidentReason').value,quantity_needed:Number(document.getElementById('rpIncidentQty').value),notes:document.getElementById('rpIncidentNotes').value,request_token:rpCreateToken})});closeModal('rpIncidentModal');rpRefreshInventory();showToast('Incident recorded.');}catch(_){}finally{rpBusy=false;setLoading('rpIncidentSubmit',false);}
}
function rpRefreshInventory(){
 loadChargeCases();if(typeof refreshToolStockViews==='function')refreshToolStockViews();if(typeof loadNotifications==='function')loadNotifications();
}
async function rpView(id){
 document.getElementById('rpDetailsTitle').textContent='Damage & Loss — Case Details';
 openModal('rpDetailsModal');const box=document.getElementById('rpDetailsBody');box.innerHTML='<p>Loading incident…</p>';rpCurrent=null;
 try{
  const d=(await apiFetch(API+'/replacement_requests.php?details='+Number(id))).data;rpCurrent=d;const r=d.request;
  const lines=[['Borrower',r.student_name||r.responsible_name_snapshot],['Borrower ID',r.student_id],['Tool',`${r.tool_name_snapshot} · ${r.tool_code||r.tool_id||''}`],['Incident',r.reason==='lost'?'Missing':'Damaged'],['Affected quantity',r.incident_quantity||r.quantity_needed],['Reported',rrFmtDT(r.created_at)],['Responsible staff',r.requested_by_snapshot],['Description / evidence',r.notes]];
  box.innerHTML='<div class="rp-summary"><div><span>Incident DLI-'+String(r.id).padStart(5,'0')+'</span><b>'+escapeHtml(r.inventory_status)+'</b></div></div><section class="rp-block"><h4>Incident Information</h4>'+lines.map(([k,v])=>'<p><b>'+k+':</b> '+escapeHtml(String(v??'Unavailable'))+'</p>').join('')+d.sources.map(s=>'<p class="rp-note">'+escapeHtml(s.borrowing_reference||'')+' · '+escapeHtml(s.return_reference||s.missing_reference||'')+' · '+Number(s.quantity)+' unit(s)</p>').join('')+'</section>'+caseInventoryPanel(r,window.CURRENT_ROLE==='Admin')+'<section class="rp-block"><h4>Activity History</h4>'+(d.events.map(e=>'<div class="rp-history-item"><b>'+escapeHtml(e.action.replaceAll('_',' '))+'</b><small>'+escapeHtml(e.user_name)+' · '+escapeHtml(rrFmtDT(e.created_at))+'</small><p>'+escapeHtml(e.notes||'')+'</p></div>').join('')||'<p>No incident activity recorded.</p>')+'</section>';
 if(r.reason==='lost')await lossMount(r.missing_report_id?'missing':'case',Number(r.missing_report_id||r.id),box);
 incidentDecorate('rpDetailsModal',box,{caseId:'DLI-'+String(r.id).padStart(5,'0'),tool:r.tool_name_snapshot,sku:r.tool_code,status:d.lifecycle?.inventory_status||(r.reason==='lost'?lossState.d.outcome:r.inventory_status),kind:r.reason,original:r.incident_quantity||r.quantity_needed,remaining:r.reason==='lost'?lossState.d.eligible:r.unresolved_quantity,recovered:r.reason==='lost'?lossState.d.recovered:r.resolved_quantity,writtenoff:r.reason==='lost'?lossState.d.gross_written_off:0,age_days:r.reason==='lost'?lossState.d.age_days:Math.max(0,Math.floor((Date.now()-parseDbDate(r.created_at).getTime())/86400000)),closed:Boolean(Number(d.lifecycle?.is_closed)),closed_at:d.lifecycle?.closed_at,needs_review:Boolean(Number(d.lifecycle?.needs_review))});
 }catch(_){box.innerHTML='<p>Could not load this incident. Close and reopen it.</p>';}
}
function caseInventoryPanel(r,admin){
 const allowed={'Pending Review':['repair','available','dispose','replacement'],'Under Repair':['repair','available','dispose','replacement'],Missing:['recovered'],'Partially Closed':['recovered'],'Confirmed Lost':['recovered'],'Awaiting Replacement':['received'],Recovered:['available','repair']};
 const labels={repair:'Under Repair',available:'Return to Available',dispose:'Dispose Tool',lost:'Confirm Lost',replacement:'Awaiting Replacement',received:'Replacement Received',recovered:'Record Recovery — inspect next'};
 if(['Resolved','Rejected'].includes(r.inventory_status))return '';
 const linked=r.missing_report_id?`<button type="button" class="card-btn secondary" onclick="closeModal('rpDetailsModal');openMissingCase(${Number(r.missing_report_id)})">Missing Report / Confirmation</button>`:'';
 const remaining=Number(r.unresolved_quantity),original=Number(r.incident_quantity||r.quantity_needed);
 return `<section class="rp-block iu-action-group"><h4>Physical Resolution</h4><p>Original: ${original} units · Unresolved: ${remaining} · Under repair: ${Number(r.repair_quantity||0)}</p><p>${escapeHtml(r.inventory_status)}</p>${linked}<label class="field-label" for="caseInventoryQty">Quantity affected by this action</label><input id="caseInventoryQty" class="form-input-full" type="number" min="1" max="${remaining}" step="1" value="${remaining}"><label class="field-label" for="caseInventoryNotes">Inspection, resolution notes or purchase reference</label><textarea id="caseInventoryNotes" class="form-input-full" maxlength="500"></textarea><div class="rp-actions">${admin?(allowed[r.inventory_status]||[]).map(a=>`<button type="button" class="card-btn secondary" onclick="return caseInventorySave('${a}')">${labels[a]}</button>`).join(''):`<button type="button" class="card-btn secondary" onclick="return caseInventorySave('request')">Request inventory resolution</button>`}</div></section>`;
}
async function caseInventorySave(action){
 if(rpBusy||!rpCurrent)return;
 if(action==='dispose'){const qty=Number(document.getElementById('caseInventoryQty').value);incidentTransition('rpDetailsModal');closeModal('rpDetailsModal');await openToolDisposal(rpCurrent.request.tool_id,rpCurrent.request.id,qty);return;}
 if(['dispose','received'].includes(action)&&!confirm(action==='dispose'?'Authorize disposal of these affected units?':'Confirm replacement units were physically received?'))return;
 const r=rpCurrent.request;rpBusy=true;document.querySelectorAll('#rpDetailsBody button').forEach(b=>b.disabled=true);
 try{await chargeFetch(API+'/replacement_requests.php',{method:'PATCH',body:JSON.stringify({id:Number(r.id),version:Number(r.payment_version),action:'inventory_resolution',inventory_action:action,quantity:Number(document.getElementById('caseInventoryQty').value),notes:document.getElementById('caseInventoryNotes').value,received_confirmed:action==='received'})});incidentSaved('rpDetailsModal');await rpView(r.id);rpRefreshInventory();showToast('Inventory resolution saved.');}catch(error){incidentError('rpDetailsModal',error.message||'Could not save. Your entries have been retained.');}finally{rpBusy=false;document.querySelectorAll('#rpDetailsBody button').forEach(b=>b.disabled=false);}
}
async function rpViewReturn(id){
 openModal('rpDetailsModal');const box=document.getElementById('rpDetailsBody');box.innerHTML='<p>Loading historical return…</p>';
 try{const r=(await apiFetch(API+'/replacement_requests.php?return_id='+Number(id))).data.return;
 box.innerHTML='<section class="rp-block"><h4>Historical damaged return '+escapeHtml(r.txn_id)+'</h4><p>'+escapeHtml(r.borrower||'Historical borrower')+' · '+escapeHtml(r.tool_name)+' · '+Number(r.qty)+' unit(s)</p><p>'+escapeHtml(r.notes||'No notes recorded.')+'</p><p class="rp-note">This is existing return evidence. Stock was already updated when returned; subsequent repairs may be recorded in Tools Management and Stock History.</p></section>';
 incidentDecorate('rpDetailsModal',box,{caseId:r.txn_id,tool:r.tool_name,sku:r.tool_code,status:'Historical Return',kind:'damaged',original:r.qty,remaining:null,recovered:null,closed:true,closed_at:r.created_at,historical:true});
 }catch(_){box.innerHTML='<p>Could not load return details.</p>';}
}
