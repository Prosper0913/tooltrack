<?php
// Existing endpoint and table names are retained for compatibility. Physical incidents only.
require_once __DIR__.'/config.php';
require_once dirname(__DIR__).'/includes/missing_lib.php';
require_once __DIR__.'/replacement_payments.php';
$db=getDB();$method=$_SERVER['REQUEST_METHOD'];$me=requireLogin();
try{rpRoute($db,$me,$method);}
catch(ReplacementPaymentError $e){fail($e->getMessage(),409);}
catch(Throwable $e){error_log('Incident API: '.$e->getMessage());fail('Could not process the incident. Refresh and retry.',500);}
fail('Unsupported request.',405);