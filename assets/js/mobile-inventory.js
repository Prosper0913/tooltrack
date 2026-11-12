/* Mobile inventory dashboard. Existing workflows remain authoritative. */
const mobileInventoryState={loadedAt:0,request:0};
function mobileInventorySyncCollection(){
 const damaged=document.getElementById('toolsPage').dataset.damagedOnly==='true';
 const category=document.getElementById('toolCategoryFilter').value;
 document.querySelector('#mobileInventoryCollection .card-title').textContent=damaged?`Damaged tools${category?' · '+category:''}`:category||'Tools Inventory';
 const label=document.getElementById('mobileInventoryCollectionLabel');
 if(label)label.textContent=damaged?`Damaged tools${category?' · '+category:''}`:category;
}
function mobileInventoryHome(){
 const page=document.getElementById('toolsPage');page.classList.remove('mi-collection-open');page.classList.add('mi-home-open');
 if(!page.classList.contains('active'))navigateTo('tools');else mobileInventoryOnNavigate('tools');
 document.getElementById('mobileInventoryHome').scrollIntoView({block:'start'});
}
function mobileInventoryCollection(category='',damaged=false){
 const page=document.getElementById('toolsPage');page.classList.add('mi-collection-open');page.classList.remove('mi-home-open');page.dataset.damagedOnly=String(damaged);
 document.getElementById('toolCategoryFilter').value=category;
 document.getElementById('toolStatusFilter').value='';document.getElementById('toolSearchFilter').value='';
 document.querySelector('#mobileInventoryCollection .card-title').textContent=damaged?'Damaged tools':category||'Tools Inventory';
 toolsCurrentPage=1;
 if(!page.classList.contains('active'))navigateTo('tools');else loadTools();
 document.getElementById('mobileInventoryCollection').scrollIntoView({block:'start'});
}
function mobileInventorySearch(){
 const page=document.getElementById('toolsPage');
 page.classList.add('mi-collection-open');page.classList.remove('mi-home-open');
 if(!page.classList.contains('active'))navigateTo('tools');
 toggleMobileInventoryFilters(true);document.getElementById('toolSearchFilter').focus();
}
function toggleMobileInventoryFilters(force){
 const page=document.getElementById('toolsPage');
 const open=typeof force==='boolean'?force:!page.classList.contains('mi-filters-open');
 page.classList.toggle('mi-filters-open',open);
 document.getElementById('mobileInventoryFilterToggle').setAttribute('aria-expanded',String(open));
}
function mobileInventoryScan(){document.getElementById('mobileScanChoice').showModal();}
async function mobileInventoryStartScan(mode){
 document.getElementById('mobileScanChoice').close();
 if(mode==='return'){navigateTo('return');await startReturnIdScanner();}
 else{navigateTo('borrow');circulationTab('checkout');await startBorrowScanner();}
}
function mobileInventoryProfile(){
 document.getElementById('mobileProfileName').textContent=document.getElementById('sidebarName').textContent;
 document.getElementById('mobileProfileRole').textContent=document.getElementById('sidebarRole').textContent;
 document.getElementById('mobileProfile').showModal();
}
// Use only destinations rendered by PHP for this role; original handlers stay authoritative.
function mobileInventoryMore(){
 const dialog=document.getElementById('mobileMoreMenu');
 const list=document.getElementById('mobileMoreDestinations');
 list.replaceChildren();
 document.querySelectorAll('.sidebar-nav .nav-item[data-page]').forEach(item=>{
  if(['dashboard','tools','reports'].includes(item.dataset.page))return;
  const button=document.createElement('button');button.type='button';
  const icon=item.querySelector('i')?.cloneNode(true);
  if(icon){icon.setAttribute('aria-hidden','true');button.append(icon);}
  button.append(document.createTextNode(item.querySelector('span').textContent));
  if(item.classList.contains('active'))button.setAttribute('aria-current','page');
  button.addEventListener('click',()=>{dialog.close();item.click();});
  list.append(button);
 });
 document.getElementById('mobileMoreBtn').setAttribute('aria-expanded','true');
 dialog.showModal();
}
async function mobileInventoryActivity(){
 if(Date.now()-mobileInventoryState.loadedAt<15000)return;
 const request=++mobileInventoryState.request,feed=document.getElementById('mobileInventoryActivity');
 feed.innerHTML='<p>Loading recent checkouts…</p>';
 try{
  const response=await apiFetch(`${API}/transactions.php?type=borrow&grouped=1&page=1&per_page=3`);
  if(request!==mobileInventoryState.request)return;
  feed.innerHTML=(response.data||[]).slice(0,3).map(t=>`<button type="button" class="mi-activity" onclick="navigateTo('borrow');circulationTab('history')"><span class="mi-feed-icon"><i class="fas fa-exchange-alt" aria-hidden="true"></i></span><span class="mi-feed-copy"><strong>${escapeHtml(t.borrower||'Historical borrower')}</strong><code>${escapeHtml(t.txn_id)}</code></span><span class="mi-feed-status">${loanStateBadge(t)}</span></button>`).join('')||'<p>No checkouts yet. Recent activity will appear here.</p>';
  mobileInventoryState.loadedAt=Date.now();
 }catch(_){if(request===mobileInventoryState.request)feed.innerHTML='<p>Could not load activity. <button type="button" onclick="mobileInventoryActivity()">Retry</button></p>';}
}
function mobileInventoryOnNavigate(page){
 document.querySelectorAll('#mobileUtilityNav [data-mobile-page]').forEach(button=>{
  const selected=button.dataset.mobilePage===(['borrow','return'].includes(page)?'scan':['dashboard','tools','reports'].includes(page)?page:'more');
  if(selected)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');
 });
 if(page==='tools'&&matchMedia('(max-width:767px)').matches)mobileInventoryActivity();
}
document.addEventListener('DOMContentLoaded',()=>{
 const more=document.getElementById('mobileMoreMenu');
 if(more){
  more.addEventListener('close',()=>document.getElementById('mobileMoreBtn').setAttribute('aria-expanded','false'));
  more.addEventListener('click',event=>{
   const rect=more.getBoundingClientRect();
   if(event.target===more&&(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom))more.close();
  });
  matchMedia('(max-width:767px)').addEventListener('change',event=>{if(!event.matches&&more.open)more.close();});
 }
 if(window.lucide)window.lucide.createIcons();
 mobileInventoryOnNavigate(document.querySelector('.page.active')?.id.replace(/Page$/,'')||'dashboard');
});
