<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';require_once __DIR__.'/replacement_payment_schema.php';
$db=getDB();
// Verify financial amounts and inventory records in addition to applying additive DDL.
$checks=[
    'tools'=>'*','transactions'=>'*','missing_reports'=>'*','inventory_adjustments'=>'*',
    'replacement_requests'=>'id,tool_id,reason,quantity_needed,status,responsible_borrower_id,missing_report_id',
];
if($db->query("SHOW COLUMNS FROM replacement_requests LIKE 'amount_due'")->fetch())
    $checks['replacement_requests'].=',workflow_mode,financial_status,amount_due,charge_version,payment_version';
if($db->query("SHOW TABLES LIKE 'replacement_payment_claims'")->fetch())
    $checks['replacement_payment_claims']='id,request_id,amount,receipt_number,payment_date,status,charge_version,submitted_by,submitted_by_name,verified_by,verified_by_name,verified_at,verification_notes';
$fingerprint=function(string $table,string $columns)use($db):string{
    $hash=hash_init('sha256');$q=$db->query("SELECT $columns FROM `$table` ORDER BY id");
    while($row=$q->fetch(PDO::FETCH_ASSOC))hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");
    return hash_final($hash);
};
$before=[];foreach($checks as $table=>$columns)$before[$table]=$fingerprint($table,$columns);
installReplacementPayments($db);
foreach($checks as $table=>$columns){
    if(!hash_equals($before[$table],$fingerprint($table,$columns)))
        throw new RuntimeException("Records in $table changed during migration or concurrent use. Review the safety backup before continuing.");
    echo "Verified preserved: $table\n";
}
echo "Damage & Loss Charges migration completed. Existing amounts, receipts and inventory preserved; no charges created.\n";
