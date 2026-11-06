<?php
// Read-only projection of the existing report SELECT. CSV and other reports keep their queries.
function groupedTransactionReport(PDO $db,string $source,array $columns,array $filters): array {
    $index=array_flip($columns);$col=fn(string $name):string=>'c'.$index[$name];
    $search=trim(mb_substr((string)($filters['search']??''),0,200));$params=[];$matches='1';
    if($search!==''){
        $terms=[];
        foreach($columns as $i=>$unused){$terms[]="LOCATE(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(COALESCE(c$i,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)>0";$params[]=$search;}
        $matches='MAX('.implode(' OR ',$terms).')';
    }
    $activeStatus="(_type='borrow' AND _status='active')";
    $active="($activeStatus AND _qty-_qty_returned-_qty_missing>0)";
    // Keep recorded legacy item statuses authoritative; never infer a historical closure.
    $status="CASE WHEN MAX($activeStatus)>0 THEN 'active' ELSE 'returned' END";
    $toolKey="COALESCE(CAST(_tool_id AS CHAR),_detail_key)";
    $toolCount="COUNT(DISTINCT $toolKey)";
    $expressions=[];
    foreach($columns as $i=>$name){
        $c='c'.$i;
        $expression=match($name){
            'TXN ID'=>"SUBSTRING(MIN(CONCAT(LPAD(_row_id,20,'0'),$c)),21)",
            'Status'=>$status,
            'Tool Name'=>"CONCAT($toolCount,IF($toolCount=1,' tool',' tools'))",
            'Tool Code'=>"GROUP_CONCAT(DISTINCT $c ORDER BY $c SEPARATOR ', ')",
            'Qty Borrowed','Qty Returned','Qty Missing'=>"SUM($c)",
            'Returned At'=>"CASE WHEN MIN(_type)='return' THEN MIN(COALESCE($c,_created)) ELSE MAX($c) END",
            'Condition','Notes'=>"GROUP_CONCAT(DISTINCT NULLIF($c,'') ORDER BY $c SEPARATOR '; ')",
            default=>"MIN($c)"
        };
        $expressions[]="$expression AS c$i";
    }
    $grouped='(SELECT '.implode(',',$expressions).",_group_key,MIN(_row_id) _row_id,MIN(_type) _type,$status _status,
        MIN(_created) _created,MIN(CASE WHEN _type='borrow' THEN ".$col('Borrowed At')." ELSE COALESCE(".$col('Returned At').",_created) END) _event_at,
        MIN(".$col('Tool Name').") _sort_tool_name,MIN(".$col('Tool Code').") _sort_tool_code,
        SUM(_qty) _qty,SUM(_qty_returned) _qty_returned,SUM(_qty_missing) _qty_missing,
        SUM(_qty_damaged) _qty_damaged,MAX(_is_overdue) _is_overdue,MAX(_pending_id) _pending_id,MAX(_open_missing) _open_missing,MAX(_resolved_missing) _resolved_missing,
        MAX($active AND _overdue_days>=".OVERDUE_FOLLOWUP_DAYS.") _followup,
        MAX($active AND DATE(_due_at)=CURDATE() AND _due_at>=NOW()) _due_today,
        $toolCount tool_count,COUNT(*) line_count,COUNT(DISTINCT _detail_key) detail_count,$matches _matches
        FROM $source GROUP BY _group_key) report_groups";
    $where='_matches=1';$category=(string)($filters['category']??'');
    if(in_array($category,['active','returned'],true)){$where.=" AND _type='borrow' AND _status=?";$params[]=$category;}
    elseif($category==='overdue')$where.=' AND _is_overdue=1';
    elseif($category==='followup')$where.=' AND _followup=1';
    elseif($category==='due_today')$where.=' AND _due_today=1';
    elseif($category==='missing')$where.=" AND _type='borrow' AND _qty_missing>0";
    elseif($category!=='')fail('Unknown transaction status filter.',422);
    $sortField=(string)($filters['sort']??'Transaction Date');
    $sort=match($sortField){'Transaction Date'=>'_event_at','Tool Name'=>'_sort_tool_name','Tool Code'=>'_sort_tool_code',default=>isset($index[$sortField])?'c'.$index[$sortField]:'_event_at'};
    $direction=strtolower((string)($filters['direction']??'desc'))==='asc'?'ASC':'DESC';
    $size=(int)($filters['per_page']??25);if(!in_array($size,[25,50,100],true))$size=25;
    $db->beginTransaction();
    try{
        $q=$db->prepare("SELECT COUNT(*) total,COALESCE(SUM(line_count),0) line_total,COALESCE(SUM(detail_count),0) detail_total FROM $grouped WHERE $where");$q->execute($params);$counts=$q->fetch();$total=(int)$counts['total'];
        $page=max(1,min((int)($filters['page']??1),max(1,(int)ceil($total/$size))));
        $q=$db->prepare("SELECT * FROM $grouped WHERE $where ORDER BY $sort $direction,_row_id $direction LIMIT ? OFFSET ?");$q->execute([...$params,$size,($page-1)*$size]);$headers=$q->fetchAll();
        $details=[];
        if($headers){
            $keys=array_column($headers,'_group_key');$ph=implode(',',array_fill(0,count($keys),'?'));
            // Fetch all members of matched groups, not merely the individual search/status matches.
            $q=$db->prepare("SELECT * FROM $source WHERE _group_key IN ($ph) ORDER BY _row_id");$q->execute($keys);
            foreach($q->fetchAll() as $record){
                $key=$record['_group_key'];$detailKey=$record['_detail_key'];
                $values=[];foreach($columns as $i=>$unused)$values[]=$record['c'.$i];
                if(!isset($details[$key][$detailKey])){
                    $details[$key][$detailKey]=['values'=>$values,'action'=>transactionReportAction($record,$columns),
                        'borrow_line_id'=>$record['_borrow_line_id']===null?null:(int)$record['_borrow_line_id'],
                        'borrow_txn_id'=>$record['_original_txn_id'],'original_qty'=>(int)$record['_original_qty'],
                        'qty_damaged'=>(int)$record['_qty_damaged'],'qty_missing'=>(int)$record['_missing_quantity'],
                        'event_ids'=>[(int)$record['_row_id']],'event_txn_ids'=>[$record[$col('TXN ID')]]];
                }else{
                    $item=&$details[$key][$detailKey];
                    foreach(['Qty Borrowed','Qty Returned','Qty Missing'] as $name)$item['values'][$index[$name]]=(int)$item['values'][$index[$name]]+(int)$record[$col($name)];
                    $item['values'][$index['Condition']]=implode('; ',array_unique(array_filter([...explode('; ',$item['values'][$index['Condition']]??''),$record[$col('Condition')]])));
                    $item['qty_damaged']+=(int)$record['_qty_damaged'];$item['qty_missing']=max($item['qty_missing'],(int)$record['_missing_quantity']);
                    $item['event_ids'][]=(int)$record['_row_id'];$item['event_txn_ids'][]=$record[$col('TXN ID')];unset($item);
                }
            }
        }
        $rows=[];$groups=[];$actions=[];
        foreach($headers as $h){
            $values=[];foreach($columns as $i=>$unused)$values[]=$h['c'.$i];
            $items=array_values($details[$h['_group_key']]??[]);
            foreach($items as &$item){
                $item['action']['qty_damaged']=$item['qty_damaged'];
                if($h['_type']==='return'){$item['values'][$index['Qty Missing']]=$item['qty_missing'];$item['action']['qty_missing']=$item['qty_missing'];}
            }unset($item);
            $action=['group_key'=>$h['_group_key'],'type'=>$h['_type'],'status'=>$h['_status'],'qty'=>(int)$h['_qty'],
                'qty_returned'=>(int)$h['_qty_returned'],'qty_missing'=>(int)$h['_qty_missing'],'is_overdue'=>(bool)(int)$h['_is_overdue'],
                'open_missing_cases'=>(int)$h['_open_missing'],'resolved_missing_cases'=>(int)$h['_resolved_missing']];
            $action['qty_damaged']=(int)$h['_qty_damaged'];
            $action['pending_report_id']=$h['_pending_id']===null?null:(int)$h['_pending_id'];
            if($h['_type']==='return'){$values[$index['Qty Missing']]=array_sum(array_column($items,'qty_missing'));$action['qty_missing']=$values[$index['Qty Missing']];}
            $rows[]=$values;$actions[]=$action;
            $groups[]=['key'=>$h['_group_key'],'tool_count'=>(int)$h['tool_count'],'line_count'=>(int)$h['line_count'],'items'=>$items,'action'=>$action];
        }
        $db->commit();
        return ['grouped'=>true,'columns'=>$columns,'rows'=>$rows,'groups'=>$groups,'actions'=>$actions,'total'=>$total,'transaction_count'=>$total,
            'line_total'=>(int)$counts['line_total'],'detail_total'=>(int)$counts['detail_total'],'page'=>$page,'per_page'=>$size,
            'categories'=>['active','overdue','due_today','followup','missing','returned']];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function transactionReportAction(array $r,array $columns): array {
    $value=fn(string $label)=>$r['c'.array_search($label,$columns,true)];
    return ['id'=>(int)$r['_row_id'],'type'=>$r['_type'],'status'=>$r['_status'],
        'qty'=>(int)$r['_qty'],'qty_returned'=>(int)$r['_qty_returned'],'qty_missing'=>(int)$r['_qty_missing'],
        'due_date'=>$r['_due_date'],'created_at'=>$r['_created'],'overdue_days'=>(int)$r['_overdue_days'],
        'is_overdue'=>(bool)(int)$r['_is_overdue'],'open_missing_cases'=>(int)$r['_open_missing'],'resolved_missing_cases'=>(int)$r['_resolved_missing'],
        'txn_id'=>$value('TXN ID'),'tool_code'=>$value('Tool Code'),'tool_name'=>$value('Tool Name'),'borrower'=>$value('Borrower'),
        'pending_report_id'=>$r['_pending_id']===null?null:(int)$r['_pending_id']];
}
