<?php
// Existing temporary-database harness; all fixture data is isolated from live inventory.
function returnOverview():array {return expectApi('Return overview','transactions.php','GET',[],true,'Staff',['type'=>'return_overview']);}
$before=returnOverview();
[$returnToolA,$returnLoanA]=loan('RETURN-UI-A');[$returnToolB,$returnLoanB]=loan('RETURN-UI-B');
$db->prepare('UPDATE transactions SET due_date=DATE_SUB(CURDATE(),INTERVAL 2 DAY),due_time=NULL WHERE id=?')->execute([$returnLoanA]);
$overview=returnOverview();check($overview['units_on_loan']===$before['units_on_loan']+20&&$overview['overdue_loans']===$before['overdue_loans']+1,'Overview counts outstanding units and overdue loans using existing rules');
$payload=['type'=>'return_batch','borrower_id'=>1,'returnee_name'=>'Return fixture','request_token'=>'return_ui_mixed_123456789','missing_reason'=>'Not handed back','items'=>[['borrow_txn_id'=>$returnLoanA,'good'=>3,'missing'=>1],['borrow_txn_id'=>$returnLoanB,'damaged'=>1]]];
$saved=expectApi('Multi-tool partial return','transactions.php','POST',$payload,true,'Staff');
$overview=returnOverview();check($overview['units_on_loan']===$before['units_on_loan']+16&&$overview['returned_today']===$before['returned_today']+4,'Overview counts physical returns; pending missing units remain outstanding');
check((int)row('tools',$returnToolA)['available']===13&&(int)row('tools',$returnToolB)['available']===10&&(int)row('tools',$returnToolB)['damaged_qty']===1,'Only good returned quantities become available');
$missingReport=(int)$saved['items'][0]['missing']['id'];
$count=(int)$db->query("SELECT COUNT(*) FROM replacement_requests WHERE tool_id IN ($returnToolA,$returnToolB)")->fetchColumn();
$duplicate=expectApi('Repeated multi-tool submission','transactions.php','POST',$payload,true,'Staff');
check($duplicate['duplicate']&&(int)$db->query("SELECT COUNT(*) FROM replacement_requests WHERE tool_id IN ($returnToolA,$returnToolB)")->fetchColumn()===$count,'Return retry preserves incident counts and inventory');
expectApi('Staff cannot confirm missing inventory','missing_tools.php','POST',['action'=>'confirm','id'=>$missingReport,'qty'=>1],false,'Staff');
expectApi('Admin confirms existing missing report','missing_tools.php','POST',['action'=>'confirm','id'=>$missingReport,'qty'=>1]);
$overview=returnOverview();check($overview['units_on_loan']===$before['units_on_loan']+15&&$overview['returned_today']===$before['returned_today']+4,'Confirmed loss reduces outstanding units without counting as a physical return');
check((int)row('tools',$returnToolA)['available']===13&&(int)row('tools',$returnToolA)['missing_qty']===1,'Confirmed missing units are never returned to available stock');
expectApi('Excess return rejected atomically','transactions.php','POST',['type'=>'return_batch','borrower_id'=>1,'returnee_name'=>'Fixture','request_token'=>'return_ui_excess_123456789','items'=>[['borrow_txn_id'=>$returnLoanA,'good'=>7],['borrow_txn_id'=>$returnLoanB,'good'=>9]]],false,'Staff');
check((int)row('transactions',$returnLoanB)['qty_returned']===1,'Failed batch does not save another loan partially');
$oldActive=(int)row('borrowers',1)['is_active'];$db->exec('UPDATE borrowers SET is_active=0 WHERE id=1');
expectApi('Full return remains available for inactive borrower','transactions.php','POST',['type'=>'return_batch','borrower_id'=>1,'returnee_name'=>'Fixture','request_token'=>'return_ui_full_123456789','items'=>[['borrow_txn_id'=>$returnLoanA,'good'=>6],['borrow_txn_id'=>$returnLoanB,'good'=>9]]],true,'Staff');
$db->prepare('UPDATE borrowers SET is_active=? WHERE id=1')->execute([$oldActive]);
$overview=returnOverview();check($overview['units_on_loan']===$before['units_on_loan']&&$overview['overdue_loans']===$before['overdue_loans']&&$overview['returned_today']===$before['returned_today']+19,'Completed returns refresh all three overview figures accurately');
$history=expectApi('Existing return-history filter','transactions.php','GET',[],true,'Staff',['type'=>'return','condition'=>'damaged','search'=>'RETURN-UI-B']);check(count($history)===1&&(int)$history[0]['qty']===1,'Return history retains condition filters and actual return records');
$html=file_get_contents(dirname(__DIR__).'/index.php');
foreach(['returnIdReader','raSearch','raStudent','raSubmitBtn','returnHistoryBody','returnHistoryFilter','rtUnitsOnLoan','rtOverdueLoans','rtReturnedToday'] as $id)check(substr_count($html,'id="'.$id.'"')===1,'One existing/reused element: '.$id);
$start=strpos($html,'    <section class="page" id="returnPage">');$end=strpos($html,'    <section class="page" id="replacementsPage">',$start);$markup=substr($html,$start,$end-$start);
$doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML('<!doctype html><html><body>'.$markup.'</body></html>');$xpath=new DOMXPath($doc);
check($xpath->query('//*[@id="returnPage"]//*[@id="raSubmitBtn"]')->length===1&&$xpath->query('//*[@id="rtWorkspace"]//*[@id="returnAllCard"]')->length===1,'Return panels and confirmation remain inside Return Management');
check($xpath->query('//*[@id="returnPage"]//*[@id="returnHistorySection"]//*[@id="returnHistoryFilter"]')->length===1,'Dedicated history view retains its original filter');
invariant();echo "PASSED: $checks cumulative Return Management checks\n";
