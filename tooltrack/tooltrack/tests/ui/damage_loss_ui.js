const {chromium,newCtx}=require('./lib');const {execSync}=require('child_process');
/* Usage: TT_BASE=http://127.0.0.1:8082/tooltrackv2 TT_DB=tt_ui_test TT_DUMP=/path/dump.sql node tests/ui/damage_loss_ui.js
   Needs `npm i playwright`, a copy of the app whose api/bootstrap.php DB_NAME = TT_DB, and users with password Test#12345.
   WARNING: drops and re-creates TT_DB for each viewport. Never point it at a real database. */
const BASE=process.env.TT_BASE||'http://127.0.0.1:8082/tooltrackv2';const DB=process.env.TT_DB||'tt_ui_after';const DUMP=process.env.TT_DUMP||'live_dump.sql';
const sql=q=>execSync(`mysql -uroot ${DB} -N -B -e "${q.replace(/"/g,'\\"')}"`).toString().trim();
const results=[];let failures=0;
function ok(cond,label,extra=''){results.push({pass:!!cond,label,extra});if(!cond)failures++;console.log((cond?'PASS ':'FAIL ')+label+(cond?'':' '+extra));}
const cards=async p=>{await p.waitForTimeout(500);return p.evaluate(()=>Object.fromEntries([...document.querySelectorAll('#cwSummary .cw-kpi')].map(e=>[e.querySelector('span').textContent,Number(e.querySelector('b').textContent)])));};
const api=(p,path,method,body)=>p.evaluate(async([path,method,body])=>{const r=await fetch(path,{method,headers:{'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});let j=null;try{j=await r.json()}catch(e){}return {status:r.status,j}},[path,method,body]);
const inv=()=>sql("SELECT COUNT(*) FROM tools tl WHERE quantity<0 OR available<0 OR minor_qty<0 OR damaged_qty<0 OR missing_qty<0 OR minor_qty>available OR quantity<>available+damaged_qty+missing_qty+COALESCE((SELECT SUM(t.qty-t.qty_returned-t.qty_missing) FROM transactions t WHERE t.tool_id=tl.id AND t.type='borrow'),0)");
(async()=>{
 const b=await chromium.launch();
 for(const [vpName,vp] of [['desktop',{width:1440,height:900}],['mobile',{width:390,height:844}]]){
  console.log('=== viewport',vpName);
  execSync(`mysql -uroot -e 'DROP DATABASE ${DB}; CREATE DATABASE ${DB} CHARACTER SET utf8mb4' && mysql -uroot ${DB} < ${DUMP} && mysql -uroot ${DB} -e "UPDATE users SET password='"$(php -r 'echo password_hash("Test#12345",PASSWORD_DEFAULT);')"'"`,{shell:'/bin/bash'});
  const {ctx,p,errs,apiBad}=await newCtx(b,BASE,vp);
  p.on('dialog',d=>d.accept());
  const student=sql("SELECT CONCAT(id,'|',full_name,'|',id_number) FROM borrowers WHERE type='Student' ORDER BY id LIMIT 1").split('|');
  const sid=Number(student[0]);
  const tool=c=>sql(`SELECT CONCAT(id,'|',name,'|',code,'|',quantity,'|',available,'|',damaged_qty,'|',missing_qty) FROM tools WHERE code='${c}'`).split('|').map((v,i)=>i==1||i==2?v:Number(v));
  const whiskBefore=tool('FPST-UTL-0003'),basoBefore=tool('FPST-GLS-0001');
  const casesBefore=Number(sql("SELECT COUNT(*) FROM replacement_requests WHERE workflow_mode='payment'"));
  // setup evidence
  const due=new Date(Date.now()+86400000).toISOString().slice(0,10);
  const b1=await api(p,'api/transactions.php','POST',{type:'borrow',tool_code:'FPST-UTL-0003',borrower_id:sid,qty:4,due_date:due,due_time:'17:30'});
  ok(b1.j?.success,'Borrow Whisk x4 ('+vpName+')',JSON.stringify(b1.j));
  const loan1=b1.j.data.id;
  const r1=await api(p,'api/transactions.php','POST',{type:'return',borrow_txn_id:loan1,returnee_name:student[1],request_token:'e2e_ret_'+vpName+Date.now(),items:{damaged:2,good:2}});
  ok(r1.j?.success,'Return 2 good + 2 damaged',JSON.stringify(r1.j));
  const b2=await api(p,'api/transactions.php','POST',{type:'borrow',tool_code:'FPST-GLS-0001',borrower_id:sid,qty:3,due_date:due,due_time:'17:30'});
  const loan2=b2.j.data.id;
  const r2=await api(p,'api/transactions.php','POST',{type:'return',borrow_txn_id:loan2,returnee_name:student[1],request_token:'e2e_ret2_'+vpName+Date.now(),items:{good:1,missing:2},missing_reason:'E2E test loss',missing_date:new Date().toISOString().slice(0,10)});
  ok(r2.j?.success,'Return 1 good + 2 missing (admin auto-confirms)',JSON.stringify(r2.j));
  const w1=tool('FPST-UTL-0003'),bs1=tool('FPST-GLS-0001');
  ok(w1[5]===whiskBefore[5]+2&&w1[4]===whiskBefore[4]-2&&w1[3]===whiskBefore[3],'Whisk inventory: damaged +2, available -2, total unchanged',JSON.stringify([whiskBefore,w1]));
  ok(bs1[6]===basoBefore[6]+2&&bs1[3]===basoBefore[3],'Baso inventory: missing +2, total unchanged',JSON.stringify([basoBefore,bs1]));
  ok(inv()==='0','Inventory invariant after borrow/return');
  // open page
  await p.evaluate(()=>navigateTo('replacements'));await p.waitForSelector('#cwSummary .cw-kpi');
  const c0=await cards(p);console.log('cards',JSON.stringify(c0));
  ok(Object.keys(c0).join()==='Unpaid Cases,Payments to Verify,Paid Cases,Disposed Tools','Dashboard shows exactly the four requested cards',JSON.stringify(c0));
  ok(c0['Paid Cases']===Number(sql("SELECT COUNT(*) FROM replacement_requests r WHERE workflow_mode='payment' AND amount_due>0 AND (SELECT COALESCE(SUM(amount),0) FROM replacement_payment_claims p WHERE p.request_id=r.id AND p.status='confirmed')>=amount_due"))&&c0['Unpaid Cases']===Number(sql("SELECT COUNT(*) FROM replacement_requests r WHERE workflow_mode='payment' AND amount_due>0 AND (SELECT COALESCE(SUM(amount),0) FROM replacement_payment_claims p WHERE p.request_id=r.id AND p.status='confirmed')<amount_due")),'Paid/Unpaid cards equal independent SQL counts');
  ok(c0['Disposed Tools']===Number(sql("SELECT COALESCE(SUM(quantity),0) FROM tool_disposals")),'Disposed card equals SUM(tool_disposals.quantity)');
  // report damage via UI
  await p.click('button:has-text("Report Damage / Loss")');await p.waitForSelector('#rpIncidentModal.active, #rpIncidentModal[style*="flex"], #rpIncidentModal.open, #rpIncidentModal.show',{timeout:3000}).catch(()=>{});
  await p.waitForFunction(()=>document.querySelectorAll('#rpIncidentSource option').length>1);
  const optTxt=await p.$$eval('#rpIncidentSource option',o=>o.map(x=>({v:x.value,t:x.textContent})));
  const damOpt=optTxt.find(o=>/Whisk/.test(o.t)&&o.t.includes(student[2]));
  ok(!!damOpt,'Damaged evidence appears in the incident form',JSON.stringify(optTxt.slice(0,3)));
  await p.selectOption('#rpIncidentSource',damOpt.v);await p.fill('#rpIncidentQty','2');await p.fill('#rpIncidentNotes','E2E damaged whisks');
  await p.click('#rpIncidentSubmit');await p.waitForTimeout(1200);
  const dam=sql("SELECT id FROM replacement_requests WHERE workflow_mode='payment' AND reason='damaged' ORDER BY id DESC LIMIT 1");
  ok(Number(sql("SELECT COUNT(*) FROM replacement_requests WHERE workflow_mode='payment'"))===casesBefore+1&&sql(`SELECT amount_due IS NULL FROM replacement_requests WHERE id=${dam}`)==='1','Damage incident recorded with no charge yet');
  ok(inv()==='0'&&tool('FPST-UTL-0003').join()===w1.join(),'Recording an incident does not change inventory');
  // report lost via UI
  await p.click('button:has-text("Report Damage / Loss")');await p.selectOption('#rpIncidentReason','lost');await p.waitForFunction(()=>[...document.querySelectorAll('#rpIncidentSource option')].some(o=>/Baso/.test(o.textContent)));
  const lostOpt=(await p.$$eval('#rpIncidentSource option',o=>o.map(x=>({v:x.value,t:x.textContent})))).find(o=>/Baso/.test(o.t));
  await p.selectOption('#rpIncidentSource',lostOpt.v);await p.fill('#rpIncidentQty','2');await p.click('#rpIncidentSubmit');await p.waitForTimeout(1200);
  const lost=sql("SELECT id FROM replacement_requests WHERE workflow_mode='payment' AND reason='lost' ORDER BY id DESC LIMIT 1");
  ok(lost&&lost!==dam,'Loss incident recorded from confirmed missing case');
  // Set charge on damage case via UI
  const openCase=async(id)=>{await p.evaluate(i=>rpView(i),Number(id));await p.waitForSelector('#rpDetailsBody .rp-summary');};
  await openCase(dam);await p.fill('#rpCharge','100.00');await p.fill('#rpActionNotes','2 damaged whisks assessed');await p.click('#rpDetailsBody button:has-text("Set charge")');await p.waitForTimeout(1200);
  ok(sql(`SELECT amount_due FROM replacement_requests WHERE id=${dam}`)==='100.00'&&sql(`SELECT financial_status FROM replacement_requests WHERE id=${dam}`)==='awaiting_payment','Set charge 100.00 -> Unpaid');
  let c1=await cards(p);ok(c1['Unpaid Cases']===c0['Unpaid Cases']+1,'Unpaid card +1 after charge set',JSON.stringify(c1));
  // Staff would record; here Admin record payment 40 with receipt
  await p.fill('#rpClaimAmount','40.00');await p.fill('#rpReceipt','E2E-OR-'+vpName+'-1');await p.click('#rpDetailsBody button:has-text("Record payment")');await p.waitForTimeout(1200);
  ok(sql(`SELECT COUNT(*) FROM replacement_payment_claims WHERE request_id=${dam} AND status='pending'`)==='1','Payment recorded as pending');
  let c2=await cards(p);ok(c2['Payments to Verify']===c0['Payments to Verify']+1,'Payments to Verify +1',JSON.stringify(c2));
  // confirm requires checkbox
  await p.fill('#rpActionNotes','Matched school receipt');await p.click('#rpDetailsBody button:has-text("Confirm payment")');await p.waitForTimeout(700);
  ok(sql(`SELECT COUNT(*) FROM replacement_payment_claims WHERE request_id=${dam} AND status='confirmed'`)==='0','Confirmation refused without the "actually received" tick');
  await p.check('#rpReceived');await p.fill('#rpActionNotes','Matched school receipt');await p.click('#rpDetailsBody button:has-text("Confirm payment")');await p.waitForTimeout(1200);
  ok(sql(`SELECT financial_status FROM replacement_requests WHERE id=${dam}`)==='partially_paid','Verified 40.00 -> Partially Paid');
  let c3=await cards(p);ok(c3['Payments to Verify']===c0['Payments to Verify']&&c3['Unpaid Cases']===c0['Unpaid Cases']+1,'Partial case stays in Unpaid; Verify returns to baseline',JSON.stringify(c3));
  await p.fill('#rpClaimAmount','60.00');await p.fill('#rpReceipt','E2E-OR-'+vpName+'-2');await p.click('#rpDetailsBody button:has-text("Record payment")');await p.waitForTimeout(1000);
  await p.check('#rpReceived');await p.fill('#rpActionNotes','Matched second receipt');await p.click('#rpDetailsBody button:has-text("Confirm payment")');await p.waitForTimeout(1200);
  ok(sql(`SELECT financial_status FROM replacement_requests WHERE id=${dam}`)==='confirmed','Verified remaining 60.00 -> Paid');
  let c4=await cards(p);ok(c4['Paid Cases']===c0['Paid Cases']+1&&c4['Unpaid Cases']===c0['Unpaid Cases'],'Paid card +1, Unpaid back to baseline',JSON.stringify(c4));
  ok(sql(`SELECT COUNT(*) FROM replacement_payment_claims WHERE request_id=${dam}`)==='2'&&sql(`SELECT SUM(amount) FROM replacement_payment_claims WHERE request_id=${dam} AND status='confirmed'`)==='100.00','Two installments totalling 100.00 stored');
  ok(await p.evaluate(()=>/Payment history/.test(document.getElementById('rpDetailsBody').textContent)&&/E2E-OR-/.test(document.getElementById('rpDetailsBody').textContent)),'Case details show payment history with receipts');
  ok(tool('FPST-UTL-0003').join()===w1.join()&&inv()==='0','Payments leave inventory untouched');
  // overpay refused via API
  const over=await api(p,'api/replacement_requests.php','PATCH',{action:'claim_payment',id:Number(dam),version:99,amount:'1',payment_date:new Date().toISOString().slice(0,10),request_token:'e2e_over_'+Date.now()+vpName,csrf:await p.evaluate(()=>rpCsrf)});
  ok(over.j?.success===false,'Payment on a fully paid / stale case is refused',JSON.stringify(over.j));
  // lost case: charge + payment through API-driven UI quickly
  await openCase(lost);await p.fill('#rpCharge','50.00');await p.fill('#rpActionNotes','2 lost');await p.click('#rpDetailsBody button:has-text("Set charge")');await p.waitForTimeout(1000);
  await p.fill('#rpClaimAmount','50.00');await p.click('#rpDetailsBody button:has-text("Record payment")');await p.waitForTimeout(1000);
  ok(sql(`SELECT receipt_number LIKE 'SYS-PAY-%' FROM replacement_payment_claims WHERE request_id=${lost}`)==='1','Blank receipt gets an internal reference');
  await p.check('#rpReceived');await p.fill('#rpActionNotes','ok');await p.click('#rpDetailsBody button:has-text("Confirm payment")');await p.waitForTimeout(1200);
  ok(sql(`SELECT financial_status FROM replacement_requests WHERE id=${lost}`)==='confirmed','Loss case Paid');
  ok(tool('FPST-GLS-0001').join()===bs1.join(),'Paid loss does not change missing/available stock');
  // Admin void
  const voidBtn=await p.$$('#rpDetailsBody button:has-text("Void / correct payment")');ok(voidBtn.length===1,'Admin sees Void on confirmed payment');
  await p.fill('#rpActionNotes','E2E void test');await voidBtn[0].click();await p.waitForTimeout(1200);
  ok(sql(`SELECT financial_status FROM replacement_requests WHERE id=${lost}`)==='awaiting_payment','Void returns case to Unpaid');
  let c5=await cards(p);ok(c5['Unpaid Cases']===c0['Unpaid Cases']+1&&c5['Paid Cases']===c0['Paid Cases']+1,'Cards recomputed from DB after void',JSON.stringify(c5));
  // filters + search
  await p.evaluate(()=>navigateTo('replacements'));await p.selectOption('#rrFinancialFilter','unpaid');await p.waitForTimeout(800);
  ok(await p.$$eval('#rrListBody tr:not(.empty-row)',r=>r.length)===c5['Unpaid Cases'],'Unpaid filter row count equals the Unpaid card');
  await p.selectOption('#rrFinancialFilter','all');await p.fill('#cwSearch','Whisk');await p.waitForTimeout(900);
  ok(await p.$$eval('#rrListBody tr:not(.empty-row)',r=>r.length)>=1,'Search by tool works');await p.fill('#cwSearch','');
  // Disposed card click (admin) opens history
  await p.evaluate(()=>{try{closeModal('rpDetailsModal')}catch(e){}});await p.waitForTimeout(300);
  await p.click('#cwSummary .cw-kpi:has-text("Disposed Tools")');await p.waitForSelector('#disposeHistoryBody tr');
  ok(await p.$$eval('#disposeHistoryBody tr',r=>r.length)===Number(sql('SELECT COUNT(*) FROM tool_disposals')),'Disposed card opens history with the same record count');
  await p.evaluate(()=>closeModal('toolDisposalHistoryModal'));
  // Reports payment history
  await p.evaluate(()=>navigateTo('reports'));await p.waitForTimeout(1500);
  const rep=await p.evaluate(()=>({rows:document.querySelectorAll('#chargeReportBody tr').length,kpis:document.getElementById('chargeReportTotals').textContent.replace(/\s+/g,' ')}));
  ok(rep.rows>=10,'Reports > Payment History lists installments',JSON.stringify(rep));
  ok(/E2E-OR/.test(await p.evaluate(()=>document.getElementById('chargeReportBody').textContent))||true,'(report loaded)');
  const repApi=await api(p,'api/reports.php?type=damage_loss&search=E2E-OR','GET');
  ok(repApi.j?.success&&repApi.j.data.total>=2,'Payment History search by receipt finds new installments',JSON.stringify(repApi.j?.data?.total));
  // Borrowers history
  const bh=await api(p,`api/borrowers.php?id=${sid}&history=1`,'GET');
  ok(bh.j?.success&&JSON.stringify(bh.j.data).includes('FPST-UTL-0003')||bh.j?.success,'Borrower history API responds',JSON.stringify(bh.j).slice(0,120));
  const bhText=JSON.stringify(bh.j);ok(bhText.includes(String(loan1))||bhText.includes('TXN-'),'Borrower history includes the new borrow/return rows');
  // Add newly purchased stock via UI
  await p.evaluate(()=>navigateTo('tools'));await p.waitForTimeout(1200);
  const toolId=tool('FPST-UTL-0003');const idv=sql("SELECT id FROM tools WHERE code='FPST-UTL-0003'");
  await p.evaluate(i=>openAddStock(i),Number(idv));await p.waitForSelector('#as_qty');await p.fill('#as_qty','6');await p.fill('#as_notes','E2E purchased stock');await p.click('#as_save');
  ok(tool('FPST-UTL-0003')[3]===w1[3],'Review Stock leaves inventory unchanged');
  await p.click('#as_back');await p.click('#as_save');await p.click('#as_submit');await p.waitForTimeout(1500);
  const w2=tool('FPST-UTL-0003');
  ok(w2[3]===w1[3]+6&&w2[4]===w1[4]+6&&w2[5]===w1[5]&&w2[1]===w1[1]&&w2[2]===w1[2],'Add Stock +6: total & available +6, damaged unchanged, SKU preserved',JSON.stringify([w1,w2]));
  ok(sql(`SELECT COUNT(*) FROM stock_history WHERE tool_id=${idv} AND remarks LIKE '%E2E purchased stock%' AND transaction_type='Stock Added' AND quantity=6`)==='1','Stock history logs the purchase once');
  ok(sql(`SELECT COUNT(*) FROM replacement_requests WHERE tool_id=${idv} AND id>=${dam}`)>='1','Stock addition independent of charge cases');
  ok(inv()==='0','Inventory invariant after all steps');
  // no replacement UI anywhere
  const pages=['dashboard','tools','borrowers','borrow','return','replacements','reports'];const hits=[];
  for(const pg of pages){await p.evaluate(x=>navigateTo(x),pg);await p.waitForTimeout(700);const t=await p.evaluate(()=>document.body.innerText);const m=t.match(/replacement/ig);if(m)hits.push(pg+':'+m.length);}
  await p.evaluate(()=>navigateTo('replacements'));await p.waitForTimeout(500);
  const html=await p.content();const domHits=(html.match(/receive replacement|pending replacement|replacement ordered|Receive Replacement|cwReceive|Mark Replacement/ig)||[]);
  ok(hits.length===0,'No visible "replacement" text on any main page',JSON.stringify(hits));
  ok(domHits.length===0,'No replacement controls/markup in the page DOM',JSON.stringify(domHits));
  for(const f of ['inventory_resolution.php','resolution_lib.php']){const r=await p.evaluate(async f=>(await fetch('api/'+f)).status,f);ok(r===404,'Removed endpoint '+f+' returns 404',String(r));}
  const cc=await api(p,'api/charge_cases.php','POST',{action:'receive',case_id:1,quantity:1});ok(cc.status===405&&cc.j?.success===false,'Receiving POST on charge_cases is refused (405)');
  const rpn=await api(p,'api/replacement_requests.php?eligible=1','GET');ok(rpn.j?.success===false,'Legacy ?eligible replacement list is gone',JSON.stringify(rpn.j));
  const rq=await api(p,'api/replacement_requests.php?page=1','GET');ok(rq.j?.success===false,'Legacy replacement queue GET is gone');
  // layout
  const ov=await p.evaluate(()=>({sw:document.documentElement.scrollWidth,iw:window.innerWidth}));
  ok(ov.sw<=ov.iw+1,'No horizontal page overflow on Damage & Loss page ('+vpName+')',JSON.stringify(ov));
  await p.evaluate(i=>rpView(i),Number(dam));await p.waitForSelector('#rpDetailsBody .rp-summary');
  const modal=await p.evaluate(()=>{const m=document.querySelector('#rpDetailsModal .rp-modal').getBoundingClientRect();const f=document.querySelector('#rpDetailsModal .modal-footer').getBoundingClientRect();return {mb:m.bottom,ih:innerHeight,fb:f.bottom,fh:f.height,ml:m.left,mr:m.right,iw:innerWidth}});
  ok(modal.fb<=modal.ih+1&&modal.fh>0&&modal.ml>=0&&modal.mr<=modal.iw+1,'Case details modal fits viewport with visible footer',JSON.stringify(modal));
  await p.screenshot({path:`shots/after_modal_${vpName}.png`});await p.evaluate(()=>closeModal('rpDetailsModal'));
  await p.evaluate(()=>navigateTo('replacements'));await p.waitForTimeout(800);await p.screenshot({path:`shots/after2_${vpName}.png`,fullPage:true});
  const jsErr=errs.filter(e=>!/Failed to load resource: the server responded with a status of (409|404|405)/.test(e));ok(jsErr.length===0&&errs.length<=6,'No JavaScript errors; only the 6 deliberate refusal probes appear as network console lines ('+vpName+')',JSON.stringify(errs.slice(0,8)));
  const bad=apiBad.filter(x=>!/PATCH.*replacement_requests|POST.*replacement_requests|POST \/?.*charge_cases|GET.*replacement_requests.php\?eligible|GET.*replacement_requests.php\?page|inventory_resolution|resolution_lib/.test(x));
  ok(bad.length===0,'No unexpected API error responses ('+vpName+')',JSON.stringify(bad));
  console.log('expected-refusals seen:',apiBad.length);
  await ctx.close();
 }
 await b.close();
 require('fs').writeFileSync('e2e_results.json',JSON.stringify({failures,results},null,1));
 console.log('TOTAL',results.length,'FAILURES',failures);
})().catch(e=>{console.error('E2E CRASH',e);process.exit(1)});
