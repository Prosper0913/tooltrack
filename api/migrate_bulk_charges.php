<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';require_once __DIR__.'/replacement_payments.php';
$db=getDB();if(($argv[1]??'')!=='--database='.DB_NAME)throw new RuntimeException('Confirm the configured development database using --database="'.DB_NAME.'"');
echo 'Selected database: '.$db->query('SELECT DATABASE()')->fetchColumn().PHP_EOL;
$tables=['tools','transactions','missing_reports','replacement_requests','replacement_incident_sources','replacement_payment_claims','stock_history'];
// Legacy resolution tables are fingerprinted only when they exist in this database.
foreach(['inventory_resolutions','inventory_resolution_receipts'] as $legacy)if($db->query("SHOW TABLES LIKE '$legacy'")->fetchColumn())$tables[]=$legacy;
$fingerprint=function(string $table,?array $columns=null)use($db):array{$columns=$columns??array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(),'Field');$h=hash_init('sha256');$q=$db->query('SELECT `'.implode('`,`',$columns)."` FROM `$table` ORDER BY id");while($row=$q->fetch())hash_update($h,json_encode($row,JSON_THROW_ON_ERROR));return [$columns,hash_final($h)];};
$before=[];foreach($tables as $table)$before[$table]=$fingerprint($table);
installBulkCharges($db);
foreach($before as $table=>[$columns,$hash])if(!hash_equals($hash,$fingerprint($table,$columns)[1]))throw new RuntimeException("$table changed during migration; review concurrent activity.");
echo "Bulk charge fields installed; existing cases, payments, incident allocations and inventory preserved.\n";
