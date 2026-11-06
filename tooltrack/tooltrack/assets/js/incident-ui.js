// Scoped presentation and accessibility for the two existing incident detail dialogs.
const incidentViews=new Map();
let incidentReturnFocus=null;
function openToolLossHistory(id){const t=toolMenuCache.get(Number(id));if(!t)return;additionalReportStates.loss_history.search=t.code;viewReport('loss_history','Written Off / Lost History');}
function incidentBusy(){return (typeof lossBusy!=='undefined'&&lossBusy)||(typeof mtBusy!=='undefined'&&mtBusy)||(typeof rpBusy!=='undefined'&&rpBusy);}
function incidentError(id,message){const v=incidentViews.get(id);if(v){v.error.textContent=message;v.error.scrollIntoView({block:'nearest'});}}
function incidentSaved(id){const v=incidentViews.get(id);if(v)v.dirty=false;}
function incidentTransition(id){const v=incidentViews.get(id);if(v){v.dirty=false;v.submitting=false;}}
function incidentCanClose(id){
 const v=incidentViews.get(id);if(!v)return true;
 if(incidentBusy()||v.submitting)return false;
 if(v.dirty&&!confirm('Discard unsaved incident changes?'))return false;
 incidentViews.delete(id);if(v.returnFocus?.isConnected)v.returnFocus.focus();else document.querySelector('.cw-tabs [aria-selected=true]')?.focus();return true;
}
function incidentPrepare(id){
 incidentReturnFocus=document.activeElement;
 const overlay=document.getElementById(id),modal=overlay.querySelector('.modal');
 overlay.querySelector('.iu-submit')?.remove();
 modal.classList.add('iu-modal');modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');
 overlay.querySelector('.modal-close').setAttribute('aria-label','Close incident details');overlay.querySelector('.modal-close').textContent='×';
 requestAnimationFrame(()=>overlay.querySelector('.modal-close')?.focus());
}
function incidentTab(id,tab,focus=false){
 const v=incidentViews.get(id);if(!v)return;
 v.tab=tab;
 v.tabs.querySelectorAll('[role=tab]').forEach(b=>{const selected=b.dataset.tab===tab;b.setAttribute('aria-selected',String(selected));b.tabIndex=selected?0:-1;if(selected&&focus)b.focus();});
 for(const [name,panel]of Object.entries(v.panels))panel.hidden=name!==tab;
 v.submit.hidden=tab!=='actions'||!v.selected;
}
function incidentChoose(id,index){
 const v=incidentViews.get(id),choice=v.choices[index];if(!choice)return;
 if(incidentBusy())return;
 v.selected=choice;
 v.groups.forEach(group=>group.hidden=group!==choice.group);
 v.picker.querySelectorAll('button').forEach((b,i)=>b.setAttribute('aria-pressed',String(i===index)));
 const action=choice.button.dataset.lossAction;
 if(action){
  const fields=choice.group.querySelectorAll('[data-loss-field]');
  fields.forEach(field=>field.hidden=action==='approve'||(field.dataset.lossField==='condition'&&action!=='recover')||(field.dataset.lossField==='quantity'&&action==='reject'));
  const qty=choice.group.querySelector('#lossQty');if(qty){qty.max=choice.button.dataset.max;qty.required=!['approve','reject'].includes(action);}
  const reason=choice.group.querySelector('#lossReason');if(reason)reason.required=['request','direct','reject'].includes(action);
  const notes=choice.group.querySelector('#lossNotes');if(notes)notes.required=['request','recover'].includes(action);
  const condition=choice.group.querySelector('#lossCondition');if(condition)condition.required=action==='recover';
 }
 v.submit.textContent=choice.button.textContent;v.submit.className=choice.tone+' iu-submit';
 v.submit.hidden=false;
 [...choice.group.querySelectorAll('input,textarea,select')].find(field=>field.getClientRects().length)?.focus();
}
function incidentDecorate(modalId,body,meta){
 const overlay=document.getElementById(modalId);if(!overlay)return;
 const old=incidentViews.get(modalId),returnFocus=old?.returnFocus||incidentReturnFocus;
 const footer=overlay.querySelector('.modal-footer'),header=overlay.querySelector('.modal-header');
 header.querySelector('.modal-title').textContent=meta.caseId+' · '+meta.tool;
 header.querySelector('.iu-header-meta')?.remove();
 const sub=document.createElement('span');sub.className='iu-header-meta';sub.innerHTML=`<code>${escapeHtml(meta.sku||'No SKU')}</code> ${iuStatusBadge(meta.status)}`;
 header.querySelector('.modal-title').append(sub);
 const panels={};for(const name of ['overview','actions','history']){const p=document.createElement('section');p.id=modalId+'-'+name;p.setAttribute('role','tabpanel');p.setAttribute('aria-labelledby',modalId+'-tab-'+name);panels[name]=p;}
 const tabs=document.createElement('div');tabs.className='iu-tabs';tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Incident details');
 tabs.innerHTML=['overview','actions','history'].map((name,i)=>`<button role="tab" id="${modalId}-tab-${name}" data-tab="${name}" aria-controls="${modalId}-${name}" aria-selected="${i===0}" tabindex="${i===0?0:-1}">${name[0].toUpperCase()+name.slice(1)}</button>`).join('');
 tabs.onclick=e=>{const b=e.target.closest('[data-tab]');if(b)incidentTab(modalId,b.dataset.tab);};
 const groups=[...body.querySelectorAll('.mt-actions .mt-form,.iu-action-group')];
 const picker=document.createElement('div');picker.className='iu-action-picker';picker.setAttribute('aria-label','Choose an incident action');
 const choices=[];
 groups.forEach(group=>{
  const buttons=[...group.querySelectorAll('button[onclick],button[data-loss-action]')].filter(b=>!b.classList.contains('iu-action-link'));
  if(!group.querySelector('input,textarea,select')){panels.actions.append(group);return;}
  buttons.forEach(button=>{
   const index=choices.length,label=button.textContent.trim(),tone=/write.off|approve/i.test(label)&&!/request/i.test(label)?'btn iu-danger':/request.*write.off/i.test(label)?'btn iu-amber-outline':/recover/i.test(label)?'btn iu-purple':'btn btn-gray';
   choices.push({button,group,tone});button.hidden=true;
   const option=document.createElement('button');option.type='button';option.className=tone;option.textContent=label;option.setAttribute('aria-pressed','false');option.onclick=()=>incidentChoose(modalId,index);picker.append(option);
  });group.hidden=true;panels.actions.append(group);
 });
 body.querySelectorAll('.iu-history-extra,.mt-timeline').forEach(n=>panels.history.append(n));
 body.querySelectorAll('.rp-block').forEach(n=>{if(n.querySelector('h4')?.textContent==='Activity History')panels.history.append(n);});
 body.querySelectorAll('.rp-summary').forEach(n=>n.remove());
 body.querySelectorAll('.mt-h').forEach(n=>{if(n.textContent==='Audit trail')n.remove();});
 body.querySelectorAll('.mt-grid .mt-kv').forEach(n=>{if(['Status','Tool','Reported','Confirmed Missing','Recovered','Outstanding','Recovered beyond open units'].includes(n.querySelector('span')?.textContent))n.remove();});
 const age=meta.closed_at?(meta.historical?'Recorded ':'Closed ')+bhFormatDate(meta.closed_at):meta.closed?'Closure date not recorded':Number(meta.age_days||0)+' days'+(meta.needs_review?' · Needs Review':'');
 const info=document.createElement('p');info.className='iu-case-age';info.textContent=age;panels.overview.append(info);
 while(body.firstChild)panels.overview.append(body.firstChild);
 panels.actions.prepend(picker);
 if(!choices.length){const note=document.createElement('p');note.textContent='No stock actions are currently available for your role and this incident balance.';panels.actions.append(note);}
 if(!panels.history.textContent.trim())panels.history.textContent='No activity recorded.';
 const summary=document.createElement('div');summary.className='iu-summary-strip';
 const amounts=[[meta.historical?'Damaged at return':'Originally reported',meta.original],[meta.kind==='lost'?'Remaining missing':'Unresolved',meta.remaining],[meta.kind==='lost'?'Recovered':'Resolved',meta.recovered]];
 if(meta.kind==='lost')amounts.push(['Written off (gross)',meta.writtenoff||0]);
 summary.innerHTML=amounts.map(([label,n])=>`<div><span>${label}</span><strong>${n===null?'Not tracked':Number(n||0).toLocaleString()}</strong></div>`).join('');
 body.append(summary,tabs,panels.overview,panels.actions,panels.history);
 footer.querySelector('.iu-submit')?.remove();
 const submit=document.createElement('button');submit.type='button';submit.className='btn iu-purple iu-submit';submit.classList.add('iu-submit');submit.hidden=true;footer.append(submit);
 const error=document.createElement('p');error.className='iu-inline-error';error.setAttribute('role','alert');error.id=modalId+'-error';panels.actions.prepend(error);
 const v={tabs,panels,groups,picker,choices,submit,selected:null,tab:'overview',dirty:false,submitting:false,returnFocus,error};incidentViews.set(modalId,v);
 body.oninput=event=>{v.dirty=true;error.textContent='';event.target.removeAttribute('aria-invalid');};
 body.querySelectorAll('input,textarea,select').forEach((field,i)=>{if(!field.id)field.id=modalId+'-field-'+i;let label=field.previousElementSibling;if(label?.matches('label'))label.htmlFor=field.id;field.setAttribute('aria-describedby',error.id);});
 submit.onclick=async()=>{
  if(v.submitting||incidentBusy()||!v.selected)return;
  for(const field of v.selected.group.querySelectorAll('input,select,textarea'))if(field.getClientRects().length&&!field.checkValidity()){field.setAttribute('aria-invalid','true');error.textContent=field.validationMessage;field.focus();return;}
  v.submitting=true;submit.disabled=true;const label=submit.textContent;submit.textContent='Saving…';
  try{await v.selected.button.onclick();}finally{v.submitting=false;submit.disabled=false;submit.textContent=label;}
 };
 incidentTab(modalId,'overview');if(overlay.classList.contains('active')&&!overlay.contains(document.activeElement))overlay.querySelector('.modal-close').focus();
}
async function incidentConfirmWriteoff(fresh,qty){
 const dialog=document.createElement('dialog');dialog.className='iu-confirm';dialog.setAttribute('aria-labelledby','iuConfirmTitle');
 dialog.innerHTML=`<h3 id="iuConfirmTitle">Write off ${Number(qty)} missing ${escapeHtml(fresh.tool.name)} units?</h3><dl class="iu-impact"><div><dt>Active total</dt><dd>${Number(fresh.tool.quantity)} → ${Number(fresh.tool.quantity)-qty}</dd></div><div><dt>Available</dt><dd>${Number(fresh.tool.available)} → ${Number(fresh.tool.available)}</dd></div><div><dt>Active missing</dt><dd>${Number(fresh.tool.missing_qty)} → ${Number(fresh.tool.missing_qty)-qty}</dd></div></dl><p>The loss history will be retained. Borrower accountability will remain unchanged.</p><div class="iu-confirm-buttons"><button class="btn btn-gray" value="cancel">Back to edit</button><button class="btn iu-danger" value="approve">Confirm Write-off</button></div>`;
 document.body.append(dialog);return new Promise(resolve=>{const finish=ok=>{dialog.close();dialog.remove();resolve(ok);};dialog.querySelectorAll('button').forEach(b=>b.onclick=()=>finish(b.value==='approve'));dialog.addEventListener('cancel',e=>{e.preventDefault();finish(false);});dialog.showModal();dialog.querySelector('button').focus();});
}
document.addEventListener('keydown',e=>{
 const tab=e.target.closest('[role=tab]');
 if(tab&&['ArrowLeft','ArrowRight','Home','End'].includes(e.key)){const buttons=[...tab.closest('[role=tablist]').querySelectorAll('[role=tab]')];let index=buttons.indexOf(tab);index=e.key==='Home'?0:e.key==='End'?buttons.length-1:(index+(e.key==='ArrowRight'?1:-1)+buttons.length)%buttons.length;e.preventDefault();buttons[index].click();buttons[index].focus();return;}
 if(document.querySelector('dialog[open]'))return;
 const overlay=[...document.querySelectorAll('#rpDetailsModal.active,#missingActionModal.active')].pop();if(!overlay)return;
 if(e.key==='Escape'){e.preventDefault();closeModal(overlay.id);}
 if(e.key==='Tab'){const nodes=[...overlay.querySelectorAll('button,input,select,textarea,a[href],[tabindex="0"]')].filter(n=>!n.disabled&&n.getClientRects().length);if(!nodes.length)return;const first=nodes[0],last=nodes.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus({preventScroll:true});}}
});
