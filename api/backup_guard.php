<?php
// Shared maintenance gate. No effect until backup setup has initialized the private folder.
function ttBackupPrivate(): string { return dirname(__DIR__, 3) . '/tooltrack-private'; }
function ttBackupGate(): void {
    if (PHP_SAPI === 'cli') return;
    $dir = ttBackupPrivate();
    if (!is_file($dir . '/initialized')) return;
    $endpoint = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($endpoint === realpath(__DIR__ . '/backups.php') || $endpoint === realpath(dirname(__DIR__) . '/backup.php')) return;
    $lock = fopen($dir . '/application.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_SH | LOCK_NB) || is_file($dir . '/recovery-required.json')) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: 30');
        echo json_encode(['success'=>false,'message'=>'Database maintenance is in progress. Please try again shortly.']);
        exit;
    }
    $GLOBALS['tt_backup_application_lock'] = $lock; // Held through request shutdown, including CMS writes.
}
// A restore may replace users and passwords. Existing sessions must authenticate again.
function ttBackupSessionGuard(): void {
    if (PHP_SAPI === 'cli' || !is_file(ttBackupPrivate().'/initialized') || session_status() !== PHP_SESSION_ACTIVE) return;
    $epoch = is_file(ttBackupPrivate().'/session-epoch') ? trim((string)file_get_contents(ttBackupPrivate().'/session-epoch')) : 'initial';
    if (!empty($_SESSION['user_id']) && ($_SESSION['tt_backup_epoch'] ?? 'initial') !== $epoch) $_SESSION = [];
    $_SESSION['tt_backup_epoch'] = $epoch;
}