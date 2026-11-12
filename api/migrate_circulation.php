<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/includes/circulation_lib.php';
installCirculation(getDB());
echo "Borrowing policies installed. Existing inventory, transactions and foreign keys preserved. Defaults preserve unlimited borrowing and allow overdue borrowers until Admin configures policy.\n";
