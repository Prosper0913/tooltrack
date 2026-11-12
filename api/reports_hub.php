<?php
// Read-only hub analytics. Existing report definitions retain their counting rules.
require_once __DIR__.'/config.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Method not allowed.',405);
session_write_close();
$month=$_GET['month']??date('Y-m');
if(!is_string($month)||!preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D',$month))fail('Choose a valid month (YYYY-MM).',422);
try {
    $start=new DateTimeImmutable($month.'-01');
    $end=$start->modify('+1 month');
    $db=getDB();
    $query=$db->prepare("SELECT LEAST((DAY(created_at)-1) DIV 7,3) week_index,type,COUNT(*) total
        FROM transactions WHERE type IN ('borrow','return') AND created_at>=? AND created_at<?
        GROUP BY week_index,type");
    $query->execute([$start->format('Y-m-d').' 00:00:00',$end->format('Y-m-d').' 00:00:00']);
    $borrows=$returns=[0,0,0,0];
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $week=(int)$row['week_index'];
        if($row['type']==='borrow')$borrows[$week]=(int)$row['total'];else $returns[$week]=(int)$row['total'];
    }
    $query=$db->prepare('SELECT category,SUM(quantity) total FROM tools GROUP BY category ORDER BY total DESC');
    $query->execute();
    $categories=array_map(fn($row)=>['name'=>(string)($row['category']??'Uncategorized'),'quantity'=>(int)$row['total']],$query->fetchAll(PDO::FETCH_ASSOC));
    ok(['month'=>$month,'month_label'=>$start->format('F Y'),'days_in_month'=>(int)$start->format('t'),
        'borrows'=>$borrows,'returns'=>$returns,'total_borrows'=>array_sum($borrows),'total_returns'=>array_sum($returns),
        'net_out'=>array_sum($borrows)-array_sum($returns),'categories'=>$categories]);
}catch(Throwable $e){error_log('Reports hub: '.$e->getMessage());fail('Could not load report analytics. Please try again.',500);}
