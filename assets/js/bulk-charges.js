/* Multiple affected tools, one financial case and one payment ledger. */
let bqState={students:[],sources:[],lines:[],student:0,mode:false,review:null,busy:false,sequence:0};
function bqSetMode(multiple){
 bqState.mode=multiple;
 for(const [id,hidden] of [['bqPanel',!multiple],['rpSingleFields',multiple],['bqModeButton',multiple],['bqSingleButton',!multiple],['rpIncidentSubmit',multiple],['bqReviewButton',!multiple],['bqConfirmButton',true],['bqBackButton',true]]){const el=document.getElementById(id);if(el)el.hidden=hidden;}
 document.querySelector('#rpIncidentModal .rp-modal')?.classList.toggle('bq-modal',multiple);
 if(multiple)bqBack();
}
async function bqOpenMultiple(){
 bqSetMode(true);document.getElementById('bqError').textContent='Loading eligible incidents…';
 try{
  const d=(await chargeFetch(`${API}/charge_cases.php?bulk_sources=1`)).data;
  bqState={...bqState,students:d.students,sources:d.sources,lines:[],student:0,review:null};
  document.getElementById('bqStudentSearch').value='';document.getElementById('bqNotes').value='';document.getElementById('bqStudentInfo').textContent='';document.getElementById('bqError').textContent='';bqFilterStudents();bqRenderItems();
 }catch(e){document.getElementById('bqError').textContent=e.message||'Could not load eligible incidents.';}
}
function bqFilterStudents(){
 const q=document.getElementById('bqStudentSearch').value.toLowerCase(),select=document.getElementById('bqStudent');
 const rows=bqState.students.filter(s=>`${s.full_name} ${s.id_number}`.toLowerCase().includes(q));
 select.innerHTML='<option value="">Choose a student with eligible incidents</option>'+rows.map(s=>`<option value="${Number(s.id)}">${escapeHtml(s.full_name)} · ${escapeHtml(s.id_number)}</option>`).join('');
 if(rows.some(s=>Number(s.id)===bqState.student))select.value=String(bqState.student);
}
function bqChooseStudent(){
 bqState.student=Number(document.getElementById('bqStudent').value);bqState.lines=[];bqState.review=null;
 const s=bqState.students.find(s=>Number(s.id)===bqState.student);document.getElementById('bqStudentInfo').textContent=s?[s.id_number,s.course,s.year_level,s.section_name].filter(Boolean).join(' · '):'';
 if(s)bqState.lines.push({id:++bqState.sequence,key:'',quantity:1,unit_charge:''});bqRenderItems();
}
function bqSources(){return bqState.sources.filter(s=>Number(s.borrower_id)===bqState.student);}
function bqAddItem(){if(!bqState.student){document.getElementById('bqError').textContent='Choose a student first.';return;}if(bqState.lines.length>=30)return;bqState.lines.push({id:++bqState.sequence,key:'',quantity:1,unit_charge:''});bqRenderItems();}
function bqRemoveItem(id){bqState.lines=bqState.lines.filter(l=>l.id!==id);bqRenderItems();}
function bqSource(line){return bqSources().find(s=>`${s.reason}:${s.source_id}`===line.key);}
function bqCents(value){const m=/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/.exec(String(value));return m?Number(m[1])*100+Number((m[2]||'').padEnd(2,'0')):NaN;}
function bqRenderItems(){
 document.getElementById('bqItems').innerHTML=bqState.lines.map(line=>{
  const source=bqSource(line),subtotal=bqCents(line.unit_charge)*Number(line.quantity);
  return `<article class="bq-item"><div class="bq-item-tool"><label class="field-label" for="bqSource${line.id}">Tool / SKU / incident</label><select id="bqSource${line.id}" class="form-input-full" onchange="bqUpdate(${line.id},'key',this.value)"><option value="">Choose incident evidence</option>${bqSources().map(s=>{const key=`${s.reason}:${s.source_id}`,used=bqState.lines.some(l=>l.id!==line.id&&l.key===key);return `<option value="${key}" ${key===line.key?'selected':''} ${used?'disabled':''}>${escapeHtml(s.tool_name)} · ${escapeHtml(s.tool_code)} · ${s.reason==='lost'?'Lost':'Damaged'} · ${escapeHtml(s.reference)}</option>`;}).join('')}</select><small>${source?`Borrowing: ${escapeHtml(source.borrowing_reference||source.reference)} · ${Number(source.remaining_units)} eligible units`:'Only evidence belonging to this student is available.'}</small></div><div><label class="field-label" for="bqQty${line.id}">Quantity</label><input id="bqQty${line.id}" type="number" class="form-input-full" min="1" step="1" max="${source?.remaining_units||1000}" value="${Number(line.quantity)||''}" oninput="bqUpdate(${line.id},'quantity',this.value)"></div><div><label class="field-label" for="bqUnit${line.id}">Charge per unit (PHP)</label><input id="bqUnit${line.id}" type="number" class="form-input-full" min="0.01" step="0.01" value="${escapeHtml(line.unit_charge)}" oninput="bqUpdate(${line.id},'unit_charge',this.value)"></div><div><span class="field-label">Subtotal</span><b id="bqSubtotal${line.id}">${Number.isFinite(subtotal)?rpPeso(subtotal/100):'—'}</b></div><button type="button" class="card-btn secondary" aria-label="Remove affected tool" onclick="bqRemoveItem(${line.id})">Remove</button></article>`;
 }).join('')||'<p class="rp-note">Select a student and add affected tools.</p>';bqUpdateTotals();
}
function bqUpdate(id,field,value){const line=bqState.lines.find(l=>l.id===id);if(!line)return;line[field]=value;if(field==='key'){line.quantity=1;bqRenderItems();}else{const cents=bqCents(line.unit_charge)*Number(line.quantity);document.getElementById('bqSubtotal'+id).textContent=Number.isFinite(cents)?rpPeso(cents/100):'—';bqUpdateTotals();}}
function bqUpdateTotals(){const tools=new Set(bqState.lines.map(l=>bqSource(l)?.tool_id).filter(Boolean)).size,units=bqState.lines.reduce((a,l)=>a+(Number(l.quantity)||0),0),total=bqState.lines.reduce((a,l)=>a+(bqCents(l.unit_charge)*Number(l.quantity)||0),0);document.getElementById('bqTotals').textContent=`${tools} affected tool(s) · ${units} affected unit(s) · Total ${rpPeso(total/100)}`;}
function bqBack(){bqState.review=null;for(const [id,hidden] of [['bqEditor',false],['bqReview',true],['bqReviewButton',false],['bqBackButton',true],['bqConfirmButton',true]])document.getElementById(id).hidden=hidden;document.getElementById('bqError').textContent='';}
function bqReviewCharges(){
 try{
  if(!bqState.student||!bqState.lines.length)throw Error('Choose a student and at least one affected tool.');
  const keys=new Set();const items=bqState.lines.map(l=>{const s=bqSource(l),quantity=Number(l.quantity),unit=bqCents(l.unit_charge);if(!s||!Number.isInteger(quantity)||quantity<1||quantity>Number(s.remaining_units)||!Number.isFinite(unit)||unit<=0||unit*quantity>999999999999)throw Error('Check evidence, quantities and positive unit charges for each item.');if(keys.has(l.key))throw Error('Combine quantities for the same incident into one row.');keys.add(l.key);return {reason:s.reason,source_id:Number(s.source_id),quantity,unit_charge:(unit/100).toFixed(2)};});
  bqState.review={action:'create_bulk_case',borrower_id:bqState.student,items,notes:document.getElementById('bqNotes').value,request_token:rpToken()};
  const s=bqState.students.find(s=>Number(s.id)===bqState.student);
  document.getElementById('bqReview').innerHTML=`<h4>Review Charges</h4><p class="rp-note">${escapeHtml(s.full_name)} · ${escapeHtml(s.id_number)}</p>`+bqState.lines.map(l=>{const e=bqSource(l);return `<div class="rp-history-item"><b>${escapeHtml(e.tool_name)} · ${escapeHtml(e.tool_code)}</b><small>${escapeHtml(e.reason)} · ${escapeHtml(e.borrowing_reference||e.reference)}<br>${Number(l.quantity)} × ${rpPeso(bqCents(l.unit_charge)/100)} = ${rpPeso(bqCents(l.unit_charge)*Number(l.quantity)/100)}</small></div>`;}).join('')+'<p class="rp-note">Confirm these charges as one case. Payments are recorded and verified separately.</p>';
  for(const [id,hidden] of [['bqEditor',true],['bqReview',false],['bqReviewButton',true],['bqBackButton',false],['bqConfirmButton',false]])document.getElementById(id).hidden=hidden;
  document.getElementById('bqError').textContent='';
 }catch(e){document.getElementById('bqError').textContent=e.message;}
}
async function bqConfirmCharges(){
 if(bqState.busy||!bqState.review)return;bqState.busy=true;document.querySelectorAll('#rpIncidentModal button').forEach(el=>el.disabled=true);
 try{const d=(await chargeFetch(`${API}/replacement_requests.php`,{method:'POST',body:JSON.stringify(bqState.review)})).data;bqState.review=null;closeModal('rpIncidentModal');showToast(`Case #${d.id} saved with one combined balance.`);await loadChargeCases(1);if(typeof loadChargeReport==='function')loadChargeReport();await rpView(d.id);}
 catch(e){document.getElementById('bqError').textContent=e.message||'Saving failed. Retry with the same reviewed charges.';}
 finally{bqState.busy=false;document.querySelectorAll('#rpIncidentModal button').forEach(el=>el.disabled=false);}
}
async function bqCorrectCharges(){
 if(rpBusy||!rpCurrent?.items?.length)return;
 const item_charges={};document.querySelectorAll('.bq-correction').forEach(el=>item_charges[el.dataset.item]=el.value);
 const notes=document.getElementById('rpActionNotes')?.value||'';if(!notes.trim()){showToast('Explain the item charge correction.','error');return;}
 rpBusy=true;
 try{await chargeFetch(`${API}/replacement_requests.php`,{method:'PATCH',body:JSON.stringify({action:'review_charge',id:Number(rpCurrent.request.id),version:Number(rpCurrent.request.payment_version),item_charges,notes})});const id=rpCurrent.request.id;await rpView(id);await loadChargeCases();if(typeof loadChargeReport==='function')loadChargeReport();showToast('Item charges and combined balance updated.');}catch(_){}finally{rpBusy=false;}
}
