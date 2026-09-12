<?php
// ================================================================
//  api/borrowers.php  —  Full CRUD for Borrowers
//
//  GET    ?page=1&per_page=10&type=&search=
//         ?id=N  → single borrower
//  POST   { full_name, id_number, type, email, phone }
//  PUT    { id, full_name, id_number, type, email, phone }
//  DELETE ?id=N
// ================================================================
require_once __DIR__ . '/config.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {

    // Single borrower
    if (!empty($_GET['id'])) {
        $stmt = $db->prepare("SELECT b.*, e.course, e.section_name, e.cms_section_id
            FROM borrowers b
            LEFT JOIN borrower_enrollments e
              ON e.borrower_id = b.id AND e.is_active = 1
            WHERE b.id = ?
            ORDER BY e.id ASC
            LIMIT 1");
        $stmt->execute([(int)$_GET['id']]);
        $b = $stmt->fetch();
        if (!$b) fail('Borrower not found.', 404);
        ok($b);
    }

    // List
    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 10)));
    $offset  = ($page - 1) * $perPage;
    $type    = $_GET['type']   ?? '';
    $search  = '%' . trim($_GET['search'] ?? '') . '%';

    $where  = ['1=1'];
    $params = [];

    if ($type) {
        $where[]  = 'type = ?';
        $params[] = $type;
    }
    if (trim($_GET['search'] ?? '') !== '') {
        $where[]  = '(full_name LIKE ? OR id_number LIKE ? OR email LIKE ?)';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $countSql = 'SELECT COUNT(*) FROM borrowers WHERE ' . implode(' AND ', $where);
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $whereSql = implode(' AND ', array_map(fn($w) => str_replace(
        ['type =','full_name LIKE','id_number LIKE','email LIKE'],
        ['b.type =','b.full_name LIKE','b.id_number LIKE','b.email LIKE'],
        $w
    ), $where));

    $sql = 'SELECT b.*, e.course, e.section_name, e.cms_section_id
            FROM borrowers b
            LEFT JOIN borrower_enrollments e
              ON e.id = (
                  SELECT MIN(e2.id)
                  FROM borrower_enrollments e2
                  WHERE e2.borrower_id = b.id AND e2.is_active = 1
              )
            WHERE ' . $whereSql . '
            ORDER BY b.full_name ASC LIMIT ? OFFSET ?';
    $params[] = $perPage;
    $params[] = $offset;

    $stmt  = $db->prepare($sql);
    $stmt->execute($params);
    $rows  = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success'  => true,
        'data'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
    exit;
}

// ── POST (Create) ─────────────────────────────────────────────
if ($method === 'POST') {
    $b = body();

    $full_name = trim($b['full_name'] ?? '');
    $id_number = trim($b['id_number'] ?? '');
    $type      = trim($b['type']      ?? '');
    $email     = trim($b['email']     ?? '');
    $phone     = trim($b['phone']     ?? '');

    if (!$full_name || !$id_number || !$type) {
        fail('Full name, ID number, and type are required.');
    }
    if (!in_array($type, ['Student', 'Faculty', 'Staff', 'Guest'])) {
    fail("Invalid type. Must be Student, Faculty, Guest or Staff.");
}

    // Duplicate ID check
    $check = $db->prepare('SELECT id FROM borrowers WHERE id_number = ?');
    $check->execute([$id_number]);
    if ($check->fetch()) fail("ID number '$id_number' already exists.");

    $stmt = $db->prepare('
        INSERT INTO borrowers (full_name, id_number, type, email, phone)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$full_name, $id_number, $type, $email, $phone]);

    $id   = (int)$db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM borrowers WHERE id = ?');
    $stmt->execute([$id]);

    ok($stmt->fetch(), 201);
}

// ── PUT (Update) ──────────────────────────────────────────────
if ($method === 'PUT') {
    $b = body();

    $id        = (int)($b['id']        ?? 0);
    $full_name = trim($b['full_name'] ?? '');
    $id_number = trim($b['id_number'] ?? '');
    $type      = trim($b['type']      ?? '');
    $email     = trim($b['email']     ?? '');
    $phone     = trim($b['phone']     ?? '');


    if (!$id || !$full_name || !$id_number || !$type) {
        fail('ID, full name, ID number, and type are required.');
    }
    if (!in_array($type, ['Student', 'Faculty', 'Staff'])) {
        fail("Invalid type.");
    }

    // Check exists
    $existing = $db->prepare('SELECT id FROM borrowers WHERE id = ?');
    $existing->execute([$id]);
    if (!$existing->fetch()) fail('Borrower not found.', 404);

    // Duplicate ID number (excluding self)
    $check = $db->prepare('SELECT id FROM borrowers WHERE id_number = ? AND id != ?');
    $check->execute([$id_number, $id]);
    if ($check->fetch()) fail("ID number '$id_number' is already used by another borrower.");

    $stmt = $db->prepare('
        UPDATE borrowers
        SET full_name=?, id_number=?, type=?, email=?, phone=?
        WHERE id=?
    ');
    $stmt->execute([$full_name, $id_number, $type, $email, $phone, $id]);

    $stmt = $db->prepare('SELECT * FROM borrowers WHERE id = ?');
    $stmt->execute([$id]);
    ok($stmt->fetch());
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('Missing borrower id.');

    // Block delete if has active borrows
    $check = $db->prepare("SELECT active_borrows FROM borrowers WHERE id = ?");
    $check->execute([$id]);
    $b = $check->fetch();
    if (!$b) fail('Borrower not found.', 404);
    if ((int)$b['active_borrows'] > 0) {
        fail("Cannot delete: this borrower has {$b['active_borrows']} active borrow(s). They must return the tools first.");
    }

    $stmt = $db->prepare('DELETE FROM borrowers WHERE id = ?');
    $stmt->execute([$id]);
    ok(['deleted_id' => $id]);
}

fail('Method not allowed.', 405);
