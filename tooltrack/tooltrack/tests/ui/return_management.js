/* Run with Node: node tests/ui/return_management.js. DOM/scanner stubs; no browser or live DB writes. */
async function runReturnUiChecks(source){
 const nodes=new Map(),callbacks=[],requests=[],checks=[];
 const assert=(ok,label)=>{if(!ok)throw Error(label);checks.push(label);};
 const node=id=>{
  if(!nodes.has(id)){
   const classes=new Set();let html='',value=id.startsWith('ra_')?'0':'';
   const n={id,value:id.startsWith('ra_')?'0':'',textContent:'',hidden:false,disabled:false,checked:false,indeterminate:false,style:{},attributes:{},
    classList:{add:x=>classes.add(x),remove:x=>classes.delete(x),contains:x=>classes.has(x),toggle(x,on){if(on===undefined)on=!classes.has(x);on?classes.add(x):classes.delete(x);return on;}},
    setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},append(child){child.parent=id;},after(child){child.parent='right';},focus(){this.focused=true;},scrollIntoView(){},querySelector(){return null;}};
   Object.defineProperty(n,'value',{get:()=>value,set:v=>value=String(v)});
   Object.defineProperty(n,'innerHTML',{get:()=>html,set:v=>{html=v;if(id==='raBody')for(const [key,input]of nodes)if(/^ra_\d+_/.test(key))input.value='0';}});nodes.set(id,n);
  }
  return nodes.get(id);
 };
 const borrower={id:1,full_name:'Fixture Borrower',id_number:'20260001',type:'Student',is_active:1};
 let loan={id:10,qty:5,qty_returned:0,qty_missing:0,outstanding:5,overdue_days:2,tool_name:'Fixture Whisk',tool_code:'FIXTURE',txn_id:'FIXTURE-LOAN',due_date:'2026-10-31',due_time:'17:00:00',pending_report_id:null};
 let finishPost=null,scannerStarts=0,scannerConstructors=0;
 const apiFetch=async(url,options={})=>{
  if(options.method==='POST'){
   requests.push(JSON.parse(options.body));await new Promise(resolve=>finishPost=resolve);
   loan={...loan,qty_returned:3,outstanding:2,pending_report_id:55};
   return {data:{totals:{good:2,minor:0,damaged:1,missing:1},loans_in_batch:1,loans_closed:0,items:[]}};
  }
  if(url.includes('borrowers.php'))return {data:[borrower]};
  if(url.includes('student_loans'))return {data:{borrower,loans:[loan]}};
  if(url.includes('return_overview'))return {data:{units_on_loan:2,overdue_loans:1,returned_today:3}};
  return {data:[],total:0};
 };
 const document={getElementById:node,querySelectorAll:()=>[]};
 const window={CURRENT_ROLE:'Staff',matchMedia:()=>({matches:false,addEventListener(){}}),addEventListener:(name,fn)=>callbacks.push(fn)};
 const params={document,window,apiFetch,API:'api',URLSearchParams:class{constructor(v){this.values=v;}toString(){return Object.entries(this.values).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');}},
  escapeHtml:v=>String(v??''),bhFormatDate:v=>v||'—',localToday:()=> '2026-11-02',parseDbDate:()=>new Date(),
  cleanScannedId:v=>String(v).trim(),ID_MIN_CAMERA_LEN:8,ID_CONFIRM_MS:1000,ID_REPEAT_MS:2000,borrowerResultsById:new Map(),
  setScanStatus(){},cameraErrorText:e=>String(e),openCamera:async(q,id,decode,start)=>{scannerStarts++;start();},
  Html5Qrcode:class{constructor(){scannerConstructors++;}stop(){return Promise.resolve();}},Html5QrcodeSupportedFormats:{QR_CODE:0,CODE_128:1,CODE_39:2,EAN_13:3},
  showToast(){},confirm:()=>true,setLoading:(id,busy)=>node(id).disabled=busy,loadBorrowHistory(){},loadNotifications(){},loadChargeCases(){},refreshToolStockViews(){},ensureHistoryPager(){},HISTORY_PER_PAGE:25,renderPagination(){}};
 const ui=new Function(...Object.keys(params),source+'\nreturn {handleReturnIdScan,startReturnIdScanner,raRecalc,raSubmit,raReset,raFillAllGood,rtSetView,getBorrower:()=>raStudent,getLoans:()=>raLoans};')(...Object.values(params));
 callbacks.forEach(fn=>fn());
 assert(scannerConstructors===1,'one existing Return ID scanner initialized');
 assert(node('rtBorrowerFinder').parent==='rtMobileFinder','existing finder placed with borrower panel on desktop');
 await ui.startReturnIdScanner();assert(scannerStarts===1,'scan action calls existing camera helper');
 await ui.handleReturnIdScan('20260001','camera');
 assert(ui.getBorrower()?.id===1&&ui.getLoans().length===1,'scanned ID loads borrower and outstanding loans');
 node('ra_10_good').value='2';node('ra_10_damaged').value='1';node('ra_10_missing').value='1';
 assert(ui.raRecalc()===''&&!node('raSubmitBtn').disabled,'mixed partial quantities enable existing confirmation');
 assert(node('raTotalLine').textContent.includes('4 of 5')&&node('raImpact').textContent.includes('NOT added to stock'),'summary preserves outstanding quantities and missing stock warning');
 node('raMissingReason').value='Not handed back';node('raMissingDate').value='2026-11-02';
 const saving=ui.raSubmit();await ui.raSubmit();
 assert(requests.length===1&&requests[0].type==='return_batch'&&requests[0].items[0].damaged===1&&requests[0].items[0].missing===1,'duplicate confirm guarded; original batch payload retained');
 const token=requests[0].request_token;assert(typeof token==='string'&&token.length>8,'existing submission token accompanies return');
 finishPost();await saving;
 assert(node('rtUnitsOnLoan').textContent===2&&node('rtReturnedToday').textContent===3,'overview refreshes from actual API response shape');
 ui.raFillAllGood();assert(!node('raSubmitBtn').disabled&&node('raTotalLine').textContent.includes('2 of 2'),'full return fills remaining quantities');
 node('ra_10_good').value='3';assert(ui.raRecalc()!==''&&node('raSubmitBtn').disabled,'excess quantities disable confirmation');
 ui.raReset();assert(ui.getBorrower()===null&&node('raSubmitBtn').disabled,'clear borrower resets standalone summary and confirmation');
 ui.rtSetView('history');assert(node('rtWorkspace').hidden&&!node('returnHistorySection').hidden&&!node('returnHistoryTableContainer').classList.contains('collapsed'),'history tab reuses and opens existing history table');
 ui.rtSetView('return');assert(!node('rtWorkspace').hidden&&node('returnHistorySection').hidden,'return tab restores workspace without replacing scanner');
 return checks;
}
if(typeof module!=='undefined'&&require.main===module){
 const fs=require('fs'),path=require('path');
 runReturnUiChecks(fs.readFileSync(path.join(__dirname,'../../assets/js/return.js'),'utf8')).then(checks=>{for(const c of checks)console.log('PASS: '+c);console.log(checks.length+' Return UI checks passed.');}).catch(e=>{console.error(e);process.exitCode=1;});
}
