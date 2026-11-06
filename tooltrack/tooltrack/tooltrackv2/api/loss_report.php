<?php
// Same outcome/filter source as Damage & Loss Tracking; CSV includes manual and loan cases.
function lossHistoryReport(PDO $db,bool $download):void {
    require_once __DIR__.'/replacement_payments.php';
    rpActor($db);
    $columns=['Case ID','Tool','SKU','Borrower','Original Quantity','Recovered Quantity','Gross Written Off','Later Recoveries','Net Outstanding Loss','Remaining Missing','Outcome','Write-off Reason','Requested By','Requested At','Decision','Approved / Rejected By','Decision At','Rejection Reason','Investigation Notes','Accountability'];
    $categories=['Active missing','Missing','Partially Closed','Needs Review','Pending write-off','Closed — Lost','Closed — Recovered','Closed — Mixed Outcome'];
    $rows=[];$page=1;$filters=['incident'=>'lost','inventory'=>(string)($_GET['inventory']??$_GET['category']??''),'search'=>(string)($_GET['search']??'')];
    $db->beginTransaction();
    try{
        do{
            $list=caseTrackingList($db,$filters+['page'=>$page]);
            foreach($list['rows'] as $i){
                if(!in_array($i['source'],['case','missing'],true))continue;
                $d=lossDetails($db,$i['source'],(int)$i['id']);
                $requests=$d['requests']?:[[]];
                foreach($requests as $w)$rows[]=[$i['case_id'],$i['tool_name'],$i['tool_code'],$i['borrower'],$d['original'],$d['recovered'],$d['gross_written_off'],$d['later_recovered'],$d['net_loss'],$d['eligible'],$d['outcome'],$w['reason']??'',$w['requester_name']??'',$w['requested_at']??'',$w['status']??'',$w['decision_name']??'',$w['decided_at']??'',$w['rejection_reason']??'',$w['investigation_notes']??'',$w['accountability_status']??''];
            }
            $page++;
        }while(($page-1)*$list['per_page']<$list['total']);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $sort=array_search((string)($_GET['sort']??''),$columns,true);if($sort!==false){$direction=($_GET['direction']??'asc')==='desc'?-1:1;usort($rows,fn($a,$b)=>$direction*($a[$sort]<=>$b[$sort]));}
    if(!$download){$total=count($rows);$size=(int)($_GET['per_page']??25);if(!in_array($size,[25,50,100],true))$size=25;$page=max(1,min(max(1,(int)ceil($total/$size)),(int)($_GET['page']??1)));ok(['columns'=>$columns,'rows'=>array_slice($rows,($page-1)*$size,$size),'total'=>$total,'page'=>$page,'per_page'=>$size,'categories'=>$categories,'note'=>'Case totals repeat for each request; do not sum repeated case totals. Gross write-offs are preserved after recovery. Accountability is separate.']);}
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="loss_history.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$columns);
    foreach($rows as $row)fputcsv($out,array_map(fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r]/',$v)?"'".$v:$v,$row));fclose($out);exit;
}
