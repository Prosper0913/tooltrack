<?php
require_once __DIR__ . '/backup_guard.php';

final class ToolTrackBackup {
    private string $private;
    private array $temporary = [];
    private array $config;
    private const MAGIC = "TTBACKUP1\n";
    private const CHUNK = 1048576;

    public function __construct(?string $verificationStorage = null) {
        $this->private = ttBackupPrivate();
        if ($verificationStorage !== null) {
            $allowed = strtolower(str_replace('\\','/',(string)realpath(ttBackupPrivate()))) . '/verification_';
            $resolved = str_replace('\\','/',(string)realpath($verificationStorage));
            if (PHP_SAPI !== 'cli' || !str_starts_with(strtolower($resolved),$allowed)) throw new RuntimeException('Invalid verification storage.');
            $this->private = $resolved;
        }
        if (!is_file($this->private . '/initialized')) throw new RuntimeException('Run backups/setup-backups.ps1 on this PC first.');
        $this->config = $this->readJson($this->private . '/config.json');
        if (!function_exists('openssl_encrypt') || !function_exists('gzopen') || !function_exists('proc_open')) throw new RuntimeException('PHP OpenSSL, zlib and proc_open are required.');
        ignore_user_abort(true);
        set_time_limit(0);
    }
    public function __destruct() { foreach ($this->temporary as $path) if (is_file($path)) @unlink($path); }
    private function readJson(string $path): array {
        $result = json_decode((string)@file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result)) throw new RuntimeException('Invalid backup configuration.');
        return $result;
    }
    private function write(string $path, string $bytes): void {
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Could not write backup data. Check disk space and folder permissions.');
    }
    private function atomicJson(string $path, array $value): void {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $this->temporary[] = $tmp;
        $this->write($tmp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!rename($tmp, $path)) throw new RuntimeException('Could not save backup settings.');
    }
    public function temp(string $suffix = ''): string {
        $path = $this->private . '/work/' . bin2hex(random_bytes(16)) . $suffix;
        $f = fopen($path, 'xb');
        if (!$f) throw new RuntimeException('Private working folder is not writable.');
        fclose($f); $this->temporary[] = $path; return $path;
    }
    public function lock(string $name = 'operation', bool $exclusive = true) {
        $f = fopen($this->private . '/' . $name . '.lock', 'c+b');
        if (!$f || !flock($f, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) throw new RuntimeException('Another request or backup operation is running. Please retry shortly.');
        return $f;
    }
    public function key(?string $recovery = null): string {
        $data = $recovery === null ? $this->readJson($this->private . '/key.json') : json_decode($recovery, true, 8, JSON_THROW_ON_ERROR);
        $key = base64_decode($data['key'] ?? '', true);
        if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('Invalid recovery key.');
        return $key;
    }
    public function exportKey(): array { return ['format'=>'ToolTrack recovery key v1','key'=>base64_encode($this->key()),'warning'=>'Keep offline, separate from your backup files. Anyone with this key can decrypt the backups.']; }
    private function folder(string $folder, bool $create = false): string {
        $folder = str_replace('\\', '/', trim($folder));
        if (!preg_match('~^[A-Za-z]:/[^<>:"|?*\x00-\x1f]+$~D', $folder)) throw new RuntimeException('Use an absolute local Windows folder, for example C:\\ToolTrackBackups.');
        foreach (explode('/', substr($folder, 3)) as $part) if ($part === '' || $part === '.' || $part === '..' || preg_match('/[ .]$/', $part)) throw new RuntimeException('Invalid backup folder path.');
        // Resolve the existing parent before creating anything: handles junctions as well as .. traversal.
        $existing = $folder;
        while (!is_dir($existing)) { $next = dirname($existing); if ($next === $existing) throw new RuntimeException('Backup drive is unavailable.'); $existing = $next; }
        $resolved = realpath($existing);
        $htdocs = strtolower(str_replace('\\','/',(string)realpath(dirname(__DIR__,2))));
        $check = strtolower(rtrim(str_replace('\\','/',(string)$resolved),'/'));
        $private = strtolower(str_replace('\\','/',(string)realpath($this->private)));
        if ($check === $htdocs || str_starts_with($check.'/', $htdocs.'/') || $check === $private || str_starts_with($check.'/', $private.'/')) throw new RuntimeException('Choose a folder outside htdocs and outside the private key folder.');
        if (!is_dir($folder) && (!$create || !mkdir($folder, 0700, true))) throw new RuntimeException('Backup folder does not exist or cannot be created.');
        if (!is_writable($folder)) throw new RuntimeException('Backup folder is not writable.');
        return str_replace('\\','/',(string)realpath($folder));
    }
    public function configure(string $folder): void {
        $this->config['folder'] = $this->folder($folder, true);
        $this->atomicJson($this->private . '/config.json', $this->config);
    }
    public function history(): array {
        $rows = [];
        $f = fopen($this->private . '/history.jsonl', 'rb');
        if (!$f) return [];
        flock($f, LOCK_SH);
        while (($line = fgets($f)) !== false) { $r = json_decode($line, true); if (is_array($r)) $rows[] = $r; }
        fclose($f); return array_reverse($rows);
    }
    public function log(string $action, string $status, string $message, array $data = []): void {
        $entry = array_merge($data, ['action'=>$action,'status'=>$status,'message'=>$message,'at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM),'actor'=>PHP_SAPI === 'cli' ? 'Windows CLI' : ($_SESSION['user_name'] ?? 'Admin')]);
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($this->private . '/history.jsonl', $line, FILE_APPEND | LOCK_EX) !== strlen($line)) throw new RuntimeException('Could not save backup history.');
    }
    public function status(): array {
        $history = $this->history(); $last = null; $list = [];
        foreach ($history as $entry) {
            if (!$last && $entry['action'] === 'backup' && $entry['status'] === 'success') $last = $entry;
            if (isset($entry['path'])) { $entry['available'] = is_file($entry['path']); unset($entry['path']); }
            $list[] = $entry;
        }
        if ($last) unset($last['path']);
        return ['configured'=>true,'folder'=>$this->config['folder'],'last_backup'=>$last,'last_operation'=>$list[0] ?? null,'history'=>array_slice($list,0,100),'recovery_required'=>is_file($this->private.'/recovery-required.json'),'sync'=>'Google Drive for Desktop handles sync. Check its tray icon for upload status.'];
    }
    public function find(string $id): array {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new RuntimeException('Invalid backup identifier.');
        foreach ($this->history() as $row) if (($row['id'] ?? '') === $id && $row['action'] === 'backup' && $row['status'] === 'success') {
            if (!is_file($row['path']) || !hash_equals($row['file_sha256'], hash_file('sha256',$row['path']))) throw new RuntimeException('Backup is missing or its integrity check failed.');
            return $row;
        }
        throw new RuntimeException('Backup not found.');
    }
    public function db(?string $schema = null): PDO {
        $dsn = 'mysql:host='.DB_HOST.';charset=utf8mb4'.($schema ? ';dbname='.$schema : '');
        return new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    private function ident(string $id): string { return '`'.str_replace('`','``',$id).'`'; }
    public function manifest(string $schema): array {
        $db = $this->db($schema); $tables = []; $objects = [];
        foreach ($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name,$type]) {
            $create = $db->query('SHOW CREATE TABLE '.$this->ident($name))->fetch(PDO::FETCH_NUM)[1];
            $create = str_replace($this->ident($schema).'.','`__schema__`.', $create);
            $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
            if ($type === 'BASE TABLE') {
                $count = (int)$db->query('SELECT COUNT(*) FROM '.$this->ident($name))->fetchColumn();
                $checksum = $db->query('CHECKSUM TABLE '.$this->ident($name).' EXTENDED')->fetch(PDO::FETCH_NUM)[1];
                if ($checksum === null) throw new RuntimeException('Cannot verify table '.$name.'.');
                $tables[$name] = ['rows'=>$count,'checksum'=>(string)$checksum,'structure'=>hash('sha256',$create)];
            } else $objects['view:'.$name] = hash('sha256',$create);
        }
        $q = $db->prepare('SELECT ROUTINE_NAME,ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=?'); $q->execute([$schema]);
        foreach ($q as $r) { $c = $db->query('SHOW CREATE '.$r['ROUTINE_TYPE'].' '.$this->ident($r['ROUTINE_NAME']))->fetch(PDO::FETCH_NUM); $objects[strtolower($r['ROUTINE_TYPE']).':'.$r['ROUTINE_NAME']] = hash('sha256',str_replace($this->ident($schema).'.','`__schema__`.', $c[2])); }
        foreach ($db->query('SHOW TRIGGERS') as $r) $objects['trigger:'.$r['Trigger']] = hash('sha256',json_encode([$r['Event'],$r['Table'],$r['Statement'],$r['Timing']]));
        foreach ($db->query('SHOW EVENTS') as $r) { $c=$db->query('SHOW CREATE EVENT '.$this->ident($r['Name']))->fetch(PDO::FETCH_NUM); $objects['event:'.$r['Name']]=hash('sha256',str_replace($this->ident($schema).'.','`__schema__`.',$c[3])); }
        $q=$db->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$schema]);$defaults=$q->fetch();
        ksort($tables); ksort($objects); return ['charset'=>$defaults['DEFAULT_CHARACTER_SET_NAME'],'collation'=>$defaults['DEFAULT_COLLATION_NAME'],'tables'=>$tables,'objects'=>$objects];
    }
    private function client(string $binary, array $args, ?string $input = null, ?string $output = null): void {
        $exe = dirname(__DIR__,3).'/mysql/bin/'.$binary.'.exe';
        if (!is_file($exe)) throw new RuntimeException('XAMPP MySQL client is missing: '.$binary);
        $defaults = $this->temp('.cnf');
        $escape = fn($v)=>str_replace(["\\",'"',"\r","\n"],["\\\\",'\\"','\\r','\\n'], $v);
        $this->write($defaults, "[client]\nhost=\"".$escape(DB_HOST)."\"\nuser=\"".$escape(DB_USER)."\"\npassword=\"".$escape(DB_PASS)."\"\ndefault-character-set=utf8mb4\n");
        $stderr = $this->temp('.err'); $stdout = $output ?? $this->temp('.out');
        $process = proc_open(array_merge([$exe,'--defaults-extra-file='.$defaults],$args), [0=>['file',$input ?? 'NUL','r'],1=>['file',$stdout,'w'],2=>['file',$stderr,'w']], $pipes, null, null, ['bypass_shell'=>true,'create_no_window'=>true]);
        if (!is_resource($process)) throw new RuntimeException('Could not start MySQL backup tool.');
        $code = proc_close($process);
        @unlink($defaults);
        if ($code !== 0) {
            error_log('ToolTrack backup client error: '.substr((string)file_get_contents($stderr),0,4096));
            throw new RuntimeException($binary.' failed (exit '.$code.'). Check the PHP error log, MySQL service, permissions and free disk space.');
        }
    }
    private function dump(string $schema): string {
        $sql = $this->temp('.sql');
        $this->client('mysqldump',['--single-transaction','--quick','--skip-lock-tables','--routines','--events','--triggers','--hex-blob','--skip-comments','--skip-dump-date','--skip-add-locks',$schema],null,$sql);
        if (!filesize($sql)) throw new RuntimeException('Database export was empty.');
        return $sql;
    }
    private function writeStream($f, string $bytes): void {
        $offset=0; while($offset<strlen($bytes)) { $n=fwrite($f,substr($bytes,$offset)); if(!$n) throw new RuntimeException('Disk write failed. Check free space.'); $offset+=$n; }
    }
    private function exact($f, int $n): string {
        $s=''; while(strlen($s)<$n) { $b=fread($f,$n-strlen($s)); if($b===false||$b==='') throw new RuntimeException('Backup is truncated or corrupt.'); $s.=$b; } return $s;
    }
    private function encrypt(string $sql, string $archive, array $manifest): void {
        $key=$this->key();
        $header=json_encode(['format'=>1,'database'=>DB_NAME,'created_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM),'key_id'=>substr(hash('sha256',$key),0,16),'sql_bytes'=>filesize($sql),'sql_sha256'=>hash_file('sha256',$sql),'manifest'=>$manifest],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $gzip=$this->temp('.sql.gz'); $in=fopen($sql,'rb'); $gz=gzopen($gzip,'wb6');
        try { while(!feof($in)) { $b=fread($in,self::CHUNK); if($b===false||gzwrite($gz,$b)!==strlen($b)) throw new RuntimeException('Backup compression failed.'); } } finally { fclose($in); gzclose($gz); }
        $out=fopen($archive,'wb'); $in=fopen($gzip,'rb'); $index=0;
        try {
            $this->writeStream($out,self::MAGIC.pack('N',strlen($header)).$header);
            while(!feof($in)) {
                $chunk=fread($in,self::CHUNK); if($chunk===false) throw new RuntimeException('Backup read failed.'); if($chunk==='') break;
                $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($chunk,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$header.'|'.$index.'|data',16);
                if($cipher===false) throw new RuntimeException('Backup encryption failed.');
                $this->writeStream($out,pack('N',strlen($cipher)).$iv.$tag.$cipher); ++$index;
            }
            $iv=random_bytes(12); $tag='';
            if(openssl_encrypt('','aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$header.'|'.$index.'|end',16)===false) throw new RuntimeException('Backup encryption failed.');
            $this->writeStream($out,pack('N',0).$iv.$tag);
        } finally { fclose($in); fclose($out); }
    }
    public function decrypt(string $archive, ?string $recovery = null): array {
        $key=$this->key($recovery); $gzip=$this->temp('.sql.gz'); $in=fopen($archive,'rb'); $out=fopen($gzip,'wb');
        try {
            if($this->exact($in,strlen(self::MAGIC))!==self::MAGIC) throw new RuntimeException('Not a ToolTrack encrypted backup. Plain SQL files are not accepted.');
            $length=unpack('N',$this->exact($in,4))[1]; if($length<2||$length>4194304) throw new RuntimeException('Invalid backup header.');
            $header=$this->exact($in,$length); $meta=json_decode($header,true,64,JSON_THROW_ON_ERROR);
            if(($meta['format']??0)!==1 || ($meta['database']??'')!==DB_NAME || !hash_equals($meta['key_id']??'',substr(hash('sha256',$key),0,16))) throw new RuntimeException('Wrong database or recovery key.');
            for($index=0;;++$index) {
                $n=unpack('N',$this->exact($in,4))[1]; if($n>self::CHUNK) throw new RuntimeException('Invalid encrypted block.');
                $iv=$this->exact($in,12);$tag=$this->exact($in,16);$cipher=$n?$this->exact($in,$n):'';
                $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$header.'|'.$index.($n?'|data':'|end'));
                if($plain===false) throw new RuntimeException('Backup integrity check failed. The file was changed or the key is incorrect.');
                if(!$n) { if(fread($in,1)!=='') throw new RuntimeException('Unexpected data after the backup.'); break; }
                $this->writeStream($out,$plain);
            }
        } finally { fclose($in);fclose($out); }
        if(!isset($meta['sql_bytes'],$meta['sql_sha256'],$meta['manifest']) || !is_int($meta['sql_bytes']) || $meta['sql_bytes']<1) throw new RuntimeException('Invalid backup metadata.');
        $sql=$this->temp('.sql');$gz=gzopen($gzip,'rb');$out=fopen($sql,'wb');$bytes=0;
        try { while(!gzeof($gz)) { $b=gzread($gz,self::CHUNK);if($b===false)throw new RuntimeException('Compressed backup is damaged.');$bytes+=strlen($b);if($bytes>$meta['sql_bytes'])throw new RuntimeException('Backup size check failed.');$this->writeStream($out,$b); } } finally {gzclose($gz);fclose($out);}
        if($bytes!==$meta['sql_bytes']||!hash_equals($meta['sql_sha256'],hash_file('sha256',$sql)))throw new RuntimeException('SQL integrity check failed.');
        return ['sql'=>$sql,'metadata'=>$meta];
    }
    public function backup(string $reason='manual'): array {
        $folder=$this->folder($this->config['folder']);
        // Freeze writes during the snapshot even for non-InnoDB tables; external SQL clients must stay idle.
        $db=$this->db(); $db->exec('FLUSH TABLES WITH READ LOCK');
        try { $before=$this->manifest(DB_NAME);$sql=$this->dump(DB_NAME);$after=$this->manifest(DB_NAME); if($before!==$after)throw new RuntimeException('Database changed during backup. Please retry while other database tools are idle.'); }
        finally { $db->exec('UNLOCK TABLES'); }
        $archive=$this->temp('.ttbackup');$this->encrypt($sql,$archive,$before);
        $verified=$this->decrypt($archive);
        if(!hash_equals(hash_file('sha256',$sql),hash_file('sha256',$verified['sql'])))throw new RuntimeException('Backup verification failed.');
        $id=bin2hex(random_bytes(16));$name='tooltrack_'.(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d_H-i-s').'_'.$id.'.ttbackup';
        $path=$folder.'/'.$name;$partial=$path.'.partial';$this->temporary[]=$partial;
        if(!copy($archive,$partial) || !hash_equals(hash_file('sha256',$archive),hash_file('sha256',$partial)) || !rename($partial,$path))throw new RuntimeException('Could not save the verified encrypted backup.');
        $record=['id'=>$id,'filename'=>$name,'path'=>$path,'bytes'=>filesize($path),'file_sha256'=>hash_file('sha256',$path),'reason'=>$reason];
        $this->log('backup','success','Encrypted backup created and verified.',$record);
        return $record;
    }
    private function validateImport(string $sql): void {
        $f=fopen($sql,'rb');
        try { while(($line=fgets($f))!==false) {
            if(preg_match('/^\s*(CREATE\s+DATABASE|DROP\s+DATABASE|USE\s|SOURCE\s|\\\\!)/i',$line) || str_contains($line,'`'.DB_NAME.'`.')) throw new RuntimeException('This dump contains database-qualified SQL. Automatic restore is blocked to protect the live database; request a reviewed manual restore.');
        } } finally { fclose($f); }
    }
    public function testRestore(array $decoded): array {
        $this->validateImport($decoded['sql']);
        $schema='tooltrack_restore_test_'.date('Ymd_His').'_'.bin2hex(random_bytes(4));$db=$this->db();
        $charset=$decoded['metadata']['manifest']['charset'];$collation=$decoded['metadata']['manifest']['collation'];
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$charset)||!preg_match('/^[a-zA-Z0-9_]+$/D',$collation))throw new RuntimeException('Invalid database character set.');
        $db->exec('CREATE DATABASE '.$this->ident($schema).' CHARACTER SET '.$charset.' COLLATE '.$collation);
        $this->log('test_restore','running','Created isolated restore test database.',['test_database'=>$schema]);
        try {
            // Events must not run in the test database; this installation has no scheduled DB events.
            if(array_filter(array_keys($decoded['metadata']['manifest']['objects']),fn($v)=>str_starts_with($v,'event:'))) throw new RuntimeException('Backups containing database events require a reviewed manual test restore with the event scheduler stopped.');
            $this->client('mysql',['--binary-mode=1','--database='.$schema],$decoded['sql']);
            $actual=$this->manifest($schema);
            if($actual!==$decoded['metadata']['manifest'])throw new RuntimeException('Restored table structures, row counts, checksums or database objects did not match the backup.');
            $this->log('test_restore','success','Restored and verified every table and database object.',['test_database'=>$schema,'tables'=>count($actual['tables']),'rows'=>array_sum(array_column($actual['tables'],'rows'))]);
            return ['database'=>$schema,'tables'=>count($actual['tables']),'rows'=>array_sum(array_column($actual['tables'],'rows'))];
        } catch(Throwable $e) { $this->log('test_restore','failed',$e->getMessage(),['test_database'=>$schema]);throw $e; }
    }
    private function applyDefaults(array $manifest): void {
        foreach(['charset','collation'] as $key)if(!preg_match('/^[a-zA-Z0-9_]+$/D',$manifest[$key]??''))throw new RuntimeException('Invalid database character set.');
        $this->db()->exec('ALTER DATABASE '.$this->ident(DB_NAME).' CHARACTER SET '.$manifest['charset'].' COLLATE '.$manifest['collation']);
    }
    private function clearLive(): void {
        $db=$this->db(DB_NAME);$db->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach($db->query('SHOW EVENTS')->fetchAll() as $r)$db->exec('DROP EVENT '.$this->ident($r['Name']));
            $q=$db->prepare('SELECT ROUTINE_NAME,ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=?');$q->execute([DB_NAME]);
            foreach($q->fetchAll() as $r)$db->exec('DROP '.$r['ROUTINE_TYPE'].' '.$this->ident($r['ROUTINE_NAME']));
            $tables=$db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
            foreach($tables as [$name,$type])if($type==='VIEW')$db->exec('DROP VIEW '.$this->ident($name));
            foreach($tables as [$name,$type])if($type==='BASE TABLE')$db->exec('DROP TABLE '.$this->ident($name));
        } finally {$db->exec('SET FOREIGN_KEY_CHECKS=1');}
    }
    // Emergency CLI recovery is possible even when an interrupted restore removed the users table.
    public function recoverSafety(string $confirmation): array {
        if(PHP_SAPI!=='cli'||$confirmation!=='RESTORE '.DB_NAME)throw new RuntimeException('Emergency recovery requires CLI and explicit RESTORE '.DB_NAME.' confirmation.');
        if(!is_file($this->private.'/recovery-required.json'))throw new RuntimeException('No interrupted restore needs recovery.');
        $report=$this->readJson($this->private.'/recovery-required.json');$safety=null;
        foreach($this->history() as $row)if(($row['filename']??'')===($report['safety_backup']??'')&&($row['action']??'')==='backup'&&$row['status']==='success'){$safety=$this->find($row['id']);break;}
        if(!$safety)throw new RuntimeException('The recorded safety backup is unavailable. Keep maintenance mode enabled and use your recovery key with an intact backup.');
        $safe=$this->decrypt($safety['path']);$test=$this->testRestore($safe);$gate=$this->lock('application');
        try{
            $this->clearLive();$this->applyDefaults($safe['metadata']['manifest']);$this->client('mysql',['--binary-mode=1','--database='.DB_NAME],$safe['sql']);
            if($this->manifest(DB_NAME)!==$safe['metadata']['manifest'])throw new RuntimeException('Emergency safety restore failed verification. Maintenance mode remains enabled.');
            $this->write($this->private.'/session-epoch',bin2hex(random_bytes(32)));
            $this->log('recovery','success','Recovered the verified pre-restore safety backup.',['safety_backup'=>$safety['filename'],'test_database'=>$test['database']]);
            unlink($this->private.'/recovery-required.json');
            return ['safety_backup'=>$safety['filename'],'test'=>$test];
        }finally{flock($gate,LOCK_UN);fclose($gate);}
    }
    public function restore(array $decoded): array {
        if(is_file($this->private.'/recovery-required.json'))throw new RuntimeException('A previous restore needs manual recovery. See the private recovery report.');
        $test=$this->testRestore($decoded); // Never touch live tables unless the same archive imports successfully first.
        $gate=$this->lock('application');
        try {
            $safety=$this->backup('before_restore');$safe=$this->decrypt($safety['path']);$this->validateImport($safe['sql']);
            $report=['safety_backup'=>$safety['filename'],'started_at'=>date(DATE_ATOM),'test_database'=>$test['database']];
            $this->atomicJson($this->private.'/recovery-required.json',$report);
            try {
                $this->clearLive();$this->applyDefaults($decoded['metadata']['manifest']);$this->client('mysql',['--binary-mode=1','--database='.DB_NAME],$decoded['sql']);
                if($this->manifest(DB_NAME)!==$decoded['metadata']['manifest'])throw new RuntimeException('Restored live database failed verification.');
            } catch(Throwable $original) {
                try { $this->clearLive();$this->applyDefaults($safe['metadata']['manifest']);$this->client('mysql',['--binary-mode=1','--database='.DB_NAME],$safe['sql']);if($this->manifest(DB_NAME)!==$safe['metadata']['manifest'])throw new RuntimeException('Safety restore verification failed.'); }
                catch(Throwable $rollback) { $this->log('restore','failed','Restore and safety recovery failed. Application remains in maintenance mode.',['safety_backup'=>$safety['filename']]);throw new RuntimeException('Restore failed and safety recovery needs manual attention. Application stays locked. Safety backup: '.$safety['filename']); }
                unlink($this->private.'/recovery-required.json');
                $this->log('restore','failed','Restore failed; original data recovered from the safety backup.');
                throw new RuntimeException('Restore failed. Original data was recovered and verified. '.$original->getMessage());
            }
            $this->write($this->private.'/session-epoch',bin2hex(random_bytes(32)));
            unlink($this->private.'/recovery-required.json');
            $this->log('restore','success','Live database restored and verified.',['safety_backup'=>$safety['filename'],'test_database'=>$test['database']]);
            return ['safety_backup'=>$safety['filename'],'test'=>$test];
        } finally {flock($gate,LOCK_UN);fclose($gate);}
    }
}