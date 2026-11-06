<?php
// Integration verification: read live DB, create encrypted backup, restore ONLY to a fresh test database.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start(); // Keep session setup before any output reaches PHP's headers.
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/backup_lib.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
function rejects(callable $work,string $message):void{try{$work();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException($message.' was not rejected.');}
function request(string $path,string $session='',?array $payload=null):array{
    $c=curl_init('http://localhost/tooltrack/'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>120,CURLOPT_FOLLOWLOCATION=>false]);
    if($session!=='')curl_setopt($c,CURLOPT_COOKIE,'PHPSESSID='.$session);
    if($payload!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_HTTPHEADER,['Content-Type: application/json']);curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($payload));}
    $body=curl_exec($c);if($body===false)throw new RuntimeException('HTTP test connection failed: '.curl_error($c));
    return ['status'=>curl_getinfo($c,CURLINFO_HTTP_CODE),'body'=>$body,'json'=>json_decode($body,true)];
}
function makeSession(array $user):string{
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $id=bin2hex(random_bytes(24));session_id($id);session_start();$_SESSION=['user_id'=>(int)$user['id'],'user_role'=>$user['role'],'user_name'=>'Backup verification'];session_write_close();return $id;
}
try{
    $service=new ToolTrackBackup();$lock=$service->lock();$before=$service->manifest(DB_NAME);
    $record=$service->backup('integration_verification');
    check(is_file($record['path']),'Encrypted backup saved outside htdocs');
    check(!str_contains(strtolower(str_replace('\\','/',$record['path'])),'/htdocs/'),'Backup location is outside the web root');
    check($service->find($record['id'])['file_sha256']===hash_file('sha256',$record['path']),'Saved archive checksum matches history');
    $decoded=$service->decrypt($record['path']);check($decoded['metadata']['manifest']===$before,'Decrypted archive matches live table manifest');
    $roundtrip=$service->testRestore($decoded);
    check($service->manifest($roundtrip['database'])===$before,'Restored database matches all structures, rows, checksums, objects and schema defaults');
    check($service->manifest(DB_NAME)===$before,'Live database records and structures remain unchanged');
    rejects(fn()=>$service->decrypt($record['path'],json_encode(['key'=>base64_encode(random_bytes(32))])),'Wrong recovery key rejected');
    $tampered=$service->temp('.ttbackup');$bytes=file_get_contents($record['path']);$bytes[strlen($bytes)-1]=chr(ord($bytes[strlen($bytes)-1])^1);file_put_contents($tampered,$bytes);
    rejects(fn()=>$service->decrypt($tampered),'Modified authentication tag rejected');
    file_put_contents($tampered,substr($bytes,0,-25));rejects(fn()=>$service->decrypt($tampered),'Truncated backup rejected');
    file_put_contents($tampered,'DROP DATABASE tooltrack_db;');rejects(fn()=>$service->decrypt($tampered),'Plain SQL rejected');
    rejects(fn()=>$service->configure(dirname(__DIR__).'/backups'),'Destination inside htdocs rejected');
    rejects(fn()=>$service->configure('C:/xampp/htdocs/../ToolTrackBackups'),'Parent traversal rejected');
    rejects(fn()=>$service->find('../key.json'),'Download path traversal rejected');
    flock($lock,LOCK_UN);fclose($lock);
    $admin=$service->db(DB_NAME)->query("SELECT id,role FROM users WHERE role='Admin' ORDER BY id LIMIT 1")->fetch();
    if(!$admin)throw new RuntimeException('No administrator available for HTTP role verification.');
    $adminSession=makeSession($admin);
    check(request('api/backups.php')['status']===401,'Anonymous backup API access denied');
    $staffSession=makeSession(['id'=>$admin['id'],'role'=>'Staff']);
    check(request('api/backups.php',$staffSession)['status']===403,'Staff backup API access denied');
    $status=request('api/backups.php',$adminSession);check($status['status']===200&&$status['json']['data']['configured'],'Administrator can read real backup history');
    $csrf=$status['json']['data']['csrf'];
    check(request('api/backups.php',$adminSession,['action'=>'backup','csrf'=>'bad'])['status']===403,'Missing or incorrect CSRF token denied');
    $download=request('api/backups.php',$adminSession,['action'=>'download','id'=>$record['id'],'csrf'=>$csrf]);check($download['status']===200&&hash('sha256',$download['body'])===$record['file_sha256'],'Administrator download preserves encrypted archive bytes');
    check(request('api/backups.php',$adminSession,['action'=>'export_key','password'=>'','csrf'=>$csrf])['status']===403,'Recovery key export requires administrator password');
    check(request('backup.php',$adminSession)['status']===404,'Legacy backup entry point cannot be run through GET');
    $gate=$service->lock('application');
    try{check(request('api/dashboard.php',$adminSession)['status']===503,'Restore lock blocks normal application requests');check(request('receive_masterlist.php')['status']===503,'Restore lock blocks inbound CMS writes');}finally{flock($gate,LOCK_UN);fclose($gate);}
    $page=request('index.php',$adminSession);check($page['status']===200&&str_contains($page['body'],'Backup Now'),'Admin Settings contains real backup controls');
    $staffPage=request('index.php',$staffSession);check($staffPage['status']===200&&!str_contains($staffPage['body'],'id="backupSettingsModal"'),'Staff does not receive backup controls');
    check($service->manifest(DB_NAME)===$before,'Live database still unchanged after security tests');
    // Delete only ephemeral verification sessions, never user sessions or databases.
    foreach([$adminSession,$staffSession] as $id){session_id($id);session_start();$_SESSION=[];session_destroy();}
    $fixtureConfig=$service->temp('.json');file_put_contents($fixtureConfig,json_encode(['DB_HOST'=>DB_HOST,'DB_USER'=>DB_USER,'DB_PASS'=>DB_PASS]));
    $process=proc_open([PHP_BINARY,__DIR__.'/backup_restore_safety.php',$fixtureConfig],[0=>['file','NUL','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_no_window'=>true]);
    $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);echo $output;
    check($exit===0,'Full restore and safety backup verified on isolated fixture database'.($exit===0?'':': '.$errors));
    check($service->manifest(DB_NAME)===$before,'Production database unchanged after full fixture restore');
    echo json_encode(['success'=>true,'backup'=>$record['filename'],'test_database'=>$roundtrip['database'],'tables'=>$roundtrip['tables'],'rows'=>$roundtrip['rows']],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);}