<?php
require_once __DIR__.'/writeoff_lib.php';
// One read model for the queue and borrower history. Missing reports are the authoritative
// loss record, so their linked incident rows are never displayed as additional incidents.
function caseTrackingSource():string {
    $names=['source','id','case_id','borrower_id','borrower','borrower_number','tool_id','tool_name','tool_code','incident','quantity','inventory_status','created_at','reported_by'];
    // Existing tables have different collations; normalize only the read model, preserving schemas.
    $text=[0,2,4,5,7,8,9,11,13];
    $select=static function(array $columns,string $from)use($names,$text):string {
        foreach($columns as $i=>&$column){if(in_array($i,$text,true))$column="CONVERT($column USING utf8mb4) COLLATE utf8mb4_unicode_ci";$column.=' AS '.$names[$i];}unset($column);
        return 'SELECT '.implode(',',$columns).' FROM '.$from;
    };
    $cases=$select(["'case'",'r.id',"CONCAT('DLI-',LPAD(r.id,5,'0'))",'r.responsible_borrower_id','COALESCE(b.full_name,r.responsible_name_snapshot)','b.id_number','r.tool_id','r.tool_name_snapshot','t.code','r.reason','COALESCE(r.incident_quantity,r.quantity_needed)',lossOutcomeSql('case','r','r.inventory_status'),'r.created_at','r.requested_by_snapshot'],
        "replacement_requests r LEFT JOIN tools t ON t.id=r.tool_id LEFT JOIN borrowers b ON b.id=r.responsible_borrower_id WHERE r.tool_id IS NOT NULL AND r.missing_report_id IS NULL AND (r.workflow_mode<>'legacy' OR r.status<>'rejected')");
    $status="CASE WHEN m.status='pending' THEN 'Pending Confirmation' WHEN m.status='rejected' THEN 'Rejected'
        WHEN EXISTS(SELECT 1 FROM replacement_requests x WHERE x.missing_report_id=m.id AND x.inventory_status NOT IN ('Resolved','Missing','Pending Review'))
        THEN (SELECT x.inventory_status FROM replacement_requests x WHERE x.missing_report_id=m.id AND x.inventory_status NOT IN ('Resolved','Missing','Pending Review') ORDER BY x.id DESC LIMIT 1)
        WHEN m.status='resolved' THEN 'Resolved' ELSE 'Missing' END";
    $missing=$select(["'missing'",'m.id','m.report_no','m.borrower_id','COALESCE(b.full_name,m.borrower_name_snapshot)','COALESCE(b.id_number,m.borrower_id_number_snapshot)','m.tool_id','t.name','t.code',"'lost'",'m.qty_reported',lossOutcomeSql('missing','m',$status),'m.reported_at','m.reported_by_name'],
        'missing_reports m LEFT JOIN tools t ON t.id=m.tool_id LEFT JOIN borrowers b ON b.id=m.borrower_id');
    $returns=$select(["'return'",'r.id','r.txn_id','r.borrower_id','COALESCE(b.full_name,r.borrower_name_snapshot)','COALESCE(b.id_number,r.borrower_id_number_snapshot)','r.tool_id','t.name','t.code',"'damaged'",'r.qty',"'Historical Return'",'r.created_at','NULL'],
        "transactions r LEFT JOIN tools t ON t.id=r.tool_id LEFT JOIN borrowers b ON b.id=r.borrower_id WHERE r.type='return' AND r.`condition`='damaged'
        AND NOT EXISTS(SELECT 1 FROM replacement_incident_sources s WHERE s.return_transaction_id=r.id)
        AND NOT EXISTS(SELECT 1 FROM replacement_requests x WHERE x.tool_id=r.tool_id AND x.responsible_borrower_id=r.borrower_id AND x.workflow_mode='legacy' AND x.reason='damaged' AND x.status<>'rejected')");
    return $cases.' UNION ALL '.$missing.' UNION ALL '.$returns;
}
function caseUiSource():string {
    $closed="z.inventory_status IN ('Resolved','Rejected','Historical Return','Closed — Lost','Closed — Recovered','Closed — Mixed Outcome')";
    $remaining="CASE WHEN $closed THEN 0 WHEN z.source='missing' THEN (SELECT CASE WHEN status='pending' THEN qty_reported ELSE GREATEST(0,qty_missing-qty_replaced-qty_recovered+qty_surplus-qty_written_off+qty_late_recovered) END FROM missing_reports WHERE id=z.id) WHEN z.source='case' THEN (SELECT GREATEST(0,COALESCE(incident_quantity,quantity_needed)-resolved_quantity) FROM replacement_requests WHERE id=z.id) ELSE 0 END";
    $start="CASE WHEN z.source='missing' THEN (SELECT missing_date FROM missing_reports WHERE id=z.id) ELSE z.created_at END";
    $end="CASE WHEN NOT ($closed) THEN NULL WHEN z.source='missing' THEN (SELECT COALESCE(resolved_at,IF(status='rejected',confirmed_at,NULL)) FROM missing_reports WHERE id=z.id) WHEN z.source='case' THEN COALESCE((SELECT MAX(decided_at) FROM loss_writeoffs WHERE source='case' AND incident_id=z.id AND status='approved'),(SELECT MAX(created_at) FROM replacement_request_events WHERE request_id=z.id AND action='inventory_resolution')) ELSE z.created_at END";
    return "SELECT z.*,$remaining AS remaining_units,$start AS incident_started_at,$end AS closed_at,($closed) AS is_closed,
      GREATEST(0,DATEDIFF(COALESCE($end,CURDATE()),$start)) AS age_days,
      (NOT ($closed) AND $remaining>0 AND DATEDIFF(CURDATE(),$start)>=".lossReviewDays().") AS needs_review,
      (SELECT COUNT(*) FROM loss_writeoffs w WHERE w.source=z.source AND w.incident_id=z.id AND w.status='pending') AS pending_writeoff_count
      FROM (".caseTrackingSource().") z";
}
function caseTrackingSummary(PDO $db):array {
    $stock=$db->query('SELECT COALESCE(SUM(missing_qty),0) active_missing_units,COALESCE(SUM(damaged_qty),0) damaged_units FROM tools')->fetch();
    $stock['pending_requests']=$db->query("SELECT COUNT(*) FROM loss_writeoffs WHERE status='pending'")->fetchColumn();
    $stock['review_cases']=$db->query('SELECT COUNT(*) FROM ('.caseUiSource().') i WHERE needs_review=1')->fetchColumn();
    return array_map('intval',$stock)+['review_days'=>lossReviewDays()];
}
function caseTrackingList(PDO $db,array $filters):array {
    $where=['1=1'];$params=[];
    if(isset($filters['borrower_id'])){$where[]='i.borrower_id=?';$params[]=(int)$filters['borrower_id'];}
    $search=trim((string)($filters['search']??''));
    if($search!==''){$where[]='(i.case_id LIKE ? OR i.borrower LIKE ? OR i.borrower_number LIKE ? OR i.tool_name LIKE ? OR i.tool_code LIKE ?)';$like='%'.addcslashes(mb_substr($search,0,150),'%_\\').'%';array_push($params,$like,$like,$like,$like,$like);}
    foreach(['incident'=>'incident','inventory'=>'inventory_status'] as $key=>$col){$v=(string)($filters[$key]??'');if($v==='Active missing'){$where[]="i.incident='lost' AND i.inventory_status IN ('Missing','Partially Closed','Confirmed Lost')";}elseif($v==='Needs Review'){$where[]="i.needs_review=1";}elseif($v==='Pending write-off'){$where[]="EXISTS(SELECT 1 FROM loss_writeoffs w WHERE w.source=i.source AND w.incident_id=i.id AND w.status='pending')";}elseif($key==='inventory'&&$v==='Resolved'){$where[]="i.inventory_status IN ('Resolved','Closed — Recovered','Closed — Lost','Closed — Mixed Outcome')";}elseif($v!==''){$where[]="i.$col=?";$params[]=$v;}}
    if(($filters['filter']??'all')==='open')$where[]="i.inventory_status NOT IN ('Resolved','Rejected','Closed — Lost','Closed — Recovered','Closed — Mixed Outcome')";
    $tab=(string)($filters['tab']??'all');
    if(!in_array($tab,['active','pending','closed','all'],true))throw new ReplacementPaymentError('Unknown case tab.');
    if($tab==='active')$where[]='i.is_closed=0';
    if($tab==='pending')$where[]='i.pending_writeoff_count>0';
    if($tab==='closed')$where[]='i.is_closed=1';
    $from='('.caseUiSource().') i WHERE '.implode(' AND ',$where);
    $q=$db->prepare('SELECT COUNT(*) FROM '.$from);$q->execute($params);$total=(int)$q->fetchColumn();
    $per=25;$page=max(1,min((int)($filters['page']??1),max(1,(int)ceil($total/$per))));$sort=($filters['sort']??'newest')==='oldest'?'ASC':'DESC';
    $q=$db->prepare("SELECT i.* FROM $from ORDER BY i.created_at $sort,i.source,i.id $sort LIMIT ? OFFSET ?");$q->execute(array_merge($params,[$per,($page-1)*$per]));
    $rows=$q->fetchAll();
    foreach($rows as &$item)if($item['source']==='case'){
        $s=$db->prepare('SELECT resolved_quantity,repair_quantity FROM replacement_requests WHERE id=?');$s->execute([$item['id']]);$c=$s->fetch();
        $item['unresolved_quantity']=max(0,(int)$item['quantity']-(int)$c['resolved_quantity']);$item['repair_quantity']=(int)$c['repair_quantity'];
    }unset($item);
    return ['rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$per];
}
function casePublicRow(array $row):array {
    $row['unresolved_quantity']=max(0,(int)($row['incident_quantity']??$row['quantity_needed'])-(int)$row['resolved_quantity']);
    return array_intersect_key($row,array_flip(['id','tool_id','tool_name_snapshot','reason','quantity_needed','incident_quantity','resolved_quantity','repair_quantity','unresolved_quantity','notes','requested_by_snapshot','responsible_borrower_id','responsible_name_snapshot','missing_report_id','inventory_status','created_at','payment_version','parent_case_id','student_name','student_id','tool_code','incident_date']));
}
function caseManualCreate(PDO $db,array $b,array $actor):array {
    $token=rpToken($b);$hash=rpPayload($b);$kind=(string)($b['reason']??'damaged');
    $qty=filter_var($b['quantity_needed']??0,FILTER_VALIDATE_INT);$notes=trim((string)($b['notes']??''));
    if(!in_array($kind,['damaged','lost'],true)||!$qty||$qty<1||$notes==='')throw new ReplacementPaymentError('Enter incident type, whole quantity and incident description.');
    $db->beginTransaction();
    try{
        if($id=rpReplay($db,$token,$hash,$actor)){$db->commit();return ['id'=>$id,'duplicate'=>true];}
        $q=$db->prepare('SELECT * FROM tools WHERE code=? AND is_active=1 FOR UPDATE');$q->execute([trim((string)($b['tool_code']??''))]);$tool=$q->fetch();
        $q=$db->prepare('SELECT * FROM borrowers WHERE id_number=?');$q->execute([trim((string)($b['borrower_identifier']??''))]);$borrower=$q->fetch();
        if(!$tool||!$borrower)throw new ReplacementPaymentError('Enter an existing tool SKU and borrower ID number.');
        if($qty>(int)$tool['available'])throw new ReplacementPaymentError('Only available units can be reported here. Use Return Tool for borrowed units.');
        if($id=rpReplay($db,$token,$hash,$actor)){$db->commit();return ['id'=>$id,'duplicate'=>true];}
        $db->prepare("INSERT INTO replacement_requests(tool_id,tool_name_snapshot,reason,quantity_needed,incident_quantity,notes,requested_by,requested_by_snapshot,responsible_borrower_id,responsible_name_snapshot,workflow_mode,financial_status,submission_token,inventory_status) VALUES(?,?,?,?,?,?,?,?,?,?,'tracking','not_applicable',?,?)")->execute([$tool['id'],$tool['name'],$kind,$qty,$qty,mb_substr($notes,0,500),$actor['id'],$actor['name'],$borrower['id'],$borrower['full_name'],$token,$kind==='lost'?'Missing':'Pending Review']);
        $id=(int)$db->lastInsertId();$bucket=$kind==='lost'?'missing_qty':'damaged_qty';
        $db->prepare("UPDATE tools SET available=available-?,minor_qty=LEAST(minor_qty,available),$bucket=$bucket+? WHERE id=?")->execute([$qty,$qty,$tool['id']]);
        rpEvent($db,$id,'manual_incident',[],rpRow($db,$id),$notes,$token,$hash,$actor);$db->commit();return ['id'=>$id];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
// Case creation participates in the caller's return transaction.
function caseAutoCreate(PDO $db,string $kind,int $source,array $loan,array $tool,int $qty,string $notes=''):int {
    if(!$db->inTransaction())throw new LogicException('A return transaction is required.');
    $column=$kind==='damaged'?'return_transaction_id':'missing_report_id';
    $q=$db->prepare("SELECT request_id FROM replacement_incident_sources WHERE $column=? LIMIT 1");$q->execute([$source]);
    if($id=$q->fetchColumn())return (int)$id;
    $actor=rpActor($db);$token='auto_'.$kind.'_'.$source;
    $db->prepare("INSERT INTO replacement_requests(tool_id,tool_name_snapshot,reason,quantity_needed,incident_quantity,notes,requested_by,requested_by_snapshot,responsible_borrower_id,responsible_name_snapshot,missing_report_id,workflow_mode,financial_status,submission_token,inventory_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,'tracking','not_applicable',?,?)")
       ->execute([$tool['id'],$tool['name'],$kind,$qty,$qty,$notes,$actor['id'],$actor['name'],$loan['borrower_id'],$loan['borrower_name_snapshot'],$kind==='lost'?$source:null,$token,$kind==='lost'?'Missing':'Pending Review']);
    $id=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO replacement_incident_sources(request_id,return_transaction_id,missing_report_id,quantity) VALUES(?,?,?,?)')->execute([$id,$kind==='damaged'?$source:null,$kind==='lost'?$source:null,$qty]);
    rpEvent($db,$id,'automatic_incident',[],rpRow($db,$id),$notes,null,null,$actor);return $id;
}

function caseInventory(PDO $db,array $r,array $b,array $actor):void {
    $action=(string)($b['inventory_action']??'');$notes=trim((string)($b['notes']??''));
    // Loss retirement must use the approved, quantity-based loss ledger.
    if($r['reason']==='lost'&&in_array($action,['lost','received'],true))throw new ReplacementPaymentError('Use Write Off as Lost to retire missing stock. Add future stock separately.');
    if($notes==='')throw new ReplacementPaymentError('Record the inspection, reference or resolution reason.');
    if($actor['role']!==ROLE_ADMIN){
        if($action!=='request')throw new ReplacementPaymentError('Admin must authorize inventory changes.');
        return; // rpAction records the request in the case timeline.
    }
    if(!$r['tool_id'])throw new ReplacementPaymentError('Resolve each tool case individually.');
    $state=$r['inventory_status'];$remaining=(int)($r['incident_quantity']??$r['quantity_needed'])-(int)$r['resolved_quantity'];
    $qty=$remaining;
    if(isset($b['quantity'])){
        $raw=$b['quantity'];
        if(!is_int($raw)&&!(is_string($raw)&&ctype_digit($raw)))throw new ReplacementPaymentError('Enter a positive whole action quantity.');
        $qty=(int)$raw;
    }
    if($qty<1||$qty>$remaining)throw new ReplacementPaymentError('Action quantity exceeds the unresolved case balance.');
    if($r['reason']!=='damaged'&&$qty!==$remaining)throw new ReplacementPaymentError('Use the existing Missing Report workflow for partial loss recovery.');
    if($r['reason']==='damaged'&&in_array($action,['replacement','received'],true)&&$qty!==$remaining)throw new ReplacementPaymentError('Replacement must cover the remaining case balance.');
    $allowed=['Pending Review'=>['repair','available','dispose','replacement'],'Under Repair'=>['repair','available','dispose','replacement'],'Missing'=>['lost','recovered','replacement'],'Partially Closed'=>['recovered'],'Confirmed Lost'=>['recovered','replacement'],'Recovered'=>['available','repair'],'Awaiting Replacement'=>['received']];
    if(!in_array($action,$allowed[$state]??[],true))throw new ReplacementPaymentError('This inventory action is no longer valid. Refresh the case.');
    $missing=null;
    if($r['reason']==='lost'&&$r['missing_report_id']){
        $q=$db->prepare('SELECT * FROM missing_reports WHERE id=? FOR UPDATE');$q->execute([$r['missing_report_id']]);$missing=$q->fetch();
        if(!$missing||!in_array($missing['status'],['confirmed','resolved'],true))throw new ReplacementPaymentError('Admin must first confirm the linked Missing Tool report.');
    }
    $q=$db->prepare('SELECT * FROM tools WHERE id=? FOR UPDATE');$q->execute([$r['tool_id']]);$tool=$q->fetch();
    $next=['repair'=>'Under Repair','available'=>'Resolved','dispose'=>'Resolved','lost'=>'Confirmed Lost','recovered'=>'Recovered','replacement'=>'Awaiting Replacement','received'=>'Resolved'][$action];
    // Missing reports keep the existing loan and recovery counters authoritative.
    if($action==='recovered'){
        if(($missing&&missingOpenQty($missing)<$qty)||(int)$tool['missing_qty']<$qty)throw new ReplacementPaymentError('These originals were already recovered or replaced. Review the missing report.');
        $db->prepare('UPDATE tools SET missing_qty=missing_qty-?,damaged_qty=damaged_qty+? WHERE id=?')->execute([$qty,$qty,$tool['id']]);
        if($missing){
            $db->prepare('UPDATE missing_reports SET qty_recovered=qty_recovered+? WHERE id=?')->execute([$qty,$missing['id']]);
            missingFinishIfResolved($db,(int)$missing['id'],missingOpenQty($missing)-$qty);
            missingEvent($db,(int)$missing['id'],'recovered',$qty,'Case #'.$r['id'].'; unavailable pending inspection: '.$notes);
        }
    }
    if($action==='available'){
        if((int)$tool['damaged_qty']<$qty)throw new ReplacementPaymentError('Affected units are no longer in the damaged bucket. Review stock history.');
        $db->prepare('UPDATE tools SET damaged_qty=damaged_qty-?,available=available+? WHERE id=?')->execute([$qty,$qty,$tool['id']]);
    }
    if($action==='received'){
        if(($b['received_confirmed']??false)!==true)throw new ReplacementPaymentError('Confirm physical receipt before adding stock.');
        // Retire the original damaged units, then reuse Add Stock for the received units.
        $bucket=$r['reason']==='lost'?'missing_qty':'damaged_qty';
        if((int)$tool[$bucket]<$qty||($missing&&missingOpenQty($missing)<$qty))throw new ReplacementPaymentError('Review original units before replacing them.');
        $db->prepare("UPDATE tools SET $bucket=$bucket-?,quantity=quantity-? WHERE id=?")->execute([$qty,$qty,$tool['id']]);
        if($missing){
            $db->prepare('UPDATE missing_reports SET qty_replaced=qty_replaced+? WHERE id=?')->execute([$qty,$missing['id']]);
            missingFinishIfResolved($db,(int)$missing['id'],missingOpenQty($missing)-$qty);
            missingEvent($db,(int)$missing['id'],'replacement_received',$qty,'Case #'.$r['id'].': '.$notes);
        }
        $db->prepare("INSERT INTO stock_history(tool_id,tool_name,tool_code,transaction_type,quantity,previous_stock,updated_stock,remarks,user_id,user_name,event_kind) VALUES(?,?,?,'Stock Removed',?,?,?,?,?,?,'adjustment')")->execute([$tool['id'],$tool['name'],$tool['code'],$qty,$tool['quantity'],(int)$tool['quantity']-$qty,'Case #'.$r['id'].' original units retired: '.$notes,$actor['id'],$actor['name']]);
        addToolStock($db,(int)$tool['id'],$qty,rpToken($b),'Replacement for case #'.$r['id'].': '.$notes,(int)$actor['id'],$actor['name'],false);
    }
    if($action==='available')$db->prepare("INSERT INTO inventory_adjustments(tool_id,action,from_state,to_state,qty,notes,user_id) VALUES (?,'repair','damaged','good',?,?,?)")->execute([$tool['id'],$qty,'Case #'.$r['id'].': '.$notes,$actor['id']]);
    if($action==='dispose')disposeDamagedTool($db,['id'=>(int)$r['tool_id'],'quantity'=>$qty,'reason'=>$notes,'case_id'=>(int)$r['id'],'request_token'=>rpToken($b)],$actor,false);
    if(in_array($action,['available','received','dispose'],true)){
        $db->prepare('UPDATE replacement_requests SET resolved_quantity=resolved_quantity+?,repair_quantity=GREATEST(0,repair_quantity-?) WHERE id=?')->execute([$qty,$qty,$r['id']]);
        if($qty<$remaining)$next=((int)$r['repair_quantity']>$qty)?'Under Repair':'Pending Review';
    }
    if($action==='repair'){
        if($qty>$remaining-(int)$r['repair_quantity'])throw new ReplacementPaymentError('These units are already marked Under Repair.');
        $db->prepare('UPDATE replacement_requests SET repair_quantity=repair_quantity+? WHERE id=?')->execute([$qty,$r['id']]);
    }
    $db->prepare('UPDATE replacement_requests SET inventory_status=? WHERE id=?')->execute([$next,$r['id']]);
    if(!empty($r['parent_case_id'])){
        $q=$db->prepare("SELECT COUNT(*) FROM replacement_requests WHERE parent_case_id=? AND inventory_status<>'Resolved'");$q->execute([$r['parent_case_id']]);
        $db->prepare('UPDATE replacement_requests SET inventory_status=?,payment_version=payment_version+1 WHERE id=?')->execute([$q->fetchColumn()?'Pending Review':'Resolved',$r['parent_case_id']]);
    }
}
