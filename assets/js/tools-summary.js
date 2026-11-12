/* ============================================================
   tools-summary.js — phone-only summary tiles on the Tools page.
   Counts come from the EXISTING api/tools.php (same status filter as the
   dropdown, per_page=1, read res.total), so they always match the list.
   Tapping a tile sets the existing status filter and calls applyToolFilters().
   No API, database or table-redesign.js behaviour is changed.
   ============================================================ */
(function(){
  'use strict';
  const root=document.getElementById('toolsSummary');
  if(!root||typeof window.loadTools!=='function')return;
  const mq=window.matchMedia('(max-width:767px)');
  const statusSel=()=>document.getElementById('toolStatusFilter');
  const MIN_GAP=5000;                       // ms between refreshes, so typing in search never spams the API
  let last=0,timer=null,busy=false;

  async function count(status){
    const p=new URLSearchParams({page:1,per_page:1,status,category:'',search:''});
    const res=await apiFetch(`${API}/tools.php?${p}`);
    return Number(res&&res.total);
  }
  function syncPressed(){
    const cur=statusSel()?statusSel().value:'';
    root.querySelectorAll('.tt-sum').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.status===cur)));
  }
  async function refresh(){
    if(!mq.matches||busy)return;
    busy=true;last=Date.now();
    try{
      const keys=Array.from(root.querySelectorAll('.tt-sum')).map(b=>b.dataset.status);
      const totals=await Promise.all(keys.map(k=>count(k).catch(()=>NaN)));
      root.querySelectorAll('.tt-sum-count').forEach((el,i)=>{if(Number.isFinite(totals[i]))el.textContent=String(totals[i]);});
    }finally{busy=false;}
  }
  function schedule(){
    syncPressed();
    clearTimeout(timer);
    timer=setTimeout(refresh,Math.max(0,MIN_GAP-(Date.now()-last)));
  }

  root.addEventListener('click',e=>{
    const b=e.target.closest('.tt-sum');if(!b)return;
    const sel=statusSel();if(!sel)return;
    const s=b.dataset.status;
    sel.value=(s!==''&&sel.value===s)?'':s;      // tapping the active tile again clears the filter
    applyToolFilters();
  });

  // Refresh whenever the Tools list (re)loads: borrow, return, stock edits and live-sync all end up here.
  const original=window.loadTools;
  window.loadTools=async function(){
    try{return await original.apply(this,arguments);}
    finally{schedule();}
  };
  if(mq.addEventListener)mq.addEventListener('change',schedule);
  const page=document.getElementById('toolsPage');
  if(page&&page.classList.contains('active'))schedule();
})();
