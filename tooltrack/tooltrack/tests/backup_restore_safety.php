<?php
// CLI-only integration test of the destructive restore code against disposable, newly-created fixture databases.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/api/backup_guard.php';
$configPath=$argv[1]??'';
if(!str_starts_with(strtolower(str_replace('\\','/',(string)realpath($configPath))),strtolower(str_replace('\\','/',ttBackupPrivate())).'/work/'))throw new RuntimeException('Private verification config required.');
$config=json_decode(file_get_contents($configPath),true,16,JSON_THROW_ON_ERROR);
foreach(['DB_HOST','DB_USER','DB_PASS'] as $name)define($name,$config[$name]);
$schema='tooltrack_restore_safety_test_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));define('DB_NAME',$schema);
require_once dirname(__DIR__).'/api/backup_lib.php';
try{
    $suffix=bin2hex(random_bytes(8));$private=ttBackupPrivate().'/verification_'.$suffix;
    mkdir($private,0700);mkdir($private.'/work',0700);
    $folder=dirname(__DIR__,3).'/ToolTrackBackupTests/'.$suffix;mkdir($folder,0700,true);
    file_put_contents($private.'/config.json',json_encode(['folder'=>$folder]));file_put_contents($private.'/key.json',json_encode(['key'=>base64_encode(random_bytes(32))]));file_put_contents($private.'/history.jsonl','');file_put_contents($private.'/initialized',date(DATE_ATOM));
    $service=new ToolTrackBackup($private);$operation=$service->lock();
    $db=$service->db();$db->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $db=$service->db($schema);$db->exec('CREATE TABLE restore_fixture(id INT PRIMARY KEY,note VARCHAR(120),payload VARBINARY(20)) ENGINE=InnoDB');
    $q=$db->prepare('INSERT INTO restore_fixture VALUES(?,?,?)');$q->execute([1,'Original fixture — café',"\x00\xff\n"]);$q->execute([2,'Second fixture',"abc"]);
    $baseline=$service->manifest($schema);$backup=$service->backup('fixture_restore_test');$decoded=$service->decrypt($backup['path']);
    $q->execute([3,'Change to preserve in safety backup','safety']);
    $modified=$service->manifest($schema);
    // Staging failure must not change even this disposable restore target.
    $bad=$decoded;$bad['metadata']['manifest']['tables']['restore_fixture']['rows']=999;
    try{$service->restore($bad);throw new RuntimeException('Invalid staging manifest was accepted.');}catch(RuntimeException $e){if($e->getMessage()==='Invalid staging manifest was accepted.')throw $e;}
    if($service->manifest($schema)!==$modified)throw new RuntimeException('Staging failure modified the restore target.');
    echo "PASS: Failed test restore leaves the target untouched\n";
    $result=$service->restore($decoded);
    if($service->manifest($schema)!==$baseline)throw new RuntimeException('Fixture restore failed.');
    echo "PASS: Full restore replaces only the isolated fixture database and verifies its contents\n";
    $safety=null;foreach($service->history() as $entry)if(($entry['filename']??'')===$result['safety_backup'])$safety=$entry;
    if(!$safety||$service->decrypt($safety['path'])['metadata']['manifest']!==$modified)throw new RuntimeException('Safety backup does not preserve the pre-restore data.');
    if(is_file($private.'/recovery-required.json'))throw new RuntimeException('Maintenance marker remained after successful restore.');
    echo "PASS: Verified safety backup preserves all pre-restore fixture records\n";
    echo "PASS: Maintenance marker cleared after verified restore\n";
    // Simulate interruption only inside the disposable fixture database and its isolated state folder.
    file_put_contents($private.'/recovery-required.json',json_encode(['safety_backup'=>$safety['filename']]));
    $service->db($schema)->exec("INSERT INTO restore_fixture VALUES(4,'Interrupted fixture restore','partial')");
    $service->recoverSafety('RESTORE '.$schema);
    if($service->manifest($schema)!==$modified)throw new RuntimeException('Emergency recovery did not restore pre-restore fixture contents.');
    echo "PASS: Emergency CLI recovery restores verified safety data after a simulated interruption\n";
    echo json_encode(['fixture_database'=>$schema,'verified_restore_database'=>$result['test']['database'],'safety_backup'=>$result['safety_backup']],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);}