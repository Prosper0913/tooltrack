<?php
// Actual API/read models in the existing disposable-schema harness.
[$tool,$loan]=loan('INCIDENT-UI');$missing=(int)report($loan,4,'incident_ui_report_123')['id'];
expectApi('Confirm incident fixture','missing_tools.php','POST',['action'=>'confirm','id'=>$missing,'qty'=>4]);
$db->prepare('UPDATE missing_reports SET missing_date=DATE_SUB(CURDATE(),INTERVAL 20 DAY) WHERE id=?')->execute([$missing]);
$request=expectApi('Staff request for pending tab','writeoffs.php','POST',['source'=>'missing','id'=>$missing,'action'=>'request','quantity'=>2,'reason'=>'Search completed','notes'=>'Checked rooms'],true,'Staff');
$manual=expectApi('Damaged incident fixture','replacement_requests.php','POST',['action'=>'manual_incident','tool_code'=>'INCIDENT-UI','borrower_identifier'=>'20260001','reason'=>'damaged','quantity_needed'=>2,'notes'=>'Inspection found damage'])['id'];
$summary=expectApi('Tracking summary for staff','charge_cases.php','GET',[],true,'Staff',['summary'=>1]);
check($summary['active_missing_units']===4&&$summary['damaged_units']===2&&$summary['pending_requests']===1&&$summary['review_cases']===1,'Summary distinguishes physical units, requests and review cases');
$active=expectApi('Active tab','charge_cases.php','GET',[],true,'Staff',['tab'=>'active']);check($active['total']===2,'Active includes both open incident types');
$pending=expectApi('Pending tab with search and incident filter','charge_cases.php','GET',[],true,'Staff',['tab'=>'pending','search'=>'INCIDENT-UI','incident'=>'lost']);check($pending['total']===1&&(int)$pending['rows'][0]['remaining_units']===4,'Pending is separate from incident outcome and preserves remaining quantity');
$none=expectApi('Conflicting combined filter','charge_cases.php','GET',[],true,'Staff',['tab'=>'pending','incident'=>'damaged']);check($none['total']===0,'Tab, search and incident filters intersect');
$closed=expectApi('Initially closed tab empty','charge_cases.php','GET',[],true,'Staff',['tab'=>'closed']);check($closed['total']===0,'Closed excludes active incidents');
expectApi('Approve partial loss','writeoffs.php','POST',['source'=>'missing','id'=>$missing,'action'=>'approve','writeoff_id'=>$request['writeoff_id'],'expected_total'=>20,'expected_available'=>8]);
$summary=expectApi('Summary after partial write-off','charge_cases.php','GET',[],true,'Staff',['summary'=>1]);check($summary['active_missing_units']===2&&$summary['damaged_units']===2&&$summary['pending_requests']===0,'Summary excludes written-off units while preserving damage');
$partial=expectApi('Partial case lifecycle','charge_cases.php','GET',[],true,'Staff',['tab'=>'active','inventory'=>'Partially Closed']);check($partial['total']===1&&(int)$partial['rows'][0]['remaining_units']===2&&(int)$partial['rows'][0]['age_days']===20,'Partial closure retains missing units and original age');
expectApi('Recover remaining units','missing_tools.php','POST',['action'=>'recover','id'=>$missing,'qty'=>2,'to'=>'good']);
$closed=expectApi('Closed tab after mixed closure','charge_cases.php','GET',[],true,'Staff',['tab'=>'closed','search'=>'INCIDENT-UI']);
check($closed['total']===1&&$closed['rows'][0]['inventory_status']==='Closed — Mixed Outcome'&&(int)$closed['rows'][0]['remaining_units']===0&&!empty($closed['rows'][0]['closed_at']),'Closed cases expose outcome, zero balance and closure timestamp');
check((int)$closed['rows'][0]['needs_review']===0,'Closed incidents no longer need active review');
$tools=expectApi('Inventory history quantities','tools.php','GET',[],true,'Staff',['search'=>'INCIDENT-UI']);check((int)$tools[0]['gross_written_off']===2&&(int)$tools[0]['later_loss_recoveries']===0&&(int)$tools[0]['quantity']===18,'Tool read model separates historical loss from active stock');
$details=expectApi('Damage details lifecycle','replacement_requests.php','GET',[],true,'Staff',['details'=>$manual]);check(isset($details['lifecycle']['age_days'])&&(int)$details['request']['unresolved_quantity']===2,'Modal uses backend lifecycle and damaged balance');
for($i=0;$i<28;$i++)$db->prepare("INSERT INTO replacement_requests(tool_id,tool_name_snapshot,reason,quantity_needed,incident_quantity,notes,workflow_mode,inventory_status,financial_status) VALUES(?,?,'damaged',1,1,'Pagination fixture','tracking','Pending Review','not_applicable')")->execute([$tool,'Pagination Fixture']);
$second=expectApi('Combined tab pagination and sort','charge_cases.php','GET',[],true,'Staff',['tab'=>'active','search'=>'Pagination Fixture','incident'=>'damaged','page'=>2,'sort'=>'oldest']);check($second['total']===28&&$second['page']===2&&count($second['rows'])===3,'Filtering and pagination use matching row count');
$all=expectApi('All tab','charge_cases.php','GET',[],true,'Staff',['tab'=>'all']);check($all['total']===30,'All includes open and closed cases');
expectApi('Unknown tab refused','charge_cases.php','GET',[],false,'Staff',['tab'=>'invalid']);
expectApi('Existing missing CSV/report source','reports.php','GET',[],true,'Staff',['type'=>'missing']);
expectApi('Existing inventory report source','reports.php','GET',[],true,'Staff',['type'=>'inventory']);
echo "PASSED: $checks incident UI API checks\n";
