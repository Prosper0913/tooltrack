<?php
// Build a local headless-browser test page without Node or application credentials.
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__,2);$sources=[];
foreach(['app','borrow','return','tools','charge-payments','charge-workflow','missing-cases','writeoffs','reports','incident-ui','table-redesign'] as $name)$sources[$name]=file_get_contents($root.'/assets/js/'.$name.'.js');
$data=json_encode($sources,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
$runner=file_get_contents(__DIR__.'/return_management.js')."\n".file_get_contents(__DIR__.'/inventory_consistency.js')."\n".file_get_contents(__DIR__.'/writeoffs.js');
file_put_contents(__DIR__.'/consistency-runner.html','<!doctype html><meta charset="utf-8"><pre id="result">RUNNING</pre><script>const sources='.$data.';</script><script>'.$runner.'</script><script>(async()=>{try{const checks=[];for(const [name,source]of Object.entries(sources)){new Function(source);checks.push(name+" syntax");}checks.push(...runInventoryUiChecks(sources.borrow,sources["charge-payments"],sources.tools,sources.app));checks.push(...await runReturnUiChecks(sources.return));checks.push(...await runLossUiChecks(sources.writeoffs));document.getElementById("result").textContent=checks.map(x=>"PASS: "+x).join("\n")+"\nPASSED: "+checks.length;}catch(e){document.getElementById("result").textContent="FAIL: "+e.stack;}})();</script>');
