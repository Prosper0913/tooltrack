<?php
// Exercises the actual inventory API branch against connection-local temporary tools.
if(PHP_SAPI!=='cli')exit;
require_once dirname(__DIR__).'/api/bootstrap.php';
class InventoryReportResult extends RuntimeException {public function __construct(public array $data){parent::__construct();}}
function inventoryReportOk($data){throw new InventoryReportResult($data);}
function verifyInventory($value,$label){if(!$value)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
$source=file_get_contents(dirname(__DIR__).'/api/reports.php');
$start=strpos($source,"    'inventory' => [",strpos($source,'$reportDefs = ['));
$end=strpos($source,'    // Date-added',$start);
$defs=eval('return ['.substr($source,$start,$end-$start).'];');$def=$defs['inventory'];
$start=strpos($source,"    if(\$type==='inventory'){");$end=strpos($source,'    // On-screen preview:',$start);$branch=str_replace('ok([','inventoryReportOk([',substr($source,$start,$end-$start));
function inventoryPage($db,$def,$branch,$query=[]){$_GET=$query;$type='inventory';try{eval($branch);}catch(InventoryReportResult $result){return $result->data;}throw new RuntimeException('No API result');}
$def['sql']=str_replace('FROM tools','FROM inventory_report_test_tools',$def['sql']);
$branch=str_replace('FROM tools','FROM inventory_report_test_tools',$branch);
$db=getDB();
$db->exec('CREATE TEMPORARY TABLE inventory_report_test_tools LIKE tools');
try{
 $insert=$db->prepare('INSERT INTO inventory_report_test_tools(name,code,category,quantity,available,min_stock,description) VALUES (?,?,?,?,?,?,?)');
 for($i=1;$i<=210;$i++)$insert->execute([sprintf('Report Tool %03d',$i),sprintf('RPT-%03d',$i),$i%2?'Cookware':'Utensils',200,$i%111,0,'Report test only']);
 $page=inventoryPage($db,$def,$branch);
 verifyInventory(count($page['rows'])===25&&$page['total']===210,'Default page has 25 of 210 tools');
 $second=inventoryPage($db,$def,$branch,['page'=>2]);
 verifyInventory($second['rows'][0][1]==='RPT-026','Second page starts at tool 26');
 $found=inventoryPage($db,$def,$branch,['search'=>'RPT-026']);
 verifyInventory($found['total']===1&&$found['rows'][0][0]==='Report Tool 026','Code search finds a tool originally on page two');
 $beyond=inventoryPage($db,$def,$branch,['search'=>'RPT-205']);
 verifyInventory($beyond['total']===1,'Search covers records beyond the former 200-row cap');
 $sorted=inventoryPage($db,$def,$branch,['sort'=>'Available','direction'=>'desc','per_page'=>100]);
 $values=array_map(fn($r)=>(int)$r[9],$sorted['rows']);$expected=$values;rsort($expected,SORT_NUMERIC);
 verifyInventory($values===$expected&&$values[0]===110,'Available sorts numerically across full inventory');
 $ascending=inventoryPage($db,$def,$branch,['sort'=>'Available','per_page'=>100]);
 $values=array_map(fn($r)=>(int)$r[9],$ascending['rows']);
 verifyInventory(array_search(2,$values,true)<array_search(10,$values,true),'Ascending Available puts 2 before 10');
 $combined=inventoryPage($db,$def,$branch,['search'=>'RPT-026','category'=>'Utensils']);
 $excluded=inventoryPage($db,$def,$branch,['search'=>'RPT-026','category'=>'Cookware']);
 verifyInventory($combined['total']===1&&$excluded['total']===0,'Name/code search and category are applied together');
 $category=inventoryPage($db,$def,$branch,['category'=>'Utensils','per_page'=>50]);
 verifyInventory($category['total']===105&&count($category['rows'])===50&&count(array_filter($category['rows'],fn($r)=>$r[2]!=='Utensils'))===0,'Category filter has accurate total before pagination');
 $empty=inventoryPage($db,$def,$branch,['search'=>'no such tool']);
 verifyInventory($empty['total']===0&&$empty['rows']===[],'No-match response has zero tools');
 $all=$db->query($def['sql'])->fetchAll(PDO::FETCH_NUM);
 verifyInventory(count($all)===210&&count($all[0])===12,'Unchanged CSV query includes all 210 tools and twelve fields');
}finally{$db->exec('DROP TEMPORARY TABLE inventory_report_test_tools');}
