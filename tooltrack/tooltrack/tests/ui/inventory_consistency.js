/* Can run in a browser or Node; checks the real Borrow functions with DOM stubs. */
function runInventoryUiChecks(source,caseSource,toolSource,appSource){
 const checks=[],assert=(v,label)=>{if(!v)throw Error(label);checks.push(label);};
 const nodes=new Map(),el=id=>{if(!nodes.has(id))nodes.set(id,{value:'',textContent:'',disabled:false});return nodes.get(id);};
 let cart=[],pending=null,borrower={full_name:'Test Borrower'},review=0,submitted=0;
 const extract=name=>{const start=source.indexOf('function '+name+'(');return source.slice(start,source.indexOf('\n}',start)+2);};
 const params={btEl:el,brCurrentBorrower:()=>borrower,showToast(){},document:{querySelector:()=>null},brFocusSection(){},localToday:()=> '2026-11-02',btCartEditPending:false};
 const make=name=>new Function(...Object.keys(params),'btCart','btPending','btBusy',extract(name)+';return '+name+'();');
 const sync=()=>make('brSyncBar')(...Object.values(params),cart,pending,false);
 sync();assert(el('borrowSubmitBtn').disabled,'empty cart disables confirmation');
 cart=[{name:'Baso',code:'BASO',qty:6}];sync();assert(!el('borrowSubmitBtn').disabled&&el('brBarCount').textContent.includes('6'),'committed selection shows six units and enables review');
 pending='BASO';el('borrowToolId').value='BASO';sync();assert(el('borrowSubmitBtn').disabled&&el('brBarCount').textContent.includes('Add Tool'),'pending entry disables confirmation and explains next action');
 el('borrowerSelect').value='1';el('borrowDueDate').value='2026-11-03';el('borrowDueTime').value='17:30';
 assert(make('btCollect')(...Object.values(params),cart,pending,false)===null,'pending entry cannot submit the previous cart');
 pending=null;el('borrowToolId').value='';assert(make('btCollect')(...Object.values(params),cart,pending,false).borrowerId==='1','ready cart keeps original borrower');
 params.btCartEditPending=true;sync();assert(el('borrowSubmitBtn').disabled&&make('btCollect')(...Object.values(params),cart,pending,false)===null,'pending cart quantity edit cannot submit the previous quantity');params.btCartEditPending=false;
 borrower=null;sync();assert(el('borrowSubmitBtn').disabled,'borrower required for confirmation');
 const confirm=new Function('btBusy','btCollect','brOpenReview',extract('borrowConfirm')+';borrowConfirm();');
 confirm(false,()=>({}),()=>review++);assert(review===1,'desktop and mobile both review the selected list');
 confirm(true,()=>({}),()=>submitted++);assert(submitted===0,'busy confirmation cannot submit twice');
 if(caseSource){
  const start=caseSource.indexOf('function caseInventoryPanel('),panelSource=caseSource.slice(start,caseSource.indexOf('\n}',start)+2);
  const panel=new Function('escapeHtml',panelSource+';return caseInventoryPanel;')(x=>String(x));
  const html=panel({inventory_status:'Under Repair',unresolved_quantity:6,incident_quantity:9,repair_quantity:3},true);
  assert(html.includes('Original: 9')&&html.includes('Unresolved: 6')&&html.includes('max="6"'),'case action form preserves original nine and limits action to six unresolved');
 }
 if(toolSource){
  const start=toolSource.indexOf('function syncDisposalEligibility('),fn=toolSource.slice(start,toolSource.indexOf('\n}',start)+2);
  const state={tool:{name:'Baso',code:'BASO',damaged_qty:6},cases:[{id:7,eligible_quantity:5,workflow_mode:'tracking'}]};
  const sync=new Function('toolDisposalState','document',fn+';syncDisposalEligibility();');
  el('disposeToolCase').value='7';sync(state,{getElementById:el});assert(state.eligible===5,'Tools disposal uses selected case eligible quantity');
  el('disposeToolCase').value='';sync(state,{getElementById:el});assert(state.eligible===1,'historical disposal excludes unresolved tracking case units');
 }
 if(appSource){
  const start=appSource.indexOf('function loanOverdueLabel('),fn=appSource.slice(start,appSource.indexOf('\n}',start)+2);
  const label=new Function(fn+';return loanOverdueLabel;')();assert(label({due_label:'Nov 2, 2026 4:07 PM PST'}).includes('4:07 PM'),'overdue label uses configured server timestamp instead of zero elapsed days');
 }
 return checks;
}
if(typeof module!=='undefined'&&require.main===module){
 const fs=require('fs'),path=require('path');const checks=runInventoryUiChecks(fs.readFileSync(path.join(__dirname,'../../assets/js/borrow.js'),'utf8'));checks.forEach(c=>console.log('PASS: '+c));
}
