<?php
// Executes the real Stock History read branch with a connection-local test ledger.
if(PHP_SAPI!=='cli')exit;
require_once dirname(__DIR__).'/api/bootstrap.php';
class StockTableResult extends RuntimeException {public function __construct(public array $data){parent::__construct();}}
function stockTableOk($data){throw new StockTableResult($data);}
function stockTableCheck($value,$label){if(!$value)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
$code=file_get_contents(dirname(__DIR__).'/api/reports.php');
$start=strpos($code,"if(\$type==='stock_history'){");$end=strpos($code,'// ONE clock',$start);
$branch=str_replace(['stockHistoryUnion()','ok(['],['$testUnion','stockTableOk(['],substr($code,$start,$end-$start));
$db=getDB();
$db->exec('CREATE TEMPORARY TABLE stock_table_test(event_id INT,tool_id INT,tool_name VARCHAR(100),sku VARCHAR(50),quantity INT,previous_stock INT,updated_stock INT,performed_by VARCHAR(100),remarks TEXT,created_at DATETIME,event VARCHAR(30))');
$testUnion='SELECT * FROM stock_table_test';
function stockPage($query=[]){global $db,$branch,$testUnion;$_GET=$query;$dl=false;$type='stock_history';try{eval($branch);}catch(StockTableResult $r){return $r->data;}throw new RuntimeException('No result');}
try{
 $insert=$db->prepare("INSERT INTO stock_table_test VALUES (?,?,?,?,?,70,85,'Admin','Delivery','2026-11-03 09:00:00','Stock Added')");
 for($i=1;$i<=210;$i++)$insert->execute([$i,$i,sprintf('Tool %03d',$i),sprintf('STK-%03d',$i),$i]);
 $first=stockPage();stockTableCheck(count($first['rows'])===25&&$first['total']===210,'Stock History defaults to 25 of 210 records');
 $second=stockPage(['page'=>2]);stockTableCheck($second['page']===2&&$second['rows'][0][3]==='STK-185','Next page advances exactly 25 records');
 $found=stockPage(['search'=>'STK-185','category'=>'Stock Added']);stockTableCheck($found['total']===1,'Search and event filter find a page-two record by code');
 $beyond=stockPage(['search'=>'STK-001']);stockTableCheck($beyond['total']===1,'Search includes records beyond the former preview cap');
 $sorted=stockPage(['sort'=>'Quantity','direction'=>'asc','per_page'=>100]);$values=array_map(fn($r)=>(int)$r[4],$sorted['rows']);stockTableCheck($values===range(1,100),'Quantities sort numerically before pagination');
 stockTableCheck($sorted['totals']['restocked_units']===22155,'Summary totals cover all matching records, not just the page');
 $empty=stockPage(['category'=>'Stock Removed']);stockTableCheck($empty['total']===0&&$empty['rows']===[],'Nonmatching event returns an empty page');
 $dates=stockPage(['date_from'=>'2026-11-04']);stockTableCheck($dates['total']===0,'Date filters remain effective');
}finally{$db->exec('DROP TEMPORARY TABLE stock_table_test');}
