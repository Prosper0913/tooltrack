<?php
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__,2);
$data=json_encode(['shell'=>file_get_contents($root.'/index.php'),'source'=>file_get_contents($root.'/assets/js/borrow.js')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
file_put_contents(__DIR__.'/borrow-tool-runner.html','<!doctype html><meta charset="utf-8"><pre id="result">RUNNING</pre><script>const fixture='.$data.';</script><script>'.file_get_contents(__DIR__.'/borrow_tool.js').'</script>');
