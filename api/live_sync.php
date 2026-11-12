<?php
// Read-only change check. Triggers are installed explicitly by migrate_live_sync.php.
require_once __DIR__.'/config.php';
$actor=requireRole([ROLE_ADMIN, ROLE_STAFF]);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Method not allowed.',405);
$page = $_GET['page'] ?? '';
$pages = ['dashboard','tools','borrowers','borrow','return','replacements','reports','users','audit'];
if (!is_string($page) || !in_array($page,$pages,true)) fail('Invalid page.');
if (in_array($page,['users','audit'],true)) requireRole(ROLE_ADMIN);
session_write_close();
try {
    $db=getDB();
    $access=$db->prepare('SELECT role FROM users WHERE id=?');$access->execute([$actor['id']]);
    $role=$access->fetchColumn();
    if(!$role)fail('Session account no longer exists. Please sign in again.',401);
    if($role!==$actor['role'])fail('Account permissions changed. Please sign in again.',403);
    // A single small consistent read; no record identifiers or sensitive fields leave here.
    $rows=$db->query('SELECT table_name, revision FROM live_sync_versions ORDER BY table_name')->fetchAll();
    $selected=[];
    foreach($rows as $r) {
        $t=$r['table_name'];
        if ($t==='users' && $page!=='users') continue;
        if ($t==='audit_log' && $page!=='audit') continue;
        $selected[]=$r;
    }
    // Time-driven overdue displays must advance without a write.
    ok(['version'=>hash('sha256',json_encode([$selected,date('Y-m-d H:i')])), 'timezone'=>APP_TIMEZONE]);
} catch(Throwable $e) {
    error_log('Live synchronization: '.$e->getMessage());
    fail('Live updates unavailable. Run the live synchronization migration.',503);
}
