<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';require_once __DIR__.'/category_lib.php';
$db=getDB();$before=$db->query('SELECT id,code FROM tools ORDER BY id')->fetchAll();
installToolCategoryStorage($db);
if($before!==$db->query('SELECT id,code FROM tools ORDER BY id')->fetchAll())throw new RuntimeException('Existing tool codes changed unexpectedly.');
echo "Category management ready. Existing tool codes unchanged.\n";