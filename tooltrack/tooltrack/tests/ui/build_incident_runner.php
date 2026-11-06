<?php
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__,2);$index=file_get_contents($root.'/index.php');
$section=function(string $start,string $end)use($index){$a=strpos($index,$start);$b=strpos($index,$end,$a);return substr($index,$a,$b-$a);};
$start=strpos($index,'<section class="page" id="replacementsPage">');$page=substr($index,$start,strpos($index,'</section>',$start)+10-$start);
$missing=$section('<div class="modal-overlay" id="missingActionModal">','<?php require');
$details=$section('<div class="modal-overlay" id="rpDetailsModal">','<script>window.CURRENT_ROLE');
$start=strpos($index,'<section class="page" id="toolsPage">');$tools=substr($index,$start,strpos($index,'</section>',$start)+10-$start);$tools=preg_replace('/<\?php.*?\?>/s','',$tools);$tools=str_replace('id="toolsPage"','id="toolsPage" hidden',$tools);
$app=file_get_contents($root.'/assets/js/app.js');$a=strpos($app,'function openModal(');$modalFunctions=substr($app,$a,strpos($app,"document.querySelectorAll('.modal-overlay')",$a)-$a);
$html='<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="../../css/styles.css"><link rel="stylesheet" href="../../css/incident-ui.css"><style>body{background:#f5f3f8}.fixture-main{padding:24px;max-width:1400px;margin:auto}.page{display:block!important}#result{white-space:pre-wrap;overflow-wrap:anywhere;max-width:100%;font:12px monospace;padding:12px}</style><main class="fixture-main">'.$page.'</main>'.$missing.$details.'<pre id="result">RUNNING</pre><script>'.file_get_contents(__DIR__.'/incident_layout.js').'</script><script>'.$modalFunctions.'</script>';
$html=str_replace('</main>',$tools.'</main>',$html);
$html=str_replace('.page{display:block!important}', '.page{display:block!important}.page[hidden]{display:none!important}',$html);
foreach(['tools','table-redesign','charge-payments','missing-cases','charge-workflow','writeoffs','incident-ui'] as $file)$html.='<script src="../../assets/js/'.$file.'.js"></script>';
$html.='<script>runIncidentLayoutChecks().catch(e=>document.getElementById("result").textContent="FAIL: "+e.stack);</script>';
file_put_contents(__DIR__.'/incident-runner.html',$html);
