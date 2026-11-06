/* Deterministic browser lifecycle tests, without production accounts or writes. */
(async()=>{
 const checks=[];const assert=(ok,label)=>{if(!ok)throw new Error(label);checks.push(label);};
 try{
  for(const [name,source] of Object.entries(sources)){new Function(source);checks.push(name+' syntax');}
  let timers=new Map(),nextId=0,hidden=false,online=true,revision='one',quantity=3,fail=false,expired=false,fetches=0,active=0,peak=0,gate=null;
  window.setTimeout=(fn,delay)=>{const id=++nextId;timers.set(id,{fn,delay});return id;};window.clearTimeout=id=>timers.delete(id);
  Object.defineProperty(document,'hidden',{configurable:true,get:()=>hidden});Object.defineProperty(navigator,'onLine',{configurable:true,get:()=>online});
  const flush=async()=>{for(let i=0;i<80;i++)await Promise.resolve();};
  const step=async()=>{const entry=[...timers].sort((a,b)=>a[1].delay-b[1].delay)[0];assert(!!entry,'poll scheduled');timers.delete(entry[0]);entry[1].fn();await flush();};
  const response=url=>({success:true,data:url.includes('live_sync')?{version:revision,timezone:'Asia/Manila'}:url.includes('tools.php')?{quantity}:[]});
  window.API='api';window.toolsCurrentPage=1;window.navigateTo=()=>{};
  window.apiFetch=async url=>response(url);
  window.loadTools=async()=>{const d=await apiFetch('api/tools.php?page='+toolsCurrentPage);document.getElementById('rows').innerHTML='<tr><td>'+d.data.quantity+'</td></tr>';};
  window.loadNotifications=async()=>{await apiFetch('api/reports.php?type=overdue_summary');await apiFetch('api/tools.php?status=low-stock&active_only=1&per_page=50');};
  window.fetch=async(url,options)=>{
   fetches++;active++;peak=Math.max(peak,active);
   try{if(gate)await gate;if(options.signal.aborted)throw new DOMException('Aborted','AbortError');if(fail)throw new Error('network down');return {status:expired?401:200,ok:!expired,json:async()=>response(url)};}finally{active--;}
  };
  (0,eval)(sources['live-sync.js']);
  await loadTools();await loadNotifications();window.dispatchEvent(new Event('load'));await step();
  const baseline=fetches;await step();assert(fetches===baseline+1,'unchanged check fetches only revision');
  revision='two';quantity=8;await step();assert(document.getElementById('rows').textContent==='8','remote change updates rows');
  assert(document.getElementById('search').value==='keep search','search preserved');
  document.getElementById('edit').classList.add('active');revision='three';quantity=9;await step();
  assert(document.getElementById('draft').value==='unsaved','open form preserved');assert(document.getElementById('liveSyncStatus').textContent.includes('Newer data'),'remote edit warning');
  hidden=true;document.dispatchEvent(new Event('visibilitychange'));assert(timers.size===0,'hidden tab stops timers');
  hidden=false;document.dispatchEvent(new Event('visibilitychange'));window.dispatchEvent(new Event('focus'));assert(timers.size===1,'resume and focus deduplicated');await step();
  fail=true;await step();assert(document.getElementById('rows').textContent==='9','failure retains displayed rows');assert(document.getElementById('liveSyncStatus').textContent==='Reconnecting…','failure does not claim success');assert([...timers.values()][0].delay===10000,'failure backs off');
  fail=false;window.dispatchEvent(new Event('online'));await step();assert(document.getElementById('liveSyncStatus').textContent.includes('Last checked'),'connection recovers');
  let release;gate=new Promise(r=>release=r);const polling=[...timers][0];timers.delete(polling[0]);polling[1].fn();await flush();const before=fetches;window.dispatchEvent(new Event('focus'));await flush();assert(fetches===before,'slow request does not overlap');gate=null;release();await flush();assert([...timers.values()][0].delay===0,'focus checks immediately after cancellation');
  peak=0;revision='four';quantity=11;await step();assert(document.getElementById('rows').textContent==='11','aborted check recovers');
  revision='five';quantity=12;gate=new Promise(r=>release=r);const stale=[...timers][0];timers.delete(stale[0]);stale[1].fn();await flush();document.getElementById('search').value='new filter';gate=null;release();await flush();
  assert(document.getElementById('rows').textContent==='11','response after filter change is ignored');
  online=false;window.dispatchEvent(new Event('offline'));await step();assert(document.getElementById('liveSyncStatus').textContent.includes('offline'),'offline status');
  online=true;expired=true;window.dispatchEvent(new Event('online'));await step();assert(timers.size===0,'unauthorized polling stops');
  window.dispatchEvent(new Event('pagehide'));assert(timers.size===0,'navigation cleanup');
  window.escapeHtml=s=>String(s??'');window.CAT_ICONS={};window.toolMenuCache=new Map();window.stockActionButton=()=>'';window.closeToolMenu=()=>{};window.renderToolsTable=()=>{};
  const table=document.createElement('table');table.innerHTML='<tbody id="toolsTableBody"></tbody>';document.getElementById('toolsPage').append(table);
  (0,eval)(sources['table-redesign.js']);
  const tool={id:1,name:'Test',code:'TEST',category:'Cookware',quantity:5,good_qty:5,minor_qty:0};renderToolsTable([tool]);
  const row=document.querySelector('#toolsTableBody [data-record]');toggleTableDetail(row.querySelector('.tt-expand'));
  renderToolsTable([{...tool,good_qty:4}]);
  assert(document.querySelector('#toolsTableBody [data-record]')===row,'inventory row identity preserved');
  assert(!row.nextElementSibling.hidden&&row.querySelector('.tt-expand').getAttribute('aria-expanded')==='true','expanded inventory row preserved');
  document.getElementById('result').textContent=checks.map(x=>'PASS: '+x).join('\n')+'\nPASSED: '+checks.length;
 }catch(e){document.getElementById('result').textContent='FAIL: '+e.stack;}
})();
