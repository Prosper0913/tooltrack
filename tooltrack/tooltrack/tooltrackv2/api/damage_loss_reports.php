<?php
// Internal handler of reports.php, not a separate report page.
function damageLossReport(PDO $db,bool $download):void {
    require_once __DIR__.'/replacement_payments.php';
    rpActor($db);
    if(!$db->query("SHOW COLUMNS FROM replacement_requests LIKE 'charge_assessed_at'")->fetch())fail('Run api/migrate_replacement_payments.php first.',503);
    $from=trim((string)($_GET['date_from']??''));$to=trim((string)($_GET['date_to']??''));
    foreach([$from,$to] as $date)if($date!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)fail('Use valid YYYY-MM-DD dates.',422);}
    if($from!==''&&$to!==''&&$from>$to)fail('From date cannot be after To date.',422);
    $search=mb_substr(trim((string)($_GET['search']??'')),0,150);$kind=(string)($_GET['incident_type']??'');
    $status=(string)($_GET['payment_status']??'');$basis=(string)($_GET['date_basis']??'payment');
    $entry=(string)($_GET['entry_status']??'');
    if(!in_array($kind,['','damaged','lost'],true)||!in_array($status,['','Unpaid','Partially Paid','Paid','Not assessed','No charge'],true)||!in_array($basis,['charge','payment'],true)||!in_array($entry,['','confirmed','pending','rejected','voided'],true))fail('Invalid report filter.',422);
    $format=(string)($_GET['format']??'csv');
    if($download&&!in_array($format,['csv','excel','print'],true))fail('Unknown export format.',422);
    $asof=$to!==''?$to:(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    // Every case is aggregated once. Payment history rows are never summed as charges.
    $params=[$asof];$where="r.workflow_mode='payment' AND (r.charge_assessed_at IS NULL OR r.charge_assessed_at<DATE_ADD(?,INTERVAL 1 DAY))";$baseParams=[$asof];
    if($kind!==''){$where.=bulkReady($db)?' AND (r.reason=? OR EXISTS(SELECT 1 FROM replacement_requests ci WHERE ci.parent_case_id=r.id AND ci.reason=?))':' AND r.reason=?';$baseParams[]=$kind;if(bulkReady($db))$baseParams[]=$kind;}
    if($search!==''){
        $where.=" AND (COALESCE(b.full_name,r.responsible_name_snapshot) LIKE ? OR b.id_number LIKE ? OR r.tool_name_snapshot LIKE ? OR CAST(r.id AS CHAR)=? OR EXISTS(SELECT 1 FROM replacement_payment_claims ps WHERE ps.request_id=r.id AND ps.receipt_number LIKE ?))";
        $like='%'.$search.'%';array_push($baseParams,$like,$like,$like,$search,$like);
    }
    $caseSql="SELECT r.id,r.reason,r.amount_due,r.charge_assessed_at,r.created_at,COALESCE(b.full_name,r.responsible_name_snapshot) borrower,b.id_number,r.tool_name_snapshot tool,
        COALESCE(t.paid,0) amount_paid,GREATEST(COALESCE(r.amount_due,0)-COALESCE(t.paid,0),0) balance,
        CASE WHEN r.amount_due IS NULL THEN 'Not assessed' WHEN r.amount_due=0 THEN 'No charge' WHEN COALESCE(t.paid,0)>=r.amount_due THEN 'Paid' WHEN COALESCE(t.paid,0)>0 THEN 'Partially Paid' ELSE 'Unpaid' END payment_status
        FROM replacement_requests r LEFT JOIN borrowers b ON b.id=r.responsible_borrower_id
        LEFT JOIN (SELECT request_id,SUM(amount) paid FROM replacement_payment_claims WHERE status='confirmed' AND payment_date<=? GROUP BY request_id) t ON t.request_id=r.id WHERE $where";
    $params=array_merge($params,$baseParams);
    $cw='1=1';if($status!==''){$cw.=' AND c.payment_status=?';$params[]=$status;}
    $source="($caseSql) c";
    $run=function(string $sql,array $args)use($db){$q=$db->prepare($sql);$q->execute($args);return $q;};
    // One consistent snapshot for cards, count, rows and exports.
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
    try {
        $period="c.charge_assessed_at IS NOT NULL";$periodArgs=$params;
        if($from!==''){$period.=' AND c.charge_assessed_at>=?';$periodArgs[]=$from.' 00:00:00';}
        if($to!==''){$period.=' AND c.charge_assessed_at<DATE_ADD(?,INTERVAL 1 DAY)';$periodArgs[]=$to;}
        $totalCharges=$run("SELECT COALESCE(SUM(c.amount_due),0) FROM $source WHERE $cw AND $period",$periodArgs)->fetchColumn();
        $collect="p.status='confirmed'";$collectArgs=$params;
        if($from!==''){$collect.=' AND p.payment_date>=?';$collectArgs[]=$from;}
        $collect.=' AND p.payment_date<=?';$collectArgs[]=$asof;
        $collected=$run("SELECT COALESCE(SUM(p.amount),0) FROM $source JOIN replacement_payment_claims p ON p.request_id=c.id WHERE $cw AND $collect",$collectArgs)->fetchColumn();
        $totals=$run("SELECT COALESCE(SUM(c.balance),0) outstanding_balance,COALESCE(SUM(c.amount_due>0 AND c.balance=0),0) paid_cases,COALESCE(SUM(c.amount_due>0 AND c.balance>0),0) unpaid_cases FROM $source WHERE $cw",$params)->fetch();
        $totals['total_charges']=$totalCharges;$totals['total_collected']=$collected;
        $hw=$cw;$historyArgs=$params;
        $dateField=$basis==='charge'?'c.charge_assessed_at':'p.payment_date';
        if($from!==''){$hw.=" AND $dateField>=?";$historyArgs[]=$from;}
        if($to!==''){$hw.=" AND $dateField<DATE_ADD(?,INTERVAL 1 DAY)";$historyArgs[]=$to;}
        if($entry!==''){$hw.=' AND p.status=?';$historyArgs[]=$entry;}
        $historySource="$source LEFT JOIN replacement_payment_claims p ON p.request_id=c.id";
        $columns=['Case','Borrower','Student ID','Tool','Incident Type','Charge Date','Charge (case total)','Paid (case total)','Balance (case total)','Payment Status','Payment Date','Receipt / Reference','Payment Amount','Entry Status','Confirmed By','Confirmer Role','Confirmed At','Voided By','Voided At','Void Reason'];
        $sql="SELECT c.id,c.borrower,c.id_number,c.tool,c.reason,c.charge_assessed_at,c.amount_due,c.amount_paid,c.balance,c.payment_status,p.payment_date,p.receipt_number,p.amount,p.status,p.verified_by_name,p.verified_by_role,p.verified_at,p.voided_by_name,p.voided_at,p.void_reason FROM $historySource WHERE $hw ORDER BY c.id DESC,p.id DESC";
        $total=(int)$run("SELECT COUNT(*) FROM $historySource WHERE $hw",$historyArgs)->fetchColumn();
        $note="Charges use first assessment date; collections use payment date and confirmed, non-voided entries only. Balance and case counts include all charges through $asof (including opening balances). Unpaid Cases includes partially paid cases. Case totals repeat on installment rows; do not sum those columns. Corrections and voids restate history using current corrected amounts. History date filter: $basis. All filters apply to matching cases; receipt search returns the case history.";
        if(!$download){
            $page=max(1,(int)($_GET['page']??1));$per=25;
            $rows=$run($sql.' LIMIT '.$per.' OFFSET '.(($page-1)*$per),$historyArgs)->fetchAll(PDO::FETCH_NUM);
            $db->commit();ok(['columns'=>$columns,'rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$per,'totals'=>$totals,'as_of'=>$asof,'note'=>$note]);
        }
        $q=$run($sql,$historyArgs);$escape=fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $stamp=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Ymd');
        if($format==='csv'){
            header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="damage_loss_charges_'.$stamp.'.csv"');
            $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
            fputcsv($out,['Damage & Loss Charges',$from?:'All dates',$to?:$asof]);fputcsv($out,[$note]);
            foreach($totals as $key=>$value)fputcsv($out,[$key,$value]);fputcsv($out,[]);fputcsv($out,$columns);
            while($row=$q->fetch(PDO::FETCH_NUM))fputcsv($out,array_map(fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r]/',$v)?"'".$v:$v,$row));fclose($out);
        }elseif($format==='excel'){
            // SpreadsheetML is a native Excel format; no dependency or misleading XLSX extension.
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');header('Content-Disposition: attachment; filename="damage_loss_charges_'.$stamp.'.xml"');
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Damage Loss Charges"><Table>';
            $xmlRow=function(array $row)use($escape){echo '<Row>';foreach($row as $v)echo '<Cell><Data ss:Type="String">'.$escape($v).'</Data></Cell>';echo '</Row>';};
            $xmlRow(['Damage & Loss Charges',$from?:'All dates',$to?:$asof]);$xmlRow([$note]);foreach($totals as $key=>$v)$xmlRow([$key,$v]);$xmlRow($columns);
            while($row=$q->fetch(PDO::FETCH_NUM))$xmlRow($row);echo '</Table></Worksheet></Workbook>';
        }else{
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html><head><meta charset="utf-8"><title>Damage &amp; Loss Charges</title><style>body{font:12px Arial;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:5px;overflow-wrap:anywhere}thead{display:table-header-group} @page{size:A3 landscape;margin:10mm}@media print{button{display:none}tr{break-inside:avoid}}</style></head><body><button onclick="window.print()">Print / Save as PDF</button><h1>Damage &amp; Loss Charges</h1><p>'.$escape(($from?:'All dates').' to '.($to?:$asof)).'</p><p>'.$escape($note).'</p><p>';
            foreach($totals as $key=>$value)echo $escape(str_replace('_',' ',$key)).': <b>'.$escape($value).'</b> &nbsp; ';
            echo '</p><table><thead><tr>';foreach($columns as $c)echo '<th>'.$escape($c).'</th>';echo '</tr></thead><tbody>';
            while($row=$q->fetch(PDO::FETCH_NUM)){echo '<tr>';foreach($row as $v)echo '<td>'.$escape($v).'</td>';echo '</tr>';}echo '</tbody></table></body></html>';
        }
        $db->commit();exit;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Charge report: '.$e->getMessage());fail('Unable to generate the charges report.',500);}
}
