/* ============================================================
   reports.js — Reports tab: report modal, Transaction Report, Monthly Statistics, Category doughnut, Trend & Forecast.
   Plain (non-module) script: shares the global scope with the other
   assets/js files so inline onclick="" handlers keep working.
   ============================================================ */

let monthlyChart=null, categoryChart=null;

/* ───────────────────────────────────────────────────────────
   11. TRANSACTION HISTORIES
   GET api/transactions.php?type=borrow&status=&page=
   GET api/transactions.php?type=return&condition=&page=
─────────────────────────────────────────────────────────── */
// Borrowing tabs retain their shared history loader and full CSV action.
function exportLoanHistory(){window.open(API+'/reports.php?type=transactions&download=1','_blank');}

let borrowHistoryPage=1;
let borrowHistoryRequest=0,borrowHistorySearchTimer=null;
const borrowHistoryExpanded=new Set();
function toggleBorrowSession(key){
  borrowHistoryExpanded.has(key)?borrowHistoryExpanded.delete(key):borrowHistoryExpanded.add(key);
  document.querySelectorAll('[data-borrow-group]').forEach(el=>{if(el.dataset.borrowGroup===key)el.setAttribute('aria-expanded',String(borrowHistoryExpanded.has(key)));});
  document.querySelectorAll('[data-borrow-details]').forEach(el=>{if(el.dataset.borrowDetails===key)el.hidden=!borrowHistoryExpanded.has(key);});
}
function searchBorrowHistory(){clearTimeout(borrowHistorySearchTimer);borrowHistorySearchTimer=setTimeout(()=>loadBorrowHistory(1),250);}
function borrowHistoryActions(t){
  if(t.pending_report_id)return `<button class="action-btn" title="A missing-tool report is awaiting confirmation" onclick="openMissingCase(${Number(t.pending_report_id)})"><i class="fas fa-hourglass-half"></i></button>`;
  return t.status==='active'&&mtOutstanding(t)>0?`<button class="action-btn delete" title="Report missing tool" onclick="openMissingReport(${Number(t.id)})"><i class="fas fa-magnifying-glass-minus"></i></button>`:'—';
}
function renderBorrowSessions(rows){
  const esc=escapeHtml;
  return rows.map(g=>{
    const key=esc(g.group_key),open=borrowHistoryExpanded.has(g.group_key);
    return `<tr><td data-label="Txn ID"><button type="button" class="action-btn" data-borrow-group="${key}" aria-expanded="${open}" aria-label="Expand checkout ${esc(g.txn_id)}" onclick="toggleBorrowSession(this.dataset.borrowGroup)"><i class="fas fa-chevron-down"></i></button><code>${esc(g.txn_id)}</code></td>
      <td data-label="Tools">${Number(g.tools_count)} tool type(s)</td><td data-label="Tool Name">${esc(g.items.map(t=>t.tool_name||t.tool_code||'Historical tool').join(', '))}</td>
      <td data-label="Borrower">${esc(g.borrower||'—')}</td><td data-label="Date & Time">${esc(parseDbDate(g.created_at).toLocaleString())}</td><td data-label="Due Date">${esc(g.due_date||'—')}</td>
      <td data-label="Qty Borrowed">${Number(g.qty)}</td><td data-label="Qty Returned">${Number(g.qty_returned)}</td><td data-label="Status">${loanStateBadge(g)}${g.status==='active'&&Number(g.qty_returned)>0?'<div class="mt-sub">Partially returned</div>':''}</td><td data-label="Actions"><button type="button" class="card-btn secondary" data-borrow-group="${key}" aria-expanded="${open}" onclick="toggleBorrowSession(this.dataset.borrowGroup)">Details</button></td></tr>
      <tr data-borrow-details="${key}" ${open?'':'hidden'}><td colspan="10"><table class="data-table"><thead><tr><th>Tool Code</th><th>Tool Name</th><th>Due Date</th><th>Borrowed</th><th>Returned</th><th>Missing</th><th>Returned Damaged</th><th>Status</th><th>Actions</th></tr></thead><tbody>${g.items.map(t=>`<tr><td>${esc(t.tool_code||'—')}</td><td>${esc(t.tool_name||'Historical tool')}</td><td>${esc(t.due_date||'—')} ${esc(t.due_time||'')}</td><td>${Number(t.qty)}</td><td>${Number(t.qty_returned)}</td><td>${Number(t.qty_missing)}</td><td>${Number(t.qty_damaged)}</td><td>${loanStateBadge(t)}</td><td>${borrowHistoryActions(t)}</td></tr>`).join('')}</tbody></table></td></tr>`;
  }).join('');
}

async function loadBorrowHistory(page){
  clearTimeout(borrowHistorySearchTimer);
  const request=++borrowHistoryRequest;
  if(typeof page==='number') borrowHistoryPage=page; else borrowHistoryPage=1;
  ensureHistoryPager('borrowHistoryTableContainer','borrowHistory');
  const status=document.getElementById('borrowHistoryFilter').value;
  const params=new URLSearchParams({type:'borrow',grouped:1,status,search:document.getElementById('borrowHistorySearch')?.value||'',page:borrowHistoryPage,per_page:HISTORY_PER_PAGE});
  try{
    const res=await apiFetch(`${API}/transactions.php?${params}`);
    if(request!==borrowHistoryRequest)return;
    borrowHistoryPage=res.page||borrowHistoryPage;
    const tbody=document.getElementById('borrowHistoryBody');
    const rows=res.data||[];
    mtRememberLoans(rows.flatMap(g=>g.items||[]));
    if(!rows.length){tbody.innerHTML='<tr class="empty-row"><td colspan="10">No borrow transactions yet.</td></tr>';}
    else tbody.innerHTML=renderBorrowSessions(rows);
    renderPagination('borrowHistoryPagerInfo','borrowHistoryPagerBtns',borrowHistoryPage,Math.ceil((res.total||0)/HISTORY_PER_PAGE),HISTORY_PER_PAGE,res.total||0,p=>loadBorrowHistory(p));

    // Sidebar badge = TOTAL active loans. (It used to count only the active rows on
    // this one page — and showed 0 whenever the filter was "returned".)
    if(status==='active'&&!document.getElementById('borrowHistorySearch')?.value.trim()){
      const badge=document.getElementById('borrowBadge');
      badge.textContent=res.total||0;
      badge.style.display=(res.total>0)?'inline-block':'none';
    }
  }catch(_){}
}

