<?php
// Stock additions and registrations share the existing stock_history ledger.
class StockInputError extends RuntimeException {}
function installStockHistory(PDO $db):void{
    if(PHP_SAPI!=='cli')throw new LogicException('Run the stock history migration from CLI.');
    $columns=array_column($db->query('SHOW COLUMNS FROM stock_history')->fetchAll(),'Field');
    foreach(['event_kind'=>"VARCHAR(16) NOT NULL DEFAULT 'adjustment'",'tool_code'=>'VARCHAR(50) NULL','request_token'=>'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL'] as $name=>$definition){
        if(!in_array($name,$columns,true))$db->exec("ALTER TABLE stock_history ADD COLUMN $name $definition");
    }
    $indexes=array_column($db->query('SHOW INDEX FROM stock_history')->fetchAll(),'Key_name');
    if(!in_array('uq_stock_request',$indexes,true))$db->exec('ALTER TABLE stock_history ADD UNIQUE KEY uq_stock_request(request_token)');
    if(!in_array('idx_stock_registration',$indexes,true))$db->exec('ALTER TABLE stock_history ADD KEY idx_stock_registration(tool_id,event_kind)');
    if(!in_array('idx_stock_created',$indexes,true))$db->exec('ALTER TABLE stock_history ADD KEY idx_stock_created(created_at,id)');
}
function recordToolRegistration(PDO $db,int $id,?int $userId,string $userName):void{
    $db->prepare("INSERT INTO stock_history(tool_id,tool_name,tool_code,transaction_type,quantity,previous_stock,updated_stock,remarks,user_id,user_name,event_kind,created_at)
      SELECT id,name,code,'Stock Added',quantity,0,quantity,'Initial tool registration',?,?,'registration',created_at FROM tools WHERE id=?")
       ->execute([$userId,$userName,$id]);
}
function addToolStock(PDO $db,int $toolId,int $qty,string $token,string $remarks,int $userId,string $userName,bool $manageTransaction=true,?array $expectedStock=null):array{
    if($manageTransaction)$db->beginTransaction();
    elseif(!$db->inTransaction())throw new LogicException('Stock receiving requires a transaction.');
    try{
        $q=$db->prepare('SELECT * FROM tools WHERE id=? FOR UPDATE');$q->execute([$toolId]);$tool=$q->fetch();
        if(!$tool)throw new StockInputError('Tool not found.');
        $dup=$db->prepare('SELECT * FROM stock_history WHERE request_token=? FOR UPDATE');$dup->execute([$token]);$history=$dup->fetch();
        if($history){
            if((int)$history['tool_id']!==$toolId||(int)$history['quantity']!==$qty||(int)$history['user_id']!==$userId||$history['event_kind']!=='restock')throw new StockInputError('This request was already saved with different details. Refresh the tool before adding more stock.');
            if($manageTransaction)$db->commit();return ['tool'=>$tool,'history_id'=>(int)$history['id'],'duplicate'=>true];
        }
        if(!(int)$tool['is_active'])throw new StockInputError('Reactivate this tool before adding stock.');
        $total=(int)$tool['quantity'];$available=(int)$tool['available'];
        if($expectedStock!==null&&($total!==$expectedStock['quantity']||$available!==$expectedStock['available'])){
            if($manageTransaction)$db->commit();
            return ['tool'=>$tool,'stock_changed'=>true,'duplicate'=>false];
        }
        if($qty<1||$qty>100000||$total+$qty>100000)throw new StockInputError('Add a positive whole quantity. The updated total cannot exceed 100,000.');
        if($available<0||(int)$tool['minor_qty']<0||(int)$tool['minor_qty']>$available||$available+(int)$tool['damaged_qty']+(int)$tool['missing_qty']>$total)throw new StockInputError('Inventory counts are inconsistent. Check Inventory Integrity before restocking.');
        $db->prepare('UPDATE tools SET quantity=quantity+?,available=available+? WHERE id=?')->execute([$qty,$qty,$toolId]);
        $db->prepare("INSERT INTO stock_history(tool_id,tool_name,tool_code,transaction_type,quantity,previous_stock,updated_stock,remarks,user_id,user_name,event_kind,request_token)
          VALUES (?,?,?,'Stock Added',?,?,?,?,?,?,'restock',?)")
          ->execute([$toolId,$tool['name'],$tool['code'],$qty,$total,$total+$qty,$remarks,$userId,$userName,$token]);
        $historyId=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO inventory_adjustments(tool_id,action,from_state,to_state,qty,notes,user_id) VALUES (?,'qty_edit',?,?,?,?,?)")
          ->execute([$toolId,(string)$total,(string)($total+$qty),$qty,'Restock: '.$remarks,$userId]);
        if($manageTransaction)$db->commit();$tool['quantity']=$total+$qty;$tool['available']=$available+$qty;
        return ['tool'=>$tool,'history_id'=>$historyId,'duplicate'=>false];
    }catch(Throwable $e){if($manageTransaction&&$db->inTransaction())$db->rollBack();throw $e;}
}
function stockHistoryUnion():string{
    return "SELECT h.id AS event_id,h.tool_id,h.tool_name,COALESCE(h.tool_code,t.code,'Unknown') AS sku,
       CASE WHEN h.event_kind='registration' THEN 'New Tool' WHEN h.event_kind='loss_writeoff' THEN 'Write Off as Lost' WHEN h.event_kind='loss_recovery' THEN 'Recovery after Write-off' ELSE h.transaction_type END AS event,
       h.quantity,h.previous_stock,h.updated_stock,COALESCE(NULLIF(h.user_name,''),u.name,'Unknown (legacy)') AS performed_by,
       COALESCE(h.remarks,'') AS remarks,h.created_at
       FROM stock_history h LEFT JOIN tools t ON t.id=h.tool_id LEFT JOIN users u ON u.id=h.user_id
       UNION ALL SELECT -t.id,t.id,t.name,t.code,'New Tool',t.original_quantity,0,t.original_quantity,'Unknown (legacy)',
       CASE WHEN t.original_quantity IS NULL THEN 'Legacy registration; original quantity not recorded' ELSE 'Legacy tool registration' END,t.created_at
       FROM tools t WHERE NOT EXISTS(SELECT 1 FROM stock_history h WHERE h.tool_id=t.id AND h.event_kind='registration')";
}
