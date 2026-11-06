<?php
// Real report SELECTs against connection-local tables; no stored records change.
if(PHP_SAPI!=='cli')exit;
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/includes/missing_lib.php';
require_once dirname(__DIR__).'/api/transaction_report_lib.php';
class DetailResult extends RuntimeException {public function __construct(public array $data){parent::__construct();}}
function detailOk($data){throw new DetailResult($data);}
function detailCheck($value,$label){if(!$value)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
$source=file_get_contents(dirname(__DIR__).'/api/reports.php');
$start=strpos($source,'$reportDefs = [');$end=strpos($source,'// Spreadsheet apps',$start);
eval(substr($source,$start,$end-$start));
$start=strpos($source,"    if(in_array(\$type,['transactions','overdue','missing'],true)){");$end=strpos($source,'    // On-screen preview:',$start);
$branch=str_replace(['ok([','ok(groupedTransactionReport('],['detailOk([','detailOk(groupedTransactionReport('],substr($source,$start,$end-$start));
$tables=['tools','borrowers','transactions','missing_reports','missing_report_events'];
$rewrite=function($sql)use($tables){foreach($tables as $table)$sql=preg_replace('/\b(FROM|JOIN)\s+'.preg_quote($table,'/').'\b/i','$1 detail_'.$table,$sql);return $sql;};
$branch=$rewrite($branch);
foreach(['transactions','overdue','missing'] as $type)$reportDefs[$type]['sql']=$rewrite($reportDefs[$type]['sql']);
function detailPage($type,$query=[]){global $db,$reportDefs,$branch;$def=$reportDefs[$type];$_GET=$query;try{eval($branch);}catch(DetailResult $r){return $r->data;}throw new RuntimeException('No result');}
$db=getDB();$created=[];
try{
 foreach($tables as $table){$db->exec("CREATE TEMPORARY TABLE detail_$table LIKE $table");$created[]=$table;}
 $db->exec("INSERT INTO detail_tools(id,name,code,category,quantity,available,min_stock) VALUES(1,'Baso','DETAIL-TOOL','Cookware',1000,500,0)");
 $db->exec("INSERT INTO detail_borrowers(id,full_name,id_number,type) VALUES(1,'Report Borrower','DETAIL-BORROWER','Faculty')");
 $loan=$db->prepare("INSERT INTO detail_transactions(id,txn_id,type,tool_id,borrower_id,qty,qty_returned,qty_missing,status,due_date,created_at) VALUES (?,?,'borrow',1,1,?,0,0,'active',DATE_SUB(CURDATE(),INTERVAL 10 DAY),DATE_SUB(NOW(),INTERVAL ? DAY))");
 $case=$db->prepare("INSERT INTO detail_missing_reports(report_no,request_token,borrow_txn_id,tool_id,borrower_id,qty_reported,qty_missing,missing_date,reason,status,reported_by_name,reported_at) VALUES (?,?,?,1,1,2,2,CURDATE(),'Test',?,'Admin',NOW())");
 $statuses=['pending','confirmed','resolved','rejected'];
 for($i=1;$i<=210;$i++){$loan->execute([$i,sprintf('DETAIL-%03d',$i),$i%11,$i]);$case->execute([sprintf('CASE-%03d',$i),'detail_case_token_'.$i,$i,$statuses[$i%4]]);}
 foreach(['transactions','overdue','missing'] as $type){
  $first=detailPage($type);detailCheck($first['total']===($type==='overdue'?191:210)&&count($first['rows'])===25,"$type paginates full matching dataset");
  $second=detailPage($type,['page'=>2]);$id=$second['rows'][0][0];
  $found=detailPage($type,['search'=>$id]);detailCheck($found['total']===1&&$found['rows'][0][0]===$id,"$type finds page-two record by ID");
  $sort=$type==='missing'?'Qty Reported':($type==='overdue'?'Qty Outstanding':'Qty Borrowed');
  $sorted=detailPage($type,['sort'=>$sort,'direction'=>'asc','per_page'=>100]);$index=array_search($sort,$sorted['columns']);$values=array_column($sorted['rows'],$index);$expected=$values;sort($expected,SORT_NUMERIC);
  detailCheck($values===$expected,"$type sorts quantities numerically");
  $empty=detailPage($type,['search'=>'no matching record']);detailCheck($empty['total']===0&&$empty['rows']===[],"$type supports empty results");
  detailCheck(count($db->query($reportDefs[$type]['sql'])->fetchAll(PDO::FETCH_NUM))===$first['total'],"$type CSV source query stays complete");
  detailCheck(count($first['actions'])===25,"$type retains row action metadata");
  $ordered=detailPage($type,['sort'=>$type==='missing'?'Reported At':($type==='overdue'?'Due Date':'Borrowed At'),'direction'=>'asc','per_page'=>100]);
  $dateIndex=array_search($type==='missing'?'Reported At':($type==='overdue'?'Due Date':'Borrowed At'),$ordered['columns']);
  $dates=array_column($ordered['rows'],$dateIndex);$expectedDates=$dates;sort($expectedDates,SORT_STRING);
  detailCheck($dates===$expectedDates,"$type sorts dates chronologically");
 }
 detailCheck(detailPage('transactions',['category'=>'overdue'])['total']===191&&detailPage('transactions',['category'=>'active'])['total']===210,'Transactions keeps active and computed overdue workflow filters');
 $missing=detailPage('missing',['per_page'=>100]);detailCheck(count($missing['categories'])===4,'Missing report retains pending, confirmed, resolved, and rejected case statuses');
 foreach(['DETAIL-TOOL','DETAIL-BORROWER','DETAIL-026'] as $identifier)detailCheck(detailPage('missing',['search'=>$identifier])['total']>0,'Missing report searches hidden identifier '.$identifier);
 $db->exec("UPDATE detail_missing_reports SET status='resolved',qty_replaced=2,qty_recovered=0 WHERE report_no='CASE-001'");
 $closed=detailPage('missing',['search'=>'CASE-001']);
 detailCheck((int)$closed['rows'][0][12]===0&&(int)$closed['rows'][0][11]===0&&(int)$closed['rows'][0][10]===2,'Historically closed units preserve zero still missing without recoveries');
 $csv=fopen('php://temp','w+');fputcsv($csv,$reportDefs['missing']['columns']);rewind($csv);$headers=fgetcsv($csv);fclose($csv);
 detailCheck(count($headers)===22&&!in_array('Actions',$headers,true),'Missing CSV has exactly 22 data headers and excludes Actions');
 $db->exec("UPDATE detail_transactions SET status='returned',returned_at=DATE_ADD(created_at,INTERVAL 1 DAY),qty_missing=5 WHERE id=1");
 $db->exec("INSERT INTO detail_transactions(id,txn_id,type,tool_id,borrower_id,qty,qty_returned,qty_missing,status,borrow_txn_id,returned_at,created_at) VALUES (211,'DETAIL-RETURN','return',1,1,5,0,0,'returned',1,NOW(),NOW())");
 $events=detailPage('transactions',['sort'=>'Transaction Date','direction'=>'asc','per_page'=>100]);
 $eventDates=array_map(fn($row)=>$row[1]==='borrow'?$row[10]:$row[9],$events['rows']);$expected=$eventDates;sort($expected,SORT_STRING);
 detailCheck($eventDates===$expected,'Transaction Date sorts by the event-specific underlying timestamp');
 $returned=detailPage('transactions',['search'=>'DETAIL-RETURN']);
 detailCheck($returned['rows'][0][1]==='return'&&$returned['rows'][0][9]!==null,'Return event retains its separate Returned At timestamp');
 $csv=fopen('php://temp','w+');fputcsv($csv,$reportDefs['transactions']['columns']);rewind($csv);$headers=fgetcsv($csv);fclose($csv);
 detailCheck(count($headers)===14&&!in_array('Transaction Date',$headers,true)&&$headers[9]==='Returned At'&&$headers[10]==='Borrowed At','Transaction CSV retains fourteen original fields and separate timestamps');
}finally{foreach(array_reverse($created) as $table)$db->exec("DROP TEMPORARY TABLE detail_$table");}
