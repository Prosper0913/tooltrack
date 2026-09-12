<?php
/**
 * ToolTrack — Automatic Database Backup
 * Place this file in your project root (e.g. C:/xampp/htdocs/tooltrack/)
 *
 * Can be triggered:
 *   1. Via browser: http://localhost/tooltrack/backup.php
 *   2. Via bat file / Task Scheduler: php backup.php
 */

// ─── CONFIGURATION ──────────────────────────────────────────
define('DB_HOST',     'localhost');
define('DB_USER',     'root');
define('DB_PASS',     '');           // your MySQL password (blank by default in XAMPP)
define('DB_NAME',     'tooltrack_db');
define('MYSQLDUMP',   'C:/xampp/mysql/bin/mysqldump.exe');

// Primary backup folder — change to your USB drive letter
// e.g.  'E:/ToolTrack_Backups'  or  'F:/Backups/ToolTrack'
define('BACKUP_DIR',  'E:/ToolTrack_Backups');

// Also keep a local copy inside the project? (true/false)
define('LOCAL_COPY',  true);
define('LOCAL_DIR',   __DIR__ . '/backups');

// How many backup files to keep per location (older ones auto-deleted)
define('MAX_BACKUPS', 30);
// ─────────────────────────────────────────────────────────────

$isCLI = (php_sapi_name() === 'cli');

function respond($ok, $msg, $data = []) {
    global $isCLI;
    if ($isCLI) {
        echo ($ok ? "[OK] " : "[ERROR] ") . $msg . PHP_EOL;
        foreach ($data as $k => $v) echo "  $k: $v" . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $data));
    }
    exit($ok ? 0 : 1);
}

// ── 1. Check mysqldump exists ────────────────────────────────
if (!file_exists(MYSQLDUMP)) {
    respond(false, 'mysqldump not found at: ' . MYSQLDUMP .
            '. Edit MYSQLDUMP path in backup.php.');
}

// ── 2. Ensure backup directories exist ──────────────────────
$dirs = [BACKUP_DIR];
if (LOCAL_COPY) $dirs[] = LOCAL_DIR;

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true)) {
            respond(false, "Cannot create backup folder: $dir — " .
                    "Is the USB drive plugged in? Is the path correct?");
        }
    }
    if (!is_writable($dir)) {
        respond(false, "Backup folder not writable: $dir");
    }
}

// ── 3. Generate filename ─────────────────────────────────────
$timestamp = date('Y-m-d_H-i-s');
$filename  = DB_NAME . '_backup_' . $timestamp . '.sql';

// ── 4. Run mysqldump ─────────────────────────────────────────
$usbPath   = BACKUP_DIR . '/' . $filename;
$password  = DB_PASS !== '' ? '--password=' . escapeshellarg(DB_PASS) : '';
$cmd = sprintf(
    '"%s" --host=%s --user=%s %s --single-transaction --routines --triggers %s > "%s" 2>&1',
    MYSQLDUMP,
    DB_HOST,
    DB_USER,
    $password,
    DB_NAME,
    $usbPath
);

exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !file_exists($usbPath) || filesize($usbPath) === 0) {
    @unlink($usbPath);
    respond(false, 'mysqldump failed.', ['output' => implode(' | ', $output)]);
}

$size = round(filesize($usbPath) / 1024, 1) . ' KB';
$saved = [$usbPath];

// ── 5. Optional local copy ───────────────────────────────────
if (LOCAL_COPY) {
    $localPath = LOCAL_DIR . '/' . $filename;
    copy($usbPath, $localPath);
    $saved[] = $localPath;
}

// ── 6. Prune old backups (keep MAX_BACKUPS newest) ───────────
foreach ($dirs as $dir) {
    $files = glob($dir . '/' . DB_NAME . '_backup_*.sql');
    if ($files && count($files) > MAX_BACKUPS) {
        usort($files, fn($a,$b) => filemtime($a) - filemtime($b));
        $toDelete = array_slice($files, 0, count($files) - MAX_BACKUPS);
        foreach ($toDelete as $f) @unlink($f);
    }
}

// ── 7. Log the backup ────────────────────────────────────────
$logLine = date('Y-m-d H:i:s') . " | SUCCESS | $filename | $size\n";
file_put_contents(BACKUP_DIR . '/backup_log.txt', $logLine, FILE_APPEND | LOCK_EX);
if (LOCAL_COPY) {
    file_put_contents(LOCAL_DIR . '/backup_log.txt', $logLine, FILE_APPEND | LOCK_EX);
}

respond(true, "Backup successful: $filename ($size)", [
    'filename'  => $filename,
    'size'      => $size,
    'saved_to'  => implode(' + ', $saved),
    'timestamp' => $timestamp,
]);
