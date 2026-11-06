<?php
// Included by the existing schema-cloning harness. Live records are never modified.
function trackingAction(int $id,string $action,array $extra=[],bool $ok=true,string $role='Admin'):array {
 return expectApi('Inventory '.$action,'replacement_requests.php','PATCH',['id'=>$id,'action'=>'inventory_resolution','inventory_action'=>$action,'version'=>(int)row('replacement_requests',$id)['payment_version'],'notes'=>'Test inspection / resolution']+$extra,$ok,$role);
}
function trackingList(array $filters=[]):array {
 return expectApi('Shared incident queue','charge_cases.php','GET',[],true,'Staff',$filters);
}
function trackingDashboard():void {
 global $db;
 $d=expectApi('Existing Dashboard statistics','dashboard.php','GET');
 $t=$db->query('SELECT SUM(quantity) total,SUM(available) available,SUM(damaged_qty) damaged,SUM(missing_qty) missing FROM tools WHERE is_active=1')->fetch();
 check((int)$d['damaged_tools']===(int)$t['damaged']&&(int)$d['missing_tools']===(int)$t['missing'],'Dashboard uses actual damaged and missing stock');
 check((int)$d['available_tools']===(int)$t['available']&&(int)$d['borrowed_tools']===(int)$t['total']-(int)$t['available']-(int)$t['damaged']-(int)$t['missing'],'Dashboard available and borrowed counts remain synchronized');
}
[$tool,$loan]=loan('TRACK-MIXED');
$body=['type'=>'return','borrow_txn_id'=>$loan,'returnee_name'=>'Test','request_token'=>'track_mixed_123456789','items'=>['good'=>4,'damaged'=>3,'missing'=>3],'missing_reason'=>'Missing at return'];
expectApi('Mixed return creates tracking automatically','transactions.php','POST',$body,true,'Staff');
$cases=$db->query("SELECT * FROM replacement_requests WHERE tool_id=$tool ORDER BY id")->fetchAll();$damage=(int)$cases[0]['id'];$lost=(int)$cases[1]['id'];
check(count($cases)===2&&$cases[0]['workflow_mode']==='tracking'&&$cases[0]['amount_due']===null,'Automatic cases reuse existing incident tables without financial assessments');
expectApi('Mixed return retry','transactions.php','POST',$body,true,'Staff');
check((int)$db->query("SELECT COUNT(*) FROM replacement_requests WHERE tool_id=$tool")->fetchColumn()===2,'Return retries never duplicate stored cases');
$list=trackingList(['search'=>'TRACK-MIXED']);
check($list['total']===2&&count(array_filter($list['rows'],fn($r)=>$r['source']==='missing'))===1,'Linked missing report appears once in the unified queue');
check(count(array_filter($list['rows'],fn($r)=>$r['source']==='return'))===0,'Damaged return evidence is not shown as a duplicate case');
check(!array_key_exists('amount_due',$list['rows'][0])&&!array_key_exists('payment_status',$list['rows'][0]),'Queue contains physical incident fields only');
$history=expectApi('Borrower incident history','borrowers.php','GET',[],true,'Staff',['id'=>1,'history'=>1]);
check(count(array_filter($history['incidents']['rows'],fn($r)=>(int)$r['tool_id']===$tool))===2,'Borrower history reuses the same deduplicated incident source');
trackingDashboard();
foreach(['review_charge','claim_payment','confirm_payment','reject_payment','void_payment','close_case','create_bulk_case'] as $action)expectApi('Retired financial action '.$action,'replacement_requests.php','PATCH',['id'=>$damage,'action'=>$action],false,'Admin');
$physicalSummary=expectApi('Physical tracking summary','charge_cases.php','GET',[],true,'Staff',['summary'=>1]);
check(isset($physicalSummary['active_missing_units'],$physicalSummary['damaged_units'])&&!isset($physicalSummary['amount_due']),'Tracking summary exposes physical units without retired financial assessments');
expectApi('Retired payment report','reports.php','GET',[],false,'Admin',['type'=>'damage_loss']);
expectApi('Retired payment detail route','replacement_requests.php','GET',[],false,'Staff',['payment_history'=>$damage]);
trackingAction($damage,'dispose',[],false,'Staff');
trackingAction($damage,'request',[],true,'Staff');
trackingAction($damage,'repair');
trackingAction($damage,'available');
check((int)row('tools',$tool)['available']===17,'Inspection restores only damaged case units');
trackingAction($damage,'available',[],false);
$report=(int)row('replacement_requests',$lost)['missing_report_id'];
expectApi('Confirm missing through existing workflow','missing_tools.php','POST',['action'=>'confirm','id'=>$report,'qty'=>3]);
trackingAction($lost,'recovered');
check((int)row('tools',$tool)['available']===17&&(int)row('tools',$tool)['damaged_qty']===3,'Recovered originals stay unavailable until inspected');
trackingAction($lost,'available');
trackingAction($lost,'recovered',[],false);
$list=trackingList(['search'=>'TRACK-MIXED','inventory'=>'Resolved']);
check($list['total']===2,'Physical resolution synchronizes missing and tracking records');
trackingDashboard();
[$disposalTool,$disposalLoan]=loan('TRACK-DISPOSE');
expectApi('Damaged return for disposal','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$disposalLoan,'returnee_name'=>'Test','request_token'=>'track_dispose_12345678','items'=>['damaged'=>2]],true,'Staff');
$disposal=(int)$db->query("SELECT id FROM replacement_requests WHERE tool_id=$disposalTool")->fetchColumn();
expectApi('Generic repair cannot consume reserved case units','tools.php','PATCH',['id'=>$disposalTool,'action'=>'repair','qty'=>1,'to'=>'good'],false);
trackingAction($disposal,'dispose');
check((int)row('tools',$disposalTool)['quantity']===18&&(int)row('tools',$disposalTool)['available']===10,'Disposal removes only damaged units once');
check((int)$db->query("SELECT COUNT(*) FROM tool_disposals WHERE case_id=$disposal")->fetchColumn()===1,'Case disposal reuses existing disposal ledger');
trackingAction($disposal,'dispose',[],false);
[$rt,$rl]=loan('TRACK-REPLACE');
expectApi('Replacement candidate return','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$rl,'returnee_name'=>'Test','request_token'=>'track_replace_12345678','items'=>['damaged'=>2]],true,'Staff');
$replacement=(int)$db->query("SELECT id FROM replacement_requests WHERE tool_id=$rt")->fetchColumn();
trackingAction($replacement,'replacement');
check((int)row('tools',$rt)['quantity']===20,'Awaiting replacement does not add stock');
trackingAction($replacement,'received',[],false);
trackingAction($replacement,'received',['received_confirmed'=>true]);
check((int)row('tools',$rt)['quantity']===20&&(int)row('tools',$rt)['available']===12,'Physical replacement reuses Add Stock while retiring affected units');
trackingAction($replacement,'received',['received_confirmed'=>true],false);
check((int)$db->query("SELECT COUNT(*) FROM stock_history WHERE tool_id=$rt AND event_kind='restock'")->fetchColumn()===1,'Replacement uses exactly one existing restock ledger event');
[$gt,$gl]=loan('TRACK-GOOD');
expectApi('Good return retains normal behavior','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$gl,'returnee_name'=>'Test','request_token'=>'track_good_123456789','items'=>['good'=>10]],true,'Staff');
check((int)$db->query("SELECT COUNT(*) FROM replacement_requests WHERE tool_id=$gt")->fetchColumn()===0,'Good returns create no incidents');
$manual=['action'=>'manual_incident','tool_code'=>'TRACK-GOOD','borrower_identifier'=>'20260001','reason'=>'lost','quantity_needed'=>2,'notes'=>'Shelf incident','request_token'=>'track_manual_123456789'];
$m=expectApi('Manual incident reuses available-stock workflow','replacement_requests.php','POST',$manual,true,'Staff');$mid=(int)$m['id'];
expectApi('Manual incident retry','replacement_requests.php','POST',$manual,true,'Staff');
check((int)row('tools',$gt)['available']===18,'Manual retry moves stock once');
trackingAction($mid,'recovered');trackingAction($mid,'available');
check((int)row('tools',$gt)['available']===20,'Manual recovery conserves stock');
[$bt,$bl]=loan('TRACK-BATCH');
$batch=['type'=>'return_batch','borrower_id'=>1,'returnee_name'=>'Test','request_token'=>'track_batch_123456789','items'=>[['borrow_txn_id'=>$bl,'good'=>6,'damaged'=>2,'missing'=>2]],'missing_reason'=>'Batch missing'];
expectApi('Batch return integrates existing conditions','transactions.php','POST',$batch,true,'Staff');
expectApi('Batch return retry','transactions.php','POST',$batch,true,'Staff');
check(trackingList(['search'=>'TRACK-BATCH'])['total']===2,'Batch retry shows exactly two distinct incidents');
// Preserve existing financial evidence even when physical status changes.
$db->prepare("UPDATE replacement_requests SET workflow_mode='payment',amount_due=500,financial_status='confirmed',responsibility='liable' WHERE id=?")->execute([$mid]);
$db->prepare("INSERT INTO replacement_payment_claims(request_id,amount,receipt_number,payment_date,status,charge_version,submitted_by,submitted_by_name) VALUES(?,500,'HIST-OR-1',CURDATE(),'confirmed',0,2,'Historical Staff')")->execute([$mid]);
$receipt=(int)$db->lastInsertId();$saved=row('replacement_payment_claims',$receipt);
expectApi('Cannot alter archived receipt','replacement_requests.php','PATCH',['id'=>$mid,'action'=>'void_payment','claim_id'=>$receipt,'notes'=>'Retired control'],false);
check(row('replacement_payment_claims',$receipt)===$saved&&row('replacement_requests',$mid)['amount_due']==='500.00','Archived receipts and assessed amounts are preserved');
$d=expectApi('Physical details for historical case','replacement_requests.php','GET',[],true,'Staff',['details'=>$mid]);
check(!isset($d['request']['amount_due'])&&!isset($d['claims']),'Incident details do not expose removed financial controls');
trackingDashboard();
check((int)$db->query("SELECT COUNT(*) FROM replacement_request_events WHERE action='inventory_resolution'")->fetchColumn()>0,'Physical resolution keeps existing activity history');
$markup=file_get_contents(dirname(__DIR__).'/index.php');
check(substr_count($markup,'id="dashMissing"')===1&&substr_count($markup,'id="dashDamaged"')===1,'Existing inventory Dashboard cards occur once');
check(substr_count($markup,'id="cwSummary"')===1&&!str_contains($markup,'id="chargeReportSection"')&&!str_contains($markup,'id="missingCasesBody"'),'One physical tracking summary; no duplicate missing table or financial report UI');
check(!str_contains($markup,'bulk-charges.js')&&!str_contains($markup,'rpClaimAmount'),'Retired financial components are not loaded');
invariant();echo "PASSED: $checks tracking workflow checks\n";
