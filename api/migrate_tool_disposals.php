<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/tool_disposal_lib.php';
installToolDisposals(getDB());
echo "Damaged tool disposal ledger ready; existing inventory and history unchanged.\n";