/* ───────────────────────────────────────────────────────────
   12. REPORTS
   GET api/reports.php?type=inventory|transactions|borrowers|overdue → file download
   GET api/reports.php?type=monthly[&month=YYYY-MM] → Week 1–4 borrows/returns + totals + available_months
   GET api/reports.php?type=trend[&months=6|12|24]  → monthly history + 3-month forecast
   GET api/reports.php?type=category → { labels[], values[] }
─────────────────────────────────────────────────────────── */
// ── Category distribution (doughnut) ─────────────────────────────
// 12 distinct colours so every category gets its own slice colour.
const CATEGORY_PALETTE=['#6d28d9','#10b981','#f59e0b','#3b82f6','#ef4444','#ec4899','#14b8a6','#8b5cf6','#84cc16','#f97316','#06b6d4','#64748b'];

// Draws each slice's piece count on the slice itself (skipped when the
// slice is too thin to fit the number). Doughnut-only, no extra library.
const doughnutValuePlugin={
  id:'doughnutValues',
  afterDatasetsDraw(chart){
    const {ctx}=chart;
    ctx.save(); ctx.font='600 12px Inter, sans-serif'; ctx.textAlign='center'; ctx.textBaseline='middle'; ctx.fillStyle='#fff';
    chart.getDatasetMeta(0).data.forEach((el,i)=>{
      const v=+chart.data.datasets[0].data[i];
      if(!v||el.hidden||el.endAngle-el.startAngle<0.28||el.outerRadius-el.innerRadius<22)return;
      const c=el.getCenterPoint();
      ctx.fillText(v,c.x,c.y);
    });
    ctx.restore();
  }
};

// Legend entry per category: "Cookware — 40 pcs (36%)".
function doughnutLegendLabels(chart){
  const ds=chart.data.datasets[0];
  const total=(ds.data||[]).reduce((a,b)=>a+(+b||0),0);
  return (chart.data.labels||[]).map((l,i)=>{
    const v=+ds.data[i]||0, pct=total?Math.round(v/total*100):0, col=ds.backgroundColor[i];
    return {text:`${l} — ${v} pcs (${pct}%)`,fillStyle:col,strokeStyle:col,hidden:!chart.getDataVisibility(i),index:i,pointStyle:'circle'};
  });
}

async function loadReports(){
  clearTimeout(inventoryReportSearchTimer);
  ++reportRequestId;currentReportType=null;
  document.getElementById('reportsPage').classList.remove('reports-showing');
  document.getElementById('reportModal').hidden=true;
  return refreshReportOverview();
}

async function refreshReportOverview(){

  // Monthly (Week 1–4) and trend/forecast load on their own, so a problem
  // in one never blanks the category chart below.
  loadMonthlyStats(monthlySelected);   // null → server default (current month)
  loadTrend(+document.getElementById('trendRange').value||6);

  try{
    const cd=(await apiFetch(`${API}/reports.php?type=category`)).data;
    const labels=cd.labels||[];
    if(categoryChart)categoryChart.destroy();
    categoryChart=new Chart(document.getElementById('categoryChart').getContext('2d'),{
      type:'doughnut',
      data:{labels,datasets:[{data:cd.values||[],backgroundColor:labels.map((_,i)=>CATEGORY_PALETTE[i%CATEGORY_PALETTE.length]),borderWidth:2,borderColor:'#fff'}]},
      plugins:[doughnutValuePlugin],
      options:{responsive:true,maintainAspectRatio:false,cutout:'52%',plugins:{
        datalabels:{display:false},
        legend:{position:'bottom',labels:{usePointStyle:true,padding:14,boxWidth:10,generateLabels:doughnutLegendLabels}},
        tooltip:{callbacks:{label:c=>{const t=c.dataset.data.reduce((a,b)=>a+(+b||0),0);return ` ${c.label}: ${c.parsed} pcs (${t?Math.round(c.parsed/t*100):0}%)`;}}}
      }}
    });
  }catch(err){console.error('Category chart failed:',err);}
}

// ── Monthly statistics: Week 1–4 of a selectable month ───────────
let monthlySelected=null;

      // 'YYYY-MM' or null (= current month)
let monthlyAvailable=[];

       // [{value,label}] newest first

// "▲ 12% vs Sep 2026" — compares this month to the previous one.
function deltaHtml(cur,prev,prevLabel,partial){
  if(partial) return `<span class="kpi-delta">month in progress</span>`;
  if(!prev&&!cur) return `<span class="kpi-delta">no activity</span>`;
  if(!prev)       return `<span class="kpi-delta up">new vs ${escapeHtml(prevLabel)}</span>`;
  const pct=Math.round((cur-prev)/prev*100);
  if(pct===0)     return `<span class="kpi-delta">same as ${escapeHtml(prevLabel)}</span>`;
  return `<span class="kpi-delta ${pct>0?'up':'down'}">${pct>0?'▲':'▼'} ${Math.abs(pct)}% vs ${escapeHtml(prevLabel)}</span>`;
}

function shiftMonth(step){
  // step=+1 → older month (next in the newest-first list), -1 → newer
  const i=monthlyAvailable.findIndex(m=>m.value===monthlySelected);
  const t=monthlyAvailable[i+step];
  if(t) loadMonthlyStats(t.value);
}

