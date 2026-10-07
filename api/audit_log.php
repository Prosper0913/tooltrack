<?php
// ================================================================
//  api/audit_log.php  —  Admin-only audit trail viewer
//
//  GET ?page=1&per_page=25&action=&search=&date_from=&date_to=
//      → { data: [...], total, page, per_page }
//  GET ?actions=1 → just the distinct action values in use, for
//      populating the filter dropdown without hardcoding a list
//      that can drift from what's actually being logged.
// ================================================================
require_once __DIR__ . '/config.php';

requireRole(ROLE_ADMIN);

$db = getDB();

if (!empty($_GET['actions'])) {
    $stmt = $db->query('SELECT DISTINCT action FROM audit_log ORDER BY action');
    ok(array_column($stmt->fetchAll(), 'action'));
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if (!empty($_GET['action'])) {
    $where[] = 'action = ?';
    $params[] = $_GET['action'];
}
if (!empty($_GET['search'])) {
    $where[] = '(username_snapshot LIKE ? OR description LIKE ? OR ip_address LIKE ?)';
    $like = '%' . $_GET['search'] . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if (!empty($_GET['date_from'])) {
    $where[] = 'DATE(created_at) >= ?';
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[] = 'DATE(created_at) <= ?';
    $params[] = $_GET['date_to'];
}

$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT * FROM audit_log WHERE $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$i = 1;
foreach ($params as $p) { $stmt->bindValue($i++, $p); }
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i++, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['device'] = friendlyDevice($r['user_agent']);
}
unset($r);

http_response_code(200);
echo json_encode([
    'success'  => true,
    'data'     => $rows,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
