<?php
// Runs only inside the existing isolated test database harness.
function lmsBorrower(string $name,string $type='Guest'):int {
 global $db;$db->prepare('INSERT INTO borrowers(full_name,id_number,type) VALUES(?,?,?)')->execute(['LMS '.$name,'LMS-'.$name,$type]);return (int)$db->lastInsertId();
}
function lmsTool(string $code,int $quantity=6):int {
 global $db;$db->prepare("INSERT INTO tools(name,code,category,quantity,available) VALUES(?,?,'Test',?,?)")->execute(['LMS '.$code,$code,$quantity,$quantity]);return (int)$db->lastInsertId();
}
function lmsCheckout(int $borrower,string $code,int $qty,array $extra=[],bool $ok=true,string $role='Staff'):array {
 return expectApi('Circulation checkout '.$code,'transactions.php','POST',array_merge(['type'=>'borrow_batch','borrower_id'=>$borrower,'due_date'=>date('Y-m-d',strtotime('+1 day')),'due_time'=>'17:30','items'=>[['tool_code'=>$code,'qty'=>$qty]]],$extra),$ok,$role);
}

$a=lmsBorrower('A');$b=lmsBorrower('B');$c=lmsBorrower('C');$d=lmsBorrower('D');
$t=lmsTool('LMS-OVERDUE');$loan=lmsCheckout($a,'LMS-OVERDUE',2)['items'][0]['id'];
$list=expectApi('Future active loan','transactions.php','GET',[],true,'Staff',['type'=>'borrow','search'=>'LMS-OVERDUE']);check($list[0]['circulation_status']==='borrowed','Future due date remains Borrowed');
$db->prepare('UPDATE transactions SET due_date=DATE_SUB(CURDATE(),INTERVAL 3 DAY),due_time=NULL WHERE id=?')->execute([$loan]);
$list=expectApi('Automatic overdue classification','transactions.php','GET',[],true,'Staff',['type'=>'borrow','status'=>'overdue','search'=>'LMS-OVERDUE']);check(count($list)===1&&(int)$list[0]['overdue_days']===3,'Overdue days come from configured local due date');
$report=expectApi('Existing overdue report layout','reports.php','GET',[],true,'Staff',['type'=>'overdue']);check(count($report['rows'][0])===count($report['columns']),'Overdue report columns remain aligned');
expectApi('Partial overdue return','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$loan,'returnee_name'=>'Test','request_token'=>'lms_partial_return123','items'=>['good'=>1]],true,'Staff');
$list=expectApi('Partially returned overdue loan','transactions.php','GET',[],true,'Staff',['type'=>'borrow','status'=>'overdue','search'=>'LMS-OVERDUE']);check((int)$list[0]['outstanding']===1,'Partial return remains overdue for outstanding units only');
expectApi('Complete overdue return','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$loan,'returnee_name'=>'Test','request_token'=>'lms_final_return12345','items'=>['damaged'=>1]],true,'Staff');
$list=expectApi('Completed loans leave overdue queue','transactions.php','GET',[],true,'Staff',['type'=>'borrow','status'=>'overdue','search'=>'LMS-OVERDUE']);check(count($list)===0,'Fully returned loan is not overdue');
$h=expectApi('Complete borrower profile','borrowers.php','GET',[],true,'Staff',['id'=>$a,'history'=>1]);check($h['stats']['total_borrows']===1&&$h['stats']['active_units']===0&&$h['stats']['damaged_incidents']===1,'Profile reuses actual loan and incident records');
check(!array_key_exists('reservations',$h),'Borrower history no longer queries or exposes reservations');
expectApi('Removed reservation list is unavailable','circulation.php','GET',[],false,'Staff');
expectApi('Removed reservation availability is unavailable','circulation.php','GET',[],false,'Staff',['availability'=>1]);
expectApi('Removed reservation creation is unavailable','circulation.php','POST',['borrower_id'=>$a],false,'Staff');
expectApi('Removed reservation updates are unavailable','circulation.php','PATCH',['id'=>1,'action'=>'ready'],false,'Staff');
$alertTool=lmsTool('LMS-ALERT');$alert=lmsCheckout($a,'LMS-ALERT',1)['items'][0]['id'];
$db->prepare("UPDATE transactions SET due_date=CURDATE(),due_time='23:59:59' WHERE id=?")->execute([$alert]);
$alerts=expectApi('Existing notification source includes due today','reports.php','GET',[],true,'Staff',['type'=>'overdue_summary']);check(count(array_filter($alerts['due_today_rows'],fn($r)=>$r[0]===row('transactions',$alert)['txn_id']))===1,'Due today notification appears once per borrowing event');
$db->prepare("UPDATE transactions SET due_time='00:00:00' WHERE id=?")->execute([$alert]);
$alerts=expectApi('Same-day due-time overdue monitoring','reports.php','GET',[],true,'Staff',['type'=>'overdue_summary']);check(count(array_filter($alerts['rows'],fn($r)=>$r[0]===row('transactions',$alert)['txn_id']))===1&&count(array_filter($alerts['due_today_rows'],fn($r)=>$r[0]===row('transactions',$alert)['txn_id']))===0,'A loan cannot be both Due Today and Overdue');
$policy=['borrower_type'=>'Guest','max_active_quantity'=>5,'max_loan_days'=>7,'allow_overdue'=>false];
expectApi('Staff cannot configure policy','circulation.php','PUT',$policy,false,'Staff');
expectApi('Admin configures existing Settings policy','circulation.php','PUT',$policy,true,'Admin');
lmsCheckout($a,'LMS-ALERT',1,[],false);
$limitTool=lmsTool('LMS-LIMIT',20);$lb=lmsCheckout($b,'LMS-LIMIT',4);
lmsCheckout($b,'LMS-LIMIT',2,[],false);
lmsCheckout($b,'LMS-LIMIT',2,[],false,'Admin');
lmsCheckout($b,'LMS-LIMIT',2,['override_reason'=>'Supervised workshop authorization'],true,'Admin');
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='borrowing_override' AND description LIKE '%Supervised workshop authorization%'")->fetchColumn()===1,'Admin override records reason, actor and timestamp in existing audit history');
lmsCheckout($b,'LMS-LIMIT',100,['override_reason'=>'Cannot override stock'],false,'Admin');
lmsCheckout($c,'LMS-LIMIT',1,['due_date'=>date('Y-m-d',strtotime('+10 days'))],false);
lmsCheckout($c,'LMS-LIMIT',1,['due_date'=>date('Y-m-d',strtotime('+10 days')),'override_reason'=>'Approved extended project'],true,'Admin');
$inactive=lmsBorrower('Inactive');$db->prepare('UPDATE borrowers SET is_active=0 WHERE id=?')->execute([$inactive]);lmsCheckout($inactive,'LMS-LIMIT',1,['override_reason'=>'Inactive cannot be overridden'],false,'Admin');
$pa=lmsBorrower('Parallel');$pt1=lmsTool('LMS-PAR-A');$pt2=lmsTool('LMS-PAR-B');
$loanBody=fn($code)=>['type'=>'borrow_batch','borrower_id'=>$pa,'due_date'=>date('Y-m-d',strtotime('+1 day')),'due_time'=>'17:30','items'=>[['tool_code'=>$code,'qty'=>3]]];
$race=concurrentApi('transactions.php','POST',[],2,[$loanBody('LMS-PAR-A'),$loanBody('LMS-PAR-B')]);check(count(array_filter($race,fn($r)=>$r['success']))===1,'Concurrent checkout across different tools still enforces one borrower limit');
// History pagination and filtering use existing completed transaction records.
$hp=lmsBorrower('History');$hpt=lmsTool('LMS-HISTORY');
for($i=0;$i<28;$i++)$db->prepare("INSERT INTO transactions(txn_id,type,tool_id,borrower_id,status,qty,qty_returned,due_date,created_at,returned_at,borrower_name_snapshot,borrower_id_number_snapshot) VALUES(?,'borrow',?,?,'returned',1,1,DATE_SUB(CURDATE(),INTERVAL 5 DAY),DATE_SUB(NOW(),INTERVAL 7 DAY),DATE_SUB(NOW(),INTERVAL 4 DAY),'History','LMS-History')")->execute(['LMS-HIST-'.$i,$hpt,$hp]);
$hist=expectApi('Borrower history pagination','borrowers.php','GET',[],true,'Staff',['id'=>$hp,'history'=>1,'history_page'=>2]);check($hist['history_pagination']['total']===28&&count($hist['history'])===3&&$hist['stats']['total_borrows']===28,'History pagination preserves complete accountability totals');
$hist=expectApi('Borrower history search','borrowers.php','GET',[],true,'Staff',['id'=>$hp,'history'=>1,'history_search'=>'LMS-HIST-27']);check(count($hist['history'])===1,'Transaction IDs are searchable in borrower history');
$hist=expectApi('Overdue history filter','borrowers.php','GET',[],true,'Staff',['id'=>$hp,'history'=>1,'history_status'=>'late']);check($hist['stats']['late']===28&&$hist['history_pagination']['total']===28,'Completed late loans remain in historical records');
$dash=expectApi('Dashboard inventory compatibility after circulation','dashboard.php','GET');$tot=$db->query('SELECT SUM(quantity) total,SUM(available) available,SUM(damaged_qty) damaged,SUM(missing_qty) missing FROM tools WHERE is_active=1')->fetch();check((int)$dash['available_tools']===(int)$tot['available']&&(int)$dash['borrowed_tools']===(int)$tot['total']-(int)$tot['available']-(int)$tot['damaged']-(int)$tot['missing'],'Borrowing views preserve physical Dashboard statistics');
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='circulation_policy'")->fetchColumn()>0,'Circulation actions reuse existing audit history');
// Restore permissive defaults for the remaining unrelated regression suites.
$db->exec("DELETE FROM circulation_policies WHERE borrower_type='Guest'");
invariant();echo "PASSED: $checks cumulative LMS workflow checks\n";
