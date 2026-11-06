<?php
// Loss outcomes are separate from physical recovery and borrower accountability.
function installWriteoffs(PDO $db):void {
    $cols=array_column($db->query('SHOW COLUMNS FROM missing_reports')->fetchAll(),'Field');
    foreach(['qty_written_off','qty_late_recovered'] as $c)if(!in_array($c,$cols,true))$db->exec("ALTER TABLE missing_reports ADD COLUMN $c INT NOT NULL DEFAULT 0");
    $db->exec("CREATE TABLE IF NOT EXISTS loss_writeoffs (
      id INT AUTO_INCREMENT PRIMARY KEY, source VARCHAR(10) NOT NULL, incident_id INT NOT NULL,
      tool_id INT NOT NULL, borrower_id INT NULL, quantity INT NOT NULL,
      reason TEXT NOT NULL, investigation_notes TEXT NOT NULL,
      status VARCHAR(12) NOT NULL, requester_id INT NOT NULL, requester_name VARCHAR(120) NOT NULL,
      requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      decision_id INT NULL, decision_name VARCHAR(120) NULL, decided_at DATETIME NULL, rejection_reason TEXT NULL,
      recovered_quantity INT NOT NULL DEFAULT 0, accountability_status VARCHAR(30) NOT NULL DEFAULT 'Review required',
      INDEX incident_lookup(source,incident_id,status), INDEX borrower_lookup(borrower_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS loss_writeoff_events (
      id INT AUTO_INCREMENT PRIMARY KEY, writeoff_id INT NOT NULL, action VARCHAR(30) NOT NULL,
      quantity INT NOT NULL DEFAULT 0, condition_name VARCHAR(12) NULL, notes TEXT NOT NULL,
      user_id INT NOT NULL, user_name VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      request_token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
      payload_hash CHAR(64) NOT NULL, before_state LONGTEXT NOT NULL, after_state LONGTEXT NOT NULL,
      INDEX writeoff_history(writeoff_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q=$db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_loss_event_writeoff'");
    if(!$q->fetchColumn())$db->exec('ALTER TABLE loss_writeoff_events ADD CONSTRAINT fk_loss_event_writeoff FOREIGN KEY(writeoff_id) REFERENCES loss_writeoffs(id)');
}
function lossReviewDays():int {return max(1,(int)(getenv('TOOLTRACK_LOSS_REVIEW_DAYS')?:14));}
function lossOutcomeSql(string $source,string $a,string $fallback):string {
    $gross="COALESCE((SELECT SUM(w.quantity) FROM loss_writeoffs w WHERE w.source='$source' AND w.incident_id=$a.id AND w.status='approved'),0)";
    $late="COALESCE((SELECT SUM(w.recovered_quantity) FROM loss_writeoffs w WHERE w.source='$source' AND w.incident_id=$a.id AND w.status='approved'),0)";
    $open=$source==='missing'?"($a.qty_missing-$a.qty_replaced-$a.qty_recovered+$a.qty_surplus-$a.qty_written_off+$a.qty_late_recovered)":"(COALESCE($a.incident_quantity,$a.quantity_needed)-$a.resolved_quantity)";
    $rec=$source==='missing'?"$a.qty_recovered":"($a.resolved_quantity-$gross+$late)";
    return "CASE WHEN $gross>0 THEN CASE WHEN $open>0 THEN 'Partially Closed' WHEN $gross-$late<=0 THEN 'Closed — Recovered' WHEN $rec>0 THEN 'Closed — Mixed Outcome' ELSE 'Closed — Lost' END WHEN ".($source==='missing'?"$a.qty_recovered>0 AND $a.qty_replaced=0":"0")." THEN CASE WHEN $open>0 THEN 'Partially Closed' ELSE 'Closed — Recovered' END ELSE $fallback END";
}
function lossContext(PDO $db,string $source,int $id,bool $lock=false):array {
    if(!in_array($source,['missing','case'],true))throw new ReplacementPaymentError('Invalid incident source.');
    $table=$source==='missing'?'missing_reports':'replacement_requests';
    $q=$db->prepare("SELECT * FROM $table WHERE id=?".($lock?' FOR UPDATE':''));$q->execute([$id]);$r=$q->fetch();
    if(!$r)throw new ReplacementPaymentError('Incident not found.');
    if($source==='case'&&($r['reason']!=='lost'||$r['missing_report_id']))throw new ReplacementPaymentError('Use the original confirmed Missing Report for this incident.');
    $eligible=$source==='missing'?($r['status']==='confirmed'?max(0,missingOpenQty($r)):0):
        (in_array($r['inventory_status'],['Missing','Confirmed Lost','Partially Closed'],true)?max(0,(int)($r['incident_quantity']??$r['quantity_needed'])-(int)$r['resolved_quantity']):0);
    return ['row'=>$r,'tool_id'=>(int)$r['tool_id'],'borrower_id'=>$r[$source==='missing'?'borrower_id':'responsible_borrower_id'],'eligible'=>$eligible];
}
function lossDetails(PDO $db,string $source,int $id):array {
    $c=lossContext($db,$source,$id);$r=$c['row'];
    $q=$db->prepare('SELECT * FROM loss_writeoffs WHERE source=? AND incident_id=? ORDER BY id');$q->execute([$source,$id]);$requests=$q->fetchAll();
    $gross=0;$late=0;$pending=0;
    foreach($requests as &$w){if($w['status']==='approved'){$gross+=(int)$w['quantity'];$late+=(int)$w['recovered_quantity'];}if($w['status']==='pending')$pending+=(int)$w['quantity'];
        $q=$db->prepare('SELECT action,quantity,condition_name,notes,user_name,created_at FROM loss_writeoff_events WHERE writeoff_id=? ORDER BY id');$q->execute([$w['id']]);$w['events']=$q->fetchAll();
    }unset($w);
    $original=(int)($source==='missing'?$r['qty_reported']:($r['incident_quantity']??$r['quantity_needed']));
    $recovered=$source==='missing'?(int)$r['qty_recovered']:max(0,(int)$r['resolved_quantity']-$gross+$late);
    $outcome=$source==='missing'?(['confirmed'=>'Missing','pending'=>'Pending Confirmation','resolved'=>'Resolved','rejected'=>'Rejected'][$r['status']]??ucfirst($r['status'])):$r['inventory_status'];
    if($gross>0||($source==='missing'&&(int)$r['qty_recovered']>0&&(int)$r['qty_replaced']===0)){
        $outcome=$c['eligible']>0?'Partially Closed':($gross-$late>0?($recovered>0?'Closed — Mixed Outcome':'Closed — Lost'):'Closed — Recovered');
    }
    $date=$source==='missing'?$r['missing_date']:$r['created_at'];$age=max(0,(int)(new DateTimeImmutable(substr($date,0,10)))->diff(new DateTimeImmutable('today'))->format('%r%a'));
    $q=$db->prepare('SELECT name,quantity,available,missing_qty FROM tools WHERE id=?');$q->execute([$c['tool_id']]);
    return ['source'=>$source,'id'=>$id,'original'=>$original,'eligible'=>$c['eligible'],'pending_quantity'=>$pending,'gross_written_off'=>$gross,'later_recovered'=>$late,'net_loss'=>$gross-$late,'recovered'=>$recovered,'outcome'=>$outcome,'age_days'=>$age,'needs_review'=>$c['eligible']>0&&$age>=lossReviewDays(),'review_days'=>lossReviewDays(),'tool'=>$q->fetch(),'requests'=>$requests];
}
function lossAction(PDO $db,array $b,array $actor):array {
    $source=(string)($b['source']??'');$id=(int)($b['id']??0);$action=(string)($b['action']??'');
    if(!in_array($action,['request','direct','approve','reject','recover'],true))throw new ReplacementPaymentError('Unknown loss action.');
    if($action!=='request'&&$actor['role']!==ROLE_ADMIN)throw new ReplacementPaymentError('Only an Admin may decide or execute a write-off or later recovery.');
    $token=rpToken($b);$hash=rpPayload($b);$notes=trim((string)($b['notes']??''));$reason=trim((string)($b['reason']??''));
    if(mb_strlen($notes)>2000||mb_strlen($reason)>2000)throw new ReplacementPaymentError('Reason and investigation notes must each be at most 2,000 characters.');
    $db->beginTransaction();
    try{
        $c=lossContext($db,$source,$id); // identify tool; all stock mutations serialize on this row
        $q=$db->prepare('SELECT * FROM tools WHERE id=? FOR UPDATE');$q->execute([$c['tool_id']]);$tool=$q->fetch();
        $c=lossContext($db,$source,$id,true);
        $q=$db->prepare('SELECT * FROM loss_writeoff_events WHERE request_token=? FOR UPDATE');$q->execute([$token]);
        if($e=$q->fetch()){if($e['payload_hash']!==$hash||(int)$e['user_id']!==(int)$actor['id'])throw new ReplacementPaymentError('Token already used with different details.');$db->commit();return ['duplicate'=>true];}
        $w=null;$wid=(int)($b['writeoff_id']??0);
        if(in_array($action,['approve','reject','recover'],true)){
            $q=$db->prepare('SELECT * FROM loss_writeoffs WHERE id=? AND source=? AND incident_id=? FOR UPDATE');$q->execute([$wid,$source,$id]);$w=$q->fetch();
            if(!$w)throw new ReplacementPaymentError('Write-off request not found.');
            if($action==='recover'?$w['status']!=='approved':$w['status']!=='pending')throw new ReplacementPaymentError('This request has already been decided.');
        }
        $raw=$b['quantity']??0;$qty=(is_int($raw)||(is_string($raw)&&ctype_digit($raw)))?(int)$raw:0;
        if(in_array($action,['approve','reject'],true))$qty=(int)$w['quantity'];
        if($qty<1||$qty>100000)throw new ReplacementPaymentError('Enter a positive whole-number quantity up to 100,000.');
        if($action==='reject'&&$reason==='')throw new ReplacementPaymentError('Rejection reason is required.');
        if(in_array($action,['request','direct'],true)&&($reason===''||($action==='request'&&$notes==='')))throw new ReplacementPaymentError('Reason and staff investigation notes are required.');
        if(in_array($action,['request','direct','approve'],true)){
            if($qty>$c['eligible']||$qty>(int)$tool['missing_qty']||$qty>(int)$tool['quantity'])throw new ReplacementPaymentError('Eligible missing quantity changed. Refresh and review this request.');
            if($action!=='approve'){$q=$db->prepare("SELECT id FROM loss_writeoffs WHERE source=? AND incident_id=? AND status='pending' FOR UPDATE");$q->execute([$source,$id]);if($q->fetch())throw new ReplacementPaymentError('Decide the existing pending request before submitting another.');}
        }
        $before=$tool;
        if(in_array($action,['request','direct'],true)){
            $db->prepare("INSERT INTO loss_writeoffs(source,incident_id,tool_id,borrower_id,quantity,reason,investigation_notes,status,requester_id,requester_name) VALUES(?,?,?,?,?,?,?,'pending',?,?)")->execute([$source,$id,$c['tool_id'],$c['borrower_id'],$qty,$reason,$notes,$actor['id'],$actor['name']]);$wid=(int)$db->lastInsertId();
        }
        if(in_array($action,['direct','approve'],true)){
            if(!isset($b['expected_total'])||(int)$b['expected_total']!==(int)$tool['quantity']||!isset($b['expected_available'])||(int)$b['expected_available']!==(int)$tool['available'])throw new ReplacementPaymentError('Stock changed since confirmation. Refresh to review the exact impact.');
            $db->prepare('UPDATE tools SET quantity=quantity-?,missing_qty=missing_qty-? WHERE id=?')->execute([$qty,$qty,$c['tool_id']]);
            $db->prepare("UPDATE loss_writeoffs SET status='approved',decision_id=?,decision_name=?,decided_at=NOW() WHERE id=?")->execute([$actor['id'],$actor['name'],$wid]);
            if($source==='missing'){
                $db->prepare('UPDATE missing_reports SET qty_written_off=qty_written_off+? WHERE id=?')->execute([$qty,$id]);
                missingFinishIfResolved($db,$id,$c['eligible']-$qty);
                missingEvent($db,$id,'written_off',$qty,'Loss approval #'.$wid.'; borrower accountability retained');
            }else $db->prepare('UPDATE replacement_requests SET resolved_quantity=resolved_quantity+?,payment_version=payment_version+1,inventory_status=? WHERE id=?')->execute([$qty,$qty===$c['eligible']?'Resolved':'Partially Closed',$id]);
        }
        if($action==='reject')$db->prepare("UPDATE loss_writeoffs SET status='rejected',decision_id=?,decision_name=?,decided_at=NOW(),rejection_reason=? WHERE id=?")->execute([$actor['id'],$actor['name'],$reason,$wid]);
        if($action==='recover'){
            $condition=(string)($b['condition']??'');
            if($notes===''||!in_array($condition,['good','minor','damaged'],true)||$qty>(int)$w['quantity']-(int)$w['recovered_quantity'])throw new ReplacementPaymentError('Enter inspection notes, condition and a quantity within the outstanding written-off balance.');
            if((int)$tool['quantity']+$qty>100000)throw new ReplacementPaymentError('Recovery exceeds the supported stock total of 100,000.');
            [$avl,$minor,$damaged]=bucketMove($tool,$condition,$qty);
            $db->prepare('UPDATE tools SET quantity=quantity+?,available=?,minor_qty=?,damaged_qty=? WHERE id=?')->execute([$qty,$avl,$minor,$damaged,$c['tool_id']]);
            $db->prepare('UPDATE loss_writeoffs SET recovered_quantity=recovered_quantity+? WHERE id=?')->execute([$qty,$wid]);
            if($source==='missing')$db->prepare('UPDATE missing_reports SET qty_recovered=qty_recovered+?,qty_late_recovered=qty_late_recovered+? WHERE id=?')->execute([$qty,$qty,$id]);
            if($condition==='damaged'){
                $r=$c['row'];$db->prepare("INSERT INTO replacement_requests(tool_id,tool_name_snapshot,reason,quantity_needed,incident_quantity,notes,requested_by,requested_by_snapshot,responsible_borrower_id,responsible_name_snapshot,workflow_mode,financial_status,inventory_status) VALUES(?,?,'damaged',?,?,?,?,?,?,?,'tracking','not_applicable','Pending Review')")->execute([$c['tool_id'],$tool['name'],$qty,$qty,'Recovered from write-off #'.$wid.': '.$notes,$actor['id'],$actor['name'],$c['borrower_id'],$source==='missing'?$r['borrower_name_snapshot']:$r['responsible_name_snapshot']]);
                $damagedId=(int)$db->lastInsertId();rpEvent($db,$damagedId,'manual_incident',[],rpRow($db,$damagedId),'Later recovery inspection; loss #'.$wid,null,null,$actor);
            }
        }
        $q=$db->prepare('SELECT * FROM tools WHERE id=?');$q->execute([$c['tool_id']]);$after=$q->fetch();
        if(in_array($action,['direct','approve','recover'],true)){
            $db->prepare('INSERT INTO stock_history(tool_id,tool_name,tool_code,transaction_type,quantity,previous_stock,updated_stock,remarks,user_id,user_name,event_kind) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$c['tool_id'],$tool['name'],$tool['code'],$action==='recover'?'Stock Added':'Stock Removed',$qty,$tool['quantity'],$after['quantity'],'Loss #'.$wid.' '.$action.': '.$reason.' '.$notes,$actor['id'],$actor['name'],$action==='recover'?'loss_recovery':'loss_writeoff']);
        }
        $db->prepare('INSERT INTO loss_writeoff_events(writeoff_id,action,quantity,condition_name,notes,user_id,user_name,request_token,payload_hash,before_state,after_state) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$wid,$action,$qty,$b['condition']??null,$reason."\n".$notes,$actor['id'],$actor['name'],$token,$hash,json_encode($before),json_encode($after)]);
        logAudit('loss_writeoff_'.$action,'Loss #'.$wid.'; '.$source.' incident #'.$id.'; '.$qty.' unit(s). Borrower accountability retained.');
        $db->commit();return ['writeoff_id'=>$wid];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
