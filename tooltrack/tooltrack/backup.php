<?php
// CLI-only entry point; web callers must use the authenticated API.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/backup_lib.php';
$service=null;
try {
    $command=$argv[1] ?? 'backup';
    if (!in_array($command,['backup','status','test-latest','initialize','recover-safety'],true)) throw new RuntimeException('Usage: php backup.php [backup|status|test-latest|initialize|recover-safety]');
    if ($command === 'initialize') {
        $dir=ttBackupPrivate();
        if (is_file($dir.'/initialized')) { echo "Backup storage is already initialized. Existing key and settings preserved.\n";exit(0); }
        if (!is_dir($dir) || !is_dir($dir.'/work')) throw new RuntimeException('Run backups/setup-backups.ps1 to create the protected folders first.');
        $folder=dirname(__DIR__,2).'/ToolTrackBackups';
        if (!is_dir($folder) && !mkdir($folder,0700,true)) throw new RuntimeException('Cannot create backup destination.');
        if (!is_file($dir.'/key.json')) file_put_contents($dir.'/key.json',json_encode(['key'=>base64_encode(random_bytes(32))],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);
        if (!is_file($dir.'/config.json')) file_put_contents($dir.'/config.json',json_encode(['folder'=>$folder],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);
        foreach(['history.jsonl','operation.lock','application.lock'] as $name) { $f=fopen($dir.'/'.$name,'c+b');if(!$f)throw new RuntimeException('Cannot initialize private files.');fclose($f); }
        file_put_contents($dir.'/initialized',date(DATE_ATOM),LOCK_EX);
        echo "Protected backup storage initialized. Default destination: $folder\n";exit(0);
    }
    $service=new ToolTrackBackup();$lock=$service->lock();
    if ($command==='status') $result=$service->status();
    elseif ($command==='recover-safety') {
        $confirmation=str_starts_with($argv[2]??'','--confirm=')?substr($argv[2],10):'';
        $result=$service->recoverSafety($confirmation);
    }
    elseif ($command==='test-latest') {
        $last=$service->status()['last_backup'];if(!$last)throw new RuntimeException('Create a backup first.');
        $r=$service->find($last['id']);$result=$service->testRestore($service->decrypt($r['path']));
    } else {
        if(is_file(ttBackupPrivate().'/recovery-required.json'))throw new RuntimeException('A restore requires manual recovery.');
        $result=$service->backup('scheduled_or_cli');unset($result['path']);
    }
    echo json_encode(['success'=>true,'data'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $e) {
    if($service){try{$service->log('cli','failed',$e->getMessage());}catch(Throwable $ignored){}}
    fwrite(STDERR,'Backup error: '.$e->getMessage().PHP_EOL);exit(1);
}