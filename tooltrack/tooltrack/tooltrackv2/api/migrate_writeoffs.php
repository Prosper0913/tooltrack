<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/writeoff_lib.php';
installWriteoffs(getDB());
echo "Loss write-off schema ready. Historical outcomes preserved.\n";
