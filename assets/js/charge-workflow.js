let rrPage=1,cwGeneration=0,cwTab='active';
function cwSelectTab(tab){
 cwTab=tab;
 document.querySelectorAll('.cw-tabs [role=tab]').forEach(b=>{const active=b.id==='cwTab-'+tab;b.setAttribute('aria-selected',String(active));b.tabIndex=active?0:-1;});
 document.getElementById('cwResults').setAttribute('aria-labelledby','cwTab-'+tab);loadChargeCases(1);
}
async function cwLoadSummary(){
 const box=document.getElementById('cwSummary');if(!box)return;
 try{const s=(await apiFetch(API+'/charge_cases.php?summary=1')).data;
 box.innerHTML=[['Active Missing Units',s.active_missing_units,'units','red'],['Damaged Units',s.damaged_units,'units','slate'],['Pending Write-off Requests',s.pending_requests,'requests','amber'],['Cases Needing Review',s.review_cases,'cases','amber']].map(([label,n,unit,tone])=>`<div class="iu-stat"><span>${label}</span><strong class="iu-${tone}">${Number(n).toLocaleString()}</strong><small>${unit}</small></div>`).join('');
 }catch(_){box.innerHTML='<p role="status">Summary unavailable. Refresh to retry.</p>';}
}
function iuStatusBadge(status){const tone=status==='Closed — Recovered'?'green':status==='Closed — Lost'?'slate':/Pending|Review/i.test(status)?'amber':/Missing|Partially Closed/.test(status)?'red':'slate';return `<span class="iu-badge iu-${tone}">${escapeHtml(status)}</span>`;}
async function loadChargeCases(page){
 if(typeof page==='number')rrPage=Math.max(1,page);
 const body=document.getElementById('rrListBody');if(!body)return;
 ensureHistoryPager('rrListContainer','rr');cwLoadSummary();
 const scroll=document.getElementById('rrListContainer');const pager=scroll.querySelector('.pagination');if(pager)scroll.after(pager);
 const query=new URLSearchParams({page:rrPage,tab:cwTab,filter:document.getElementById('cwCaseFilter')?.value||'all',search:document.getElementById('cwSearch')?.value||'',incident:document.getElementById('cwIncidentFilter')?.value||'',inventory:document.getElementById('cwInventoryFilter')?.value||'',sort:document.getElementById('cwSort')?.value||'newest'});
 const generation=++cwGeneration;if(!window.ttLiveSync?.rendering)body.innerHTML='<tr class="empty-row"><td colspan="8" role="status">Loading incidents…</td></tr>';
 try{
 const d=(await apiFetch(`${API}/charge_cases.php?${query}`)).data;if(generation!==cwGeneration)return;
 rrPage=d.page;document.getElementById('rrListCount').textContent=d.total;
 body.innerHTML=d.rows.length?d.rows.map(r=>{
  const handler=r.source==='missing'?`openMissingCase(${Number(r.id)})`:r.source==='return'?`rpViewReturn(${Number(r.id)})`:`rpView(${Number(r.id)})`;
  const age=Number(r.is_closed)?(r.closed_at?`Closed ${escapeHtml(bhFormatDate(r.closed_at))}`:'Closed date not recorded'):`${Number(r.age_days)} days`;
  return `<tr><td data-label="Case ID"><code>${escapeHtml(r.case_id)}</code></td><td data-label="Tool"><b>${escapeHtml(r.tool_name||'Historical tool')}</b><small>${escapeHtml(r.tool_code||'')}</small></td><td data-label="Borrower"><b>${escapeHtml(r.borrower||'No borrower recorded')}</b><small>${escapeHtml(r.borrower_number||'')}</small></td><td data-label="Incident">${r.incident==='lost'?'Missing':'Damaged'}</td><td data-label="Quantity"><strong>${Number(r.remaining_units)} remaining</strong><small>${Number(r.quantity)} originally reported</small></td><td data-label="Case Status">${iuStatusBadge(r.inventory_status)}${Number(r.pending_writeoff_count)>0?'<small><span class="iu-badge iu-amber">Pending Approval</span></small>':''}</td><td data-label="Age">${age}${Number(r.needs_review)?'<small><span class="iu-badge iu-amber">Needs Review</span></small>':''}</td><td data-label="Actions"><button type="button" class="card-btn secondary" onclick="${handler}">View Details</button></td></tr>`;
 }).join(''):'<tr class="empty-row"><td colspan="8">No incidents match this tab and filters.</td></tr>';
 renderPagination('rrPagerInfo','rrPagerBtns',d.page,Math.max(1,Math.ceil(d.total/d.per_page)),d.per_page,d.total,p=>loadChargeCases(p));
 }catch(e){if(generation===cwGeneration)body.innerHTML='<tr class="empty-row"><td colspan="8" role="alert">Could not load incidents. '+escapeHtml(e.message||'Refresh and retry.')+'</td></tr>';}
}
