/* Shared near-real-time updates. Existing loaders remain the source of UI behavior. */
(()=>{
 'use strict';
 if(window.ttLiveSync)return;
 const original=apiFetch, requests=new Map(),lastData=new Map();
 let timer,controller,busy=false,pendingWake=false,stopped=false,dirty=true,version=null,failures=0,rendering=false,cache=null,renderError=false;
 let timezone='Asia/Manila';
 const page=()=>document.querySelector('.page.active')?.id.replace(/Page$/,'')||'dashboard';
 const modal=()=>!!document.querySelector('.modal-overlay.active');
 const signature=()=>JSON.stringify([page(),Array.from(document.querySelectorAll('.page.active input,.page.active select,.page.active textarea')).map(e=>[e.id,e.value,e.checked]),typeof currentReportType==='undefined'?null:currentReportType,typeof currentReportType!=='undefined'&&currentReportType?reportRequestParams(currentReportType).toString():'',typeof circulationTabName==='undefined'?null:circulationTabName,typeof toolsCurrentPage==='undefined'?0:toolsCurrentPage,typeof borrowersCurrentPage==='undefined'?0:borrowersCurrentPage,typeof borrowHistoryPage==='undefined'?0:borrowHistoryPage,typeof returnHistoryPage==='undefined'?0:returnHistoryPage,typeof circulationPage==='undefined'?0:circulationPage,typeof rrPage==='undefined'?0:rrPage,typeof auditCurrentPage==='undefined'?0:auditCurrentPage]);
 const status=document.createElement('small');status.id='liveSyncStatus';status.style.cssText='display:block;font-size:11px;color:var(--gray-500,#64748b);font-weight:400';
 document.getElementById('pageTitle')?.parentElement.append(status);
 const say=s=>{status.textContent=s;};
 const key=url=>{const u=new URL(url,location.href);return u.pathname+u.search;};
 apiFetch=async function(url,options={}){
  const get=(!options.method||options.method.toUpperCase()==='GET');
  const k=key(url);
  if(rendering&&get){
   if(cache.has(k))return structuredClone(cache.get(k));
   requests.set('extra|'+page()+'|'+k,{url,k,owner:page()});
   renderError=true;throw new Error('Display changed; retrying live update.');
  }
  const owner=page();
  const result=await original(url,{cache:'no-store',...options});
  if(get){lastData.set(k,JSON.stringify(result));if(lastData.size>64)lastData.delete(lastData.keys().next().value);}
  if(get&&!modal()){
   const u=new URL(url,location.href);
   if(['id','details','return_id','meta','preview_sku'].some(p=>u.searchParams.has(p)))return result;
   const common=/type=overdue_summary|status=low-stock.*active_only=1/.test(url);
   const group=(common?'common':owner)+'|'+u.pathname+'|'+(u.searchParams.get('type')||'')+'|'+(u.searchParams.get('summary')||'')+'|'+(u.searchParams.get('active_only')==='1'&&!common?u.searchParams.get('page')||'':'');
   requests.set(group,{url,k,owner:common?'common':owner});
  }
  if(!get){dirty=true;schedule(0);}
  return result;
 };
 const schedule=(delay=5000)=>{clearTimeout(timer);if(busy&&delay===0){pendingWake=true;return;}if(!stopped&&!document.hidden)timer=setTimeout(check,delay);};
 async function read(url,signal){
  const r=await fetch(url,{credentials:'same-origin',cache:'no-store',signal,headers:{Accept:'application/json'}});
  if(r.status===401||r.status===403){stopped=true;throw new Error('Session expired or access removed. Please sign in again.');}
  const d=await r.json();if(!r.ok||!d.success)throw new Error(d.message||'Live check failed');return d;
 }
 async function refresh(active,content=true,notifications=true){
  if(content){
  if(active==='dashboard')await loadDashboard();
  if(active==='tools')await loadTools();
  if(active==='borrowers')await loadBorrowers();
  if(active==='borrow'){
   if(['active','overdue','history'].includes(circulationTabName))await loadBorrowHistory(borrowHistoryPage);
   else if(circulationTabName==='checkout')await loadToolsForBorrow(true);
  }
  if(active==='return'){await rtLoadOverview();if(!document.getElementById('returnHistoryTableContainer')?.classList.contains('collapsed'))await loadReturnHistory(returnHistoryPage);}
  if(active==='replacements')await loadChargeCases();
  if(active==='reports'){
   if(currentReportType)await viewReport(currentReportType,reportTitle(currentReportType),true);
   else await refreshReportOverview();
  }
  if(active==='users')await loadUsers();
  if(active==='audit')await loadAuditLog();
  }
  if(notifications)await loadNotifications();
 }
 function relevant(r,active){
  if(r.owner==='common')return true;
  if(r.owner!==active)return false;
  const u=new URL(r.url,location.href),file=u.pathname.split('/').pop(),type=u.searchParams.get('type');
  if(active==='reports')return file==='reports.php'&&(currentReportType?type===currentReportType:['monthly','trend','category'].includes(type));
  if(active==='borrow')return circulationTabName==='checkout'?file==='tools.php':file==='transactions.php'&&type==='borrow';
  if(active==='return')return file==='transactions.php'&&['return','return_overview'].includes(type);
  if(active==='tools')return file==='tools.php';
  if(active==='borrowers')return file==='borrowers.php';
  if(active==='replacements')return file==='charge_cases.php';
  return true;
 }
 async function check(){
  if(busy||stopped||document.hidden)return;
  clearTimeout(timer);
  if(!navigator.onLine){say('Updates paused — offline');schedule();return;}
  busy=true;controller=new AbortController();const signal=controller.signal;
  const active=page(),view=signature();
  let timedOut=false;
  const timeout=setTimeout(()=>{timedOut=true;controller?.abort();},20000);
  try{
   const d=(await read(API+'/live_sync.php?page='+encodeURIComponent(active),signal)).data;timezone=d.timezone;
   if(signal.aborted||view!==signature())return;
   const changed=version!==null&&version!==d.version;
   if(changed)dirty=true;
   let updated=false;
   if(dirty){
    const targets=[...requests.values()].filter(r=>relevant(r,active));
    // Drain every request even when one fails, so retries cannot overlap siblings.
    const settled=await Promise.allSettled(targets.map(async r=>[r.k,await read(r.url,signal)]));
    const failed=settled.find(r=>r.status==='rejected');if(failed)throw failed.reason;
    const responses=settled.map(r=>r.value);
    const changedKeys=new Set(responses.filter(([k,value])=>lastData.get(k)!==JSON.stringify(value)).map(([k])=>k));
    updated=changedKeys.size>0;
    const contentChanged=targets.some(r=>r.owner!=='common'&&changedKeys.has(r.k));
    const notificationsChanged=targets.some(r=>r.owner==='common'&&changedKeys.has(r.k));
    // If a transaction commits between check and fetch, retry instead of mixing versions.
    const after=(await read(API+'/live_sync.php?page='+encodeURIComponent(active),signal)).data;
    if(signal.aborted||view!==signature()||after.version!==d.version)return;
    cache=new Map(responses);renderError=false;rendering=true;
    const scroll=[...document.querySelectorAll('.page.active,.page.active .table-container,.page.active .report-table-scroll')].map(el=>[el,el.scrollLeft,el.scrollTop]);
    const win=[scrollX,scrollY];
    const checked=[...document.querySelectorAll('.page.active input[type=checkbox]:checked')].map(e=>[e.id,e.name,e.value]);
    const tables=[...document.querySelectorAll('.page.active tbody')].map(el=>[el,[...el.childNodes]]);
    try{if(updated)await refresh(active,contentChanged,notificationsChanged);}finally{
     rendering=false;cache=null;
     if(renderError)tables.forEach(([el,nodes])=>el.replaceChildren(...nodes));
     checked.forEach(([id,name,value])=>{const el=id?document.getElementById(id):[...document.querySelectorAll('.page.active input[type=checkbox]')].find(e=>e.name===name&&e.value===value);if(el&&!el.disabled)el.checked=true;});
     scroll.forEach(([el,x,y])=>el.scrollTo(x,y));window.scrollTo(...win);
    }
    if(renderError)throw new Error('Display changed during update');
    responses.forEach(([k,value])=>lastData.set(k,JSON.stringify(value)));
    while(lastData.size>64)lastData.delete(lastData.keys().next().value);
    dirty=false;
   }
   version=d.version;failures=0;
   if(modal()&&(changed||updated))document.querySelectorAll('.modal-overlay.active').forEach(overlay=>{
    if(overlay.querySelector('.live-sync-warning'))return;
    const note=document.createElement('p');note.className='live-sync-warning';note.setAttribute('role','status');note.style.cssText='margin:12px;padding:10px;border-radius:6px;background:#fff7ed;color:#9a3412;font-size:13px';
    note.textContent='Newer data is available. Your entries are retained. Review the current record before saving.';
    (overlay.querySelector('[role=dialog],.modal')||overlay).prepend(note);
   });
   say(modal()&&(changed||updated)?'Newer data available. Form entries retained; review current data before saving.':'Last checked: '+new Date().toLocaleTimeString('en-US',{timeZone:timezone})+(updated?' · updated':''));
  }catch(e){if(!document.hidden&&(e.name!=='AbortError'||timedOut)){failures++;say(stopped?e.message:(!navigator.onLine?'Updates paused — offline':'Reconnecting…'));}}
  finally{clearTimeout(timeout);busy=false;controller=null;const delay=pendingWake?0:Math.min(60000,5000*2**Math.min(failures,4));pendingWake=false;schedule(delay);}
 }
 window.ttLiveSync={get rendering(){return rendering;},wake(){dirty=true;schedule(0);}};
 const wake=()=>{if(!document.hidden){controller?.abort();schedule(0);}};
 document.addEventListener('visibilitychange',()=>{if(document.hidden){clearTimeout(timer);controller?.abort();}else wake();});
 window.addEventListener('focus',wake);window.addEventListener('online',wake);
 window.addEventListener('offline',()=>{controller?.abort();say('Updates paused — offline');});
 window.addEventListener('pagehide',()=>{stopped=true;clearTimeout(timer);controller?.abort();});
 window.addEventListener('pageshow',e=>{if(e.persisted){stopped=false;wake();}});
 const navigate=navigateTo;
 navigateTo=function(...args){controller?.abort();for(const [k,r] of requests)if(r.owner!=='common')requests.delete(k);version=null;dirty=true;const result=navigate(...args);schedule(0);return result;};
 window.addEventListener('load',()=>schedule(5000));
})();
