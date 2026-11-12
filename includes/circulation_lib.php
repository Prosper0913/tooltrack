<?php
require_once __DIR__.'/borrow_session_lib.php';
class CirculationError extends RuntimeException {}
function circulationReady(PDO $db):bool {
    static $ready=null;return $ready??=(bool)$db->query("SHOW TABLES LIKE 'circulation_policies'")->fetchColumn();
}
function installCirculation(PDO $db):void {
    if(PHP_SAPI!=='cli')throw new LogicException('Run the CLI migration.');
    $db->exec("CREATE TABLE IF NOT EXISTS circulation_policies (
        borrower_type VARCHAR(20) PRIMARY KEY,max_active_quantity INT NULL,max_loan_days INT NULL,
        allow_overdue TINYINT NOT NULL DEFAULT 1,
        updated_by INT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("INSERT IGNORE INTO circulation_policies(borrower_type) VALUES('*')");
}
function circulationPolicy(PDO $db,string $type):array {
    if(!circulationReady($db))return ['max_active_quantity'=>null,'max_loan_days'=>null,'allow_overdue'=>1];
    $q=$db->prepare("SELECT * FROM circulation_policies WHERE borrower_type IN ('*',?) ORDER BY (borrower_type='*') LIMIT 1");$q->execute([$type]);return $q->fetch();
}
function circulationEligibility(PDO $db,array $borrower,int $quantity,string $due,array $body=[],?string $start=null,bool $checkDuration=true):void {
    if(!(int)$borrower['is_active'])throw new CirculationError('This borrower is inactive and cannot borrow tools.');
    $now=$db->query('SELECT NOW()')->fetchColumn();$start=$start??$now;
    if($due<$now)throw new CirculationError('The due date and time must be in the future.');
    $policy=circulationPolicy($db,$borrower['type']);
    $q=$db->prepare("SELECT COALESCE(SUM(".sqlOutstanding('t')."),0) quantity,COALESCE(SUM(".sqlIsOverdue('t')."),0) overdue FROM transactions t WHERE borrower_id=? AND type='borrow' AND status='active'");$q->execute([$borrower['id']]);$active=$q->fetch();$reasons=[];
    if($policy['max_active_quantity']!==null&&(int)$active['quantity']+$quantity>(int)$policy['max_active_quantity'])$reasons[]='Borrowing limit reached: '.$active['quantity'].' active unit(s), limit '.$policy['max_active_quantity'].'.';
    if(!(int)$policy['allow_overdue']&&(int)$active['overdue']>0)$reasons[]='This borrower has overdue tools. Return or resolve them before borrowing again.';
    if($checkDuration&&$policy['max_loan_days']!==null&&strtotime($due)>strtotime($start.' +'.(int)$policy['max_loan_days'].' days'))$reasons[]='Maximum loan duration is '.$policy['max_loan_days'].' day(s).';
    if($reasons){
        $override=trim((string)($body['override_reason']??''));
        if(($_SESSION['user_role']??'')!==ROLE_ADMIN||mb_strlen($override)<3)throw new CirculationError(implode(' ',$reasons).' Admin may override with a reason.');
        logAudit('borrowing_override','Borrower #'.$borrower['id'].'; '.implode(' ',$reasons).'; reason: '.mb_substr($override,0,500));
    }
}
function circulationCheckout(PDO $db,array $body,int $borrowerId,array $lines,string $due):void {
    $q=$db->prepare('SELECT * FROM borrowers WHERE id=? FOR UPDATE');$q->execute([$borrowerId]);$borrower=$q->fetch();
    if(!$borrower)throw new CirculationError('Borrower not found.');
    circulationEligibility($db,$borrower,array_sum(array_column($lines,1)),$due,$body);
}
