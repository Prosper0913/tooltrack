<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/tool_disposal_lib.php';
$db=getDB();
$columns=array_column($db->query('SHOW COLUMNS FROM replacement_requests')->fetchAll(),'Field');
foreach(['resolved_quantity','repair_quantity'] as $column)if(!in_array($column,$columns,true))$db->exec("ALTER TABLE replacement_requests ADD COLUMN $column INT NOT NULL DEFAULT 0");
$db->exec("UPDATE replacement_requests SET resolved_quantity=COALESCE(incident_quantity,quantity_needed) WHERE inventory_status='Resolved' AND resolved_quantity=0");
$db->exec("CREATE TABLE IF NOT EXISTS borrow_submissions (
 request_token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 payload_hash CHAR(64) NOT NULL,user_id INT NOT NULL,response_json LONGTEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
installToolDisposals($db);
echo "Inventory consistency schema ready. Existing loans and return evidence preserved.\n";
