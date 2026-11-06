<?php
// Additive, rerunnable MariaDB/XAMPP migration. Existing requests remain legacy and uncharged.
function installReplacementPayments(PDO $db): void {
    if(PHP_SAPI!=='cli')throw new LogicException('Run this migration from CLI.');
    $db->exec("CREATE TABLE IF NOT EXISTS borrow_submissions (
      request_token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
      payload_hash CHAR(64) NOT NULL,user_id INT NOT NULL,response_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $columns=array_column($db->query('SHOW COLUMNS FROM replacement_requests')->fetchAll(),'Field');
    $add=[
      'workflow_mode'=>"VARCHAR(20) NOT NULL DEFAULT 'legacy'",
      'financial_status'=>"VARCHAR(24) NOT NULL DEFAULT 'legacy'",
      'responsibility'=>"VARCHAR(24) NOT NULL DEFAULT 'unreviewed'",
      'incident_quantity'=>'INT NULL','resolved_quantity'=>'INT NOT NULL DEFAULT 0','repair_quantity'=>'INT NOT NULL DEFAULT 0','amount_due'=>'DECIMAL(12,2) NULL',
      'charge_version'=>'INT NOT NULL DEFAULT 0','payment_version'=>'INT NOT NULL DEFAULT 0',
      'needs_financial_review'=>'TINYINT(1) NOT NULL DEFAULT 0',
      'reviewed_by'=>'INT NULL','reviewed_by_name'=>'VARCHAR(120) NULL','reviewed_at'=>'DATETIME NULL',
      'financial_closed_at'=>'DATETIME NULL','submission_token'=>'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
      'requested_by_snapshot'=>'VARCHAR(120) NULL'];
    foreach($add as $name=>$definition)if(!in_array($name,$columns,true))$db->exec("ALTER TABLE replacement_requests ADD COLUMN $name $definition");
    $db->exec("UPDATE replacement_requests r LEFT JOIN users u ON u.id=r.requested_by SET r.requested_by_snapshot=COALESCE(u.name,'Unknown (legacy)') WHERE r.requested_by_snapshot IS NULL");
    $indexes=array_column($db->query('SHOW INDEX FROM replacement_requests')->fetchAll(),'Key_name');
    if(!in_array('uq_rr_submission',$indexes,true))$db->exec('ALTER TABLE replacement_requests ADD UNIQUE KEY uq_rr_submission(submission_token)');
    if(!in_array('idx_rr_financial',$indexes,true))$db->exec('ALTER TABLE replacement_requests ADD KEY idx_rr_financial(workflow_mode,financial_status,needs_financial_review)');
    // A deleted staff account must not erase a request or its financial evidence.
    $fk=$db->query("SELECT CONSTRAINT_NAME,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='replacement_requests' AND REFERENCED_TABLE_NAME='users'")->fetchAll();
    foreach($fk as $f)if($f['CONSTRAINT_NAME']==='fk_rr_requested_by'&&$f['DELETE_RULE']==='CASCADE')$db->exec('ALTER TABLE replacement_requests DROP FOREIGN KEY fk_rr_requested_by');
    $db->exec('ALTER TABLE replacement_requests MODIFY requested_by INT NULL');
    $count=$db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='replacement_requests' AND CONSTRAINT_NAME='fk_rr_requested_by'")->fetchColumn();
    if(!$count)$db->exec('ALTER TABLE replacement_requests ADD CONSTRAINT fk_rr_requested_by FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE SET NULL');
    $db->exec("CREATE TABLE IF NOT EXISTS replacement_incident_sources (
      id INT AUTO_INCREMENT PRIMARY KEY,request_id INT NOT NULL,return_transaction_id INT NULL,missing_report_id INT NULL,quantity INT NOT NULL,
      UNIQUE KEY uq_ris_return(request_id,return_transaction_id),UNIQUE KEY uq_ris_missing(request_id,missing_report_id),
      KEY idx_ris_return(return_transaction_id),KEY idx_ris_missing(missing_report_id),
      CONSTRAINT fk_ris_request FOREIGN KEY(request_id) REFERENCES replacement_requests(id),
      CONSTRAINT fk_ris_return FOREIGN KEY(return_transaction_id) REFERENCES transactions(id),
      CONSTRAINT fk_ris_missing FOREIGN KEY(missing_report_id) REFERENCES missing_reports(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS replacement_payment_claims (
      id INT AUTO_INCREMENT PRIMARY KEY,request_id INT NOT NULL,amount DECIMAL(12,2) NOT NULL,
      receipt_number VARCHAR(80) NOT NULL,payment_date DATE NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'pending',
      charge_version INT NOT NULL,submitted_by INT NULL,submitted_by_name VARCHAR(120) NOT NULL,submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      verified_by INT NULL,verified_by_name VARCHAR(120) NULL,verified_at DATETIME NULL,verification_notes VARCHAR(500) NULL,
      active_receipt VARCHAR(80) COLLATE utf8mb4_bin NULL,active_request_id INT NULL,
      UNIQUE KEY uq_rpc_receipt(active_receipt),UNIQUE KEY uq_rpc_active_request(active_request_id),KEY idx_rpc_request(request_id,id),
      CONSTRAINT fk_rpc_request FOREIGN KEY(request_id) REFERENCES replacement_requests(id),
      CONSTRAINT fk_rpc_submitter FOREIGN KEY(submitted_by) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT fk_rpc_verifier FOREIGN KEY(verified_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS replacement_request_events (
      id INT AUTO_INCREMENT PRIMARY KEY,request_id INT NOT NULL,action VARCHAR(40) NOT NULL,
      before_state LONGTEXT NULL,after_state LONGTEXT NULL,notes VARCHAR(500) NULL,user_id INT NULL,user_name VARCHAR(120) NOT NULL,user_role VARCHAR(24) NOT NULL,
      request_token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,payload_hash CHAR(64) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_rre_token(request_token),KEY idx_rre_request(request_id,id),
      CONSTRAINT fk_rre_request FOREIGN KEY(request_id) REFERENCES replacement_requests(id),
      CONSTRAINT fk_rre_actor FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $cols=array_column($db->query('SHOW COLUMNS FROM replacement_requests')->fetchAll(),'Field');
    if(!in_array('inventory_status',$cols,true))$db->exec("ALTER TABLE replacement_requests ADD COLUMN inventory_status VARCHAR(32) NOT NULL DEFAULT 'Pending Review'");
    $db->exec("UPDATE replacement_requests SET resolved_quantity=COALESCE(incident_quantity,quantity_needed) WHERE inventory_status='Resolved' AND resolved_quantity=0");
    $cols=array_column($db->query('SHOW COLUMNS FROM replacement_payment_claims')->fetchAll(),'Field');
    if(!in_array('payment_method',$cols,true))$db->exec("ALTER TABLE replacement_payment_claims ADD COLUMN payment_method VARCHAR(32) NOT NULL DEFAULT 'Cash', ADD COLUMN payment_notes VARCHAR(500) NULL");
    // Repeatable FPST upgrade: preserves records, balances, receipts and inventory.
    $cols=array_column($db->query('SHOW COLUMNS FROM replacement_requests')->fetchAll(),'Field');
    if(!in_array('charge_assessed_at',$cols,true))$db->exec('ALTER TABLE replacement_requests ADD COLUMN charge_assessed_at DATETIME NULL, ADD KEY idx_rr_assessed(charge_assessed_at)');
    $cols=array_column($db->query('SHOW COLUMNS FROM replacement_payment_claims')->fetchAll(),'Field');
    foreach(['verified_by_role'=>'VARCHAR(24) NULL','voided_by'=>'INT NULL','voided_by_name'=>'VARCHAR(120) NULL','voided_at'=>'DATETIME NULL','void_reason'=>'VARCHAR(500) NULL'] as $name=>$definition)
        if(!in_array($name,$cols,true))$db->exec("ALTER TABLE replacement_payment_claims ADD COLUMN $name $definition");
    $db->exec("UPDATE replacement_requests r SET charge_assessed_at=COALESCE((SELECT MIN(e.created_at) FROM replacement_request_events e WHERE e.request_id=r.id AND e.action='review_charge'),r.reviewed_at) WHERE r.workflow_mode='payment' AND r.amount_due IS NOT NULL AND r.charge_assessed_at IS NULL");
    $db->exec("UPDATE replacement_payment_claims SET active_request_id=NULL WHERE status<>'pending' AND active_request_id IS NOT NULL");
    $db->exec("UPDATE replacement_payment_claims p JOIN users u ON u.id=p.verified_by SET p.verified_by_role=u.role WHERE p.verified_by_role IS NULL AND p.verified_at IS NOT NULL");
    $indexes=array_column($db->query('SHOW INDEX FROM replacement_payment_claims')->fetchAll(),'Key_name');
    if(!in_array('idx_rpc_collection',$indexes,true))$db->exec('ALTER TABLE replacement_payment_claims ADD KEY idx_rpc_collection(status,payment_date,request_id)');

}
