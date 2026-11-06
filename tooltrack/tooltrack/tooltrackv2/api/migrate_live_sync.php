<?php
// CLI only. Re-run after adding workflow tables; installation is idempotent.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/live_sync_lib.php';
echo 'Live synchronization installed for '.installLiveSync(getDB())." tables.\n";