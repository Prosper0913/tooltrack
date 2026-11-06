/* ============================================================
   dashboard.js — Dashboard tab: stat cards, Borrow Activity chart (Week 1-4 / Last 7 days), top borrowed, recent transactions, low stock.
   Plain (non-module) script: shares the global scope with the other
   assets/js files so inline onclick="" handlers keep working.
   ============================================================ */

let borrowChart=null;

async function loadDashboard(){
  try{
    const res=await apiFetch(`${API}/dashboard.php`);
    const d=res.data;

    initDashChart(d);   // own try/catch: no other widget below can block the chart now

    document.getElementById('dashTotal').textContent=d.total_tool_types??'—';
    document.getElementById('dashTotalUnits').textContent=d.total_units??'—';
    document.getElementById('dashAvailable').textContent=d.available_tools??'—';
    document.getElementById('dashBorrowed').textContent=d.borrowed_tools??'—';
    document.getElementById('dashDamaged').textContent=d.damaged_tools??'—';
    document.getElementById('dashMissing').textContent=d.missing_tools??'—';
    document.getElementById('dashBorrowers').textContent=d.total_borrowers??'—';
    document.getElementById('lowStockCount').textContent=d.low_stock_count??0;
    document.getElementById('borrowBadge').textContent=d.active_borrows??0;
    document.getElementById('borrowBadge').style.display=(d.active_borrows>0)?'inline-block':'none';

    // Most borrowed
    const rankClasses=['gold','silver','bronze'];
    const mbEl=document.getElementById('dashMostBorrowed');
    if(!d.most_borrowed||!d.most_borrowed.length){
      mbEl.innerHTML='<div class="empty-state"><i class="fas fa-box-open"></i><p>No data yet.</p></div>';
    } else {
      mbEl.innerHTML=d.most_borrowed.slice(0,5).map((item,i)=>`
        <div class="borrowed-item">
          <div class="borrowed-rank ${rankClasses[i]||''}">${i+1}</div>
          <div class="tool-icon"><i class="fas fa-tools"></i></div>
          <div class="borrowed-info">
            <div class="borrowed-name">${item.name}</div>
            <div class="progress-bar"><div class="progress-fill" style="width:${item.pct||0}%"></div></div>
          </div>
          <div class="borrowed-count">${item.count}</div>
        </div>`).join('');
    }

    // Recent transactions
    const txEl=document.getElementById('dashRecentTx');
    if(!d.recent_transactions||!d.recent_transactions.length){
      txEl.innerHTML='<div class="empty-state"><i class="fas fa-exchange-alt"></i><p>No transactions yet.</p></div>';
    } else {
      txEl.innerHTML=d.recent_transactions.slice(0,5).map(t=>`
        <div class="transaction-item">
          <div class="transaction-icon ${t.type}"><i class="fas fa-arrow-${t.type==='borrow'?'right':'left'}"></i></div>
          <div class="transaction-details">
            <div class="transaction-title">${t.tool_name||t.tool_code} ${t.type==='borrow'?'borrowed':'returned'}</div>
            <div class="transaction-meta">${t.type==='borrow'?'by '+escapeHtml(t.borrower||'Unknown borrower'):'by '+escapeHtml(t.returnee_name||'Unknown returner')+' &bull; '+escapeHtml(t.condition||'Unknown')+' condition'}</div>
          </div>
          <div class="transaction-time">${timeAgo(t.created_at)}</div>
        </div>`).join('');
    }

    // Low stock
    const alertEl=document.getElementById('dashAlerts');
    if(!d.low_stock_items||!d.low_stock_items.length){
      alertEl.innerHTML='<div class="empty-state"><i class="fas fa-check-circle"></i><p>No low stock items.</p></div>';
    } else {
      alertEl.innerHTML=d.low_stock_items.map(t=>`
        <div class="alert-item">
          <i class="fas fa-exclamation-triangle"></i>
          <div class="alert-info">
            <div class="alert-title">${t.name}</div>
            <div class="alert-desc">Only ${t.available} remaining • Min: ${t.min_stock}</div>
          </div>
        </div>`).join('');
    }
  }catch(err){console.error('Dashboard load failed:',err);}
}

// ── Dashboard: Borrow Activity chart ─────────────────────────────
// Two views: "Week 1–4" of any month (data from reports.php, same source as
// the Reports page) and "Last 7 days" (data from dashboard.php).
// The chart has its own try/catch and shows a message if something fails,
// so it can no longer be blocked silently by another widget on the page.
let dashChartMode='month';

      // 'month' | 'week'
let dashMonth=null;

             // 'YYYY-MM' or null (= current month)
let dashMonthList=[];

           // [{value,label}] newest first
let dashWeekData=null;

          // last response from dashboard.php

const dashSum=a=>(a||[]).reduce((x,y)=>x+(+y||0),0);

function dashChartMsg(t){const el=document.getElementById('dashChartNote'); if(el) el.textContent=t||'';}

function initDashChart(d){
  dashWeekData=d;
  dashSetMode(dashChartMode);
}

