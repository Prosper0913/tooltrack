async function runReportInterfaceChecks(source, shell, liveInventory=null){
 const checks=[];
 const check=(value,label)=>{if(!value)throw new Error(label);checks.push(label);};
 const doc=new DOMParser().parseFromString(shell,'text/html');
 const listeners={},requests=[],opened=[],exports=[];
 let page='',historyLoads=0;
 const storage=new Map();
 const browser={location:{href:'https://tooltrack.test/index.php'},open:url=>exports.push(url),localStorage:{getItem:key=>storage.get(key)??null,setItem:(key,value)=>storage.set(key,value)}};
 const testDocument={getElementById:id=>doc.getElementById(id),querySelectorAll:s=>doc.querySelectorAll(s),addEventListener:(name,fn)=>listeners[name]=fn};
 const factory=new Function('document','window','apiFetch','API','openModal','closeModal','navigateTo','escapeHtml','localToday','mockLoadBorrowHistory',source+`;loadBorrowHistory=mockLoadBorrowHistory;return {viewReport,returnToReports,exportCurrentReport,inventoryReportChangePage,inventoryReportPageSizeChanged,sortInventoryReport,inventoryReportFilterChanged,clearInventoryReportFilters};`);
 const inventory=liveInventory||{columns:['Name','Code','Category','Total Quantity','Good','Minor Wear','Damaged','Missing','Borrowed','Available','Min Stock','Description'],rows:Array.from({length:40},()=>['Whisk','W-1','Utensils',70,58,2,0,0,10,60,5,'Kitchen tool'])};
 const stock={columns:['Date / Time','Event','Tool Name','SKU','Quantity','Previous Total','Updated Total','Recorded By','Remarks'],rows:Array.from({length:40},()=>['11/03/2026 9:30 AM','Stock Added','Whisk','W-1',15,70,85,'Admin','Delivery'])};
 const detailFixtures={
  transactions:{columns:['TXN ID','Type','Tool Code','Tool Name','Borrower','Status','Condition','Notes','Due Date','Returned At','Borrowed At','Qty Borrowed','Qty Returned','Qty Missing'],rows:Array.from({length:40},(_,i)=>['TXN-'+i,'borrow','BASO-'+i,'Baso','Borrower','active','good','','2026-10-01',null,'2026-09-01',10,0,0])},
  overdue:{columns:['TXN ID','Tool Code','Tool Name','Borrower Name','Borrower ID','Due Date','Borrowed On','Qty Outstanding','Days Overdue','Status','Pending Missing Report'],rows:Array.from({length:40},(_,i)=>['TXN-'+i,'BASO-'+i,'Baso','Borrower','B-1','2026-10-01','2026-09-01',10,2,'Overdue',null])},
  missing:{columns:['Case No','Status','Tool Code','Tool Name','Borrower','Borrower ID','Loan TXN','Date Missing','Qty Reported','Qty Confirmed Missing','Qty Closed (historical)','Qty Recovered','Qty Still Missing','Reason','Investigation Notes','Reported By','Reported At','Confirmed/Rejected By','Confirmed/Rejected At','Last Recovered By','Last Recovered At','Resolved At'],rows:Array.from({length:40},(_,i)=>['CASE-'+i,'Missing (unresolved)','BASO-'+i,'Baso','Borrower','B-1','TXN-'+i,'2026-10-01',2,2,0,0,2,'Loss','','Admin','2026-10-01',null,null,null,null,null])}
 };
 const app=factory(testDocument,browser,async url=>{requests.push(url);return {data:url.includes('type=inventory')?inventory:url.includes('type=stock_history')?stock:{columns:['Status'],rows:[['Missing (unresolved)']]}};},'api',id=>opened.push(id),()=>{},p=>page=p,String,()=> '2026-11-03',()=>historyLoads++);
 const removed={borrowers:'Borrower Activity',conditions:'Tool Condition Issues',unresolved:'Unresolved Missing Cases',integrity:'Inventory Integrity'};
 const cards=[...doc.querySelectorAll('#reportsPage .report-card')];
 check(doc.querySelectorAll('script[src="assets/js/reports.js"]').length===1,'Only one active report script is loaded');
 for(const id of ['reportModal','reportTableHead','reportTableBody','reportColumns','inventoryReportToolbar','inventoryReportNext','exportReportBtn'])check(doc.querySelectorAll('#'+id).length===1,'One shared control ID: '+id);
 check(!doc.querySelector('#reportModal.modal-overlay'),'Shared report workspace is not an active legacy modal');
 check(cards.length===5,'Five overview cards retain the write-off history destination');
 check(cards.some(c=>c.getAttribute('onclick')?.includes("viewReport('loss_history'")),'Write-off history remains available');
 check(!doc.getElementById('reportsMore')&&!doc.getElementById('specializedReportCount'),'Detailed section and count removed');
 check(doc.querySelector('.reports-heading p').textContent==='Open the reports you use most.','Short overview subtitle');
 const destinations=[];
 for(const link of doc.querySelectorAll('.report-card-group a')){
  check(link.hasAttribute('href')&&link.tabIndex===0,'Keyboard accessible link: '+link.textContent);
  check(!link.closest('button')&&!link.closest('.report-card').hasAttribute('onclick'),'Independent link in non-interactive card: '+link.textContent);
  const calls=[];
  new Function('viewReport',link.getAttribute('onclick'))(id=>calls.push(id));
  check(calls.length===1,'One destination per activation: '+link.textContent);
  destinations.push(calls[0]);
 }
 check(destinations.join(',')==='inventory,stock_history,transactions,returns','Main and secondary links target the correct reports');
 for(const [id,name] of Object.entries(removed)){
  check(!cards.some(c=>c.textContent.includes(name))&&!doc.querySelector(`#reportsPage option[value="${id}"]`),'Removed card and selection: '+name);
  check(!doc.querySelector(`#reportsPage [onclick*="viewReport('${id}'"]`),'Removed opening links: '+id);
  const before=requests.length;await app.viewReport(id,name);
  check(requests.length===before&&page==='reports','Retired opener returns to Reports: '+id);
  app.exportCurrentReport();check(exports.length===0,'Retired opener cannot export: '+id);
  for(const suffix of ['?report='+id,'?type='+id,'#reports/'+id,'#'+id]){
   page='';browser.location.href='https://tooltrack.test/index.php'+suffix;listeners.DOMContentLoaded();
   check(page==='reports','Old bookmark returns to Reports: '+suffix);
  }
 }
 for(const id of ['inventory','transactions','overdue','missing','stock_history','returns']){
  await app.viewReport(id,id);
  check(!doc.getElementById('reportModal').hidden&&doc.getElementById('reportModal').parentElement.id==='reportsPage'&&requests.at(-1).includes('type='+id)&&doc.getElementById('reportTableBody').rows.length>0,'Report opens and renders in main content: '+id);
  app.exportCurrentReport();check(exports.at(-1).includes('type='+id)&&exports.at(-1).includes('download=1'),'CSV preserved: '+id);
 }
 check(!doc.getElementById('transactionReportSection')&&!doc.getElementById('transactionReportHome'),'Retired Transactions inline panel is removed');
 check(doc.getElementById('circulationLoanHistory').closest('#borrowPage')!==null,'Shared loan history stays in Borrowing');
 const preview=document.createElement('div');
 preview.id='reportsPage';preview.style.cssText='padding:16px;width:100%;box-sizing:border-box';
 preview.append(doc.querySelector('.reports-grid-primary').cloneNode(true));document.body.append(preview);
 const grid=preview.querySelector('.reports-grid');
 const columns=getComputedStyle(grid).gridTemplateColumns.split(' ').length;
 check(columns===(innerWidth<=768?1:2),'Responsive overview columns at '+innerWidth+'px');
 check([...preview.querySelectorAll('.report-card')].every(c=>c.getBoundingClientRect().right<=innerWidth),'Cards fit viewport at '+innerWidth+'px');
 for(const a of preview.querySelectorAll('a')){
  const rect=a.getBoundingClientRect();
  check(rect.width>0&&a.offsetHeight>=32,`Visible click target: ${a.textContent}`);
 }
 preview.remove();
 for(const type of ['inventory','stock_history']){
  await app.viewReport(type,type);
  const view=doc.getElementById('reportModal');
  const headers=[...view.querySelectorAll('#reportTableHead th:not([hidden])')].map(h=>h.textContent.replace(/ [↑↓]$/,''));
  check(headers.join('|')===(type==='inventory'?inventory.columns.join('|'):'Date / Time|Tool|Event|Quantity|Previous Total|Updated Total|Recorded By|Remarks'),'Export field order: '+type);
  if(type==='inventory'){
   check(doc.getElementById('exportReportBtn').parentElement.classList.contains('report-toolbar-row'),'Single CSV action is in Inventory toolbar');
   check(doc.querySelectorAll('#exportReportBtn').length===1,'Moving Export does not duplicate the action');
   check(doc.getElementById('inventoryReportSearch').placeholder==='Search this report by tool name or code','Search is labeled for this report');
   check(doc.getElementById('reportShowAllColumns').textContent==='Show all 12','Show all 12 action is labeled clearly');
   const cells=[...view.querySelector('#reportTableBody tr').cells].map(c=>c.textContent);
   check(JSON.stringify(cells)===JSON.stringify(inventory.rows[0].map(v=>String(v??''))),'All twelve source values match, including zeros');
   if(liveInventory)check(JSON.stringify(cells)===JSON.stringify(liveInventory.csvRow.map(v=>v===''?'':v)),'Live tool matches freshly serialized CSV row');
   const summary=doc.getElementById('reportColumns').querySelector('summary');
   check(summary.textContent==='Columns (12/12)','Default count is 12/12');
   for(const input of [...doc.getElementById('reportColumnOptions').querySelectorAll('input')].slice(1,3)){
    input.checked=false;doc.getElementById('reportColumnOptions').onchange({target:input});
   }
   check(summary.textContent==='Columns (10/12)','Hide two fields gives 10/12');
   doc.getElementById('reportShowAllColumns').onclick();
   check(summary.textContent==='Columns (12/12)'&&view.querySelectorAll('#reportTableHead th:not([hidden])').length===12,'Show all restores 12/12');
  }else{
   check(view.querySelector('.report-identity small').textContent==='W-1','Tool code on second line: '+type);
   check(doc.getElementById('exportReportBtn').parentElement.classList.contains('report-toolbar-row'),'Stock History reuses the toolbar export action');
  }
  const option=doc.getElementById('reportColumnOptions').querySelector('input:not(:checked)')||doc.getElementById('reportColumnOptions').querySelector('input');
  option.checked=true;doc.getElementById('reportColumnOptions').onchange({target:option});
  check(!view.querySelector(`th[data-report-column="${option.dataset.reportColumn}"]`).hidden,'Columns reveals remaining fields: '+type);
  option.checked=false;doc.getElementById('reportColumnOptions').onchange({target:option});
  doc.getElementById('reportShowAllColumns').onclick();
  const host=document.createElement('main');host.className='main-content';host.style.padding='24px';host.append(view.cloneNode(true));document.body.append(host);
  const live=host.firstElementChild,scroller=live.querySelector(type==='stock_history'?'.report-table-scroll':'.report-records'),table=live.querySelector('.report-table-scroll .data-table');
  check(getComputedStyle(scroller).overflowY==='auto'&&getComputedStyle(live.querySelector(type==='stock_history'?'.report-records':'.report-table-scroll')).overflowY==='visible','One report scroll area: '+type);
  const filters=live.querySelector('#stockHistoryControls'),summary=live.querySelector('#stockHistorySummary');
  const filterTop=filters.getBoundingClientRect().top,summaryLeft=summary.getBoundingClientRect().left;
  if(type==='inventory')check(getComputedStyle(live.querySelector('.report-code')).whiteSpace==='nowrap','Inventory codes stay on one line');
  scroller.style.scrollBehavior='auto';
  scroller.scrollLeft=scroller.scrollWidth;scroller.scrollTop=300;
  if(type==='stock_history'){
   check(scroller.scrollHeight>scroller.clientHeight&&scroller.scrollTop>0,'Stock History records have working vertical overflow');
   check(filters.getBoundingClientRect().top===filterTop&&summary.getBoundingClientRect().left===summaryLeft,'Records scroll independently of Stock History filters and summaries');
   if(innerWidth>=1000){
    const controls=[...filters.querySelectorAll('input,button')];
    check(Math.max(...controls.map(c=>c.getBoundingClientRect().bottom))-Math.min(...controls.map(c=>c.getBoundingClientRect().bottom))<2,'Desktop date controls stay on one row');
    check(getComputedStyle(summary.querySelector('.stock-report-totals')).gridTemplateColumns.split(' ').length===4,'Desktop summary keeps four cards on one row');
   }
   const daily=live.querySelector('.stock-daily');daily.open=true;
   check(scroller.getBoundingClientRect().top>=summary.getBoundingClientRect().bottom-1,'Expanded Daily totals stays above records without overlap');
   check(getComputedStyle(daily.querySelector('th')).position==='static','Daily totals headers are not sticky');
   daily.open=false;
  }
  if(type==='inventory')check(live.querySelector('td.report-identity').getBoundingClientRect().left>=scroller.getBoundingClientRect().left-1&&live.querySelector('td.report-identity').getBoundingClientRect().right<=scroller.getBoundingClientRect().right,'Name remains visible at the rightmost column');
  check(getComputedStyle(live.querySelector('td.report-identity')).position==='sticky','Sticky tool identity: '+type);
  check(getComputedStyle(live.querySelector('#reportTableHead th')).position==='sticky','Sticky column headers: '+type);
  check(live.querySelector('#reportTableHead th').getBoundingClientRect().top>=scroller.getBoundingClientRect().top-1,`Headers remain visible while scrolling: ${type} (${live.querySelector('#reportTableHead th').getBoundingClientRect().top}, ${scroller.getBoundingClientRect().top})`);
  if(innerWidth<768){
   check(scroller.scrollWidth>scroller.clientWidth,'Horizontal overflow stays in report scroll area: '+type);
   check(live.querySelector('td.report-identity').getBoundingClientRect().left>=scroller.getBoundingClientRect().left-1,'Tool identity remains visible after sideways scroll: '+type);
  }
  check(live.getBoundingClientRect().right<=innerWidth,'Report stays inside main content: '+type);
  host.remove();
 }
 if(!liveInventory){
  await app.viewReport('stock_history','Stock History');
  check(!doc.getElementById('inventoryReportToolbar').hidden&&!doc.getElementById('inventoryReportPagination').hidden,'Stock History exposes shared search and pagination controls');
  check(doc.getElementById('inventoryReportCategory').parentElement.firstChild.textContent==='Event','Stock History filter is labeled Event');
  check(doc.getElementById('reportColumns').querySelector('summary').textContent==='Columns (8/8)','Stock History shows all eight grouped columns by default');
  const remarks=doc.getElementById('reportColumnOptions').querySelector('input[data-report-column="7"]');
  remarks.checked=false;doc.getElementById('reportColumnOptions').onchange({target:remarks});
  check(JSON.parse(storage.get('tooltrack.stockHistoryReport.hiddenColumns')).includes('Remarks'),'Stock History has independent saved column preferences');
  doc.getElementById('reportShowAllColumns').onclick();
  await app.sortInventoryReport('Quantity');
  check(requests.at(-1).includes('type=stock_history')&&requests.at(-1).includes('sort=Quantity')&&requests.at(-1).includes('direction=asc'),'Stock History quantity sorting uses the shared handler');
  await app.inventoryReportChangePage(1);
  check(requests.at(-1).includes('type=stock_history')&&requests.at(-1).includes('page=2'),'Stock History paginates independently');
  doc.getElementById('inventoryReportSearch').value='W-1';app.inventoryReportFilterChanged();
  await new Promise(resolve=>setTimeout(resolve,250));
  check(requests.at(-1).includes('type=stock_history')&&requests.at(-1).includes('search=W-1')&&requests.at(-1).includes('page=1'),'Stock History code search resets pagination');
  app.exportCurrentReport();
  check(exports.at(-1).includes('type=stock_history')&&exports.at(-1).includes('date_from=')&&!exports.at(-1).includes('search=')&&!exports.at(-1).includes('page='),'Stock History CSV preserves date-filtered full export scope');
  for(let i=0;i<3;i++){
   await app.viewReport('inventory','Inventory Report');app.returnToReports();
   check(doc.getElementById('reportModal').hidden&&!doc.getElementById('reportsPage').classList.contains('reports-showing'),'Return to overview after opening '+(i+1));
  }
  await app.viewReport('inventory','Inventory Report');
  const tick=()=>new Promise(resolve=>setTimeout(resolve,0));
  let before=requests.length;
  doc.querySelector('#reportTableHead button[data-sort="Name"]').click();await tick();
  check(requests.length===before+1&&requests.at(-1).includes('direction=desc'),'After three reopens one sort click changes direction once');
  before=requests.length;
  doc.querySelector('#reportTableHead button[data-sort="Name"]').click();await tick();
  check(requests.length===before+1&&requests.at(-1).includes('direction=asc'),'Next sort click reverses direction exactly once');
  const next=doc.getElementById('inventoryReportNext');
  next.onclick=new Function('inventoryReportChangePage',next.getAttribute('onclick')).bind(null,app.inventoryReportChangePage);
  before=requests.length;next.click();await tick();
  check(requests.length===before+1&&requests.at(-1).includes('page=2'),'After three reopens one Next click advances one page');
  const exportButton=doc.getElementById('exportReportBtn');
  exportButton.onclick=new Function('exportCurrentReport',exportButton.getAttribute('onclick')).bind(null,app.exportCurrentReport);
  const exported=exports.length;exportButton.click();
  check(exports.length===exported+1,'After three reopens one export click starts one download');
  doc.getElementById('inventoryReportSearch').value='Whisk';app.inventoryReportFilterChanged();
  before=requests.length;doc.querySelector('#reportTableHead button[data-sort="Name"]').click();
  await new Promise(resolve=>setTimeout(resolve,250));
  check(requests.length===before+1,'Sorting cancels a pending debounced search refresh');
  await app.clearInventoryReportFilters();
  await app.viewReport('inventory','Inventory Report');
  await app.inventoryReportChangePage(1);
  check(requests.at(-1).includes('page=2'),'Next page requests server page two');
  const hiddenCode=doc.getElementById('reportColumnOptions').querySelector('input[data-report-column="1"]');
  hiddenCode.checked=false;doc.getElementById('reportColumnOptions').onchange({target:hiddenCode});
  doc.getElementById('inventoryReportSearch').value='W-1';
  app.inventoryReportFilterChanged();await new Promise(resolve=>setTimeout(resolve,250));
  check(requests.at(-1).includes('search=W-1')&&requests.at(-1).includes('page=1'),'Search resets page and sends code to server');
  check(doc.querySelector('#reportTableHead th[data-report-column="1"]').hidden,'Code search works while Code remains hidden');
  await app.sortInventoryReport('Available');
  check(requests.at(-1).includes('sort=Available')&&requests.at(-1).includes('direction=asc'),'Available sort requests numeric source field');
  await app.inventoryReportPageSizeChanged('50');
  check(requests.at(-1).includes('per_page=50')&&requests.at(-1).includes('page=1'),'Page size resets to first page');
  app.exportCurrentReport();
  check(exports.at(-1)==='api/reports.php?type=inventory&download=1','CSV ignores page, search, sort, and column selection');
  const code=doc.getElementById('reportColumnOptions').querySelector('input[data-report-column="1"]');
  code.checked=false;doc.getElementById('reportColumnOptions').onchange({target:code});
  check(JSON.parse(storage.get('tooltrack.inventoryReport.hiddenColumns')).includes('Code'),'Column selection saved to browser storage');
  const reopened=factory(testDocument,browser,async()=>({data:inventory}),'api',()=>{},()=>{},()=>{},String,()=> '2026-11-03',()=>{});
  await reopened.viewReport('inventory','Inventory Report');
  check(doc.getElementById('reportColumns').querySelector('summary').textContent==='Columns (11/12)'&&doc.querySelector('#reportTableHead th[data-report-column="1"]').hidden,'Saved selection restored in a fresh report instance');
  doc.getElementById('reportShowAllColumns').onclick();
  check(storage.get('tooltrack.inventoryReport.hiddenColumns')==='[]','Show all persists complete selection');
 }
 // Use mounted controls: detached DOM fixtures cannot expose browser focus loss.
 for(const focusType of ['stock_history','inventory','transactions','overdue','missing']){
 const focusData=focusType==='inventory'?inventory:focusType==='stock_history'?stock:detailFixtures[focusType];
 const focusCheck=(value,label)=>check(value,focusType+': '+label);
 const fixture=document.createElement('div');
 fixture.append(doc.getElementById('reportsPage').cloneNode(true));document.body.append(fixture);
 const mountedDoc={getElementById:id=>fixture.querySelector('#'+id),querySelectorAll:s=>fixture.querySelectorAll(s),addEventListener:()=>{}};
 const pending=[];let initial=true;
 const focusApp=factory(mountedDoc,browser,async url=>{
  if(initial){initial=false;return {data:focusData};}
  return new Promise(resolve=>pending.push({url,resolve}));
 },'api',()=>{},()=>{},()=>mountedDoc.getElementById('reportsPage').classList.add('active'),String,()=> '2026-11-03',()=>{});
 await focusApp.viewReport(focusType,focusType);
 const search=mountedDoc.getElementById('inventoryReportSearch'),category=mountedDoc.getElementById('inventoryReportCategory');
 const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
 const result=text=>({data:{...focusData,rows:[focusData.rows[0].map((v,i)=>i===0?text:v)],total:1,page:1,per_page:25}});
 search.focus();search.value='bas';search.setSelectionRange(3,3);focusApp.inventoryReportFilterChanged();
 await delay(230);
 focusCheck(document.activeElement===search,'Pending search keeps the mounted input focused');
 pending[0].resolve(result('bas'));await delay(0);
 focusCheck(document.activeElement===search&&search.selectionStart===3&&search.selectionEnd===3,'Response preserves focus and cursor');
 search.setRangeText('o',search.selectionStart,search.selectionEnd,'end');focusApp.inventoryReportFilterChanged();
 await delay(230);pending[1].resolve(result('baso'));await delay(0);
 focusCheck(search.value==='baso'&&document.activeElement===search&&mountedDoc.getElementById('reportTableBody').textContent.includes('baso'),'Typing bas then o continues without clicking after results');
 search.value='bas';focusApp.inventoryReportFilterChanged();await delay(230);
 category.focus();pending[2].resolve(result('bas'));await delay(0);
 focusCheck(document.activeElement===category,'Moving to the next control during search is respected when results arrive');
 search.focus();search.value='bas';focusApp.inventoryReportFilterChanged();await delay(230);
 search.value='baso';search.setSelectionRange(2,2);focusApp.inventoryReportFilterChanged();await delay(230);
 pending[4].resolve(result('Latest baso'));await delay(0);pending[3].resolve(result('Stale bas'));await delay(0);
 focusCheck(mountedDoc.getElementById('reportTableBody').textContent.includes('Latest baso')&&!mountedDoc.getElementById('reportTableBody').textContent.includes('Stale bas'),'Only the latest search response renders');
 focusCheck(search.selectionStart===2&&search.selectionEnd===2&&mountedDoc.getElementById('inventoryReportSearch')===search,'Cursor and input identity survive asynchronous updates');
 fixture.remove();
 }
 // Reproduce the user's older auto-saved all-visible view.
 storage.set('tooltrack.missingReport.hiddenColumns','[]');
 storage.delete('tooltrack.missingReport.hiddenColumns.defaultVersion');
 const workflow=document.createElement('div');workflow.append(doc.getElementById('reportsPage').cloneNode(true));document.body.append(workflow);
 const workflowDoc={getElementById:id=>workflow.querySelector('#'+id),querySelectorAll:s=>workflow.querySelectorAll(s),addEventListener:()=>{}};
 const historyListeners={},historyStates=[null];let historyIndex=0;
 const workflowWindow={...browser,location:{href:'https://tooltrack.test/index.php'},addEventListener:(type,fn)=>historyListeners[type]=fn};
 workflowWindow.history={get state(){return historyStates[historyIndex];},pushState(state){historyStates.splice(++historyIndex);historyStates[historyIndex]=state;},back(){if(historyIndex>0)historyListeners.popstate({state:historyStates[--historyIndex]});},forward(){if(historyIndex+1<historyStates.length)historyListeners.popstate({state:historyStates[++historyIndex]});}};
 const workflowRequests=[],workflowExports=[];workflowWindow.open=url=>workflowExports.push(url);
 const workflowApp=factory(workflowDoc,workflowWindow,async url=>{
  workflowRequests.push(url);const params=new URL(url,'https://tooltrack.test/').searchParams,type=params.get('type'),fixtureData=detailFixtures[type]||inventory;
  const rows=params.get('search')==='no match'?[]:fixtureData.rows.concat(fixtureData.rows);
  const page=Number(params.get('page')||1),size=Number(params.get('per_page')||25);
  const visible=rows.slice((page-1)*size,page*size);
  const actions=visible.map((row,i)=>type==='missing'?{case_id:i+1}:{id:i+1,type:'borrow',status:'active',qty:10,qty_returned:0,qty_missing:0,tool_name:'Baso',tool_code:'BASO-1'});
  return {data:{...fixtureData,rows:visible,actions,total:rows.length,page,per_page:size,categories:type==='missing'?['Pending confirmation','Missing (unresolved)','Resolved','Rejected']:['active','returned']}};
 },'api',()=>{},()=>{},()=>workflowDoc.getElementById('reportsPage').classList.add('active'),String,()=> '2026-11-03',()=>{});
 const settle=()=>new Promise(resolve=>setTimeout(resolve,0));
 const click=async node=>{node.onclick=new Function(...Object.keys(workflowApp),node.getAttribute('onclick')).bind(null,...Object.values(workflowApp));node.click();await settle();};
 for(let cycle=0;cycle<3;cycle++)for(const type of ['transactions','overdue','missing','transactions']){
  const before=workflowRequests.length;
  await click(workflow.querySelector(`.reports-grid-primary [onclick*="viewReport('${type}'"]`));
  check(workflowRequests.length===before+1&&workflowDoc.getElementById('reportModal').dataset.report===type,`Cycle ${cycle+1}: one card opens one ${type} detail`);
  check(workflowDoc.getElementById('reportTableBody').querySelector(type==='missing'?'[onclick^="openMissingCase"]':'[onclick^="openMissingReport"]')!==null,type+' retains its row actions');
  check(getComputedStyle(workflow.querySelector('.report-records')).overflowX==='auto'&&getComputedStyle(workflow.querySelector('#reportTableBody .report-identity')).position==='sticky',type+' contains horizontal scrolling and pins its identifying column');
  await workflowApp.clearInventoryReportFilters();
  const pageBefore=workflowRequests.length;await click(workflowDoc.getElementById('inventoryReportNext'));
  check(workflowRequests.length===pageBefore+1&&workflowRequests.at(-1).includes('page=2'),`Cycle ${cycle+1}: ${type} Next advances once`);
  const exportBefore=workflowExports.length;await click(workflowDoc.getElementById('exportReportBtn'));
  check(workflowExports.length===exportBefore+1&&workflowExports.at(-1)===`api/reports.php?type=${type}&download=1`,`Cycle ${cycle+1}: ${type} exports once with complete scope`);
  await click(workflow.querySelector('[onclick="returnToReports()"]'));
  check(workflowDoc.getElementById('reportModal').hidden,'Back to Reports restores overview');
 }
 await workflowApp.viewReport('transactions','Transactions');
 const transactionColumn=workflowDoc.getElementById('reportColumnOptions').querySelector('input[data-report-column="1"]');transactionColumn.checked=false;workflowDoc.getElementById('reportColumnOptions').onchange({target:transactionColumn});
 await workflowApp.viewReport('overdue','Overdue Loans');check(!workflowDoc.getElementById('reportTableHead').querySelector('th[data-report-column="1"]').hidden,'Transactions column preference does not hide Overdue columns');
 workflowWindow.history.back();await settle();check(workflowDoc.getElementById('reportModal').dataset.report==='transactions','Browser Back returns to the previous report');
 workflowWindow.history.back();await settle();check(workflowDoc.getElementById('reportModal').hidden,'Browser Back returns from detail to overview');
 workflowWindow.history.forward();await settle();check(workflowDoc.getElementById('reportModal').dataset.report==='transactions'&&!workflowDoc.getElementById('reportModal').hidden,'Browser Forward reopens the report');
 for(const type of ['overdue','missing']){
  await workflowApp.viewReport(type,type);workflowDoc.getElementById('inventoryReportSearch').value='no match';workflowApp.inventoryReportFilterChanged();await new Promise(resolve=>setTimeout(resolve,230));
  check(workflowDoc.getElementById('reportTableBody').textContent.includes('No records match your filters')&&workflowDoc.getElementById('reportTableBody').querySelector('button'),'Empty '+type+' results offer Clear filters');
 }
 check(!workflow.querySelector('#transactionReportSection')&&!workflow.querySelector('#transactionReportHome'),'No retired Transactions panel returns after repeated switching');
 await workflowApp.clearInventoryReportFilters();
 await workflowApp.viewReport('missing','Missing Tools');
 const missingDefault=['Case No','Status','Tool Name','Borrower','Date Missing','Qty Reported','Qty Still Missing','Actions'];
 const visibleMissing=()=>[...workflowDoc.getElementById('reportTableHead').querySelectorAll('th:not([hidden])')].map(c=>c.textContent.replace(/ [↑↓]$/,''));
 const missingCount=()=>workflowDoc.getElementById('reportColumns').querySelector('summary').textContent;
 check(missingCount()==='Columns (8/23)'&&JSON.stringify(visibleMissing())===JSON.stringify(missingDefault),'Missing first use shows the exact eight default columns');
 check(storage.get('tooltrack.missingReport.hiddenColumns.defaultVersion')==='8-column-v1','Legacy all-visible default migrates once to the eight-column layout');
 workflowDoc.getElementById('reportShowAllColumns').click();
 check(missingCount()==='Columns (23/23)'&&visibleMissing().at(-1)==='Actions','Missing Show all restores 23 columns with Actions last');
 workflowDoc.getElementById('reportResetColumns').click();
 check(missingCount()==='Columns (8/23)'&&JSON.stringify(visibleMissing())===JSON.stringify(missingDefault),'Missing Reset to default restores exact 8/23 layout');
 const codeChoice=[...workflowDoc.getElementById('reportColumnOptions').querySelectorAll('label')].find(label=>label.textContent.trim()==='Tool Code').querySelector('input');
 codeChoice.checked=true;workflowDoc.getElementById('reportColumnOptions').onchange({target:codeChoice});
 check(missingCount()==='Columns (9/23)','Missing custom checkbox selection immediately updates count');
 detailFixtures.missing.rows[0][1]='Resolved';detailFixtures.missing.rows[0][10]=2;detailFixtures.missing.rows[0][11]=0;detailFixtures.missing.rows[0][12]=0;
 const reloadedMissing=factory(workflowDoc,workflowWindow,async()=>({data:{...detailFixtures.missing,actions:detailFixtures.missing.rows.map((r,i)=>({case_id:i+1}))}}),'api',()=>{},()=>{},()=>workflowDoc.getElementById('reportsPage').classList.add('active'),String,()=> '2026-11-03',()=>{});
 await reloadedMissing.viewReport('missing','Missing Tools');
 check(missingCount()==='Columns (9/23)'&&visibleMissing().includes('Tool Code'),'Missing custom selection survives a fresh report instance');
 workflowDoc.getElementById('reportResetColumns').click();
 const balanceIndex=[...workflowDoc.getElementById('reportTableHead').querySelectorAll('th')].findIndex(th=>th.textContent==='Qty Still Missing');
 check(workflowDoc.getElementById('reportTableBody').rows[0].cells[balanceIndex].textContent==='0','Missing table displays supplied zero balance without recalculating recoveries');
 const actionButton=workflowDoc.getElementById('reportTableBody').querySelector('[onclick^="openMissingCase"]');let openedCase=null;
 actionButton.onclick=new Function('openMissingCase',actionButton.getAttribute('onclick')).bind(null,id=>openedCase=id);actionButton.click();
 check(openedCase===1&&visibleMissing().at(-1)==='Actions','Default Missing layout opens the existing case action');
 workflowDoc.getElementById('reportShowAllColumns').click();
 const createMissingInstance=()=>factory(workflowDoc,workflowWindow,async()=>({data:{...detailFixtures.missing,actions:detailFixtures.missing.rows.map((r,i)=>({case_id:i+1}))}}),'api',()=>{},()=>{},()=>workflowDoc.getElementById('reportsPage').classList.add('active'),String,()=> '2026-11-03',()=>{});
 await createMissingInstance().viewReport('missing','Missing Tools');
 check(missingCount()==='Columns (23/23)','Explicit Show all remains saved after migration and reload');
 storage.set('tooltrack.missingReport.hiddenColumns',JSON.stringify(['Reason']));storage.delete('tooltrack.missingReport.hiddenColumns.defaultVersion');
 await createMissingInstance().viewReport('missing','Missing Tools');
 check(missingCount()==='Columns (22/23)','Legacy custom selections remain intact during default migration');
 workflowDoc.getElementById('reportResetColumns').click();
 storage.set('tooltrack.transactionsReport.hiddenColumns','[]');
 storage.delete('tooltrack.transactionsReport.hiddenColumns.defaultVersion');
 const transactionRows=[
  ['TXN-BORROW','borrow','BASO-CODE','Baso','Borrower','returned','good','','2026-11-02','2026-11-03 16:00:00','2026-11-01 08:00:00',5,0,5],
  ['TXN-RETURN','return','BASO-CODE','Baso','Borrower','returned','good','',null,'2026-11-03 16:00:00','2026-11-01 08:00:00',0,5,0],
  ['TXN-NODATE','borrow','BASO-CODE','Baso','Borrower','active','good','',null,null,null,0,0,0]
 ];
 const transactionQueries=[];
 const createTransactionInstance=()=>factory(workflowDoc,workflowWindow,async url=>{transactionQueries.push(url);return {data:{...detailFixtures.transactions,rows:transactionRows,actions:transactionRows.map((r,i)=>({id:i+1,type:r[1],status:r[5],qty:5,qty_returned:0,qty_missing:r[13]})),total:3,page:1,per_page:25}};},'api',()=>{},()=>{},()=>workflowDoc.getElementById('reportsPage').classList.add('active'),String,()=> '2026-11-03',()=>{});
 const transactionApp=createTransactionInstance();await transactionApp.viewReport('transactions','Transactions');
 const transactionDefaults=['Transaction Date','TXN ID','Type','Tool Name','Borrower','Qty Borrowed','Qty Returned','Status'];
 const transactionHeaders=()=>[...workflowDoc.getElementById('reportTableHead').querySelectorAll('th:not([hidden])')].map(th=>th.textContent.replace(/ [↑↓]$/,''));
 check(JSON.stringify(transactionHeaders())===JSON.stringify(transactionDefaults),'Transactions uses the exact eight first-use columns');
 check(workflowDoc.getElementById('reportColumns').querySelector('summary').textContent==='Columns (8/16)'&&storage.get('tooltrack.transactionsReport.hiddenColumns.defaultVersion')==='8-column-v1','Transactions migrates legacy all-visible preference to 8/16 once');
 const transactionBody=workflowDoc.getElementById('reportTableBody');
 const columnIndex=name=>[...workflowDoc.getElementById('reportTableHead').querySelectorAll('th')].findIndex(th=>th.textContent.replace(/ [↑↓]$/,'')===name);
 const cell=(row,name)=>transactionBody.rows[row].cells[columnIndex(name)];
 check(cell(0,'Transaction Date').textContent===new Date('2026-11-01T08:00:00').toLocaleString(),'Returned borrow event keeps its original Borrowed At date');
 check(cell(1,'Transaction Date').textContent===new Date('2026-11-03T16:00:00').toLocaleString(),'Return event uses Returned At date');
 check(cell(2,'Transaction Date').textContent==='—','Missing event timestamp displays an em dash');
 check(cell(0,'Type').textContent==='Borrow'&&cell(1,'Type').textContent==='Return','Transaction event types remain distinct');
 check(cell(0,'Status').textContent==='Returned5 missing'&&cell(0,'Qty Missing').hidden,'Recorded Returned status has missing badge even with Qty Missing hidden');
 check(cell(0,'Qty Returned').textContent==='0'&&cell(2,'Qty Borrowed').textContent==='0','Transaction zero quantities remain visible');
 workflowDoc.getElementById('reportShowAllColumns').click();
 check(workflowDoc.getElementById('reportColumns').querySelector('summary').textContent==='Columns (16/16)','Transactions Show all reveals date, fourteen data fields, and actions');
 workflowDoc.getElementById('reportResetColumns').click();
 check(JSON.stringify(transactionHeaders())===JSON.stringify(transactionDefaults),'Transaction Reset restores the eight defaults');
 const transactionCode=[...workflowDoc.getElementById('reportColumnOptions').querySelectorAll('label')].find(l=>l.textContent.trim()==='Tool Code').querySelector('input');
 transactionCode.checked=true;workflowDoc.getElementById('reportColumnOptions').onchange({target:transactionCode});
 await createTransactionInstance().viewReport('transactions','Transactions');
 check(transactionHeaders().includes('Tool Code')&&transactionHeaders().length===9,'Custom Transaction column selection survives reload');
 workflowDoc.getElementById('reportResetColumns').click();
 workflowDoc.getElementById('reportTableHead').querySelector('[data-sort="Transaction Date"]').click();await settle();
 check(new URL(transactionQueries.at(-1),'https://tooltrack.test').searchParams.get('sort')==='Transaction Date','Transaction Date header requests timestamp sorting');
 workflowDoc.getElementById('reportShowAllColumns').click();
 await createTransactionInstance().viewReport('transactions','Transactions');
 check(workflowDoc.getElementById('reportColumns').querySelector('summary').textContent==='Columns (16/16)','Explicit Transaction Show all survives reload after migration');
 storage.set('tooltrack.transactionsReport.hiddenColumns',JSON.stringify(['Notes']));storage.delete('tooltrack.transactionsReport.hiddenColumns.defaultVersion');
 await createTransactionInstance().viewReport('transactions','Transactions');
 check(workflowDoc.getElementById('reportColumns').querySelector('summary').textContent==='Columns (15/16)','Legacy custom Transaction selection survives migration');
 const groupedAction={id:901,type:'borrow',status:'active',qty:15,qty_returned:0,qty_missing:0};
 const groupedData={...detailFixtures.transactions,grouped:true,rows:[transactionRows[0]],actions:[groupedAction],groups:[{key:'layout-test',tool_count:3,action:groupedAction,items:Array.from({length:3},(_,i)=>({values:transactionRows[0],action:{...groupedAction,id:901+i},qty_missing:0,qty_damaged:0}))}],total:1,page:1,per_page:25};
 const groupedApp=factory(workflowDoc,workflowWindow,async()=>({data:groupedData}),'api',()=>{},()=>{},()=>workflowDoc.getElementById('reportsPage').classList.add('active'),String,()=> '2026-11-03',()=>{});
 await groupedApp.viewReport('transactions','Transactions');
 const details=workflow.querySelector('[data-transaction-details]');
 const toggle=workflow.querySelector('button[data-transaction-group]');
 check(details.hidden,'Grouped detail starts collapsed');toggle.click();
 check(!details.hidden&&toggle.getAttribute('aria-expanded')==='true'&&details.querySelector('table').tBodies[0].rows.length===3,'Expanding shows three tool rows');
 const checkDetailLayout=label=>{
  const cell=details.cells[0],wrapper=cell.firstElementChild,table=wrapper.querySelector('table');
  const style=getComputedStyle(cell),rect=cell.getBoundingClientRect(),wrap=wrapper.getBoundingClientRect();
  const scale=rect.width/cell.offsetWidth;
  check(cell.colSpan===workflow.querySelectorAll('#reportTableHead th:not([hidden])').length,'Detail span includes every visible column and control: '+label);
  check(Math.abs(wrap.width-(cell.clientWidth-parseFloat(style.paddingLeft)-parseFloat(style.paddingRight))*scale)<2,'Detail wrapper fills spanning cell: '+label);
  check(Math.abs(wrap.left-(rect.left+parseFloat(style.paddingLeft)*scale))<2,'Detail wrapper aligns with cell content: '+label);
  check(table.offsetWidth>=wrapper.clientWidth,'Detail table fills wrapper: '+label);
  wrapper.scrollLeft=wrapper.scrollWidth;
  check(wrapper.scrollWidth<=wrapper.clientWidth+1||wrapper.scrollLeft>0,'Wide details can scroll to final actions: '+label);
  check(details.querySelector('table').tBodies[0].rows[0].cells[8].getBoundingClientRect().right<=wrap.right+1,'Final detail actions remain reachable: '+label);
  check(document.documentElement.scrollWidth<=innerWidth,'Expanded details do not overflow the page: '+label);
 };
 checkDetailLayout('custom columns');
 workflowDoc.getElementById('reportShowAllColumns').click();checkDetailLayout('all columns');
 workflowDoc.getElementById('reportResetColumns').click();checkDetailLayout('default columns');
 for(const input of workflowDoc.getElementById('reportColumnOptions').querySelectorAll('input')){input.checked=false;workflowDoc.getElementById('reportColumnOptions').onchange({target:input});}
 checkDetailLayout('control column only');
 toggle.click();check(details.hidden&&toggle.getAttribute('aria-expanded')==='false','Grouped transaction collapses after column changes');
 workflow.remove();
 return checks;
}
