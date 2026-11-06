<?php
if(PHP_SAPI!=='cli'||!isset($db,$work))exit;
if(!preg_match('/^tooltrack_test_[a-f0-9]{12}$/',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Disposal tests require an isolated database.');
require_once dirname(__DIR__).'/api/tool_disposal_lib.php';installToolDisposals($db);
function disposeApi(array $body,bool $ok=true,string $role='Admin'):array {
 return expectApi('Dispose damaged tool','tools.php','POST',array_merge(['action'=>'dispose_damaged','request_token'=>'dispose_'.bin2hex(random_bytes(12))],$body),$ok,$role);
}
[$dt,$dl,$dc]=chargeFixture('DISPOSAL-PARTIAL');$before=row('tools',$dt);$caseBefore=row('replacement_requests',$dc);
disposeApi(['id'=>$dt,'quantity'=>1,'reason'=>'Staff is not authorized','case_id'=>$dc],false,'Staff');
disposeApi(['id'=>$dt,'quantity'=>0,'reason'=>'Invalid quantity'],false);
disposeApi(['id'=>$dt,'quantity'=>4,'reason'=>'Would include available stock'],false);
disposeApi(['id'=>$dt,'quantity'=>1,'reason'=>''],false);
[$otherTool,$otherLoan,$otherCase]=chargeFixture('DISPOSAL-WRONG-CASE');
disposeApi(['id'=>$dt,'quantity'=>1,'reason'=>'Wrong case','case_id'=>$otherCase],false);
check(row('tools',$dt)===$before&&row('replacement_requests',$dc)===$caseBefore,'Unauthorized, invalid, and mismatched disposal attempts change no records');
$body=['action'=>'dispose_damaged','id'=>$dt,'quantity'=>2,'reason'=>'Beyond repair after inspection','case_id'=>$dc,'request_token'=>'dispose_race_'.bin2hex(random_bytes(12))];
$results=concurrentApi('tools.php','POST',$body);
check(count(array_filter($results,fn($r)=>$r['success']))===2,'Concurrent duplicate disposal safely returns the original result');
$after=row('tools',$dt);$disposals=$db->query("SELECT * FROM tool_disposals WHERE tool_id=$dt ORDER BY id")->fetchAll();
check((int)$after['quantity']===(int)$before['quantity']-2&&(int)$after['damaged_qty']===(int)$before['damaged_qty']-2&&(int)$after['available']===(int)$before['available']&&(int)$after['missing_qty']===(int)$before['missing_qty']&&$after['code']===$before['code'],'Partial disposal removes only damaged quantity and preserves available, missing and SKU');
check(count($disposals)===1&&(int)$disposals[0]['quantity']===2&&$disposals[0]['reason']==='Beyond repair after inspection'&&(int)$disposals[0]['case_id']===$dc&&(int)$disposals[0]['stock_history_id']>0,'Disposal ledger preserves reason, case and linked stock history');
check((int)$disposals[0]['disposed_by']===1&&$disposals[0]['disposed_by_name']==='Test Actor'&&!empty($disposals[0]['created_at'])&&(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='tool_dispose' AND description LIKE '%disposal #{$disposals[0]['id']}%'")->fetchColumn()===1,'Disposal date, staff identity and audit event are recorded');
$stock=$db->query('SELECT * FROM stock_history WHERE id='.(int)$disposals[0]['stock_history_id'])->fetch();
check($stock['event_kind']==='disposal'&&$stock['transaction_type']==='Stock Removed'&&(int)$stock['previous_stock']===(int)$before['quantity']&&(int)$stock['updated_stock']===(int)$after['quantity']&&$stock['tool_code']===$before['code'],'Stock history records the exact quantity and original SKU');
check(row('replacement_requests',$dc)===$caseBefore,'Disposal does not change the linked charge case');
$again=expectApi('Repeat same disposal token','tools.php','POST',$body,true,'Admin');check(!empty($again['duplicate'])&&row('tools',$dt)===$after,'Repeated request does not decrement inventory twice');
$changed=$body;$changed['quantity']=1;expectApi('Changed disposal payload under same token','tools.php','POST',$changed,false);
disposeApi(['id'=>$dt,'quantity'=>2,'reason'=>'Too many damaged units after partial disposal'],false);
$history=expectApi('Disposed tools filtered history','tools.php','GET',[],true,'Admin',['disposed_history'=>1,'search'=>'DISPOSAL-PARTIAL']);
check($history['total']===1&&$history['rows'][0]['tool_code_snapshot']===$before['code']&&$history['rows'][0]['performed_by']==='Test Actor'&&str_starts_with((string)$history['rows'][0]['borrower_name'],'Test Borrower')&&(int)$history['rows'][0]['linked_case_id']===$dc,'Disposed history filter returns actor, borrower, SKU and linked case accurately');
expectApi('Staff cannot read disposal history','tools.php','GET',[],false,'Staff',['disposed_history'=>1]);
$stockHistory=expectApi('Disposal appears in tool stock history','tools.php','GET',[],true,'Admin',['stock_history'=>1,'tool_id'=>$dt]);
check(count(array_filter($stockHistory,fn($r)=>str_contains((string)$r['remarks'],'Beyond repair after inspection')))>0,'Disposal reason remains visible in stock history');
$countBefore=(int)$db->query("SELECT COUNT(*) FROM tool_disposals WHERE tool_id=$dt")->fetchColumn();
$db->exec("CREATE TRIGGER disposal_fail BEFORE INSERT ON tool_disposals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Disposal history unavailable'");
disposeApi(['id'=>$dt,'quantity'=>1,'reason'=>'Must roll back'],false);
$db->exec('DROP TRIGGER disposal_fail');
check(row('tools',$dt)===$after&&(int)$db->query("SELECT COUNT(*) FROM tool_disposals WHERE tool_id=$dt")->fetchColumn()===$countBefore,'Failed disposal ledger write rolls back inventory and history');
invariant();check(true,'Damaged disposal preserves inventory conservation');
