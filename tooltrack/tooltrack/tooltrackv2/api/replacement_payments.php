<?php
class ReplacementPaymentError extends RuntimeException {}
function rpReady(PDO $db):bool{
    static $ready=null;if($ready===null)$ready=(bool)$db->query("SHOW COLUMNS FROM replacement_requests LIKE 'workflow_mode'")->fetch();return $ready;
}
function rpActor(PDO $db):array{
    $me=requireLogin();$q=$db->prepare('SELECT id,name,role FROM users WHERE id=?');$q->execute([$me['id']]);$r=$q->fetch();
    if(!$r||!in_array($r['role'],[ROLE_ADMIN,ROLE_STAFF],true)||$r['role']!==$me['role'])fail('Current Staff or Admin access is required.',403);return $r;
}
function rpMoney($value):string{
    if(!is_scalar($value)||!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D',(string)$value,$m))throw new ReplacementPaymentError('Enter a valid peso amount with no more than two decimal places.');
    return $m[1].'.'.str_pad($m[2]??'',2,'0');
}
function rpToken(array $b):string{
    $token=(string)($b['request_token']??'');if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$token))throw new ReplacementPaymentError('A valid request token is required. Refresh the form.');return $token;
}
function rpPayload(array $b):string{unset($b['csrf']);ksort($b);return hash('sha256',json_encode($b,JSON_THROW_ON_ERROR));}
function rpEvent(PDO $db,int $id,string $action,array $before,array $after,string $notes='',?string $token=null,?string $hash=null,?array $actor=null):void{
    $actor=$actor??rpActor($db);
    $db->prepare('INSERT INTO replacement_request_events(request_id,action,before_state,after_state,notes,user_id,user_name,user_role,request_token,payload_hash) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([$id,$action,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),mb_substr($notes,0,500),$actor['id'],$actor['name'],$actor['role'],$token,$hash]);
}
function rpRow(PDO $db,int $id,bool $lock=false):array{
    $q=$db->prepare('SELECT * FROM replacement_requests WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$r=$q->fetch();if(!$r)throw new ReplacementPaymentError('Incident not found.');return $r;
}
function rpReplay(PDO $db,string $token,string $hash,array $actor):?int{
    $q=$db->prepare('SELECT request_id,payload_hash,user_id FROM replacement_request_events WHERE request_token=? FOR UPDATE');$q->execute([$token]);$r=$q->fetch();
    if(!$r)return null;if(!hash_equals($r['payload_hash'],$hash)||(int)$r['user_id']!==(int)$actor['id'])throw new ReplacementPaymentError('This request token was already used with different details.');return (int)$r['request_id'];
}
// Exact evidence, independent of payment or physical fulfillment. Legacy quantities conservatively reserve old evidence first.
function rpSources(PDO $db,string $kind):array{
    if($kind==='lost'){
        $rows=$db->query("SELECT m.id source_id,m.tool_id,m.borrower_id,m.qty_missing qty,m.report_no reference,(SELECT txn_id FROM transactions WHERE id=m.borrow_txn_id) borrowing_reference,tl.name tool_name,tl.code tool_code,b.full_name,b.id_number,
          GREATEST(0,m.qty_missing-m.qty_replaced-m.qty_recovered+m.qty_surplus) stock_open,
          COALESCE((SELECT SUM(s.quantity) FROM replacement_incident_sources s WHERE s.missing_report_id=m.id),0) allocated,
          COALESCE((SELECT SUM(r.quantity_needed) FROM replacement_requests r WHERE r.missing_report_id=m.id AND r.workflow_mode='legacy' AND r.status<>'rejected'),0) legacy_qty
          FROM missing_reports m JOIN tools tl ON tl.id=m.tool_id AND tl.is_active=1 JOIN borrowers b ON b.id=m.borrower_id WHERE m.status='confirmed' ORDER BY m.id")->fetchAll();
        foreach($rows as &$r)$r['remaining_units']=max(0,min((int)$r['stock_open'],(int)$r['qty']-(int)$r['allocated']-(int)$r['legacy_qty']));unset($r);
    }else{
        $rows=$db->query("SELECT r.id source_id,r.tool_id,r.borrower_id,r.qty,r.txn_id reference,b.txn_id borrowing_reference,tl.name tool_name,tl.code tool_code,bw.full_name,bw.id_number,
          COALESCE((SELECT SUM(s.quantity) FROM replacement_incident_sources s WHERE s.return_transaction_id=r.id),0) allocated
          FROM transactions r JOIN transactions b ON b.id=r.borrow_txn_id AND b.type='borrow' AND b.tool_id=r.tool_id AND b.borrower_id=r.borrower_id
          JOIN tools tl ON tl.id=r.tool_id AND tl.is_active=1 JOIN borrowers bw ON bw.id=r.borrower_id
          WHERE r.type='return' AND r.`condition`='damaged' AND r.qty>0 ORDER BY r.id")->fetchAll();
        $reserves=[];foreach($db->query("SELECT tool_id,responsible_borrower_id,SUM(quantity_needed) qty FROM replacement_requests WHERE workflow_mode='legacy' AND status<>'rejected' AND COALESCE(reason,'damaged') NOT IN ('worn_out','lost') GROUP BY tool_id,responsible_borrower_id") as $r)$reserves[$r['tool_id'].':'.$r['responsible_borrower_id']]=(int)$r['qty'];
        foreach($rows as &$r){$key=$r['tool_id'].':'.$r['borrower_id'];$free=max(0,(int)$r['qty']-(int)$r['allocated']);$legacy=min($free,$reserves[$key]??0);$reserves[$key]=max(0,($reserves[$key]??0)-$legacy);$r['remaining_units']=$free-$legacy;}unset($r);
    }
    return array_values(array_filter($rows,fn($r)=>$r['remaining_units']>0));
}
function rpCreate(PDO $db,array $b,array $actor):array{
    $kind=(string)($b['reason']??'damaged');if(!in_array($kind,['damaged','lost'],true))throw new ReplacementPaymentError('Incidents are for damage or confirmed loss only. Minor wear remains maintenance.');
    $qty=filter_var($b['quantity_needed']??0,FILTER_VALIDATE_INT);if($qty===false||$qty<1||$qty>1000)throw new ReplacementPaymentError('Quantity must be a whole number between 1 and 1000.');
    $token=rpToken($b);$hash=rpPayload($b);$source=(int)($b['source_id']??($b['missing_report_id']??0));$tool=(int)($b['tool_id']??0);$borrower=(int)($b['responsible_borrower_id']??0);
    // The source check must see commits made while this request waited for the tool/case lock.
    $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->beginTransaction();
    try{
        if($id=rpReplay($db,$token,$hash,$actor)){$db->commit();return ['id'=>$id,'duplicate'=>true];}
        if($kind==='lost'){
            if(!$source)throw new ReplacementPaymentError('Choose a confirmed missing-tool case.');
            $db->prepare('SELECT id FROM replacement_requests WHERE missing_report_id=? ORDER BY id FOR UPDATE')->execute([$source]);
            $q=$db->prepare('SELECT * FROM missing_reports WHERE id=? FOR UPDATE');$q->execute([$source]);$case=$q->fetch();if(!$case||$case['status']!=='confirmed')throw new ReplacementPaymentError('Only a confirmed missing-tool case is eligible.');$tool=(int)$case['tool_id'];$borrower=(int)$case['borrower_id'];
            if(!empty($b['tool_id'])&&(int)$b['tool_id']!==$tool)throw new ReplacementPaymentError('Tool does not match the missing case.');
            if(!empty($b['responsible_borrower_id'])&&(int)$b['responsible_borrower_id']!==$borrower)throw new ReplacementPaymentError('Student does not match the missing case.');
        }
        if(!$tool||!$borrower)throw new ReplacementPaymentError('Choose the tool and student linked to the incident.');
        $q=$db->prepare('SELECT id FROM tools WHERE id=? FOR UPDATE');$q->execute([$tool]);if(!$q->fetch())throw new ReplacementPaymentError('Tool not found.');
        $sources=array_values(array_filter(rpSources($db,$kind),fn($r)=>(int)$r['tool_id']===$tool&&(int)$r['borrower_id']===$borrower&&(!$source||(int)$r['source_id']===$source)));
        if(array_sum(array_column($sources,'remaining_units'))<$qty)throw new ReplacementPaymentError('These incident units are already recorded, reserved by a legacy request, or not supported by eligible student evidence.');
        if($id=rpReplay($db,$token,$hash,$actor)){$db->commit();return ['id'=>$id,'duplicate'=>true];}
        $first=$sources[0];$notes=mb_substr(trim((string)($b['notes']??'')),0,500);
        $db->prepare("INSERT INTO replacement_requests(tool_id,tool_name_snapshot,reason,quantity_needed,incident_quantity,notes,requested_by,requested_by_snapshot,responsible_borrower_id,responsible_name_snapshot,missing_report_id,workflow_mode,financial_status,submission_token)
          VALUES(?,?,?,?,?,?,?,?,?,?,?,'tracking','not_applicable',?)")->execute([$tool,$first['tool_name'],$kind,$qty,$qty,$notes,$actor['id'],$actor['name'],$borrower,$first['full_name'].' — '.$first['id_number'],$kind==='lost'?$source:null,$token]);
        $id=(int)$db->lastInsertId();$left=$qty;
        foreach($sources as $s){$n=min($left,(int)$s['remaining_units']);if(!$n)break;$db->prepare('INSERT INTO replacement_incident_sources(request_id,return_transaction_id,missing_report_id,quantity) VALUES(?,?,?,?)')->execute([$id,$kind==='damaged'?$s['source_id']:null,$kind==='lost'?$s['source_id']:null,$n]);$left-=$n;}
        if($kind==='lost')$db->prepare("UPDATE replacement_requests SET inventory_status='Missing' WHERE id=?")->execute([$id]);
        rpEvent($db,$id,'incident_created',[],rpRow($db,$id),$notes,$token,$hash,$actor);
        if($kind==='lost')missingEvent($db,$source,'incident_linked',$qty,"Incident #$id filed; pending physical resolution");
        $db->commit();logAudit('damage_loss_incident',"Filed incident #$id; linked to existing evidence; stock unchanged.");return ['id'=>$id];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
require_once __DIR__.'/charge_lib.php';
require_once __DIR__.'/case_inventory.php';
require_once dirname(__DIR__).'/includes/stock_lib.php';
require_once __DIR__.'/tool_disposal_lib.php';
require_once __DIR__.'/bulk_charge_lib.php';
function rpRecoveryReview(PDO $db,int $caseId,int $qty,string $condition='damaged'):void {
    $q=$db->prepare('SELECT * FROM replacement_requests WHERE missing_report_id=? ORDER BY id FOR UPDATE');$q->execute([$caseId]);
    $mq=$db->prepare('SELECT * FROM missing_reports WHERE id=?');$mq->execute([$caseId]);$missing=$mq->fetch();
    foreach($q->fetchAll() as $r){
        if($missing&&missingOpenQty($missing)<=0)$db->prepare('UPDATE replacement_requests SET inventory_status=?,payment_version=payment_version+1 WHERE id=?')->execute([$condition==='damaged'?'Recovered':'Resolved',$r['id']]);
        rpEvent($db,(int)$r['id'],'original_recovered',$r,rpRow($db,(int)$r['id']),"$qty original unit(s) recovered as $condition. Historical financial records preserved.");
    }
}
function rpRoute(PDO $db,array $me,string $method):void {
    if(!rpReady($db))fail('The existing incident schema must be installed before using tracking.',503);
    $actor=rpActor($db);
    if(empty($_SESSION['replacement_payment_csrf']))$_SESSION['replacement_payment_csrf']=bin2hex(random_bytes(32));
    if($method==='GET'){
        if(isset($_GET['meta']))ok(['csrf'=>$_SESSION['replacement_payment_csrf']]);
        if(isset($_GET['payment_meta'])||isset($_GET['payment_history']))fail('Charge and payment features have been retired.',410);
        if(isset($_GET['sources']))ok(rpSources($db,($_GET['reason']??'')==='lost'?'lost':'damaged'));
        if(isset($_GET['return_id'])){
            $q=$db->prepare("SELECT r.id,r.txn_id,r.qty,r.notes,r.created_at,r.borrower_id,r.tool_id,t.name tool_name,t.code tool_code,b.full_name borrower,b.id_number borrower_number FROM transactions r LEFT JOIN tools t ON t.id=r.tool_id LEFT JOIN borrowers b ON b.id=r.borrower_id WHERE r.id=? AND r.type='return' AND r.`condition`='damaged'");$q->execute([(int)$_GET['return_id']]);$r=$q->fetch();if(!$r)fail('Return not found.',404);ok(['return'=>$r]);
        }
        if(isset($_GET['details'])){
            $id=(int)$_GET['details'];$r=rpRow($db,$id);
            $q=$db->prepare('SELECT b.full_name student_name,b.id_number student_id,t.code tool_code FROM replacement_requests r LEFT JOIN borrowers b ON b.id=r.responsible_borrower_id LEFT JOIN tools t ON t.id=r.tool_id WHERE r.id=?');$q->execute([$id]);$r=array_merge($r,$q->fetch()?:[]);
            $q=$db->prepare("SELECT id,action,notes,user_name,user_role,created_at FROM replacement_request_events WHERE request_id=? AND action IN ('automatic_incident','manual_incident','incident_created','inventory_resolution','original_recovered','bulk_item_created') ORDER BY id");$q->execute([$id]);$events=$q->fetchAll();
            $q=$db->prepare('SELECT s.*,t.txn_id return_reference,m.report_no missing_reference,b.txn_id borrowing_reference FROM replacement_incident_sources s LEFT JOIN transactions t ON t.id=s.return_transaction_id LEFT JOIN missing_reports m ON m.id=s.missing_report_id LEFT JOIN transactions b ON b.id=COALESCE(t.borrow_txn_id,m.borrow_txn_id) WHERE s.request_id=? ORDER BY s.id');$q->execute([$id]);
            $sources=$q->fetchAll();$life=$db->prepare('SELECT age_days,closed_at,is_closed,needs_review,inventory_status,remaining_units FROM ('.caseUiSource().') i WHERE source=? AND id=?');$life->execute([$r['missing_report_id']?'missing':'case',$r['missing_report_id']?:$id]);
            ok(['request'=>casePublicRow($r),'events'=>$events,'sources'=>$sources,'items'=>array_map('casePublicRow',bulkItems($db,$id)),'lifecycle'=>$life->fetch()?:null]);
        }
        return;
    }
    if(!in_array($method,['POST','PATCH'],true))return;
    $b=body();$action=(string)($b['action']??'');
    if(!in_array($action,['manual_incident','create_incident','inventory_resolution'],true))fail('Charge and payment features have been retired. Use physical incident actions.',410);
    if(!hash_equals($_SESSION['replacement_payment_csrf'],(string)($b['csrf']??'')))fail('Security token expired. Reload tracking.',403);
    try{
        if($method==='POST'&&$action==='manual_incident')ok(caseManualCreate($db,$b,$actor),201);
        if($method==='POST'&&$action==='create_incident')ok(rpCreate($db,$b,$actor),201);
        if($method==='PATCH'&&$action==='inventory_resolution')ok(rpAction($db,$b,$actor));
    }catch(ReplacementPaymentError $e){fail($e->getMessage(),409);}catch(PDOException $e){error_log('Incident SQL: '.$e->getMessage());fail('Incident update failed. Nothing was committed; refresh and retry.',409);}
}
