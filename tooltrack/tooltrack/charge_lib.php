<?php
// Exact decimal accounting; financial mutations never adjust inventory.
function rpCents($v):int {$p=explode('.',rpMoney($v));return (int)$p[0]*100+(int)$p[1];}
function rpDecimal(int $v):string {return intdiv($v,100).'.'.str_pad((string)($v%100),2,'0',STR_PAD_LEFT);}
function rpPaid(PDO $db,int $id):int {$q=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM replacement_payment_claims WHERE request_id=? AND status='confirmed'");$q->execute([$id]);return rpCents($q->fetchColumn());}
function rpSettlement(PDO $db,array $r):array {
 $paid=rpPaid($db,(int)$r['id']);$due=$r['amount_due']===null?null:rpCents($r['amount_due']);
 return ['amount_paid'=>rpDecimal($paid),'balance'=>$due===null?null:rpDecimal(max(0,$due-$paid)),'payment_status'=>$due===null?'Not assessed':($due===0?'No charge':($paid>=$due?'Paid':($paid>0?'Partially Paid':'Unpaid')))];
}
function rpRefreshStatus(PDO $db,int $id):string {
 $r=rpRow($db,$id);$q=$db->prepare("SELECT COUNT(*) FROM replacement_payment_claims WHERE request_id=? AND status='pending'");$q->execute([$id]);
 $paid=rpPaid($db,$id);$due=$r['amount_due']===null?null:rpCents($r['amount_due']);
 $status=$q->fetchColumn()?'verification_pending':($due===null?'under_review':($due===0?'waived':($paid>=$due?'confirmed':($paid>0?'partially_paid':'awaiting_payment'))));
 $db->prepare('UPDATE replacement_requests SET financial_status=?,financial_closed_at=NULL WHERE id=?')->execute([$status,$id]);return $status;
}
function rpAction(PDO $db,array $b,array $actor):array {
 $action=(string)($b['action']??'');$id=(int)($b['id']??0);$token=rpToken($b);$hash=rpPayload($b);
 if($action==='void_payment'&&$actor['role']!==ROLE_ADMIN)fail('Only Admin may void confirmed payments.',403);
 $db->beginTransaction();
 try {
  $r=rpRow($db,$id,true);if($r['workflow_mode']!=='payment')throw new ReplacementPaymentError('Historical no-charge records cannot be charged.');
  if($replay=rpReplay($db,$token,$hash,$actor)){$db->commit();return ['id'=>$replay,'duplicate'=>true];}
  if(!isset($b['version'])||(int)$b['version']!==(int)$r['payment_version'])throw new ReplacementPaymentError('This case changed. Refresh before saving.');
  $notes=mb_substr(trim((string)($b['notes']??'')),0,500);$after=[];$paid=rpPaid($db,$id);
  if($action==='review_charge'){
   $beforeItems=bulkItems($db,$id);if($beforeItems)$after['items_before']=$beforeItems;
   $q=$db->prepare("SELECT COUNT(*) FROM replacement_payment_claims WHERE request_id=? AND status IN ('confirmed','voided')");$q->execute([$id]);
   if($q->fetchColumn()&&$actor['role']!==ROLE_ADMIN)fail('Only Admin may correct charges with confirmed financial history.',403);
   if($notes==='')throw new ReplacementPaymentError('Explain the assessment or correction.');
   if(($b['responsibility']??'liable')!=='liable')throw new ReplacementPaymentError('FPST requires student damage/loss charges.');
   $amount=bulkItems($db,$id)?bulkReassess($db,$id,$b):rpMoney($b['amount_due']??'');if(rpCents($amount)<=0||rpCents($amount)<$paid)throw new ReplacementPaymentError('Charge must be positive and at least the confirmed collections. Void incorrect payments first.');
   $db->prepare("UPDATE replacement_payment_claims SET status='rejected',active_receipt=NULL,active_request_id=NULL,verified_by=?,verified_by_name=?,verified_by_role=?,verified_at=NOW(),verification_notes='Charge corrected; resubmit payment' WHERE request_id=? AND status='pending'")->execute([$actor['id'],$actor['name'],$actor['role'],$id]);
   $db->prepare("UPDATE replacement_requests SET responsibility='liable',amount_due=?,charge_version=charge_version+1,reviewed_by=?,reviewed_by_name=?,reviewed_at=NOW(),charge_assessed_at=COALESCE(charge_assessed_at,NOW()),needs_financial_review=0 WHERE id=?")->execute([$amount,$actor['id'],$actor['name'],$id]);
  }elseif($action==='claim_payment'){
   if($r['amount_due']===null||$r['needs_financial_review'])throw new ReplacementPaymentError('Set the charge and complete recovery review first.');
   $q=$db->prepare("SELECT COUNT(*) FROM replacement_payment_claims WHERE request_id=? AND status='pending'");$q->execute([$id]);if($q->fetchColumn())throw new ReplacementPaymentError('Confirm or reject the pending payment first.');
   $amount=rpMoney($b['amount']??'');if(rpCents($amount)<1||rpCents($amount)>rpCents($r['amount_due'])-$paid)throw new ReplacementPaymentError('Payment must be positive and cannot exceed the balance.');
   $receipt=trim((string)($b['receipt_number']??''));
   if($receipt==='')$receipt='SYS-PAY-'.strtoupper(bin2hex(random_bytes(12)));
   $normalized=preg_replace('/\s+/u','',mb_strtoupper($receipt));
   if(mb_strlen($receipt)>80||preg_match('/[\x00-\x1f]/',$receipt))throw new ReplacementPaymentError('Invalid payment reference.');
   $date=(string)($b['payment_date']??'');$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Manila'));
   $earliest=substr($r['charge_assessed_at']??$r['reviewed_at']??$r['created_at'],0,10);
   if(!$d||$d->format('Y-m-d')!==$date||$date>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d')||$date<$earliest)throw new ReplacementPaymentError('Payment date must be between charge assessment and today.');
   $db->prepare('INSERT INTO replacement_payment_claims(request_id,amount,receipt_number,payment_date,charge_version,submitted_by,submitted_by_name,active_receipt,active_request_id) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$amount,$receipt,$date,$r['charge_version'],$actor['id'],$actor['name'],$normalized,$id]);$after['claim_id']=(int)$db->lastInsertId();
  }elseif(in_array($action,['confirm_payment','reject_payment'],true)){
   if($r['needs_financial_review'])throw new ReplacementPaymentError('Complete recovery review first.');
   $q=$db->prepare("SELECT * FROM replacement_payment_claims WHERE id=? AND request_id=? AND status='pending' FOR UPDATE");$q->execute([(int)($b['claim_id']??0),$id]);$claim=$q->fetch();
   if(!$claim||(int)$claim['charge_version']!==(int)$r['charge_version'])throw new ReplacementPaymentError('No current payment ready for confirmation.');
   if($notes==='')throw new ReplacementPaymentError('Record confirmation details or rejection reason.');
   $confirm=$action==='confirm_payment';if($confirm&&($b['received_confirmed']??false)!==true)throw new ReplacementPaymentError('Confirm payment was actually received.');
   if($confirm&&$paid+rpCents($claim['amount'])>rpCents($r['amount_due']))throw new ReplacementPaymentError('Payment exceeds the balance.');
   $db->prepare('UPDATE replacement_payment_claims SET status=?,verified_by=?,verified_by_name=?,verified_by_role=?,verified_at=NOW(),verification_notes=?,active_receipt=?,active_request_id=NULL WHERE id=?')->execute([$confirm?'confirmed':'rejected',$actor['id'],$actor['name'],$actor['role'],$notes,$confirm?$claim['active_receipt']:null,$claim['id']]);$after['claim_id']=$claim['id'];$after['payment_before']=$claim;
  }elseif($action==='void_payment'){
   if($notes==='')throw new ReplacementPaymentError('A void reason is required.');
   $q=$db->prepare("SELECT * FROM replacement_payment_claims WHERE id=? AND request_id=? AND status='confirmed' FOR UPDATE");$q->execute([(int)($b['claim_id']??0),$id]);$claim=$q->fetch();if(!$claim)throw new ReplacementPaymentError('Only confirmed payments can be voided.');
   $db->prepare("UPDATE replacement_payment_claims SET status='voided',active_request_id=NULL,voided_by=?,voided_by_name=?,voided_at=NOW(),void_reason=? WHERE id=?")->execute([$actor['id'],$actor['name'],$notes,$claim['id']]);$after['claim_id']=$claim['id'];$after['payment_before']=$claim;
  }elseif($action==='acknowledge_recovery'){
   if($actor['role']!==ROLE_ADMIN)fail('Admin must review recovery adjustments.',403);
   if(!$r['needs_financial_review']||$notes==='')throw new ReplacementPaymentError('Explain the recovery review.');
   $db->prepare('UPDATE replacement_requests SET needs_financial_review=0 WHERE id=?')->execute([$id]);
  }elseif($action==='close_case'){
   if($r['needs_financial_review']||$r['amount_due']===null||$paid<rpCents($r['amount_due']))throw new ReplacementPaymentError('Settle the balance and complete recovery review first.');
  }else throw new ReplacementPaymentError('Unknown financial action.');
  $status=rpRefreshStatus($db,$id);
  if($action==='close_case'){$status='closed';$db->prepare("UPDATE replacement_requests SET financial_status='closed',financial_closed_at=NOW() WHERE id=?")->execute([$id]);}
  $db->prepare('UPDATE replacement_requests SET payment_version=payment_version+1 WHERE id=?')->execute([$id]);
  if(isset($after['claim_id'])){$q=$db->prepare('SELECT * FROM replacement_payment_claims WHERE id=?');$q->execute([$after['claim_id']]);$after['payment_after']=$q->fetch();}
  $after=array_merge($after,rpRow($db,$id));if(bulkItems($db,$id))$after['items_after']=bulkItems($db,$id);rpEvent($db,$id,$action,$r,$after,$notes,$token,$hash,$actor);$db->commit();
  logAudit('damage_loss_'.$action,"Charge #$id: $action; $status; PHP ".($after['amount_due']??'unassessed').'; '.$notes.'; inventory unchanged.');return ['id'=>$id,'financial_status'=>$status]+rpSettlement($db,$after);
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
