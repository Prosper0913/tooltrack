<?php
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__,2);$sources=[];
foreach(glob($root.'/assets/js/*.js') as $file)$sources[basename($file)]=file_get_contents($file);
$json=json_encode($sources,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
$test=file_get_contents(__DIR__.'/live_sync.js');
file_put_contents(__DIR__.'/live-sync-runner.html','<!doctype html><meta charset="utf-8"><h1 id="pageTitle">Tools</h1><section id="toolsPage" class="page active"><input id="search" value="keep search"><table><tbody id="rows"><tr><td>original</td></tr></tbody></table></section><section id="dashboardPage" class="page"></section><div class="modal-overlay" id="edit"><input id="draft" value="unsaved"></div><pre id="result">RUNNING</pre><script>const sources='.$json.';</script><script>'.$test.'</script>');
