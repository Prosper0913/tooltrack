<?php
final class ToolDisposalError extends RuntimeException {}

function installToolDisposals(PDO $db):void {
 if(PHP_SAPI!=='cli')throw new LogicException('Run the tool disposal migration from CLI.');
 $db->exec("CREATE TABLE IF NOT EXISTS tool_disposals (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tool_id INT NOT NULL,
  tool_name_snapshot VARCHAR(150) NOT NULL,
  tool_code_snapshot VARCHAR(50) NOT NULL,
  quantity INT NOT NULL,
  reason VARCHAR(500) NOT NULL,
  case_id INT NULL,
  stock_history_id INT NOT NULL,
  disposed_by INT NULL,
  disposed_by_name VARCHAR(100) NOT NULL,
  request_token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tool_disposal_token(request_token),
  KEY idx_tool_disposal_tool_date(tool_id,created_at,id),
  KEY idx_tool_disposal_case(case_id),
  KEY idx_tool_disposal_date(created_at,id)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function toolDisposalReady(PDO $db):bool {
 return $db->query("SHOW TABLES LIKE 'tool_disposals'")->fetchColumn()!==false;
}

function disposeDamagedTool(PDO $db,array $b,array $actor,bool $manageTransaction=true):array {
 if(($actor['role']??'')!==ROLE_ADMIN)throw new ToolDisposalError('Only Admin may dispose inventory.');
 $id=filter_var($b['id']??null,FILTER_VALIDATE_INT);
 $rawQty=$b['quantity']??null;
 if(!$id||(!is_int($rawQty)&&!(is_string($rawQty)&&ctype_digit($rawQty))))throw new ToolDisposalError('Choose a tool and a whole disposal quantity.');
 $qty=(int)$rawQty;
 if($qty<1||$qty>100000)throw new ToolDisposalError('Disposal quantity must be between 1 and 100,000.');
 $reason=trim((string)($b['reason']??''));
 if($reason===''||mb_strlen($reason)>500)throw new ToolDisposalError('Enter a disposal reason of 1 to 500 characters.');
 $caseRaw=$b['case_id']??'';$caseId=null;
 if($caseRaw!==''&&$caseRaw!==null){$caseId=filter_var($caseRaw,FILTER_VALIDATE_INT);if(!$caseId||$caseId<1)throw new ToolDisposalError('Choose a valid linked damage case or leave it blank.');}
 $token=trim((string)($b['request_token']??''));
 if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/',$token))throw new ToolDisposalError('Invalid disposal request token. Reopen the disposal form.');
 $userId=(int)$actor['id'];$userName=trim((string)($actor['name']??''));
 $payloadHash=hash('sha256',json_encode([$id,$qty,$reason,$caseId],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
 if($manageTransaction&&$caseId){
  require_once __DIR__.'/replacement_payments.php';
  $linked=rpRow($db,$caseId);
  if((int)$linked['tool_id']!==$id||$linked['reason']!=='damaged')throw new ToolDisposalError('The selected damage case does not belong to this tool.');
  if(!isset($b['case_version']))throw new ToolDisposalError('Reload the disposal form to review the current case balance.');
  try{return rpAction($db,['id'=>$caseId,'action'=>'inventory_resolution','inventory_action'=>'dispose','quantity'=>$qty,'notes'=>$reason,'version'=>(int)$b['case_version'],'request_token'=>$token],$actor);}
  catch(ReplacementPaymentError $e){throw new ToolDisposalError($e->getMessage());}
 }
 if($manageTransaction)$db->beginTransaction();
 elseif(!$db->inTransaction())throw new LogicException('Case disposal requires a transaction.');
 try{
  // Serialize all disposal requests for this tool before validating its damaged bucket.
  $q=$db->prepare('SELECT * FROM tools WHERE id=? FOR UPDATE');$q->execute([$id]);$tool=$q->fetch();
  if(!$tool)throw new ToolDisposalError('Tool not found.');
  $q=$db->prepare('SELECT * FROM tool_disposals WHERE request_token=? FOR UPDATE');$q->execute([$token]);$duplicate=$q->fetch();
  if($duplicate){
   if(!hash_equals($duplicate['payload_hash'],$payloadHash)||(int)$duplicate['disposed_by']!==$userId)throw new ToolDisposalError('This request token was already used with different details.');
   if($manageTransaction)$db->commit();return ['disposal_id'=>(int)$duplicate['id'],'duplicate'=>true,'quantity'=>$qty,'tool_id'=>$id];
  }
  $reserved=$db->prepare("SELECT COALESCE(SUM(GREATEST(0,COALESCE(incident_quantity,quantity_needed)-resolved_quantity)),0) FROM replacement_requests WHERE tool_id=? AND workflow_mode IN ('payment','payment_item','tracking') AND reason='damaged' AND inventory_status<>'Resolved' AND id<>?");
  $reserved->execute([$id,!$manageTransaction&&$caseId?$caseId:0]);
  if($qty>max(0,(int)$tool['damaged_qty']-(int)$reserved->fetchColumn()))throw new ToolDisposalError('These damaged units belong to incident cases. Authorize disposal from the case details to keep stock and case history synchronized.');
  $q=$db->prepare('SELECT id FROM stock_history WHERE request_token=? FOR UPDATE');$q->execute([$token]);
  if($q->fetch())throw new ToolDisposalError('This request token belongs to another inventory operation.');
  if($caseId!==null){
   $q=$db->prepare("SELECT id FROM replacement_requests WHERE id=? AND tool_id=? AND reason='damaged' AND workflow_mode IN ('payment','payment_item','legacy','tracking')");
   $q->execute([$caseId,$id]);if(!$q->fetch())throw new ToolDisposalError('The linked case must be a damage case for this tool.');
  }
  $quantity=(int)$tool['quantity'];$available=(int)$tool['available'];$damaged=(int)$tool['damaged_qty'];$missing=(int)$tool['missing_qty'];$minor=(int)$tool['minor_qty'];
  $borrow=$db->prepare("SELECT COALESCE(SUM(qty-qty_returned-qty_missing),0) FROM transactions WHERE tool_id=? AND type='borrow'");$borrow->execute([$id]);$borrowed=(int)$borrow->fetchColumn();
  if($quantity<0||$available<0||$damaged<0||$missing<0||$minor<0||$minor>$available||$borrowed<0||$quantity!==$available+$damaged+$missing+$borrowed)throw new ToolDisposalError('Inventory counts are inconsistent. Check Inventory Integrity before disposal.');
  if($qty>$damaged)throw new ToolDisposalError("Only $damaged damaged unit(s) are eligible. Available, borrowed and missing units cannot be disposed here.");
  $after=$quantity-$qty;
  $update=$db->prepare('UPDATE tools SET quantity=quantity-?,damaged_qty=damaged_qty-? WHERE id=? AND damaged_qty>=? AND quantity>=?');$update->execute([$qty,$qty,$id,$qty,$qty]);
  if($update->rowCount()!==1)throw new ToolDisposalError('Inventory changed before disposal. Refresh and retry.');
  $db->prepare("INSERT INTO stock_history(tool_id,tool_name,tool_code,transaction_type,quantity,previous_stock,updated_stock,remarks,user_id,user_name,event_kind,request_token)
    VALUES (?,?,?,'Stock Removed',?,?,?,?,?,?,'disposal',?)")
   ->execute([$id,$tool['name'],$tool['code'],$qty,$quantity,$after,mb_substr('Damaged tool disposal: '.$reason,0,500),$userId,$userName,$token]);
  $stockHistoryId=(int)$db->lastInsertId();
  $db->prepare('INSERT INTO tool_disposals(tool_id,tool_name_snapshot,tool_code_snapshot,quantity,reason,case_id,stock_history_id,disposed_by,disposed_by_name,request_token,payload_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$id,$tool['name'],$tool['code'],$qty,$reason,$caseId,$stockHistoryId,$userId,$userName,$token,$payloadHash]);
  $disposalId=(int)$db->lastInsertId();
  if($manageTransaction)$db->commit();
  logAudit('tool_dispose',"Disposed {$qty} damaged unit(s) of {$tool['name']} ({$tool['code']}); disposal #$disposalId".($caseId?"; linked damage case #$caseId":'')."; reason: $reason");
  return ['disposal_id'=>$disposalId,'duplicate'=>false,'quantity'=>$qty,'tool_id'=>$id,'quantity_after'=>$after,'damaged_after'=>$damaged-$qty,'stock_history_id'=>$stockHistoryId];
 }catch(Throwable $e){if($manageTransaction&&$db->inTransaction())$db->rollBack();throw $e;}
}

function listToolDisposals(PDO $db,array $filters):array {
 $page=max(1,(int)($filters['page']??1));$per=min(100,max(1,(int)($filters['per_page']??25)));$where=[];$params=[];
 $search=trim((string)($filters['search']??''));
 if($search!==''){$where[]='(d.tool_name_snapshot LIKE ? OR d.tool_code_snapshot LIKE ? OR d.reason LIKE ? OR d.disposed_by_name LIKE ? OR CAST(d.case_id AS CHAR) LIKE ?)';$needle='%'.mb_substr($search,0,150).'%';array_push($params,$needle,$needle,$needle,$needle,$needle);}
 foreach(['from','to'] as $key){$value=(string)($filters[$key]??'');if($value==='')continue;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new ToolDisposalError('Use valid disposal date filters.');$where[]=$key==='from'?'d.created_at>=?':'d.created_at<DATE_ADD(?,INTERVAL 1 DAY)';$params[]=$value;}
 if(!empty($filters['tool_id'])){$toolId=filter_var($filters['tool_id'],FILTER_VALIDATE_INT);if(!$toolId||$toolId<1)throw new ToolDisposalError('Choose a valid tool filter.');$where[]='d.tool_id=?';$params[]=$toolId;}
 $clause=$where?' WHERE '.implode(' AND ',$where):'';$count=$db->prepare('SELECT COUNT(*) FROM tool_disposals d'.$clause);$count->execute($params);$total=(int)$count->fetchColumn();
 $q=$db->prepare("SELECT d.*,COALESCE(NULLIF(d.disposed_by_name,''),u.name) performed_by,r.responsible_name_snapshot borrower_name,r.id linked_case_id,h.id stock_entry_id FROM tool_disposals d LEFT JOIN users u ON u.id=d.disposed_by LEFT JOIN replacement_requests r ON r.id=d.case_id LEFT JOIN stock_history h ON h.id=d.stock_history_id$clause ORDER BY d.created_at DESC,d.id DESC LIMIT ? OFFSET ?");
 $q->execute(array_merge($params,[$per,($page-1)*$per]));return ['rows'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'per_page'=>$per];
}

function listToolDisposalCases(PDO $db,int $toolId):array {
 if($toolId<1)throw new ToolDisposalError('Choose a valid tool.');
 $q=$db->prepare("SELECT id,tool_name_snapshot,responsible_name_snapshot,reason,workflow_mode,created_at,payment_version,GREATEST(0,COALESCE(incident_quantity,quantity_needed)-resolved_quantity) eligible_quantity FROM replacement_requests WHERE tool_id=? AND reason='damaged' AND inventory_status IN ('Pending Review','Under Repair') AND COALESCE(incident_quantity,quantity_needed)>resolved_quantity ORDER BY id DESC");$q->execute([$toolId]);return $q->fetchAll();
}

// Dashboard figure. A disposal counts only when its ledger row matches an exact "Stock Removed"
// disposal entry in stock history (same tool and quantity). If the ledger table does not exist,
// available=false and the dashboard hides the card instead of showing an unreliable zero.
function toolDisposalSummary(PDO $db):array {
 if(!toolDisposalReady($db))return ['available'=>false,'units'=>0,'records'=>0,'unverified_records'=>0];
 $r=$db->query("SELECT COUNT(*) total,COALESCE(SUM(h.id IS NOT NULL),0) records,COALESCE(SUM(CASE WHEN h.id IS NOT NULL THEN d.quantity ELSE 0 END),0) units
  FROM tool_disposals d LEFT JOIN stock_history h ON h.id=d.stock_history_id AND h.tool_id=d.tool_id AND h.quantity=d.quantity AND h.event_kind='disposal' AND h.transaction_type='Stock Removed'")->fetch();
 return ['available'=>true,'units'=>(int)$r['units'],'records'=>(int)$r['records'],'unverified_records'=>(int)$r['total']-(int)$r['records']];
}