async function loadMonthlyStats(month){
  try{
    const qs=month?`&month=${encodeURIComponent(month)}`:'';
    const md=(await apiFetch(`${API}/reports.php?type=monthly${qs}`)).data;
    monthlySelected=md.month;
    monthlyAvailable=md.available_months||[];
    if(!monthlyAvailable.some(m=>m.value===md.month)) monthlyAvailable.unshift({value:md.month,label:md.month_label});

    // Month picker + prev/next arrows
    const sel=document.getElementById('monthSelect');
    sel.innerHTML=monthlyAvailable.map(m=>`<option value="${escapeHtml(m.value)}">${escapeHtml(m.label)}</option>`).join('');
    sel.value=md.month;
    const idx=monthlyAvailable.findIndex(m=>m.value===md.month);
    document.getElementById('monthPrevBtn').disabled = idx>=monthlyAvailable.length-1;
    document.getElementById('monthNextBtn').disabled = idx<=0;
    const badge=document.getElementById('monthlyBadge');
    badge.textContent=md.is_current?'In progress':''; badge.classList.toggle('live',!!md.is_current);

    // KPI tiles
    const prevLabel=(()=>{const [y,m]=md.month.split('-').map(Number);return new Date(y,m-2,1).toLocaleDateString('en-US',{month:'short',year:'numeric'});})();
    const net=md.total_borrows-md.total_returns;
    document.getElementById('monthlyKpis').innerHTML=`
      <div class="kpi"><div class="kpi-label">Borrows</div><div class="kpi-value">${md.total_borrows}</div>${deltaHtml(md.total_borrows,md.prev_borrows,prevLabel,md.is_current)}</div>
      <div class="kpi"><div class="kpi-label">Returns</div><div class="kpi-value">${md.total_returns}</div>${deltaHtml(md.total_returns,md.prev_returns,prevLabel,md.is_current)}</div>
      <div class="kpi"><div class="kpi-label">Net change in tools out</div><div class="kpi-value">${net>0?'+':''}${net}</div><span class="kpi-delta">${net>0?'more borrowed than returned':net<0?'more returned than borrowed':'balanced'}</span></div>`;

    // Week 1–4 bar chart. Array labels render on two lines: "Week 1" / "Day 1–7".
    const labels=md.labels.map((l,i)=>[l,`Day ${md.ranges[i]}`]);
    const dl=(typeof ChartDataLabels!=='undefined')?[ChartDataLabels]:[];   // value labels above bars (same plugin your category chart uses)
    if(monthlyChart)monthlyChart.destroy();
    monthlyChart=new Chart(document.getElementById('monthlyChart').getContext('2d'),{
      type:'bar',
      data:{labels,datasets:[{label:'Borrows',data:md.borrows,backgroundColor:CHART_COLORS.purple,borderRadius:8},{label:'Returns',data:md.returns,backgroundColor:CHART_COLORS.green,borderRadius:8}]},
      plugins:dl,
      options:{responsive:true,maintainAspectRatio:false,layout:{padding:{top:20}},plugins:{legend:{position:'top',labels:{usePointStyle:true,padding:20}},tooltip:{callbacks:{title:items=>`${md.labels[items[0].dataIndex]} (days ${md.ranges[items[0].dataIndex]}) — ${md.month_label}`}},datalabels:{anchor:'end',align:'end',offset:0,color:'#374151',font:{weight:'600',size:12},display:ctx=>ctx.dataset.data[ctx.dataIndex]>0}},scales:{y:{beginAtZero:true,grace:'10%',ticks:{precision:0},grid:{color:'rgba(0,0,0,.05)'}},x:{grid:{display:false}}}}
    });
  }catch(_){}
}

// ── Trend & forecast ─────────────────────────────────────────────
let trendChart=null;

async function loadTrend(months=6){
  try{
    const d=(await apiFetch(`${API}/reports.php?type=trend&months=${months}`)).data;
    const cur=d.current_index;
    // Sub-labels: current month is partial, later months are projections.
    const labels=d.labels.map((l,i)=>i===cur?[l,'so far']:i>cur?[l,'forecast']:l);
    const faded=(c)=>d.labels.map((_,i)=>i===cur?c+'88':c);   // current-month bar is lighter
    const lineBase={type:'line',borderWidth:2,borderDash:[6,5],pointRadius:4,pointStyle:'rectRot',tension:.25,spanGaps:false,fill:false};

    if(trendChart)trendChart.destroy();
    trendChart=new Chart(document.getElementById('trendChart').getContext('2d'),{
      data:{labels,datasets:[
        {type:'bar',label:'Borrows',data:d.borrows,backgroundColor:faded(CHART_COLORS.purple),borderRadius:6,order:2},
        {type:'bar',label:'Returns',data:d.returns,backgroundColor:faded(CHART_COLORS.green),borderRadius:6,order:2},
        {...lineBase,label:'Borrows forecast',data:d.forecast_borrows,borderColor:CHART_COLORS.purple,backgroundColor:CHART_COLORS.purple,order:1},
        {...lineBase,label:'Returns forecast',data:d.forecast_returns,borderColor:CHART_COLORS.green,backgroundColor:CHART_COLORS.green,order:1}
      ]},
      options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{datalabels:{display:false},legend:{position:'top',labels:{usePointStyle:true,padding:20}}},scales:{y:{beginAtZero:true,grace:'10%',ticks:{precision:0},grid:{color:'rgba(0,0,0,.05)'}},x:{grid:{display:false}}}}
    });

    // Confidence badge
    const confText={none:'Not enough data',low:'Low confidence',medium:'Medium confidence',good:'Good confidence'}[d.confidence]||'';
    const cb=document.getElementById('trendConfidence');
    cb.textContent=confText; cb.className='title-sub conf-'+d.confidence;

    // Forecast cards (this month = full-month estimate, then next two)
    const cards=document.getElementById('forecastCards');
    if(!d.forecast.length){
      cards.innerHTML='';
      document.getElementById('forecastNote').textContent='Forecasts appear once at least one full month of transactions has been recorded.';
      return;
    }
    cards.innerHTML=d.forecast.map(f=>`
      <div class="forecast-chip">
        <div class="fc-month">${escapeHtml(f.label)}</div>
        <div class="fc-tag">${f.is_current?'full-month estimate':'forecast'}</div>
        <div class="fc-line"><span>Borrows</span><b>${f.borrows}</b></div>
        <div class="fc-line"><span>Returns</span><b>${f.returns}</b></div>
      </div>`).join('');
    const p=d.pace;
    document.getElementById('forecastNote').textContent=
      `Based on ${d.months_used} complete month${d.months_used!==1?'s':''} (${d.months_used<3?'simple average':'linear trend'}). `+
      `This month is on pace for ${p.borrows} borrows / ${p.returns} returns after ${p.days_elapsed} of ${p.days_in_month} days. `+
      `Forecasts are estimates — they don't account for seasonality, holidays or events until you have 12+ months of history.`;
  }catch(_){}
}

