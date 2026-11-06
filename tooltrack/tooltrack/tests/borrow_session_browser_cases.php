<?php
$browserProfileName='.borrow-session-browser-chrome';
$browserScenario=str_replace('TOOLS_FIXTURE',json_encode([$a['code'],$b['code']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), <<<'JS'
window.addEventListener('load',async()=>{
 const output=document.getElementById('tesdaBrowserResult'),passed=[],errors=[];
 window.addEventListener('error',e=>errors.push(e.message));window.confirm=()=>true;
 const assert=(ok,label)=>{if(!ok)throw Error(label);passed.push(label);},el=id=>document.getElementById(id);
 const until=async predicate=>{for(let i=0;i<160;i++){if(predicate())return;await new Promise(r=>setTimeout(r,50));}throw Error('Timed out waiting for checkout UI');};
 try{
  navigateTo('borrow');await loadToolsForBorrow();
  el('borrowerSearchInput').value='Test Borrower';filterBorrowerDropdown();
  await until(()=>el('borrowerDropdownList').querySelector('[onclick]'));
  el('borrowerDropdownList').querySelector('[onclick]').click();
  for(const code of TOOLS_FIXTURE){
   el('borrowToolSearchInput').value=code;filterBorrowToolDropdown();
   el('borrowToolDropdownList').querySelector('[data-code]').click();el('borrowQty').value='2';el('borrowAddBtn').click();
  }
  assert(btCart.length===2&&btCart.every(t=>t.qty===2),'Two tool types with two units each selected through existing tool controls');
  el('borrowDueDate').value=new Date(Date.now()+2*86400000).toLocaleDateString('en-CA');el('borrowDueTime').value='17:30';
  let saved=null;const original=apiFetch;
  apiFetch=async function(url,options={}){const result=await original(url,options);if(options.method==='POST'&&String(url).includes('transactions.php')&&JSON.parse(options.body).type==='borrow_batch')saved=result.data;return result;};
  el('borrowSubmitBtn').click();assert(!el('brReview').hidden,'Existing Confirm Borrow review opens');
  el('brReviewConfirm').click();await until(()=>saved&&!btBusy);
  assert(saved.items.length===2&&saved.items.every(t=>t.txn_id===saved.txn_id),'One actual Confirm Borrow creates a shared public TXN ID');
  circulationTab('history');el('borrowHistorySearch').value=saved.txn_id;await loadBorrowHistory();
  let body=el('borrowHistoryBody');assert(body.children.length===2&&body.children[0].textContent.includes(saved.txn_id),'History renders one header record for the checkout');
  body.querySelector('[data-borrow-group]').click();assert(!body.children[1].hidden&&body.children[1].querySelector('tbody').children.length===2,'Expanding checkout reveals both loan detail rows');
  await loadBorrowHistory(borrowHistoryPage);assert(!body.children[1].hidden,'History refresh preserves expanded checkout');
  await apiFetch(API+'/transactions.php',{method:'POST',body:JSON.stringify({type:'return',borrow_txn_id:saved.items[0].id,returnee_name:'Browser session fixture',request_token:'browser_session_return_'+saved.items[0].id+'_1234',items:{good:2}})});
  await loadBorrowHistory();assert(body.children[0].textContent.includes('Partially returned'),'Returning one line leaves grouped checkout partially returned');
  const current=(await apiFetch(API+'/transactions.php?type=borrow&grouped=1&search='+encodeURIComponent(saved.txn_id))).data[0];
  assert(current.status==='active'&&current.items.filter(t=>t.status==='active').length===1&&current.items.filter(t=>t.status==='returned').length===1,'Only the returned detail closes');
  await brOpenHistory();el('brHistSearch').value=saved.txn_id;brHistRender();
  assert(el('brHistList').querySelectorAll(':scope > details').length===1&&el('brHistList').textContent.includes(saved.items[0].tool_name)&&el('brHistList').textContent.includes(saved.items[1].tool_name),'Mobile history shows one expandable checkout containing both tools');
  brCloseSheets();tableReportState('transactions').search=saved.txn_id;await viewReport('transactions','Transactions');
  assert(el('reportModalCount').textContent.startsWith('1 transaction(s)')&&el('reportModalCount').textContent.includes('2 tool/event line(s)'),'Reports distinguish one checkout from two tool lines');
  let reportBody=el('reportTableBody');
  assert(reportBody.querySelectorAll(':scope > .transaction-report-summary').length===1&&reportBody.querySelectorAll(':scope > .transaction-report-details').length===1,'Transactions report renders one main row for both tools');
  reportBody.querySelector('button[data-transaction-group]').click();
  assert(!reportBody.querySelector('.transaction-report-details').hidden&&reportBody.querySelectorAll('[data-transaction-item]').length===2,'Report expand control shows both tool detail lines');
  assert(reportBody.querySelector('.transaction-report-summary').textContent.includes('Partially returned'),'Report header stays active after a partial return');
  el('reportResetColumns').click();
  const returnedBox=[...el('reportColumnOptions').querySelectorAll('label')].find(label=>label.textContent.trim()==='Qty Returned').querySelector('input');returnedBox.click();
  await viewReport('transactions','Transactions',true);
  reportBody=el('reportTableBody');
  assert(!reportBody.querySelector('.transaction-report-details').hidden,'Expanded report transaction survives a refresh');
  assert([...el('reportColumnOptions').querySelectorAll('label')].find(label=>label.textContent.trim()==='Qty Returned').querySelector('input').checked===false,'Selected report columns survive grouped refresh');
  assert(reportBody.querySelector('[data-transaction-detail-cell]').colSpan===el('reportTableHead').querySelectorAll('th:not([hidden])').length,'Expanded detail spans track selected columns');
  const sortRequest=sortInventoryReport('TXN ID');await sortRequest;
  assert(tableReportState('transactions').sort==='TXN ID'&&reportBody.querySelectorAll('[data-transaction-item]').length===2,'Grouped report sorting preserves complete transaction details');
  el('inventoryReportSearch').value=saved.items[0].tool_name;inventoryReportFilterChanged();await refreshInventoryReport();
  const match=[...reportBody.querySelectorAll('.transaction-report-summary')].find(row=>row.textContent.includes(saved.txn_id));
  assert(!!match&&match.nextElementSibling.querySelectorAll('[data-transaction-item]').length===2,'Searching one tool keeps its complete checkout together');
  const detail=match.nextElementSibling;match.querySelector('button[data-transaction-group]').click();assert(detail.hidden,'One collapse click closes one transaction');match.querySelector('button[data-transaction-group]').click();assert(!detail.hidden,'One expand click reopens one transaction');
  assert(el('inventoryReportRange').textContent.includes('transactions'),'Report pagination explicitly counts transactions');
  assert(el('reportModal').getBoundingClientRect().right<=innerWidth+1,'Grouped report remains within the page viewport');
  const detailScroll=detail.querySelector('.transaction-report-detail-scroll');assert(detailScroll.getBoundingClientRect().width<=innerWidth,'Tool details have a responsive contained scroll area');
  const exported=[];window.open=url=>exported.push(url);el('exportReportBtn').click();assert(exported.length===1&&exported[0].includes('download=1')&&!exported[0].includes('search=')&&!exported[0].includes('page='),'CSV retains existing full-report export scope');
  const csv=await (await fetch(API+'/reports.php?type=transactions&download=1')).text();assert(csv.split(/\r?\n/).filter(line=>line.includes(saved.txn_id)).length===2,'CSV retains both tool lines under the shared number');
  assert(csv.replace(/^\uFEFF/,'').split(/\r?\n/)[0].split(',').length===14,'CSV preserves its fourteen data columns');
  await viewReport('inventory','Inventory Report');assert(!el('reportTableHead').querySelector('.transaction-report-toggle')&&el('reportModal').dataset.transactionsGrouped==='false','Inventory report keeps its existing shared layout');
  assert(errors.length===0,'No uncaught browser errors');
  output.textContent='PASS: '+passed.length+' real-browser borrow-session assertions\n'+passed.join('\n');
 }catch(e){output.textContent='FAIL: '+e.stack+'\n'+errors.join('\n');}
});
JS);
require __DIR__.'/tesda_browser_cases.php';
if(getenv('TOOLTRACK_GROUPED_REPORT_MOBILE_TEST')){
 $browserWindowSize='500,900';$browserProfileName='.grouped-report-mobile-chrome';require __DIR__.'/tesda_browser_cases.php';
}