function dashSetMode(mode){
  dashChartMode=mode;
  document.getElementById('dashModeMonth').classList.toggle('active',mode==='month');
  document.getElementById('dashModeWeek').classList.toggle('active',mode==='week');
  document.getElementById('dashMonthNav').style.display=mode==='month'?'flex':'none';
  if(mode==='week') renderDashWeek(); else loadDashMonth(dashMonth);
}

function dashShiftMonth(step){
  const i=dashMonthList.findIndex(m=>m.value===dashMonth);
  const t=dashMonthList[i+step];
  if(t) loadDashMonth(t.value);
}

// Last 7 days (line chart — your original look)
function renderDashWeek(){
  try{
    const d=dashWeekData;
    if(!d){dashChartMsg('No data yet.');return;}
    const badge=document.getElementById('dashChartBadge');
    badge.textContent='Last 7 days'; badge.classList.remove('live');
    if(borrowChart)borrowChart.destroy();
    borrowChart=new Chart(document.getElementById('borrowChart').getContext('2d'),{
      type:'line',
      data:{
        labels:d.weekly_labels||[],
        datasets:[
          {label:'Borrows',data:d.weekly_borrows||[],borderColor:CHART_COLORS.purple,backgroundColor:CHART_COLORS.purpleFill,fill:true,tension:.4,borderWidth:2},
          {label:'Returns',data:d.weekly_returns||[],borderColor:CHART_COLORS.green,backgroundColor:CHART_COLORS.greenFill,fill:true,tension:.4,borderWidth:2}
        ]
      },
      options:{responsive:true,maintainAspectRatio:false,plugins:{datalabels:{display:false},legend:{position:'top',labels:{usePointStyle:true,padding:20}},tooltip:{callbacks:{title:items=>(d.weekly_dates&&d.weekly_dates[items[0].dataIndex])||items[0].label}}},scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:'rgba(0,0,0,.05)'}},x:{grid:{display:false}}}}
    });
    dashChartMsg(`Last 7 days: ${dashSum(d.weekly_borrows)} borrows · ${dashSum(d.weekly_returns)} returns`);
  }catch(err){console.error('Borrow chart (7 days) failed:',err);dashChartMsg('Could not draw the chart.');}
}

// Week 1–4 of a chosen month (bar chart — same as Reports → Monthly Statistics)
async function loadDashMonth(month){
  try{
    const qs=month?`&month=${encodeURIComponent(month)}`:'';
    const md=(await apiFetch(`${API}/reports.php?type=monthly${qs}`)).data;
    dashMonth=md.month;
    dashMonthList=md.available_months||[];
    if(!dashMonthList.some(m=>m.value===md.month)) dashMonthList.unshift({value:md.month,label:md.month_label});

    const sel=document.getElementById('dashMonthSelect');
    sel.innerHTML=dashMonthList.map(m=>`<option value="${escapeHtml(m.value)}">${escapeHtml(m.label)}</option>`).join('');
    sel.value=md.month;
    const idx=dashMonthList.findIndex(m=>m.value===md.month);
    document.getElementById('dashPrevBtn').disabled=idx>=dashMonthList.length-1;
    document.getElementById('dashNextBtn').disabled=idx<=0;
    const badge=document.getElementById('dashChartBadge');
    badge.textContent=md.is_current?'In progress':''; badge.classList.toggle('live',!!md.is_current);

    const labels=md.labels.map((l,i)=>[l,`Day ${md.ranges[i]}`]);
    const dl=(typeof ChartDataLabels!=='undefined')?[ChartDataLabels]:[];
    if(borrowChart)borrowChart.destroy();
    borrowChart=new Chart(document.getElementById('borrowChart').getContext('2d'),{
      type:'bar',
      data:{labels,datasets:[{label:'Borrows',data:md.borrows,backgroundColor:CHART_COLORS.purple,borderRadius:8},{label:'Returns',data:md.returns,backgroundColor:CHART_COLORS.green,borderRadius:8}]},
      plugins:dl,
      options:{responsive:true,maintainAspectRatio:false,layout:{padding:{top:20}},plugins:{legend:{position:'top',labels:{usePointStyle:true,padding:20}},tooltip:{callbacks:{title:items=>`${md.labels[items[0].dataIndex]} (days ${md.ranges[items[0].dataIndex]}) — ${md.month_label}`}},datalabels:{anchor:'end',align:'end',offset:0,color:'#374151',font:{weight:'600',size:12},display:ctx=>ctx.dataset.data[ctx.dataIndex]>0}},scales:{y:{beginAtZero:true,grace:'10%',ticks:{precision:0},grid:{color:'rgba(0,0,0,.05)'}},x:{grid:{display:false}}}}
    });
    const net=md.total_borrows-md.total_returns;
    dashChartMsg(`${md.month_label}: ${md.total_borrows} borrows · ${md.total_returns} returns · net ${net>0?'+':''}${net}${md.is_current?' (month in progress)':''}`);
  }catch(err){console.error('Borrow chart (month) failed:',err);dashChartMsg('Could not load the chart data. Please refresh.');}
}
