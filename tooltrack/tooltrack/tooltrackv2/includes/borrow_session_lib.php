<?php
// A checkout header owns the public number; existing transaction row IDs remain loan-line IDs.
function installBorrowSessions(PDO $db): void {
    if(PHP_SAPI!=='cli')throw new LogicException('Run the borrow-session migration from CLI.');
    $db->exec("CREATE TABLE IF NOT EXISTS borrow_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        txn_id VARCHAR(30) NOT NULL UNIQUE,
        borrower_id INT NULL, borrower_name_snapshot VARCHAR(150) NOT NULL,
        borrower_id_number_snapshot VARCHAR(50) NOT NULL,
        created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_borrow_session_borrower(borrower_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns=array_column($db->query('SHOW COLUMNS FROM transactions')->fetchAll(),'Field');
    if(!in_array('borrow_session_id',$columns,true))$db->exec('ALTER TABLE transactions ADD COLUMN borrow_session_id BIGINT UNSIGNED NULL, ADD KEY idx_borrow_session_line(borrow_session_id,id)');
    // Preserve every old number. Only its uniqueness moves to the new header table.
    $indexes=[];foreach($db->query('SHOW INDEX FROM transactions')->fetchAll() as $index)$indexes[$index['Key_name']][]=$index;
    foreach($indexes as $name=>$parts)if(count($parts)===1&&$parts[0]['Column_name']==='txn_id'&&(int)$parts[0]['Non_unique']===0){
        $db->exec('ALTER TABLE transactions DROP INDEX `'.str_replace('`','``',$name).'`');
    }
    if(!isset($indexes['idx_transaction_reference']))$db->exec('ALTER TABLE transactions ADD KEY idx_transaction_reference(txn_id)');
}
function borrowSessionsReady(PDO $db): bool {
    static $ready=null;
    if($ready===null)$ready=(bool)$db->query("SHOW COLUMNS FROM transactions LIKE 'borrow_session_id'")->fetch();
    return $ready;
}
function createBorrowSession(PDO $db,array $borrower): array {
    if(!$db->inTransaction())throw new LogicException('Checkout headers require the inventory transaction.');
    if(!borrowSessionsReady($db))throw new CirculationError('Run api/migrate_borrow_sessions.php before confirming a checkout.');
    $number=generateTxnId();
    $db->prepare('INSERT INTO borrow_sessions(txn_id,borrower_id,borrower_name_snapshot,borrower_id_number_snapshot,created_by) VALUES(?,?,?,?,?)')
       ->execute([$number,$borrower['id'],$borrower['full_name'],$borrower['id_number'],$_SESSION['user_id']??null]);
    $id=(int)$db->lastInsertId();$stamp=$db->prepare('SELECT created_at FROM borrow_sessions WHERE id=?');$stamp->execute([$id]);
    return ['id'=>$id,'txn_id'=>$number,'created_at'=>$stamp->fetchColumn()];
}
function refreshBorrowerBorrowCounts(PDO $db,int $borrowerId): void {
    $db->prepare("UPDATE borrowers SET
        active_borrows=(SELECT COUNT(DISTINCT t.txn_id) FROM transactions t WHERE t.borrower_id=borrowers.id AND t.type='borrow' AND t.status='active' AND t.qty-t.qty_returned-t.qty_missing>0),
        total_borrows=(SELECT COUNT(DISTINCT t.txn_id) FROM transactions t WHERE t.borrower_id=borrowers.id AND t.type='borrow') WHERE id=?")->execute([$borrowerId]);
}
