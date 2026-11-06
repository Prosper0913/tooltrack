<?php
// Existing endpoint now serves the shared incident queue; inventory totals stay on Dashboard.
require_once __DIR__.'/config.php';
require_once dirname(__DIR__).'/includes/missing_lib.php';
require_once __DIR__.'/replacement_payments.php';
$db=getDB();rpActor($db);
if($_SERVER['REQUEST_METHOD']!=='GET')fail('Method not allowed.',405);
session_write_close();
if(isset($_GET['summary']))ok(caseTrackingSummary($db));
if(isset($_GET['bulk_sources']))fail('This view was retired.',410);
try {ok(caseTrackingList($db,$_GET));}
catch(ReplacementPaymentError $e){fail($e->getMessage(),422);}
