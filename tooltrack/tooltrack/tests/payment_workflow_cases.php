<?php
if(PHP_SAPI!=='cli'||!isset($db,$work))exit;
if(!preg_match('/^tooltrack_test_[a-f0-9]{12}$/',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Charges tests require an isolated database.');
$GLOBALS['paymentCases']=true;
$db->exec("UPDATE borrowers SET type='Student' WHERE id=1");
function payAction(string $action,int $id,array $extra=[],bool $ok=true,string $role='Admin'):array {
 return expectApi('FPST '.$action,'replacement_requests.php','PATCH',array_merge(['id'=>$id,'action'=>$action,'version'=>(int)row('replacement_requests',$id)['payment_version']],$extra),$ok,$role);
}
function chargeFixture(string $code):array {
 [$tool,$loan]=loan($code);
 expectApi('Damaged return retains borrowing evidence','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$loan,'returnee_name'=>'Student','request_token'=>'ret_'.bin2hex(random_bytes(12)),'items'=>['damaged'=>3,'good'=>7]],true,'Staff');
 $r=expectApi('Staff reports damage','replacement_requests.php','POST',['action'=>'create_incident','reason'=>'damaged','tool_id'=>$tool,'responsible_borrower_id'=>1,'quantity_needed'=>2],true,'Staff');
 return [$tool,$loan,(int)$r['id']];
}
function confirmInstallment(int $id,string $amount,string $receipt,string $role='Staff'):int {
 $r=payAction('claim_payment',$id,['amount'=>$amount,'receipt_number'=>$receipt,'payment_date'=>date('Y-m-d')],true,$role);
 $claim=(int)$GLOBALS['db']->query("SELECT id FROM replacement_payment_claims WHERE request_id=$id AND status='pending'")->fetchColumn();
 payAction('confirm_payment',$id,['claim_id'=>$claim,'received_confirmed'=>true,'notes'=>'Receipt matched school records'],true,$role);
 return $claim;
}
[$tool,$loan,$id]=chargeFixture('FPST-CHARGE-A');$stock=row('tools',$tool);
expectApi('Duplicate incident evidence refused','replacement_requests.php','POST',['action'=>'create_incident','reason'=>'damaged','tool_id'=>$tool,'responsible_borrower_id'=>1,'quantity_needed'=>2],false,'Staff');
payAction('claim_payment',$id,['amount'=>'100.00','receipt_number'=>'EARLY','payment_date'=>date('Y-m-d')],false,'Staff');
payAction('review_charge',$id,['amount_due'=>'1000.00','notes'=>'Two damaged units assessed'],true,'Staff');
check(row('replacement_requests',$id)['financial_status']==='awaiting_payment','New assessed charge is Unpaid');
$autoClaimResult=payAction('claim_payment',$id,['amount'=>'50.00','payment_date'=>date('Y-m-d')],true,'Staff');
$autoClaimId=(int)$db->query("SELECT id FROM replacement_payment_claims WHERE request_id=$id AND status='pending'")->fetchColumn();
$autoClaim=row('replacement_payment_claims',$autoClaimId);
check(str_starts_with($autoClaim['receipt_number'],'SYS-PAY-'),'Payment without receipt input receives an internal reference');
payAction('reject_payment',$id,['claim_id'=>$autoClaimId,'notes'=>'Test generated payment reference'],true,'Staff');
foreach(['0','-1','1000.001','1e2'] as $amount)payAction('claim_payment',$id,['amount'=>$amount,'receipt_number'=>'INVALID','payment_date'=>date('Y-m-d')],false,'Staff');
payAction('review_charge',$id,['amount_due'=>'0','responsibility'=>'not_responsible','notes'=>'Invalid waiver'],false,'Staff');
payAction('claim_payment',$id,['amount'=>'1001.00','receipt_number'=>'OVER','payment_date'=>date('Y-m-d')],false,'Staff');
$c1=confirmInstallment($id,'400.00','FPST-OR-400');
check(row('replacement_requests',$id)['financial_status']==='partially_paid','Confirmed installment becomes Partially Paid');
$sum=expectApi('Dashboard counts partial case as unpaid','charge_cases.php','GET',[],true,'Staff',['summary'=>1]);check((int)$sum['unpaid']>=1,'Partially paid case counts as Unpaid on the dashboard');
$d=expectApi('Staff reads payment history','replacement_requests.php','GET',[],true,'Staff',['payment_history'=>$id]);
check($d['request']['amount_paid']==='400.00'&&$d['request']['balance']==='600.00','Exact confirmed total and remaining balance');
check($d['claims'][0]['verified_by_role']==='Staff','History records confirming Staff role');
payAction('review_charge',$id,['amount_due'=>'1100.00','notes'=>'Staff correction forbidden'],false,'Staff');
payAction('void_payment',$id,['claim_id'=>$c1,'notes'=>'Staff void forbidden'],false,'Staff');
$c2=confirmInstallment($id,'600.00','FPST-OR-600','Admin');
check(row('replacement_requests',$id)['financial_status']==='confirmed','Multiple confirmed installments become Paid');
$paidList=expectApi('Paid filter lists fully paid case','charge_cases.php','GET',[],true,'Staff',['filter'=>'paid','search'=>'FPST-CHARGE-A']);
check($paidList['total']===1&&(int)$paidList['rows'][0]['id']===$id&&$paidList['rows'][0]['payment_status']==='Paid'&&$paidList['rows'][0]['balance']==='0.00','Full verified payment moves the case to Paid with zero balance');
$retryBody=['id'=>$id,'action'=>'confirm_payment','claim_id'=>$c2,'version'=>(int)row('replacement_requests',$id)['payment_version'],'received_confirmed'=>true,'notes'=>'Already confirmed','request_token'=>'paid_retry_'.bin2hex(random_bytes(12))];
expectApi('Repeated verification without original token refused','replacement_requests.php','PATCH',$retryBody,false);
check(row('replacement_requests',$id)['financial_status']==='confirmed','Repeated verification leaves the case Paid');
check(row('replacement_payment_claims',$c1)['active_request_id']===null&&row('replacement_payment_claims',$c2)['active_request_id']===null,'Confirmed installments release pending slot');
payAction('claim_payment',$id,['amount'=>'0.01','receipt_number'=>'EXTRA','payment_date'=>date('Y-m-d')],false);
expectApi('Removed status-change PATCH is refused','replacement_requests.php','PATCH',['id'=>$id,'status'=>'approved'],false);
expectApi('Removed fulfilment PATCH is refused','replacement_requests.php','PATCH',['id'=>$id,'status'=>'fulfilled','condition'=>'good','verified'=>true],false);
payAction('void_payment',$id,['claim_id'=>$c2,'notes'=>'Incorrect second receipt; replace with corrected transaction']);
$unp=expectApi('Voided payment returns case to Unpaid filter','charge_cases.php','GET',[],true,'Staff',['filter'=>'unpaid','search'=>'FPST-CHARGE-A']);check($unp['total']===1&&$unp['rows'][0]['payment_status']==='Partially Paid','Voided settlement returns the case to Unpaid (partially paid)');
check(row('replacement_payment_claims',$c2)['status']==='voided'&&row('replacement_payment_claims',$c2)['receipt_number']==='FPST-OR-600','Voids preserve original receipt and amount');
payAction('void_payment',$id,['claim_id'=>$c2,'notes'=>'Double void'],false);
payAction('review_charge',$id,['amount_due'=>'300.00','notes'=>'Below confirmed receipts'],false);
payAction('review_charge',$id,['amount_due'=>'1100.00','notes'=>'Admin corrects assessed charge']);
$c3=confirmInstallment($id,'700.00','FPST-OR-CORRECTED');
check(row('replacement_requests',$id)['financial_status']==='confirmed','Corrected full payment makes the case Paid again');
check(row('tools',$tool)===$stock,'Charge, installments, correction and void never modify stock');
[$toolB,$loanB,$idB]=chargeFixture('FPST-CHARGE-B');
payAction('review_charge',$idB,['amount_due'=>'250.00','notes'=>'Unpaid fixture'],true,'Staff');
payAction('claim_payment',$idB,['amount'=>'100.00','receipt_number'=>' fpst-or-400 ','payment_date'=>date('Y-m-d')],false,'Staff');
payAction('claim_payment',$idB,['amount'=>'100.00','receipt_number'=>'FPST-OR-600','payment_date'=>date('Y-m-d')],false,'Staff');
payAction('claim_payment',$idB,['amount'=>'100.00','receipt_number'=>'FPST-PENDING','payment_date'=>date('Y-m-d')],true,'Staff');
$pending=(int)$db->query("SELECT id FROM replacement_payment_claims WHERE request_id=$idB AND status='pending'")->fetchColumn();
payAction('confirm_payment',$idB,['claim_id'=>$pending,'notes'=>'No actual receipt'],false,'Staff');
payAction('reject_payment',$idB,['claim_id'=>$pending,'notes'=>'Receipt invalid'],true,'Staff');
check(row('replacement_requests',$idB)['financial_status']==='awaiting_payment','Rejected payment leaves the case Unpaid');
$report=expectApi('Charges integrated into existing Reports endpoint','reports.php','GET',[],true,'Staff',['type'=>'damage_loss','search'=>'FPST-CHARGE']);
check($report['totals']['total_charges']==='1350.00'&&$report['totals']['total_collected']==='1100.00'&&$report['totals']['outstanding_balance']==='250.00','Reports aggregate charges once and exclude voided/rejected receipts');
check((int)$report['totals']['paid_cases']===1&&(int)$report['totals']['unpaid_cases']===1,'Paid and unpaid case counts are per case');
$filtered=expectApi('Filter partially paid history','reports.php','GET',[],true,'Staff',['type'=>'damage_loss','search'=>'FPST-CHARGE','payment_status'=>'Paid','incident_type'=>'damaged']);
check($filtered['totals']['total_charges']==='1100.00'&&(int)$filtered['totals']['paid_cases']===1,'Status and incident filters apply to totals');
$search=expectApi('Receipt search retains full case history','reports.php','GET',[],true,'Staff',['type'=>'damage_loss','search'=>'FPST-OR-400']);
check($search['total']===4,'Receipt search returns all payment history including the system-referenced entry and preserved void');
expectApi('Reject reversed date range','reports.php','GET',[],false,'Staff',['type'=>'damage_loss','date_from'=>'2026-10-30','date_to'=>'2026-10-01']);
expectApi('Reject invalid dates','reports.php','GET',[],false,'Staff',['type'=>'damage_loss','date_from'=>'2026-02-30']);
expectApi('Reject injected report status','reports.php','GET',[],false,'Staff',['type'=>'damage_loss','payment_status'=>"Paid' OR 1=1"]);
$old=(new DateTimeImmutable(date('Y-m-d')))->modify('-1 month')->format('Y-m-d');
$db->prepare('UPDATE replacement_requests SET charge_assessed_at=? WHERE id=?')->execute([$old.' 09:00:00',$id]);
$period=expectApi('Collections use payment dates rather than charge dates','reports.php','GET',[],true,'Staff',['type'=>'damage_loss','search'=>'FPST-CHARGE','date_from'=>date('Y-m-d'),'date_to'=>date('Y-m-d')]);
check($period['totals']['total_charges']==='250.00'&&$period['totals']['total_collected']==='1100.00'&&$period['totals']['outstanding_balance']==='250.00','Period charges, period collections and opening balances are distinct');
$before=expectApi('Historical balance uses payment cutoff','reports.php','GET',[],true,'Staff',['type'=>'damage_loss','search'=>'FPST-CHARGE-A','date_to'=>$old,'date_basis'=>'charge']);
check($before['totals']['outstanding_balance']==='1100.00'&&$before['totals']['total_collected']==='0.00','Future payments do not reduce earlier balances');
function rawChargeExport(array $query):string {
 global $work;
 $p=proc_open([PHP_BINARY,$work.'/worker.php','reports.php','GET','{}','Staff',http_build_query($query)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);if($err)throw new RuntimeException($err);return $out;
}
foreach(['csv','excel','print'] as $format){
 $export=rawChargeExport(['type'=>'damage_loss','search'=>'FPST-CHARGE','download'=>1,'format'=>$format]);
 check(str_contains($export,'FPST-OR-400')&&str_contains($export,'FPST-OR-CORRECTED')&&str_contains($export,$format==='print'?'total collected':'total_collected'),'Complete filtered '.$format.' export contains receipts and totals');
 if($format==='excel'){check(simplexml_load_string($export)!==false,'Excel SpreadsheetML is valid XML');}
 if($format==='print')check(str_contains($export,'window.print()'),'PDF uses printable full report');
}
// Concurrent confirmations must not double-collect.
payAction('claim_payment',$idB,['amount'=>'50.00','receipt_number'=>'FPST-RACE','payment_date'=>date('Y-m-d')],true,'Admin');
$pending=(int)$db->query("SELECT id FROM replacement_payment_claims WHERE request_id=$idB AND status='pending'")->fetchColumn();
$race=concurrentApi('replacement_requests.php','PATCH',['action'=>'confirm_payment','id'=>$idB,'claim_id'=>$pending,'version'=>(int)row('replacement_requests',$idB)['payment_version'],'received_confirmed'=>true,'notes'=>'Concurrent confirmation','request_token'=>'race_'.bin2hex(random_bytes(12))]);
check(count(array_filter($race,fn($r)=>$r['success']))===2,'Concurrent confirmation retries return safely');
check($db->query("SELECT SUM(amount) FROM replacement_payment_claims WHERE request_id=$idB AND status='confirmed'")->fetchColumn()==='50.00','Concurrent retries count payment once');
// History must commit atomically with finance.
$db->exec("CREATE TRIGGER fpst_test_history BEFORE INSERT ON replacement_request_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='History unavailable'");
$beforeRow=row('replacement_requests',$idB);
payAction('review_charge',$idB,['amount_due'=>'275.00','notes'=>'Must roll back if event write fails'],false);
check(row('replacement_requests',$idB)===$beforeRow,'Audit event failure rolls back financial correction');
$db->exec('DROP TRIGGER fpst_test_history');
invariant();check(true,'FPST financial workflow preserves inventory invariant');
// Lost-tool charges retain physical missing stock after payment.
[$lostTool,$lostLoan]=loan('FPST-LOSS');
$loss=report($lostLoan,2,'fpst_loss_report');$case=(int)$loss['id'];
expectApi('Confirm actual loss','missing_tools.php','POST',['action'=>'confirm','id'=>$case,'qty'=>2]);
$lost=expectApi('Report charge from confirmed borrowing loss','replacement_requests.php','POST',['action'=>'create_incident','reason'=>'lost','source_id'=>$case,'missing_report_id'=>$case,'quantity_needed'=>2],true,'Staff');$lostId=(int)$lost['id'];
$lostStock=row('tools',$lostTool);
payAction('review_charge',$lostId,['amount_due'=>'300.00','notes'=>'Lost units assessed'],true,'Staff');confirmInstallment($lostId,'300.00','FPST-LOSS-RECEIPT');
check(row('tools',$lostTool)===$lostStock&&(int)row('tools',$lostTool)['missing_qty']===2,'Paid loss does not deduct or restore inventory again');
expectApi('Original recovery still works','missing_tools.php','POST',['action'=>'recover','id'=>$case,'qty'=>2,'to'=>'good']);
check((int)row('tools',$lostTool)['missing_qty']===0&&(int)row('replacement_requests',$lostId)['needs_financial_review']===1,'Recovery updates stock once and flags financial review');
payAction('acknowledge_recovery',$lostId,['notes'=>'Department reviewed recovery and original charge']);
check((int)$db->query("SELECT COUNT(*) FROM replacement_request_events WHERE action='void_payment'")->fetchColumn()===1,'Admin void is retained in case audit history');
