<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backup_lib.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$user = requireRole(ROLE_ADMIN);
// Check the current database role too: a stale session must not grant backup access.
$stmt = getDB()->prepare('SELECT id, role, password FROM users WHERE id=?');
$stmt->execute([$user['id']]); $admin = $stmt->fetch();
if (!$admin || $admin['role'] !== ROLE_ADMIN) fail('Administrator access required.',403);
if (empty($_SESSION['backup_csrf'])) $_SESSION['backup_csrf'] = bin2hex(random_bytes(32));
$service = null; $action = ''; $operation = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!is_file(ttBackupPrivate().'/initialized')) ok(['configured'=>false,'csrf'=>$_SESSION['backup_csrf'],'message'=>'Run backups/setup-backups.ps1 on the XAMPP PC to initialize protected storage.']);
        $service = new ToolTrackBackup();
        ok(array_merge($service->status(),['csrf'=>$_SESSION['backup_csrf']]));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed.',405);
    $data = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') ? $_POST : body();
    if (!hash_equals($_SESSION['backup_csrf'],(string)($data['csrf'] ?? ''))) fail('Security token expired. Reopen Settings and retry.',403);
    $action = (string)($data['action'] ?? '');
    $allowed = ['configure','backup','download','export_key','test_restore','restore'];
    if (!in_array($action,$allowed,true)) fail('Unknown backup action.');
    if (in_array($action,['configure','export_key','restore'],true)) {
        if (($_SESSION['backup_password_retry_after'] ?? 0) > time()) fail('Wait before retrying administrator confirmation.',429);
        $attempt = (string)($data['password'] ?? '');
        $isHashed = password_get_info($admin['password'])['algo'] !== null;
        $valid = $attempt !== '' && ($isHashed ? password_verify($attempt,$admin['password']) : hash_equals($admin['password'],$attempt));
        if (!$valid) { $_SESSION['backup_password_retry_after']=time()+3;fail('Administrator password is incorrect.',403); }
        unset($_SESSION['backup_password_retry_after']);
    }
    $service = new ToolTrackBackup();
    $operation = $service->lock();
    if (in_array($action,['backup','configure','restore','test_restore'],true) && is_file(ttBackupPrivate().'/recovery-required.json')) throw new RuntimeException('Restore recovery is required. See the private recovery report before continuing.');
    if ($action === 'configure') { $service->configure((string)($data['folder'] ?? ''));$service->log('configure','success','Backup destination updated.');ok(['message'=>'Backup folder saved.']); }
    if ($action === 'backup') { $r=$service->backup();unset($r['path']);ok($r); }
    if ($action === 'export_key') {
        $service->log('export_key','success','Administrator downloaded the recovery key.');
        header('Content-Type: application/json');header('Content-Disposition: attachment; filename="tooltrack-recovery-key.json"');
        echo json_encode($service->exportKey(),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);exit;
    }
    if ($action === 'download') {
        $r=$service->find((string)($data['id'] ?? ''));
        header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.$r['filename'].'"');header('Content-Length: '.$r['bytes']);
        readfile($r['path']);exit;
    }
    if ($action === 'restore' && ($data['confirmation'] ?? '') !== 'RESTORE '.DB_NAME) fail('Type RESTORE '.DB_NAME.' to approve replacing the live database.');
    $recovery = null;
    if (isset($_FILES['recovery']) && $_FILES['recovery']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['recovery']['error'] !== UPLOAD_ERR_OK || $_FILES['recovery']['size'] > 4096) fail('Recovery key upload failed or is too large.');
        $recovery = file_get_contents($_FILES['recovery']['tmp_name']);
    }
    if (isset($_FILES['backup']) && $_FILES['backup']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['backup']['error'] !== UPLOAD_ERR_OK) fail('Backup upload failed. Check PHP upload_max_filesize and post_max_size, or use a backup from history.');
        $archive = $service->temp('.ttbackup');
        if (!move_uploaded_file($_FILES['backup']['tmp_name'],$archive)) throw new RuntimeException('Could not store the uploaded encrypted backup.');
    } else { $r=$service->find((string)($data['id'] ?? ''));$archive=$r['path']; }
    $decoded = $service->decrypt($archive,$recovery);
    if ($action === 'test_restore') ok($service->testRestore($decoded));
    $result=$service->restore($decoded);
    // Restored users/passwords may differ: require a fresh login for this administrator.
    $_SESSION=[];session_destroy();
    ok($result);
} catch (Throwable $e) {
    error_log('ToolTrack backups: '.$e->getMessage());
    if ($service && $action !== '' && $operation) { try {$service->log($action,'failed',$e->getMessage());} catch(Throwable $ignored) {} }
    fail($e instanceof JsonException ? 'Invalid backup metadata or recovery key.' : $e->getMessage(),400);
}