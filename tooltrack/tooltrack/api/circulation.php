<?php
require_once __DIR__.'/config.php';require_once dirname(__DIR__).'/includes/missing_lib.php';require_once dirname(__DIR__).'/includes/circulation_lib.php';
$actor=requireRole([ROLE_ADMIN,ROLE_STAFF]);$db=getDB();$method=$_SERVER['REQUEST_METHOD'];
if(!circulationReady($db))fail('Run api/migrate_circulation.php before using borrowing policies.',503);
if(empty($_SESSION['circulation_csrf']))$_SESSION['circulation_csrf']=bin2hex(random_bytes(32));
if($method==='GET')session_write_close();
try{
 if($method==='GET'){
  if(isset($_GET['meta'])){
   $meta=['csrf'=>$_SESSION['circulation_csrf']];
   if(!empty($_GET['borrower_id'])){$q=$db->prepare('SELECT type FROM borrowers WHERE id=?');$q->execute([(int)$_GET['borrower_id']]);$type=$q->fetchColumn();if($type)$meta['policy']=circulationPolicy($db,$type);}
   ok($meta);
  }
  if(isset($_GET['policies'])){requireRole(ROLE_ADMIN);ok($db->query('SELECT * FROM circulation_policies ORDER BY borrower_type')->fetchAll());}
  fail('Method not allowed.',405);
 }
 $body=body();if(!hash_equals($_SESSION['circulation_csrf'],(string)($body['csrf']??'')))fail('Security token expired. Reopen the circulation screen.',403);
    if($method==='PUT'){
        requireRole(ROLE_ADMIN);$type=(string)($body['borrower_type']??'*');
        if(!in_array($type,['*','Student','Faculty','Staff','Guest'],true))throw new CirculationError('Choose an existing borrower type.');
        $values=[];foreach(['max_active_quantity','max_loan_days'] as $key){$v=$body[$key]??null;if(($v===null||$v==='')){$values[]=null;continue;}$n=filter_var($v,FILTER_VALIDATE_INT);if($n===false||$n<1||$n>100000)throw new CirculationError('Policy limits must be positive whole numbers; leave a limit blank for unlimited.');$values[]=$n;}
        $db->beginTransaction();$q=$db->prepare('INSERT INTO circulation_policies(borrower_type,max_active_quantity,max_loan_days,allow_overdue,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE max_active_quantity=VALUES(max_active_quantity),max_loan_days=VALUES(max_loan_days),allow_overdue=VALUES(allow_overdue),updated_by=VALUES(updated_by)');$q->execute([$type,...$values,!empty($body['allow_overdue'])?1:0,$actor['id']]);
        logAudit('circulation_policy','Updated borrowing policy for '.$type.': limits '.json_encode($values).'; allow overdue '.(!empty($body['allow_overdue'])?'yes':'no'));$db->commit();ok(['saved'=>true]);
    }
 fail('Method not allowed.',405);
}catch(CirculationError $e){if($db->inTransaction())$db->rollBack();fail($e->getMessage(),409);}
catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Circulation: '.$e->getMessage());fail('Circulation update failed. Nothing was saved; refresh and retry.',409);}
