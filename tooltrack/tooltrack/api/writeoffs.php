<?php
require_once __DIR__.'/config.php';
require_once dirname(__DIR__).'/includes/missing_lib.php';
require_once __DIR__.'/replacement_payments.php';
require_once __DIR__.'/writeoff_lib.php';
$db=getDB();$actor=rpActor($db);
try{
    if($_SERVER['REQUEST_METHOD']==='GET')ok(lossDetails($db,(string)($_GET['source']??''),(int)($_GET['id']??0)));
    if($_SERVER['REQUEST_METHOD']!=='POST')fail('Method not allowed.',405);
    $b=body();
    if(empty($_SESSION['replacement_payment_csrf'])||!hash_equals($_SESSION['replacement_payment_csrf'],(string)($b['csrf']??'')))fail('Security token expired. Reload tracking.',403);
    ok(lossAction($db,$b,$actor));
}catch(ReplacementPaymentError $e){fail($e->getMessage(),409);}catch(Throwable $e){error_log('Loss workflow: '.$e->getMessage());fail('Loss action failed. Nothing was committed. Refresh and retry.',409);}