let currentReportType = null;
function hasReportTableControls(type){return ['inventory','stock_history','transactions','overdue','missing','loss_history'].includes(type);}
function reportTitle(type){return {inventory:'Inventory Report',stock_history:'Stock History',transactions:'Transactions',overdue:'Overdue Loans',missing:'Missing Tools',returns:'Returns by Condition',loss_history:'Written Off / Lost History'}[type]||'Report';}
function reportColumnStorageKey(type){return 'tooltrack.'+({inventory:'inventoryReport',stock_history:'stockHistoryReport'}[type]||type+'Report')+'.hiddenColumns';}
let stockReportInitialized=false,reportRequestId=0;
const removedReportTypes=new Set(['borrowers','conditions','unresolved','integrity']);

function returnToReports(fromHistory=false){
 if(!fromHistory&&window.history?.state?.tooltrackReport)window.history.pushState({tooltrackReports:true},'','#reports');
 clearTimeout(inventoryReportSearchTimer);
 ++reportRequestId;currentReportType=null;
 document.getElementById('reportModal').hidden=true;
 document.getElementById('reportsPage').classList.remove('reports-showing');
 navigateTo('reports');
}
function leaveReportDetail(){clearTimeout(inventoryReportSearchTimer);++reportRequestId;currentReportType=null;}

