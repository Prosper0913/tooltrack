/* Borrowing views and shared Admin borrowing policies. */
let circulationCsrf='',circulationTabName='checkout',circulationBusy=false;
async function circulationFetch(method,body){
 if(!circulationCsrf)circulationCsrf=(await apiFetch(API+'/circulation.php?meta=1')).data.csrf;
 return apiFetch(API+'/circulation.php',{method,body:JSON.stringify({...body,csrf:circulationCsrf})});
}
function circulationTab(tab){
 circulationTabName=tab;document.querySelector('#borrowPage .br-layout').hidden=tab!=='checkout';
 const host=document.getElementById('circulationLoansHost');host.hidden=!['active','overdue','history'].includes(tab);
 document.querySelectorAll('[data-circulation-tab]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.circulationTab===tab)));
 if(['active','overdue','history'].includes(tab)){document.getElementById('borrowHistoryFilter').value=tab==='history'?'':tab;loadBorrowHistory(1);}
}
async function loadCirculationPolicy(){
 if(window.CURRENT_ROLE!=='Admin')return;
 try{const rows=(await apiFetch(API+'/circulation.php?policies=1')).data;const type=document.getElementById('policyType').value;
 const p=rows.find(r=>r.borrower_type===type)||rows.find(r=>r.borrower_type==='*');
 document.getElementById('policyQuantity').value=p.max_active_quantity??'';document.getElementById('policyDays').value=p.max_loan_days??'';document.getElementById('policyOverdue').checked=Number(p.allow_overdue)===1;
 document.getElementById('policyMessage').textContent=rows.some(r=>r.borrower_type===type)?'Current policy loaded.':'This type inherits the default until saved.';
 }catch(e){document.getElementById('policyMessage').textContent=e.message;}
}
async function saveCirculationPolicy(){
 if(circulationBusy)return;circulationBusy=true;
 try{await circulationFetch('PUT',{borrower_type:document.getElementById('policyType').value,max_active_quantity:document.getElementById('policyQuantity').value,max_loan_days:document.getElementById('policyDays').value,allow_overdue:document.getElementById('policyOverdue').checked});showToast('Borrowing policy saved.');await loadCirculationPolicy();}catch(_){}finally{circulationBusy=false;}
}
