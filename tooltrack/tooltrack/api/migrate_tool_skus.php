<?php
// Run once on each installation: php api/migrate_tool_skus.php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/sku_lib.php';
$db=getDB();
$before=$db->query('SELECT id,code FROM tools ORDER BY id')->fetchAll();
installToolSkuStorage($db);
$after=$db->query('SELECT id,code FROM tools ORDER BY id')->fetchAll();
if($before!==$after)throw new RuntimeException('Tool codes changed unexpectedly.');
echo "SKU storage ready. Existing tool codes unchanged.\n";
foreach($db->query('SELECT category_name,category_code,last_number FROM tool_sku_sequences ORDER BY category_name')->fetchAll() as $row){
    echo $row['category_name'].' = '.$row['category_code'].' (last '.$row['last_number'].")\n";
}