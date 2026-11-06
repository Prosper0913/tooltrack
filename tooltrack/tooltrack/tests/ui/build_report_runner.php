<?php
// Local browser checks without application credentials or database writes.
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__,2);
$live=null;
if(in_array('--live-inventory',$argv,true)){
    require_once $root.'/api/bootstrap.php';
    $api=file_get_contents($root.'/api/reports.php');
    $start=strpos($api,"    'inventory' => [",strpos($api,'$reportDefs = ['));
    $end=strpos($api,'    // Date-added',$start);
    $defs=eval('return ['.substr($api,$start,$end-$start).'];');
    $def=$defs['inventory'];
    $row=getDB()->query($def['sql'].' LIMIT 1')->fetch(PDO::FETCH_NUM);
    if(!$row)throw new RuntimeException('No inventory row available for live comparison.');
    $start=strpos($api,'function csvSafe(');$end=strpos($api,"\n}",$start)+2;
    eval(substr($api,$start,$end-$start));
    $csv=fopen('php://temp','w+');fputcsv($csv,$def['columns']);fputcsv($csv,array_map('csvSafe',$row));rewind($csv);
    $csvColumns=fgetcsv($csv);$csvRow=fgetcsv($csv);fclose($csv);
    if($csvColumns!==$def['columns'])throw new RuntimeException('CSV header mismatch.');
    $live=['columns'=>$def['columns'],'rows'=>[$row],'csvRow'=>$csvRow];
}
$data=json_encode([file_get_contents($root.'/assets/js/reports.js'),file_get_contents($root.'/index.php'),$live],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
$runner=file_get_contents(__DIR__.'/report_interface.js');
$runner='const style=document.createElement("style");style.textContent='.json_encode(file_get_contents($root.'/css/styles.css'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';document.head.append(style);'.$runner;
file_put_contents(__DIR__.'/report-runner.html','<!doctype html><meta charset="utf-8"><pre id="result">RUNNING</pre><script>const inputs='.$data.';</script><script>'.$runner.'</script><script>(async()=>{try{const checks=await runReportInterfaceChecks(...inputs);document.getElementById("result").textContent=checks.map(x=>"PASS: "+x).join("\n")+"\nPASSED: "+checks.length;}catch(e){document.getElementById("result").textContent="FAIL: "+e.stack;}})();</script>');
