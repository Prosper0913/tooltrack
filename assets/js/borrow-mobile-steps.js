/* Mobile presentation only. Keep the original inputs, cart and checkout handlers. */
document.addEventListener('DOMContentLoaded',()=>{
 const page=document.getElementById('borrowPage'),workspace=document.getElementById('borrowCheckoutWorkspace');
 if(!page||!workspace)return;
 const names=['Borrower','Tools','Schedule','Review'];let step=0;
 const flow=document.createElement('div');flow.className='bm-flow';
 const progress=document.createElement('nav');progress.className='bm-progress';progress.setAttribute('aria-label','Borrowing steps');
 const summaries=document.createElement('div');summaries.className='bm-summaries';
 const status=document.createElement('p');status.className='bm-status';status.setAttribute('role','status');
 const controls=document.createElement('div');controls.className='bm-controls';
 const back=document.createElement('button');back.type='button';back.textContent='Back';
 const next=document.createElement('button');next.type='button';next.textContent='Continue';
 controls.append(back,next);flow.append(progress,summaries,status);workspace.before(flow);workspace.after(controls);
 const sections=['brSecBorrower','brSecTool','brSecLoan'].map(id=>document.getElementById(id));
 const cart=document.getElementById('borrowCartCard');
 const cartToggle=document.createElement('button');cartToggle.type='button';cartToggle.className='bm-cart-toggle';cartToggle.textContent='Review / edit tools';cartToggle.setAttribute('aria-expanded','false');
 cart.querySelector('.br-card-header').append(cartToggle);
 cartToggle.onclick=()=>{const open=cart.classList.toggle('bm-cart-open');cartToggle.setAttribute('aria-expanded',String(open));};
 function summary(i){
  if(i===0)return document.getElementById('brBarWho')?.textContent||'Borrower selected';
  if(i===1)return `${document.getElementById('btCount').textContent} · ${document.getElementById('btTotal').textContent} units`;
  return [document.getElementById('borrowDueDate').value,document.getElementById('borrowDueTime').value].filter(Boolean).join(' ')||'Set schedule';
 }
 function render(){
  page.dataset.mobileBorrowStep=String(step);
  sections.forEach((el,i)=>el.classList.toggle('bm-inactive',i!==step));
  progress.replaceChildren();summaries.replaceChildren();
  names.forEach((name,i)=>{
   const button=document.createElement('button');button.type='button';button.textContent=`${i+1}. ${name}`;button.disabled=i>step;button.setAttribute('aria-current',i===step?'step':'false');button.onclick=()=>go(i);progress.append(button);
   if(i<step){const edit=document.createElement('button');edit.type='button';edit.textContent=`${name}: ${summary(i)} · Edit`;edit.onclick=()=>go(i);summaries.append(edit);}
  });
  back.disabled=step===0;next.hidden=step===3;next.textContent=step===2?'Continue to Review':'Continue';
  cart.classList.toggle('bm-cart-open',step===3);cartToggle.setAttribute('aria-expanded',String(step===3));
  if(step===3&&typeof brSyncBar==='function')brSyncBar();
 }
 function go(target){step=Math.max(0,Math.min(3,target));status.textContent='';render();flow.scrollIntoView({block:'start',behavior:'smooth'});}
 back.onclick=()=>go(step-1);
 next.onclick=()=>{
  if(step===0&&!document.getElementById('borrowerSelect').value){status.textContent='Select a borrower to continue.';return;}
  if(step===1&&Number(document.getElementById('btTotal').textContent)<=0){status.textContent='Add at least one tool to continue.';return;}
  if(step===2){for(const id of ['borrowDueDate','borrowDueTime']){const input=document.getElementById(id);if(!input.value||!input.checkValidity()){status.textContent='Enter a valid due date and time.';input.focus();input.reportValidity();return;}}}
  go(step+1);
 };
 page.addEventListener('click',event=>{if(event.target.closest('#idStartBtn,#brSegId'))page.classList.add('bm-id-used');});
 document.getElementById('idScanInput')?.addEventListener('input',()=>page.classList.add('bm-id-used'));
 render();
});
