<?php
// Dedicated mode of the existing isolated-database harness; never alters live inventory.
require_once dirname(__DIR__).'/api/sku_lib.php';installToolSkuStorage($db);
require_once dirname(__DIR__).'/includes/stock_lib.php';installStockHistory($db);
function dashboardSummary():array {return expectApi('Dashboard summary','dashboard.php','GET');}
function expectSummary(array $expected):void {
 $actual=dashboardSummary();foreach($expected as $key=>$value)check($actual[$key]===$value,"$key = $value");
 check($actual['total_units']===$actual['available_tools']+$actual['borrowed_tools']+$actual['damaged_tools']+$actual['missing_tools'],'Unit cards conserve inventory');
}
expectSummary(['total_tool_types'=>0,'total_units'=>0,'available_tools'=>0,'borrowed_tools'=>0,'damaged_tools'=>0,'missing_tools'=>0,'total_borrowers'=>1]);
for($i=0;$i<11;$i++)$db->prepare("INSERT INTO borrowers(full_name,id_number,type) VALUES(?,?,'Faculty')")->execute(['Dashboard fixture '.$i,'DASH-'.$i]);
$a=expectApi('Create first tool type','tools.php','POST',['name'=>'Dashboard fixture A','category'=>'Cookware','quantity'=>60,'min_stock'=>1,'price'=>0]);
$b=expectApi('Create second tool type','tools.php','POST',['name'=>'Dashboard fixture B','category'=>'Utensils','quantity'=>36,'min_stock'=>1,'price'=>0]);
$loan=expectApi('Borrow sixteen units','transactions.php','POST',['type'=>'borrow','tool_code'=>$a['code'],'borrower_id'=>1,'qty'=>16,'due_date'=>date('Y-m-d',strtotime('+1 day'))]);
expectApi('Confirm five missing units through existing return','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$loan['id'],'returnee_name'=>'Fixture borrower','request_token'=>'dashboard_missing_123456','items'=>['missing'=>5],'missing_reason'=>'Dashboard fixture loss']);
expectSummary(['total_tool_types'=>2,'total_units'=>96,'available_tools'=>80,'borrowed_tools'=>11,'damaged_tools'=>0,'missing_tools'=>5,'total_borrowers'=>12]);
expectApi('Add stock to an existing type','tools.php','POST',['action'=>'add_stock','id'=>$a['id'],'quantity'=>5,'request_token'=>'dashboard_restock_123456','remarks'=>'Dashboard test delivery']);
expectSummary(['total_tool_types'=>2,'total_units'=>101,'available_tools'=>85,'borrowed_tools'=>11,'damaged_tools'=>0,'missing_tools'=>5,'total_borrowers'=>12]);
$new=expectApi('Register another tool type','tools.php','POST',['name'=>'Dashboard fixture C','category'=>'Cookware','quantity'=>7,'min_stock'=>1,'price'=>0]);
expectSummary(['total_tool_types'=>3,'total_units'=>108,'available_tools'=>92,'borrowed_tools'=>11,'damaged_tools'=>0,'missing_tools'=>5,'total_borrowers'=>12]);
expectApi('Retire a tool type using existing filter','tools.php','PATCH',['id'=>$new['id'],'is_active'=>0]);
expectSummary(['total_tool_types'=>2,'total_units'=>101,'available_tools'=>85,'borrowed_tools'=>11,'damaged_tools'=>0,'missing_tools'=>5,'total_borrowers'=>12]);
expectApi('Return two damaged units','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$loan['id'],'returnee_name'=>'Fixture borrower','request_token'=>'dashboard_damaged_12345','items'=>['damaged'=>2]],true,'Staff');
expectSummary(['total_tool_types'=>2,'total_units'=>101,'available_tools'=>85,'borrowed_tools'=>9,'damaged_tools'=>2,'missing_tools'=>5,'total_borrowers'=>12]);
$q=$db->prepare("SELECT id,payment_version FROM replacement_requests WHERE tool_id=? AND reason='damaged' ORDER BY id DESC LIMIT 1");$q->execute([$a['id']]);$case=$q->fetch();
expectApi('Dispose affected units through existing resolution','replacement_requests.php','PATCH',['id'=>$case['id'],'version'=>$case['payment_version'],'action'=>'inventory_resolution','inventory_action'=>'dispose','notes'=>'Dashboard fixture disposal']);
expectSummary(['total_tool_types'=>2,'total_units'=>99,'available_tools'=>85,'borrowed_tools'=>9,'damaged_tools'=>0,'missing_tools'=>5,'total_borrowers'=>12]);
$html=file_get_contents(dirname(__DIR__).'/index.php');$start=strpos($html,'id="dashboardPage"');$end=strpos($html,'<div class="content-grid">',$start);$cards=substr($html,$start,$end-$start);
check(substr_count($cards,'class="stat-card"')===7,'Exactly seven Dashboard summary cards');
foreach(['Total Tool Types','Total Units','Available Units','Borrowed Units','Damaged Units','Missing Units','Total Borrowers'] as $label)check(substr_count($cards,'>'.$label.'</div>')===1,'One card for '.$label);
invariant();echo "PASSED: $checks dashboard summary checks\n";
