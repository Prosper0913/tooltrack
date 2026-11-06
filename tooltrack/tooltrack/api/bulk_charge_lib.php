<?php
// Bulk cases reuse existing case rows: one financial parent plus one item row per affected tool.
function bulkReady(PDO $db):bool {
 static $ready=null;return $ready??=(bool)$db->query("SHOW COLUMNS FROM replacement_requests LIKE 'parent_case_id'")->fetch();
}
function installBulkCharges(PDO $db):void {
 if(PHP_SAPI!=='cli')throw new LogicException('Run the CLI migration.');
 $columns=array_column($db->query('SHOW COLUMNS FROM replacement_requests')->fetchAll(),'Field');
 foreach(['parent_case_id'=>'INT NULL','assessed_unit_charge'=>'DECIMAL(12,2) NULL','line_subtotal'=>'DECIMAL(12,2) NULL','tool_code_snapshot'=>'VARCHAR(50) NULL'] as $name=>$sql)if(!in_array($name,$columns,true))$db->exec("ALTER TABLE replacement_requests ADD COLUMN $name $sql");
 $indexes=array_column($db->query('SHOW INDEX FROM replacement_requests')->fetchAll(),'Key_name');
 if(!in_array('idx_charge_parent',$indexes,true))$db->exec('ALTER TABLE replacement_requests ADD KEY idx_charge_parent(parent_case_id,id)');
}
function bulkItems(PDO $db,int $id):array {
 if(!bulkReady($db))return [];
 $q=$db->prepare("SELECT c.*,COALESCE(c.tool_code_snapshot,t.code) tool_code FROM replacement_requests c LEFT JOIN tools t ON t.id=c.tool_id WHERE c.parent_case_id=? ORDER BY c.id");$q->execute([$id]);$items=$q->fetchAll();
 foreach($items as &$item){$s=$db->prepare('SELECT s.*,t.txn_id return_reference,b.txn_id borrowing_reference,m.report_no missing_reference FROM replacement_incident_sources s LEFT JOIN transactions t ON t.id=s.return_transaction_id LEFT JOIN missing_reports m ON m.id=s.missing_report_id LEFT JOIN transactions b ON b.id=COALESCE(t.borrow_txn_id,m.borrow_txn_id) WHERE s.request_id=?');$s->execute([$item['id']]);$item['sources']=$s->fetchAll();}unset($item);
 return $items;
}
function bulkParent(PDO $db,array $r,bool $lock=false):array {
 return !empty($r['parent_case_id'])?rpRow($db,(int)$r['parent_case_id'],$lock):$r;
}