// Older report links should land on the report list, not reopen a retired view.
document.addEventListener('DOMContentLoaded',()=>{
 const url=new URL(window.location.href);
 const target=url.searchParams.get('report')||url.searchParams.get('type');
 const hashTarget=url.hash.replace(/^#(?:reports?[/:=])?/, '');
 if(removedReportTypes.has(target)||removedReportTypes.has(hashTarget))returnToReports();
 else{
  const type=target||hashTarget;
  if(hasReportTableControls(type)||type==='returns')viewReport(type,reportTitle(type),false,true);
 }
});

window.addEventListener?.('popstate',event=>{
 const type=event.state?.tooltrackReport;
 if(type)viewReport(type,reportTitle(type),false,true);
 else if(currentReportType)returnToReports(true);
});

async function viewReport(type, title, refresh=false,fromHistory=false){
  if(removedReportTypes.has(type)){returnToReports();return;}
  const mountedRefresh=hasReportTableControls(type)&&refresh&&currentReportType===type;
  clearTimeout(inventoryReportSearchTimer);
  const interactive=hasReportTableControls(type);
  document.getElementById('inventoryReportToolbar').hidden=!interactive;
  document.getElementById('inventoryReportPagination').hidden=!interactive;
  document.getElementById('stockHistoryControls').hidden=type!=='stock_history';
  document.getElementById('stockHistorySummary').hidden=type!=='stock_history';
  if(type==='stock_history'&&!mountedRefresh)document.getElementById('stockHistorySummary').innerHTML='';
  if(type==='stock_history'&&!stockReportInitialized){document.getElementById('stockDateFrom').value=localToday();document.getElementById('stockDateTo').value=localToday();stockReportInitialized=true;}
  document.getElementById('reportModalTitle').textContent = title;
  document.getElementById('reportModalCount').textContent = '';
  document.getElementById('reportTableHead').innerHTML = '';
  if(!window.ttLiveSync?.rendering)document.getElementById('reportTableBody').innerHTML = '<tr class="empty-row"><td><span class="spinner dark"></span> Loading…</td></tr>';
  const page=document.getElementById('reportsPage'),view=document.getElementById('reportModal');
  if(!page.classList.contains('active'))navigateTo('reports');
  const requestId=++reportRequestId;
  currentReportType=type;
  if(!refresh&&!fromHistory&&window.history?.pushState&&window.history.state?.tooltrackReport!==type)window.history.pushState({tooltrackReport:type},'',`#reports/${type}`);
  if(interactive&&!mountedRefresh){
   const search=document.getElementById('inventoryReportSearch');
   search.value=activeTableReportState().search;
   const searchLabel=['transactions','overdue','missing'].includes(type)?`Search this report by ${type==='missing'?'case':'transaction'} ID, tool or borrower`:'Search this report by tool name or code';
   search.parentElement.firstChild.textContent=searchLabel;search.placeholder=searchLabel;
   document.getElementById('inventoryReportCategory').parentElement.firstChild.textContent=type==='stock_history'?'Event':type==='inventory'?'Category':'Status';
  }
  // Moving a mounted ancestor blurs its focused descendants in the browser.
  // Inventory and Stock History updates replace result content, never the toolbar.
  if(!mountedRefresh)page.append(view);
  page.classList.add('reports-showing');view.hidden=false;
  view.dataset.report=type;
  // Reuse the same export action; other reports keep it in their heading.
  const toolbar=view.querySelector('.report-toolbar-row');
  if(!mountedRefresh){
   if(interactive){
    view.querySelector('.report-records').before(toolbar);
    toolbar.append(document.getElementById('exportReportBtn'));
   }else{
    view.querySelector('.report-view-heading').after(toolbar);
    view.querySelector('.report-view-heading').append(document.getElementById('exportReportBtn'));
   }
  }
  view.querySelector('.report-records').scrollTop=0;
  if(type==='stock_history'){
   const tableScroll=view.querySelector('.report-table-scroll');
   tableScroll.scrollTop=0;tableScroll.scrollLeft=0;
  }
  if(!refresh)document.getElementById('reportColumns').open=false;
  document.getElementById('reportColumnOptions').innerHTML='';
  const heading=document.getElementById('reportModalTitle');
  if(!refresh){heading.focus({preventScroll:true});heading.scrollIntoView({block:'start'});}
  try{
    const res = await apiFetch(`${API}/reports.php?${reportRequestParams(type)}`);
    if(requestId!==reportRequestId)return;
    const {rows} = res.data;
    if(type==='stock_history')renderStockHistorySummary(res.data);
    const reportTotal=res.data.total??rows.length;
    renderReportTable(type,res.data);
    if(interactive)renderInventoryReportPagination(res.data);
    document.getElementById('reportModalCount').textContent = res.data.truncated
      ? `Showing newest ${rows.length} of ${reportTotal} records — use Export CSV for the full report`
      : type==='inventory'?`${reportTotal} tools`:`${reportTotal} record${reportTotal!==1?'s':''}`;
    if(type==='transactions'&&res.data.transaction_count!==undefined)document.getElementById('reportModalCount').textContent=`${res.data.transaction_count} transaction(s) · ${res.data.line_total??reportTotal} tool/event line(s)`;
  }catch(_){
    if(requestId!==reportRequestId)return;
    document.getElementById('reportTableBody').innerHTML = '<tr class="empty-row"><td>Failed to load report.</td></tr>';
  }
}

function exportCurrentReport(){
  if(!currentReportType||removedReportTypes.has(currentReportType)) return;
  const params=reportRequestParams(currentReportType);
  if(hasReportTableControls(currentReportType))['search','category','sort','direction','page','per_page'].forEach(key=>params.delete(key));
  window.open(`${API}/reports.php?${params}&download=1`,'_blank');
}

const reportHiddenColumns=new Map();
const transactionReportExpanded=new Set();
function toggleReportTransaction(key){
 const open=!transactionReportExpanded.has(key);open?transactionReportExpanded.add(key):transactionReportExpanded.delete(key);
 document.getElementById('reportTableBody').querySelectorAll('[data-transaction-group]').forEach(button=>{
  if(button.dataset.transactionGroup!==key)return;button.setAttribute('aria-expanded',String(open));
  const icon=button.querySelector('i');if(icon)icon.className='fas '+(open?'fa-chevron-down':'fa-chevron-right');
 });
 document.getElementById('reportTableBody').querySelectorAll('tr[data-transaction-details]').forEach(row=>{if(row.dataset.transactionDetails===key)row.hidden=!open;});
}
function syncTransactionDetailSpans(){
 const count=document.getElementById('reportTableHead').querySelectorAll('th:not([hidden])').length;
 document.getElementById('reportTableBody').querySelectorAll('[data-transaction-detail-cell]').forEach(cell=>cell.colSpan=Math.max(1,count));
}
function groupedTransactionStatus(action,row,columns,esc){
 let html=transactionReportCell('Status',row,columns,esc);
 if(action?.type==='borrow'&&action.status==='returned'&&Number(action.qty_missing)>0)html=html.replace('>Returned<','>Closed<');
 if(action?.type==='borrow'&&action.status==='active'&&Number(action.qty_returned)>0)html+='<small>Partially returned</small>';
 if(action?.is_overdue)html+='<small class="transaction-report-warning">Overdue</small>';
 if(action?.pending_report_id)html+='<small class="transaction-report-warning">Missing report pending</small>';
 if(Number(action?.qty_damaged)>0)html+=`<small>${Number(action.qty_damaged)} returned damaged</small>`;
 if(action?.type==='borrow'&&action.status==='returned'&&!Number(action.open_missing_cases)&&Number(action.resolved_missing_cases)>0)html+='<small>Missing case resolved</small>';
 return html;
}
function renderGroupedTransactionRows(data,ordered,attrs,esc){
 const {columns,rows,groups}=data;
 if(typeof mtRememberLoans==='function')mtRememberLoans(groups.flatMap(g=>g.items.map(i=>i.action)).filter(a=>a.type==='borrow'));
 if(!rows.length)return `<tr><td colspan="${ordered.length+1}">No transactions match your filters. <button type="button" class="card-btn secondary" onclick="clearInventoryReportFilters()">Clear filters</button></td></tr>`;
 return groups.map((group,index)=>{
  const row=rows[index],key=esc(group.key),open=transactionReportExpanded.has(group.key),get=name=>row[columns.indexOf(name)];
  const toggle=`data-transaction-group="${key}" aria-expanded="${open}"`;
  const cells=ordered.map(column=>{
   const raw=get(column);
   const value=column==='Actions'?`<button type="button" class="card-btn secondary" ${toggle}>View tools</button>`:
    column==='Status'?groupedTransactionStatus(group.action,row,columns,esc):
    ['Transaction Date','Type'].includes(column)?transactionReportCell(column,row,columns,esc):esc(raw);
   return `<td ${attrs(column)} ${column==='Tool Name'?`title="${esc(group.items.map(i=>i.values[columns.indexOf('Tool Name')]||'Historical tool').join(', '))}"`:''}>${value}</td>`;
  }).join('');
  const items=group.items.map(item=>{
   const value=name=>item.values[columns.indexOf(name)],action=item.action;
   const original=action.type==='return'?`<small>Borrow: <code>${esc(item.borrow_txn_id||'Historical borrow')}</code> · line ${esc(item.borrow_line_id??'—')}</small>`:'';
   return `<tr data-transaction-item="${Number(action.id)}"><td><strong>${esc(value('Tool Name')||'Historical tool')}</strong><small>${esc(value('Tool Code')||'—')}</small>${original}</td>
    <td>${Number(action.type==='return'?item.original_qty:value('Qty Borrowed'))}</td><td>${Number(value('Qty Returned'))}</td><td>${Number(item.qty_missing)}</td><td>${Number(item.qty_damaged)}</td>
    <td>${groupedTransactionStatus(action,item.values,columns,esc)}</td><td>${esc(value('Due Date')||'—')}</td><td>${esc(value('Condition')||'—')}</td><td>${reportRowActions(action)}</td></tr>`;
  }).join('');
  return `<tr class="transaction-report-summary" data-transaction-key="${key}"><td class="transaction-report-toggle"><button type="button" class="action-btn" ${toggle} aria-label="Show tools for ${esc(get('TXN ID'))}"><i class="fas ${open?'fa-chevron-down':'fa-chevron-right'}"></i></button></td>${cells}</tr>
   <tr class="transaction-report-details" data-transaction-details="${key}" ${open?'':'hidden'}><td data-transaction-detail-cell colspan="${ordered.length+1}"><div class="transaction-report-detail-scroll" tabindex="0" aria-label="Tool details for ${esc(get('TXN ID'))}">
    <table class="data-table transaction-report-tools"><caption>${esc(get('TXN ID'))} · ${group.tool_count} tool(s)${group.action.type==='return'?' · Borrowed quantity refers to the original loan; returned quantity is this event.':''}</caption><thead><tr><th>Tool</th><th>Qty Borrowed</th><th>Qty Returned</th><th>Missing</th><th>Returned Damaged</th><th>Item Status</th><th>Due Date</th><th>Condition</th><th>Actions</th></tr></thead><tbody>${items}</tbody></table>
   </div></td></tr>`;
 }).join('');
}
const missingReportDefaultColumns=['Case No','Status','Tool Name','Borrower','Date Missing','Qty Reported','Qty Still Missing','Actions'];
const transactionReportDefaultColumns=['Transaction Date','TXN ID','Type','Tool Name','Borrower','Qty Borrowed','Qty Returned','Status'];
function renderReportTable(type,data){
 const transactionGroups=type==='transactions'&&data.grouped===true;
 document.getElementById('reportModal').dataset.transactionsGrouped=String(transactionGroups);
 const interactive=hasReportTableControls(type),state=tableReportState(type);
 const {columns,rows}=data,esc=value=>escapeHtml(String(value??(type==='inventory'?'':'—')));
 const identity=['Tool Name',type==='stock_history'?'SKU':'Tool Code'];
 const grouped=['stock_history','returns'].includes(type)&&columns.includes(identity[0])&&columns.includes(identity[1]);
 const preferred=type==='transactions'?transactionReportDefaultColumns:type==='missing'?missingReportDefaultColumns:type==='stock_history'?['Date / Time','Tool','Event','Quantity','Previous Total','Updated Total','Recorded By','Remarks']:[];
 const fields=columns.filter(c=>!grouped||!identity.includes(c));
 if(grouped)fields.unshift('Tool');
 if(type==='transactions')fields.unshift('Transaction Date');
 if(data.actions)fields.push('Actions');
 const ordered=[...preferred.filter(c=>fields.includes(c)),...fields.filter(c=>!preferred.includes(c))];
 if(type==='missing'&&ordered.includes('Actions')){ordered.splice(ordered.indexOf('Actions'),1);ordered.push('Actions');}
 if(!reportHiddenColumns.has(type)){
  let saved=null;
  if(interactive)try{saved=JSON.parse(window.localStorage.getItem(reportColumnStorageKey(type)));}catch(_){}
  if((type==='missing'&&ordered.length===23)||(type==='transactions'&&ordered.length===16))try{
   const versionKey=reportColumnStorageKey(type)+'.defaultVersion';
   // Earlier renders automatically persisted [] for their all-visible default.
   // Upgrade that legacy default once, while keeping custom hidden-column lists.
   if(window.localStorage.getItem(versionKey)!=='8-column-v1'){
    if(Array.isArray(saved)&&saved.length===0)saved=null;
    window.localStorage.setItem(versionKey,'8-column-v1');
   }
  }catch(_){}
  reportHiddenColumns.set(type,new Set(Array.isArray(saved)?saved.filter(c=>ordered.includes(c)):preferred.length?ordered.filter(c=>!preferred.includes(c)):[]));
 }
 const hidden=reportHiddenColumns.get(type);
 const identifying=type==='transactions'?'TXN ID':grouped?'Tool':ordered[0];
 const numeric=c=>/^(Total Quantity|Available|Borrowed|Good|Minor Wear|Damaged|Missing|Min Stock|Quantity|Previous Total|Updated Total)$/.test(c)||/^(Qty |Days |Handed Back|Damaged at Return|Missing Reported)/.test(c);
 const attrs=c=>`data-report-column="${ordered.indexOf(c)}" class="${c===identifying?'report-identity ':''}${type==='inventory'&&c==='Code'?'report-code ':''}${(type==='inventory'&&c==='Description')||(type==='stock_history'&&c==='Remarks')?'report-description ':''}${numeric(c)?'report-number':''}" ${hidden.has(c)?'hidden':''}`;
 const head=document.getElementById('reportTableHead');
 head.innerHTML='<tr>'+(transactionGroups?'<th scope="col" class="transaction-report-toggle"><span class="sr-only-txt">Expand transaction</span></th>':'')+ordered.map(c=>`<th scope="col" ${attrs(c)} ${interactive&&c!=='Actions'?`aria-sort="${state.sort===c?(state.direction==='asc'?'ascending':'descending'):'none'}"`:''}>${interactive&&c!=='Actions'?`<button type="button" class="inventory-report-sort" data-sort="${esc(c)}">${esc(transactionGroups&&c==='Tool Name'?'Tools':c)}${state.sort===c?(state.direction==='asc'?' ↑':' ↓'):''}</button>`:esc(c)}</th>`).join('')+'</tr>';
 // Replace the single delegated binding; refreshes never accumulate listeners.
 head.onclick=interactive?event=>{
  const button=event.target.closest('button[data-sort]');
  if(button&&head.contains(button))sortInventoryReport(button.dataset.sort);
 }:null;
 if(data.actions&&!transactionGroups&&typeof mtRememberLoans==='function')mtRememberLoans(data.actions.filter(a=>a.type==='borrow'));
 const reportBody=document.getElementById('reportTableBody');
 reportBody.onclick=transactionGroups?event=>{const button=event.target.closest('button[data-transaction-group]');if(button&&reportBody.contains(button))toggleReportTransaction(button.dataset.transactionGroup);}:null;
 reportBody.innerHTML=transactionGroups?renderGroupedTransactionRows(data,ordered,attrs,esc):rows.length?rows.map((row,rowIndex)=>'<tr>'+ordered.map(c=>{
  const action=data.actions?.[rowIndex];
  const raw=row[columns.indexOf(c)];
  const statusClass=/missing|unresolved/i.test(raw)?'missing':/overdue|follow-up/i.test(raw)?'overdue':/pending|active/i.test(raw)?'borrowed':/resolved|returned|closed/i.test(raw)?'returned':'neutral';
  const value=type==='transactions'&&['Transaction Date','Type','Status'].includes(c)?transactionReportCell(c,row,columns,esc):c==='Actions'?reportRowActions(action):c==='Status'&&['overdue','missing'].includes(type)?`<span class="status-badge ${statusClass}">${esc(raw)}</span>`:c==='Tool'&&grouped?`<strong>${esc(row[columns.indexOf(identity[0])])}</strong><small>${esc(row[columns.indexOf(identity[1])])}</small>`:esc(raw);
  if(type==='missing'&&c==='Write-off Reason')return `<td ${attrs(c)} data-writeoff-reason><div class="report-writeoff-reason-text">${value}</div></td>`;
  return `<td ${attrs(c)}>${value}</td>`;
 }).join('')+'</tr>').join(''):`<tr><td colspan="${ordered.length}">${interactive?`${type==='inventory'?'No tools':'No records'} match your filters. <button type="button" class="card-btn secondary" onclick="clearInventoryReportFilters()">Clear filters</button>`:'No data for this report.'}</td></tr>`;
 if(transactionGroups)syncTransactionDetailSpans();
 const options=document.getElementById('reportColumnOptions');
 const updateCount=()=>{
  document.getElementById('reportColumns').querySelector('summary').textContent=interactive?`Columns (${ordered.filter(c=>!hidden.has(c)).length}/${ordered.length})`:'Columns';
  if(interactive)try{window.localStorage.setItem(reportColumnStorageKey(type),JSON.stringify([...hidden]));}catch(_){}
 };
 updateCount();
 options.innerHTML=(interactive?`<button type="button" class="tt-column-button" id="reportShowAllColumns">Show all ${ordered.length}</button>`:'')+(['missing','transactions'].includes(type)?'<button type="button" class="tt-column-button" id="reportResetColumns">Reset to default</button>':'')+ordered.filter(c=>interactive||c!==identifying).map(c=>`<label><input type="checkbox" data-report-column="${ordered.indexOf(c)}" ${hidden.has(c)?'':'checked'}> ${esc(c)}</label>`).join('');
 const applyColumnSelection=()=>{
  document.getElementById('reportModal').querySelectorAll('th[data-report-column],td[data-report-column]').forEach(cell=>cell.hidden=hidden.has(ordered[Number(cell.dataset.reportColumn)]));
  options.querySelectorAll('input[data-report-column]').forEach(input=>input.checked=!hidden.has(ordered[Number(input.dataset.reportColumn)]));
  updateCount();
  if(transactionGroups)syncTransactionDetailSpans();
 };
 if(interactive)document.getElementById('reportShowAllColumns').onclick=()=>{hidden.clear();applyColumnSelection();};
 if(['missing','transactions'].includes(type))document.getElementById('reportResetColumns').onclick=()=>{
  const defaults=type==='missing'?missingReportDefaultColumns:transactionReportDefaultColumns;
  hidden.clear();ordered.filter(c=>!defaults.includes(c)).forEach(c=>hidden.add(c));applyColumnSelection();
 };
 options.onchange=event=>{
  const input=event.target;if(!input.matches('input[data-report-column]'))return;
  const field=ordered[Number(input.dataset.reportColumn)];
  input.checked?hidden.delete(field):hidden.add(field);
  document.getElementById('reportModal').querySelectorAll(`th[data-report-column="${input.dataset.reportColumn}"],td[data-report-column="${input.dataset.reportColumn}"]`).forEach(cell=>cell.hidden=!input.checked);
  updateCount();
  if(transactionGroups)syncTransactionDetailSpans();
 };
}
function transactionReportCell(column,row,columns,esc){
 const get=label=>row[columns.indexOf(label)];
 const event=String(get('Type')||'').toLowerCase();
 if(column==='Transaction Date'){
  const timestamp=get(event==='borrow'?'Borrowed At':event==='return'?'Returned At':'');
  if(!timestamp)return '—';
  const date=typeof parseDbDate==='function'?parseDbDate(timestamp):new Date(String(timestamp).replace(' ','T'));
  return Number.isNaN(date.getTime())?'—':esc(date.toLocaleString());
 }
 if(column==='Type')return event==='borrow'?'Borrow':event==='return'?'Return':esc(get('Type'));
 const status=String(get('Status')??'');
 const label=status?status.charAt(0).toUpperCase()+status.slice(1):'—';
 const badgeClass=/returned|closed/i.test(status)?'returned':/active/i.test(status)?'borrowed':'neutral';
 const missing=Number(get('Qty Missing'));
 return `<span class="transaction-status-badges"><span class="status-badge ${badgeClass}">${esc(label)}</span>${missing>0?`<span class="status-badge missing">${esc(missing)} missing</span>`:''}</span>`;
}
function reportRowActions(action){
 if(!action)return '—';
 if(action.case_id)return `<button type="button" class="card-btn secondary" onclick="openMissingCase(${Number(action.case_id)})">View case</button>`;
 if(action.type!=='borrow')return '—';
 if(action.pending_report_id)return `<button type="button" class="card-btn secondary" onclick="openMissingCase(${Number(action.pending_report_id)})">View pending case</button>`;
 if(action.status==='active'&&action.qty-action.qty_returned-action.qty_missing>0)return `<button type="button" class="card-btn secondary" onclick="openMissingReport(${Number(action.id)})">Report missing</button>`;
 return '—';
}

function reportRequestParams(type){
 const params=new URLSearchParams({type});
 if(hasReportTableControls(type)&&type!=='stock_history'){
  Object.entries(tableReportState(type)).forEach(([key,value])=>params.set(key,value));
 }
 if(type==='stock_history'){
  Object.entries(stockHistoryReportState).forEach(([key,value])=>params.set(key,value));
  const from=document.getElementById('stockDateFrom').value,to=document.getElementById('stockDateTo').value;
  if(from)params.set('date_from',from);if(to)params.set('date_to',to);
 }
 return params;
}
const inventoryReportState={search:'',category:'',sort:'Name',direction:'asc',page:1,per_page:25};
const stockHistoryReportState={search:'',category:'',sort:'Date / Time',direction:'desc',page:1,per_page:25};
const additionalReportStates={
 transactions:{search:'',category:'',sort:'Transaction Date',direction:'desc',page:1,per_page:25},
 overdue:{search:'',category:'',sort:'Due Date',direction:'asc',page:1,per_page:25},
 missing:{search:'',category:'',sort:'Reported At',direction:'desc',page:1,per_page:25},
 loss_history:{search:'',category:'',sort:'Case ID',direction:'desc',page:1,per_page:25}
};
function tableReportState(type){return type==='inventory'?inventoryReportState:type==='stock_history'?stockHistoryReportState:additionalReportStates[type]||inventoryReportState;}
function activeTableReportState(){return tableReportState(currentReportType);}
let inventoryReportSearchTimer;
function refreshInventoryReport(){return viewReport(currentReportType,reportTitle(currentReportType),true);}
function inventoryReportFilterChanged(){
 const state=activeTableReportState();
 state.search=document.getElementById('inventoryReportSearch').value;
 state.category=document.getElementById('inventoryReportCategory').value;
 state.page=1;++reportRequestId;
 clearTimeout(inventoryReportSearchTimer);
 inventoryReportSearchTimer=setTimeout(()=>{if(hasReportTableControls(currentReportType))refreshInventoryReport();},200);
}
function clearInventoryReportFilters(){
 clearTimeout(inventoryReportSearchTimer);
 document.getElementById('inventoryReportSearch').value='';document.getElementById('inventoryReportCategory').value='';
 Object.assign(activeTableReportState(),{search:'',category:'',page:1});return refreshInventoryReport();
}
function sortInventoryReport(field){
 const state=activeTableReportState();
 state.direction=state.sort===field&&state.direction==='asc'?'desc':'asc';
 state.sort=field;state.page=1;return refreshInventoryReport();
}
function inventoryReportChangePage(delta){const state=activeTableReportState();state.page=Math.max(1,state.page+delta);return refreshInventoryReport();}
function inventoryReportPageSizeChanged(value){const state=activeTableReportState();state.per_page=Number(value);state.page=1;return refreshInventoryReport();}
function renderInventoryReportPagination(data){
 const total=data.total??data.rows.length,page=data.page??1,size=data.per_page??25;
 const state=activeTableReportState();state.page=page;
 document.getElementById('inventoryReportRange').textContent=`Showing ${total?(page-1)*size+1:0}–${Math.min(page*size,total)} of ${total} ${currentReportType==='inventory'?'tools':currentReportType==='transactions'&&data.grouped?'transactions':'records'}`;
 document.getElementById('inventoryReportPrevious').disabled=page<=1;
 document.getElementById('inventoryReportNext').disabled=page*size>=total;
 document.getElementById('inventoryReportPageSize').value=String(size);
 const categories=document.getElementById('inventoryReportCategory');
 const statusLabels={active:'Active',overdue:'Overdue',due_today:'Due Today',followup:'Follow-up (7+ days)',missing:'Has missing units',returned:'Closed'};
 categories.innerHTML=`<option value="">${currentReportType==='stock_history'?'All events':currentReportType==='inventory'?'All categories':'All statuses'}</option>`+(data.categories||[]).map(c=>`<option value="${escapeHtml(c)}">${escapeHtml(currentReportType==='transactions'?(statusLabels[c]||c):c)}</option>`).join('');
 categories.value=state.category;
}
function refreshStockHistoryReport(){stockHistoryReportState.page=1;return viewReport('stock_history','Stock History',true);}
function stockHistoryToday(){document.getElementById('stockDateFrom').value=localToday();document.getElementById('stockDateTo').value=localToday();return refreshStockHistoryReport();}
function stockHistoryAll(){document.getElementById('stockDateFrom').value='';document.getElementById('stockDateTo').value='';return refreshStockHistoryReport();}
function renderStockHistorySummary(data){
 const t=data.totals||{};
 document.getElementById('stockHistorySummary').innerHTML=`<div class="stock-report-totals"><span>Units added <b>${Number(t.units_added)||0}</b></span><span>New tools <b>${Number(t.tools_registered)||0}</b></span><span>Restocked units <b>${Number(t.restocked_units)||0}</b></span><span>Units removed <b>${Number(t.units_removed)||0}</b></span></div>${t.unknown_registrations?`<p class="stock-note">${Number(t.unknown_registrations)} legacy registration(s) have unknown initial quantities and are excluded from the units-added total.</p>`:''}<details class="stock-daily"><summary>Daily totals (${(data.daily||[]).length} day(s))</summary><div class="table-container"><table class="data-table"><thead><tr><th>Date</th><th>Units added</th><th>New tools</th><th>Restocked</th><th>Removed</th></tr></thead><tbody>${(data.daily||[]).map(d=>`<tr><td>${escapeHtml(d.date)}</td><td>${d.units_added}</td><td>${d.tools_registered}</td><td>${d.restocked_units}</td><td>${d.units_removed}</td></tr>`).join('')||'<tr><td colspan="5">No stock events for these dates.</td></tr>'}</tbody></table></div></details>`;
}
