<?php
// A real multi-page dataset, created only in the isolated workflow schema.
require_once dirname(__DIR__).'/api/sku_lib.php';installToolSkuStorage($db);
require_once dirname(__DIR__).'/includes/stock_lib.php';installStockHistory($db);
$person=expectApi('Register grouped-report borrower','borrowers.php','POST',['full_name'=>'Grouped Report Fixture','id_number'=>'GROUP-REPORT','type'=>'Faculty'],true,'Staff');
$tools=[];foreach(['Alpha','Beta','Gamma'] as $name)$tools[]=expectApi('Register report '.$name,'tools.php','POST',['name'=>'Grouped Report '.$name,'category'=>'Cookware','quantity'=>200,'min_stock'=>1,'price'=>5]);
$sessions=[];
for($i=1;$i<=28;$i++)$sessions[]=expectApi('Confirm separate three-tool checkout '.$i,'transactions.php','POST',['type'=>'borrow_batch','borrower_id'=>$person['id'],'due_date'=>date('Y-m-d',strtotime('+2 days')),'due_time'=>'17:30','request_token'=>'grouped_report_page_'.str_pad((string)$i,4,'0',STR_PAD_LEFT),'items'=>[['tool_code'=>$tools[0]['code'],'qty'=>1+$i%3],['tool_code'=>$tools[1]['code'],'qty'=>1],['tool_code'=>$tools[2]['code'],'qty'=>1]]],true,'Staff');
$report=fn(array $q=[])=>expectApi('Read grouped transaction report','reports.php','GET',[],true,'Staff',['type'=>'transactions','search'=>'Grouped Report Fixture',...$q]);
$page1=$report();$page2=$report(['page'=>2]);
check($page1['grouped']&&$page1['total']===28&&count($page1['rows'])===25&&count($page2['rows'])===3&&$page1['line_total']===84,'Pagination counts 28 transactions rather than 84 tool lines');
check(!array_intersect(array_column($page1['groups'],'key'),array_column($page2['groups'],'key'))&&array_sum(array_map(fn($g)=>count($g['items']),$page1['groups']))===75&&array_sum(array_map(fn($g)=>count($g['items']),$page2['groups']))===9,'Every checkout stays whole and appears on only one page');
check(count(array_unique(array_column($sessions,'txn_id')))===28,'Separate Confirm Borrow actions retain separate numbers');
$toolSearch=$report(['search'=>$tools[0]['name']]);check($toolSearch['total']===28&&count($toolSearch['groups'][0]['items'])===3,'Matching one tool returns every tool in its checkout');
$sorted=$report(['sort'=>'Qty Borrowed','direction'=>'asc','per_page'=>100]);$values=array_map('intval',array_column($sorted['rows'],array_search('Qty Borrowed',$sorted['columns'],true)));$expected=$values;sort($expected,SORT_NUMERIC);check($values===$expected,'Quantity sorting uses transaction sums');
$byNumber=$report(['sort'=>'TXN ID','direction'=>'asc','per_page'=>100]);$numbers=array_column($byNumber['rows'],0);$expected=$numbers;sort($expected,SORT_STRING);check($numbers===$expected,'TXN ID sorting uses displayed header numbers');
$byDate=$report(['sort'=>'Transaction Date','direction'=>'asc','per_page'=>100]);$dates=array_column($byDate['rows'],10);$expected=$dates;sort($expected,SORT_STRING);check($dates===$expected,'Transaction Date sorts grouped checkout dates');
$clamped=$report(['page'=>999]);check($clamped['page']===2&&count($clamped['groups'])===3,'Out-of-range transaction pages clamp gracefully');
$empty=$report(['search'=>'No such grouped transaction']);check($empty['total']===0&&$empty['rows']===[]&&$empty['groups']===[],'Empty grouped results preserve a valid response');
$first=$sessions[0];$ids=array_column($first['items'],'id');
$returned=expectApi('Return only Alpha with one damaged unit','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$ids[0],'returnee_name'=>'Grouped report fixture','request_token'=>'grouped_report_return_alpha','items'=>['good'=>1,'damaged'=>1]],true,'Staff');
$partial=$report(['search'=>$first['txn_id'],'category'=>'active']);
check($partial['total']===1&&count($partial['groups'][0]['items'])===3&&$partial['actions'][0]['status']==='active','One returned detail cannot close the report header');
$items=$partial['groups'][0]['items'];check($items[0]['action']['status']==='returned'&&$items[1]['action']['status']==='active'&&$items[0]['qty_damaged']===1&&$items[1]['qty_damaged']===0,'Return status and damaged quantities stay on the affected detail');
check($report(['search'=>$first['txn_id'],'category'=>'returned'])['total']===0,'Closed filter excludes partial checkouts');
$event=$report(['search'=>$returned['txn_id']]);$item=$event['groups'][0]['items'][0];
check($event['total']===1&&$event['line_total']===2&&count($event['groups'][0]['items'])===1&&$item['qty_damaged']===1&&(int)$item['values'][12]===2,'A split-condition return stays one event with one accurate tool detail');
check($event['rows'][0][0]===$returned['txn_id']&&$item['borrow_line_id']===$ids[0]&&$item['borrow_txn_id']===$first['txn_id']&&count($item['event_ids'])===2,'Return event retains its first public ID and exact original loan link');
$missing=expectApi('Report missing Beta','missing_tools.php','POST',['action'=>'report','borrow_txn_id'=>$ids[1],'qty'=>1,'missing_date'=>date('Y-m-d'),'reason'=>'Grouped report fixture','request_token'=>'grouped_report_missing_beta'],true,'Staff');
expectApi('Confirm missing Beta','missing_tools.php','POST',['action'=>'confirm','id'=>$missing['id'],'qty'=>1,'notes'=>'Fixture']);
$loss=$report(['search'=>$first['txn_id'],'category'=>'missing']);check($loss['total']===1&&$loss['groups'][0]['items'][1]['qty_missing']===1&&$loss['groups'][0]['items'][2]['qty_missing']===0&&$loss['actions'][0]['status']==='active','Confirmed missing quantity is item-specific while Gamma keeps checkout active');
expectApi('Return remaining Gamma','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$ids[2],'returnee_name'=>'Grouped fixture','request_token'=>'grouped_report_return_gamma','items'=>['good'=>1]],true,'Staff');
$closed=$report(['search'=>$first['txn_id'],'category'=>'returned']);check($closed['total']===1&&$closed['actions'][0]['status']==='returned'&&$closed['actions'][0]['qty_missing']===1&&$closed['actions'][0]['open_missing_cases']>0,'Closing circulation retains missing accountability in the group');
check($report(['category'=>'active'])['total']===27,'Status filtering counts remaining open checkout headers');
// Same borrower and timestamp are intentionally insufficient evidence for grouping old records.
$db->prepare('UPDATE tools SET available=available-2 WHERE id=?')->execute([$tools[0]['id']]);
foreach(['LEGACY-GROUP-A','LEGACY-GROUP-B'] as $id)$db->prepare("INSERT INTO transactions(txn_id,type,tool_id,borrower_id,status,qty,due_date,created_at) VALUES(?,'borrow',?,?,'active',1,CURDATE(),'2025-10-01 10:00:00')")->execute([$id,$tools[0]['id'],$person['id']]);
$legacy=$report(['search'=>'LEGACY-GROUP-']);check($legacy['total']===2&&count($legacy['groups'][0]['items'])===1&&count($legacy['groups'][1]['items'])===1,'Ambiguous historical checkouts with different IDs remain separate');
$snapshot=fn()=>hash('sha256',json_encode([$db->query('SELECT * FROM tools ORDER BY id')->fetchAll(),$db->query('SELECT * FROM transactions ORDER BY id')->fetchAll()]));
$before=$snapshot();$report();$report(['category'=>'missing']);$report(['sort'=>'Status','direction'=>'desc']);check($snapshot()===$before,'Report reads do not modify stock or transaction records');
// Exercise the real CSV endpoint, not a reconstruction of its SELECT.
file_put_contents($work.'/grouped-report-router.php', <<<'PHP'
<?php
ini_set('session.save_path',__DIR__);require __DIR__.'/bootstrap.php';
$_SESSION=['user_id'=>2,'user_role'=>'Staff','user_name'=>'Test Staff'];require __DIR__.'/reports.php';
PHP);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
$server=proc_open([PHP_BINARY,'-d','display_errors=0','-S',$address,$work.'/grouped-report-router.php'],[0=>['pipe','r'],1=>['file',$work.'/report-http.log','a'],2=>['file',$work.'/report-http-error.log','a']],$pipes);fclose($pipes[0]);
try{
 for($i=0;$i<50;$i++){$s=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($s){fclose($s);break;}usleep(20000);}
 $csv=file_get_contents('http://'.$address.'/?type=transactions&download=1');$stream=fopen('php://temp','r+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);$headers=fgetcsv($stream);$rows=[];while(($row=fgetcsv($stream))!==false)$rows[]=$row;fclose($stream);
 check(count($headers)===14&&count($rows)===(int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn(),'CSV preserves all fourteen fields and every original tool/condition row');
 $exported=array_values(array_filter($rows,fn($r)=>$r[0]===$first['txn_id']));check(count($exported)===3&&array_sum(array_column($exported,11))===4,'CSV keeps each shared-TXN tool and its borrowed quantity');
}finally{proc_terminate($server);proc_close($server);}
invariant();echo "PASSED: $checks grouped transaction report checks\n";
