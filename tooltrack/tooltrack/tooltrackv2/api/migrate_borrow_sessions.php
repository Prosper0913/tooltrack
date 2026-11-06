<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/includes/borrow_session_lib.php';
require_once __DIR__.'/live_sync_lib.php';
$db=getDB();installBorrowSessions($db);installLiveSync($db);
echo "Borrow-session schema ready. Existing transaction IDs and historical rows were not changed.\n";
