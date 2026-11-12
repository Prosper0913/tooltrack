<?php
// Grouped history is opt-in; return forms and incident endpoints still consume loan lines.
function borrowSessionHistory(PDO $db,array $filters): array {
    $ready=borrowSessionsReady($db);
    $key=$ready?"COALESCE(CONCAT('S',t.borrow_session_id),CONCAT('L',t.id))":"CONCAT('L',t.id)";
    $session=$ready?'MAX(t.borrow_session_id)':'NULL';
    $out=sqlOutstanding('t');$overdue=sqlIsOverdue('t');$due=sqlDueAt('t');
    $params=[];$search=trim(mb_substr((string)($filters['search']??''),0,150));$match='1';
    if($search!==''){
        $match="MAX(t.txn_id LIKE ? OR tl.name LIKE ? OR tl.code LIKE ? OR COALESCE(b.full_name,t.borrower_name_snapshot) LIKE ? OR COALESCE(b.id_number,t.borrower_id_number_snapshot) LIKE ?)";
        $params=array_fill(0,5,'%'.addcslashes($search,'%_\\').'%');
    }
    $source="(SELECT $key group_key,$session session_id,MIN(t.id) id,MIN(t.txn_id) txn_id,
        MIN(COALESCE(b.full_name,t.borrower_name_snapshot)) borrower,MIN(t.created_at) created_at,
        MIN(t.due_date) due_date,SUM(t.qty) qty,SUM(t.qty_returned) qty_returned,SUM(t.qty_missing) qty_missing,
        SUM($out) outstanding,COUNT(*) tools_count,
        CASE WHEN SUM(t.status='active' AND $out>0)>0 THEN 'active' ELSE 'returned' END status,
        MAX($overdue) is_overdue,
        MAX(t.status='active' AND $out>0 AND DATEDIFF(CURDATE(),t.due_date)>=".OVERDUE_FOLLOWUP_DAYS.") followup,
        MAX(t.status='active' AND $out>0 AND t.due_date=CURDATE() AND $due>=NOW()) due_today,
        $match matches_search
        FROM transactions t LEFT JOIN tools tl ON tl.id=t.tool_id LEFT JOIN borrowers b ON b.id=t.borrower_id
        WHERE t.type='borrow' GROUP BY $key) sessions";
    $status=(string)($filters['status']??'');$where='matches_search=1';
    if(in_array($status,['active','returned'],true)){$where.=' AND status=?';$params[]=$status;}
    elseif($status==='overdue')$where.=' AND is_overdue=1';
    elseif($status==='followup')$where.=' AND followup=1';
    elseif($status==='due_today')$where.=' AND due_today=1';
    elseif($status==='missing')$where.=' AND qty_missing>0';
    elseif($status!=='')fail('Unknown borrow history status.',422);
    $size=min(100,max(1,(int)($filters['per_page']??50)));
    $db->beginTransaction();
    try{
        $q=$db->prepare("SELECT COUNT(*) FROM $source WHERE $where");$q->execute($params);$total=(int)$q->fetchColumn();
        $page=max(1,min((int)($filters['page']??1),max(1,(int)ceil($total/$size))));
        $q=$db->prepare("SELECT * FROM $source WHERE $where ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?");$q->execute([...$params,$size,($page-1)*$size]);$groups=$q->fetchAll();
        $sessionIds=[];$legacyIds=[];
        foreach($groups as $g){if($g['session_id']!==null)$sessionIds[]=(int)$g['session_id'];else $legacyIds[]=(int)$g['id'];}
        $clauses=[];$ids=[];
        if($sessionIds){$clauses[]='t.borrow_session_id IN ('.implode(',',array_fill(0,count($sessionIds),'?')).')';$ids=[...$ids,...$sessionIds];}
        if($legacyIds){$clauses[]='t.id IN ('.implode(',',array_fill(0,count($legacyIds),'?')).')';$ids=[...$ids,...$legacyIds];}
        $byGroup=[];
        if($clauses){
            $q=$db->prepare("SELECT $key group_key,t.*,tl.name tool_name,tl.code tool_code,
                COALESCE(b.full_name,t.borrower_name_snapshot) borrower,
                $out outstanding,$overdue is_overdue,".sqlOverdueDays('t')." overdue_days,CURDATE() db_today,
                (SELECT r.id FROM missing_reports r WHERE r.borrow_txn_id=t.id AND r.status='pending' LIMIT 1) pending_report_id,
                (SELECT COUNT(*) FROM missing_reports r WHERE r.borrow_txn_id=t.id AND r.status='confirmed') open_missing_cases,
                (SELECT COUNT(*) FROM missing_reports r WHERE r.borrow_txn_id=t.id AND r.status='resolved') resolved_missing_cases,
                (SELECT COALESCE(SUM(r.qty),0) FROM transactions r WHERE r.borrow_txn_id=t.id AND r.type='return' AND r.`condition`='damaged') qty_damaged
                FROM transactions t LEFT JOIN tools tl ON tl.id=t.tool_id LEFT JOIN borrowers b ON b.id=t.borrower_id
                WHERE t.type='borrow' AND (".implode(' OR ',$clauses).') ORDER BY t.id');$q->execute($ids);
            foreach($q->fetchAll() as $line){
                $line['is_overdue']=(bool)(int)$line['is_overdue'];
                $line['circulation_status']=loanCirculationState($line);
                $line['loan_state']=(int)$line['open_missing_cases']>0?'missing':($line['status']==='active'?((int)$line['overdue_days']>=OVERDUE_FOLLOWUP_DAYS?'followup':($line['is_overdue']?'overdue':'borrowed')):((int)$line['resolved_missing_cases']>0?'resolved':'returned'));
                $byGroup[$line['group_key']][]=$line;
            }
        }
        foreach($groups as &$g){
            $g['is_overdue']=(bool)(int)$g['is_overdue'];
            $g['items']=$byGroup[$g['group_key']]??[];
            $missing=array_sum(array_column($g['items'],'open_missing_cases'));$resolved=array_sum(array_column($g['items'],'resolved_missing_cases'));
            $g['loan_state']=$missing?'missing':($g['status']==='active'?($g['followup']?'followup':($g['is_overdue']?'overdue':'borrowed')):($resolved?'resolved':'returned'));
            $g['circulation_status']=$g['is_overdue']?'overdue':($g['due_today']?'due_today':$g['status']);
            $g['qty_damaged']=array_sum(array_column($g['items'],'qty_damaged'));
        }unset($g);
        $db->commit();return ['success'=>true,'data'=>$groups,'total'=>$total,'page'=>$page,'per_page'=>$size];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
