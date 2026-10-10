<?php
/**
 * ToolTrack scheduled backup.
 *
 * Run every 2 minutes by Windows Task Scheduler (see Install-Backup.bat).
 * Each run is quick and does only what's needed:
 *
 *   DATABASE  If there is no backup yet for today's date in the
 *             Philippines, dump the database and zip it.  Because it
 *             checks "do we have today's?" rather than firing once at
 *             00:00, a PC that was off at midnight catches up as soon
 *             as it is on and MySQL is running.
 *
 *   PROJECT   If any project file changed (and has been left alone for
 *             a few seconds), zip a snapshot of the project.
 *
 *   CLOUD     Copy any backup not yet in Google Drive to Drive.  If
 *             Drive isn't available the local backup is unaffected and
 *             the copy is retried automatically.
 *
 * Manual use (from a command prompt, using XAMPP's php.exe):
 *   php run.php --check          show what was found / what's wrong
 *   php run.php --status         show when the last backups happened
 *   php run.php --force-db       take a database backup right now
 *   php run.php --force-files    take a project snapshot right now
 *   php run.php --config=FILE    use a different settings file
 *   php run.php --quiet          no console output (log file only)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('The backup script can only be run from the command line.');
}

$opts  = getopt('', ['check', 'status', 'force-db', 'force-files', 'quiet', 'config:']);
$QUIET = isset($opts['quiet']);

// -- Settings -----------------------------------------------------
$defaults = [
    'timezone' => 'Asia/Manila', 'local_dir' => '', 'gdrive_dir' => '',
    'gdrive_subfolder' => 'ToolTrack-Backups', 'mysqldump' => '',
    'db_host' => '', 'db_port' => 3306, 'db_name' => '', 'db_user' => '', 'db_pass' => null,
    'keep_db_days' => 60, 'keep_all_files_days' => 2, 'keep_daily_files_days' => 30,
    'quiet_seconds' => 20, 'cloud_resync_minutes' => 60,
    'exclude' => ['.git', '_incoming', 'backups'],
];
$cfgFile = $opts['config'] ?? (__DIR__ . '/config.php');
$userCfg = is_file($cfgFile) ? require $cfgFile : [];
$cfg     = array_merge($defaults, is_array($userCfg) ? $userCfg : []);
date_default_timezone_set($cfg['timezone']);

$state = [];   // persisted in <local_dir>/_system/state.json

// -- Small helpers ------------------------------------------------
function norm(string $p): string { return rtrim(str_replace('\\', '/', $p), '/'); }

function nowTs(): int {
    $o = getenv('TOOLTRACK_BACKUP_NOW');            // test hook only
    return ($o !== false && $o !== '') ? (int)strtotime($o) : time();
}

function projectDir(): string { return norm(dirname(__DIR__)); }

function xamppRoot(): ?string {
    $bin = norm(PHP_BINARY);
    return (strtolower(basename(dirname($bin))) === 'php') ? norm(dirname(dirname($bin))) : null;
}

function resolvePath(string $p): string {       // like realpath, but works for folders that don't exist yet
    $p = norm($p); $rest = [];
    while ($p !== '' && !file_exists($p)) {
        $rest[] = basename($p);
        $parent = norm(dirname($p));
        if ($parent === $p) break;
        $p = $parent;
    }
    $base = realpath($p);
    return norm($base !== false ? $base : $p) . ($rest ? '/' . implode('/', array_reverse($rest)) : '');
}

function isInside(string $child, string $parent): bool {
    return strpos(strtolower(resolvePath($child)) . '/', strtolower(resolvePath($parent)) . '/') === 0;
}

function localDir(): string {
    global $cfg;
    if ($cfg['local_dir'] !== '') return norm($cfg['local_dir']);
    $x = xamppRoot();
    return norm($x ? dirname($x) : dirname(projectDir())) . '/ToolTrack-Backups';
}
function sysDir(): string { return localDir() . '/_system'; }
function kindDir(string $kind): string { return localDir() . '/' . $kind; }

function logmsg(string $level, string $msg): void {
    global $QUIET;
    $line = '[' . date('Y-m-d H:i:s P', nowTs()) . '] ' . str_pad($level, 5) . ' ' . $msg;
    if (!$QUIET) echo $line . PHP_EOL;
    $f = sysDir() . '/backup.log';
    if (is_dir(sysDir())) {
        if (is_file($f) && filesize($f) > 1048576) { @unlink($f . '.1'); @rename($f, $f . '.1'); }
        @file_put_contents($f, $line . PHP_EOL, FILE_APPEND);
    }
}

function loadState(): void {
    global $state;
    $f = sysDir() . '/state.json';
    $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $state = is_array($s) ? $s : [];
    foreach (['db', 'files', 'cloud'] as $k) if (!isset($state[$k]) || !is_array($state[$k])) $state[$k] = [];
}
function saveState(): void {
    global $state;
    $state['updated_at'] = date('Y-m-d H:i:s P', nowTs());
    $tmp = sysDir() . '/state.json.tmp';
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
    @unlink(sysDir() . '/state.json');
    rename($tmp, sysDir() . '/state.json');
}

function setError(string $section, string $msg): array {
    global $state;
    $state[$section]['last_error'] = date('Y-m-d H:i:s P', nowTs()) . ' - ' . $msg;
    logmsg('ERROR', ucfirst($section) . ' backup: ' . $msg);
    return ['status' => 'error'];
}

function fmtSize(int $b): string { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024, 1) . ' KB'; }

// -- Database settings (read from api/bootstrap.php unless overridden) --
function dbCreds(): array {
    global $cfg;
    $src = @file_get_contents(projectDir() . '/api/bootstrap.php') ?: '';
    $get = function (string $k, string $def) use ($src): string {
        return preg_match("/define\(\s*'" . $k . "'\s*,\s*'([^']*)'\s*\)/", $src, $m) ? $m[1] : $def;
    };
    $host = $cfg['db_host'] !== '' ? $cfg['db_host'] : $get('DB_HOST', 'localhost');
    return [
        'host' => strtolower($host) === 'localhost' ? '127.0.0.1' : $host,   // force TCP
        'port' => (int)$cfg['db_port'],
        'name' => $cfg['db_name'] !== '' ? $cfg['db_name'] : $get('DB_NAME', 'tooltrack_db'),
        'user' => $cfg['db_user'] !== '' ? $cfg['db_user'] : $get('DB_USER', 'root'),
        'pass' => $cfg['db_pass'] !== null ? (string)$cfg['db_pass'] : $get('DB_PASS', ''),
    ];
}

function findMysqldump(): ?string {
    global $cfg;
    if ($cfg['mysqldump'] !== '') return is_file($cfg['mysqldump']) ? norm($cfg['mysqldump']) : null;
    $exe = PHP_OS_FAMILY === 'Windows' ? 'mysqldump.exe' : 'mysqldump';
    $x = xamppRoot();
    $try = $x ? ["$x/mysql/bin/$exe"] : [];
    $try = array_merge($try, ['C:/xampp/mysql/bin/mysqldump.exe', 'C:/xampp1/mysql/bin/mysqldump.exe',
                              '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/lampp/bin/mysqldump']);
    foreach ($try as $p) if (is_file($p)) return $p;
    return null;
}

function mysqlReachable(string $host, int $port): bool {
    $s = @fsockopen($host, $port, $errno, $errstr, 2);
    if ($s) { fclose($s); return true; }
    return false;
}

// -- Google Drive location ----------------------------------------
function driveBase(): ?string {
    global $cfg;
    if ($cfg['gdrive_dir'] !== '') return norm($cfg['gdrive_dir']);
    if (PHP_OS_FAMILY !== 'Windows') return null;
    foreach (range('D', 'Z') as $l) if (@is_dir("$l:/My Drive")) return "$l:/My Drive";
    $home = norm((string)getenv('USERPROFILE'));
    foreach (["$home/My Drive", "$home/Google Drive"] as $p) if ($home !== '' && @is_dir($p)) return $p;
    return null;
}

// -- Project scan / fingerprint -----------------------------------
function scanProject(): array {
    global $cfg;
    $root = projectDir();
    $ex   = array_map('strtolower', $cfg['exclude']);
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($cur) use ($root, $ex) {
            $rel = ltrim(substr(norm($cur->getPathname()), strlen($root)), '/');
            return !in_array(strtolower(explode('/', $rel)[0]), $ex, true);
        }
    ));
    $files = []; $newest = 0;
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = ltrim(substr(norm($f->getPathname()), strlen($root)), '/');
        $mt  = (int)@$f->getMTime();
        $files[$rel] = [$rel, (int)@$f->getSize(), $mt];
        if ($mt > $newest) $newest = $mt;
    }
    ksort($files);
    $lines = array_map(fn($x) => implode('|', $x), array_values($files));
    return [array_values($files), $newest, sha1(implode("\n", $lines))];
}

// -- DATABASE backup ----------------------------------------------
function backupDb(bool $force): array {
    global $state;
    $today = date('Y-m-d', nowTs());
    if (!$force && glob(kindDir('db') . "/tooltrack_db_{$today}_*.zip")) return ['status' => 'skip'];

    $db   = dbCreds();
    $dump = findMysqldump();
    if (!$dump) return setError('db', 'mysqldump not found. Set "mysqldump" in backup/config.php.');

    if (!mysqlReachable($db['host'], $db['port'])) {
        $state['db']['waiting'] = true;
        if (nowTs() - (int)($state['last_wait_log'] ?? 0) > 3600) {
            logmsg('WARN', "No backup for $today yet and MySQL isn't running - will retry automatically once it is.");
            $state['last_wait_log'] = nowTs();
        }
        return ['status' => 'waiting'];
    }
    $state['db']['waiting'] = false;

    $base   = 'tooltrack_db_' . date('Y-m-d_H-i-s', nowTs());
    $tmpDir = sysDir() . '/tmp';
    $sql = "$tmpDir/$base.sql"; $zipTmp = "$tmpDir/$base.zip"; $cnf = "$tmpDir/$base.cnf";

    // Credentials go in a throw-away option file, not on the command line.
    $esc = fn(string $v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    file_put_contents($cnf, "[client]\nuser={$esc($db['user'])}\npassword={$esc($db['pass'])}\n"
                          . "host={$esc($db['host'])}\nport={$db['port']}\n");
    $cmd = [$dump, "--defaults-extra-file=$cnf", '--single-transaction', '--routines', '--triggers',
            '--default-character-set=utf8mb4', '--databases', $db['name'], "--result-file=$sql"];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { @unlink($cnf); return setError('db', 'could not start mysqldump.'); }
    stream_get_contents($pipes[1]); $err = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    @unlink($cnf);

    $okDump = $code === 0 && is_file($sql) && filesize($sql) > 0
              && strpos((string)file_get_contents($sql, false, null, max(0, filesize($sql) - 2048)), 'Dump completed') !== false;
    if (!$okDump) {
        @unlink($sql);
        return setError('db', "mysqldump failed (exit $code): " . ($err !== '' ? $err : 'dump was incomplete'));
    }

    $z = new ZipArchive();
    if ($z->open($zipTmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true || !$z->addFile($sql, "$base.sql") || !$z->close()) {
        @unlink($sql); @unlink($zipTmp);
        return setError('db', 'could not create the zip file.');
    }
    $v = new ZipArchive();                                  // verify what we wrote
    if ($v->open($zipTmp) !== true || $v->numFiles !== 1 || $v->statIndex(0)['size'] !== filesize($sql)) {
        @unlink($sql); @unlink($zipTmp);
        return setError('db', 'zip verification failed.');
    }
    $v->close(); @unlink($sql);

    $final = kindDir('db') . "/$base.zip";
    if (!rename($zipTmp, $final)) { @unlink($zipTmp); return setError('db', 'could not move backup into place.'); }

    $state['db']['last_ok'] = date('Y-m-d H:i:s P', nowTs());
    $state['db']['last_file'] = basename($final);
    $state['db']['last_error'] = null;
    logmsg('INFO', 'Database backup saved: ' . basename($final) . ' (' . fmtSize(filesize($final)) . ')');
    return ['status' => 'done', 'file' => $final];
}

// -- PROJECT FILES snapshot ---------------------------------------
function snapshotFiles(bool $force): array {
    global $state, $cfg;
    [$list, $newest, $fp] = scanProject();
    if (!$force && $fp === ($state['files_fp'] ?? '')) return ['status' => 'skip'];
    if (!$force && nowTs() - $newest < (int)$cfg['quiet_seconds']) return ['status' => 'busy'];

    $base   = 'tooltrack_files_' . date('Y-m-d_H-i-s', nowTs());
    $zipTmp = sysDir() . "/tmp/$base.zip";
    $prefix = basename(projectDir());                       // unzipping in htdocs recreates the folder
    $z = new ZipArchive();
    if ($z->open($zipTmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return setError('files', 'could not create the zip file.');
    foreach ($list as [$rel]) $z->addFile(projectDir() . '/' . $rel, "$prefix/$rel");
    if (!@$z->close()) {
        @unlink($zipTmp);
        return setError('files', 'could not finish the zip (a file may be locked/in use) - will retry next run.');
    }
    $final = kindDir('files') . "/$base.zip";
    if (!rename($zipTmp, $final)) { @unlink($zipTmp); return setError('files', 'could not move snapshot into place.'); }

    $state['files_fp'] = $fp;
    $state['files']['last_ok'] = date('Y-m-d H:i:s P', nowTs());
    $state['files']['last_file'] = basename($final);
    $state['files']['last_error'] = null;
    logmsg('INFO', 'Project snapshot saved: ' . basename($final) . ' (' . count($list) . ' files, ' . fmtSize(filesize($final)) . ')');
    return ['status' => 'done', 'file' => $final];
}

// -- Retention ----------------------------------------------------
function pruneDir(string $dir, string $kind): int {
    global $cfg;
    $items = [];
    foreach (glob("$dir/tooltrack_{$kind}_*.zip") ?: [] as $f) {
        if (preg_match('/^tooltrack_' . $kind . '_(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})\.zip$/', basename($f), $m)) {
            $items[] = ['f' => $f, 'ts' => (int)strtotime("$m[1] $m[2]:$m[3]:$m[4]"), 'day' => $m[1]];
        }
    }
    if (count($items) < 2) return 0;
    usort($items, fn($a, $b) => $b['ts'] <=> $a['ts']);      // newest first
    $now = nowTs(); $seenDay = []; $deleted = 0;
    foreach ($items as $i => $it) {
        $age  = $now - $it['ts'];
        $keep = $i === 0;                                    // never delete the newest
        if (!$keep && $kind === 'db')    $keep = $age <= $cfg['keep_db_days'] * 86400;
        if (!$keep && $kind === 'files') {
            if ($age <= $cfg['keep_all_files_days'] * 86400) $keep = true;
            elseif ($age <= $cfg['keep_daily_files_days'] * 86400 && !isset($seenDay[$it['day']])) $keep = true;
        }
        if ($kind === 'files' && $age > $cfg['keep_all_files_days'] * 86400 && $keep) $seenDay[$it['day']] = true;
        if (!$keep && @unlink($it['f'])) $deleted++;
    }
    return $deleted;
}

// -- Google Drive replication -------------------------------------
function replicate(): array {
    global $state, $cfg;
    $base = driveBase();
    $c =& $state['cloud'];
    if (!$base) { $c['enabled'] = false; return ['status' => 'disabled']; }
    $c['enabled'] = true; $c['path'] = $base;

    $fail = function (string $msg) use (&$c): array {
        if (($c['last_error'] ?? null) === null || strpos($c['last_error'], $msg) === false) logmsg('WARN', "Google Drive: $msg");
        $c['last_error'] = date('Y-m-d H:i:s P', nowTs()) . ' - ' . $msg;
        $c['pending'] = true;
        return ['status' => 'pending'];
    };
    if (!@is_dir($base)) return $fail("folder not found: $base (is Google Drive for desktop running and signed in?)");

    $root = "$base/" . $cfg['gdrive_subfolder'];
    foreach (['db', 'files'] as $kind) {
        if (!@is_dir("$root/$kind") && !@mkdir("$root/$kind", 0777, true)) return $fail("cannot create $root/$kind");
    }
    $copied = 0; $failed = 0;
    foreach (['db', 'files'] as $kind) {
        foreach (glob(kindDir($kind) . "/tooltrack_{$kind}_*.zip") ?: [] as $src) {
            $dst = "$root/$kind/" . basename($src);
            if (is_file($dst) && filesize($dst) === filesize($src)) continue;
            if (@copy($src, "$dst.part") && @rename("$dst.part", $dst)) {
                $copied++; logmsg('INFO', 'Copied to Google Drive: ' . "$kind/" . basename($src));
            } else { $failed++; @unlink("$dst.part"); }
        }
        pruneDir("$root/$kind", $kind);
    }
    $c['last_sync'] = nowTs();
    if ($failed) return $fail("$failed file(s) could not be copied - will retry");
    $c['pending'] = false; $c['last_error'] = null;
    if ($copied) $c['last_ok'] = date('Y-m-d H:i:s P', nowTs());
    return ['status' => 'ok', 'copied' => $copied];
}

// -- One scheduled cycle ------------------------------------------
function runCycle(bool $forceDb, bool $forceFiles): int {
    global $state, $cfg;
    $errors = 0; $new = false;

    foreach ([['db', fn() => backupDb($forceDb)], ['files', fn() => snapshotFiles($forceFiles)]] as [$name, $fn]) {
        try {
            $r = $fn();
            if ($r['status'] === 'done')  $new = true;
            if ($r['status'] === 'error') $errors++;
        } catch (Throwable $e) {
            setError($name, 'unexpected error: ' . $e->getMessage()); $errors++;
        }
    }

    // Retention locally first, so Drive ends up mirroring the same set.
    foreach (['db', 'files'] as $kind) pruneDir(kindDir($kind), $kind);

    $due = $new || !empty($state['cloud']['pending'])
           || nowTs() - (int)($state['cloud']['last_sync'] ?? 0) > (int)$cfg['cloud_resync_minutes'] * 60;
    if ($due) {
        try { replicate(); } catch (Throwable $e) { logmsg('ERROR', 'Google Drive copy crashed: ' . $e->getMessage()); }
    }
    // Clear out temp leftovers from any interrupted run.
    foreach (glob(sysDir() . '/tmp/*') ?: [] as $t) if (is_file($t) && filemtime($t) < time() - 3600) @unlink($t);
    return $errors ? 1 : 0;
}

// -- --check / --status -------------------------------------------
function doCheck(): int {
    global $cfg;
    $bad = 0;
    $line = function (bool $ok, string $label, string $detail) use (&$bad) {
        if (!$ok) $bad++;
        echo ($ok ? '  [ OK ] ' : '  [FAIL] ') . str_pad($label, 22) . $detail . PHP_EOL;
    };
    echo PHP_EOL . 'ToolTrack backup check' . PHP_EOL . str_repeat('-', 60) . PHP_EOL;
    echo '  Time in Philippines now: ' . date('Y-m-d H:i:s P', nowTs()) . PHP_EOL;
    echo '  Project folder:          ' . projectDir() . PHP_EOL . PHP_EOL;

    $line(class_exists('ZipArchive'), 'PHP zip extension', class_exists('ZipArchive') ? 'enabled'
          : 'MISSING - in XAMPP\\php\\php.ini remove the ";" before extension=zip');
    $dump = findMysqldump();
    $line((bool)$dump, 'mysqldump', $dump ?: 'not found - set "mysqldump" in backup/config.php');

    $db = dbCreds();
    $up = mysqlReachable($db['host'], $db['port']);
    $line($up, 'MySQL running', $up ? "{$db['host']}:{$db['port']} (database {$db['name']}, user {$db['user']})"
          : "nothing listening on {$db['host']}:{$db['port']} - start MySQL in XAMPP (backups wait until it is)");

    $ld = localDir();
    if (isInside($ld, projectDir())) { $line(false, 'Local backup folder', "$ld is INSIDE the project - choose a folder outside htdocs"); }
    else {
        @mkdir(sysDir() . '/tmp', 0777, true); @mkdir(kindDir('db'), 0777, true); @mkdir(kindDir('files'), 0777, true);
        $probe = "$ld/.write-test";
        $w = @file_put_contents($probe, 'x') !== false; @unlink($probe);
        $line($w, 'Local backup folder', $w ? $ld : "cannot write to $ld");
    }

    $dr = driveBase();
    if (!$dr) echo '  [ -- ] ' . str_pad('Google Drive', 22) . 'not found - install/sign in to Google Drive for desktop, or set "gdrive_dir" in backup/config.php (local backups still work)' . PHP_EOL;
    elseif (!@is_dir($dr)) $line(false, 'Google Drive', "$dr does not exist (is Drive for desktop running and signed in?)");
    else {
        $sub = "$dr/" . $cfg['gdrive_subfolder']; @mkdir($sub, 0777, true);
        $probe = "$sub/.write-test"; $w = @file_put_contents($probe, 'x') !== false; @unlink($probe);
        $line($w, 'Google Drive', $w ? $sub : "cannot write to $sub");
    }
    echo PHP_EOL . ($bad ? "  $bad problem(s) above need fixing." : '  Everything needed is in place.') . PHP_EOL . PHP_EOL;
    return $bad ? 1 : 0;
}

function doStatus(): int {
    global $state;
    $when = fn($v) => $v ?: 'never';
    echo PHP_EOL . 'ToolTrack backup status (last run: ' . $when($state['updated_at'] ?? null) . ')' . PHP_EOL . str_repeat('-', 60) . PHP_EOL;
    echo '  Database  last OK: ' . $when($state['db']['last_ok'] ?? null) . '  ' . ($state['db']['last_file'] ?? '') . PHP_EOL;
    if (!empty($state['db']['waiting'])) echo '            waiting for MySQL to be started' . PHP_EOL;
    if (!empty($state['db']['last_error'])) echo '            last error: ' . $state['db']['last_error'] . PHP_EOL;
    echo '  Project   last OK: ' . $when($state['files']['last_ok'] ?? null) . '  ' . ($state['files']['last_file'] ?? '') . PHP_EOL;
    if (!empty($state['files']['last_error'])) echo '            last error: ' . $state['files']['last_error'] . PHP_EOL;
    $c = $state['cloud'];
    echo '  Drive     ' . (empty($c['enabled']) ? 'not configured/found' : 'last copy: ' . $when($c['last_ok'] ?? null) . ($c['pending'] ?? false ? '  (copies PENDING)' : '  (up to date)')) . PHP_EOL;
    if (!empty($c['last_error'])) echo '            last error: ' . $c['last_error'] . PHP_EOL;
    echo PHP_EOL;
    return 0;
}

// -- Main ---------------------------------------------------------
$ld = localDir();
if (isInside($ld, projectDir())) {
    fwrite(STDERR, "Local backup folder ($ld) is inside the project. Set 'local_dir' in backup/config.php to a folder outside htdocs.\n");
    exit(2);
}
foreach ([sysDir() . '/tmp', kindDir('db'), kindDir('files')] as $d) {
    if (!is_dir($d) && !@mkdir($d, 0777, true)) { fwrite(STDERR, "Cannot create $d\n"); exit(2); }
}

if (isset($opts['check'])) exit(doCheck());

// One run at a time (a slow Google Drive copy must not overlap the next run).
$lock = fopen(sysDir() . '/run.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$QUIET) echo "Another backup run is already in progress - try again in a minute.\n";
    exit(0);
}

loadState();
if (isset($opts['status'])) exit(doStatus());

$exit = runCycle(isset($opts['force-db']), isset($opts['force-files']));
saveState();
exit($exit);
