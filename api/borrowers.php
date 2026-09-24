<?php
// ================================================================
//  api/borrowers.php  —  Full CRUD for Borrowers
//
//  GET    ?page=1&per_page=10&type=&search=
//         ?id=N  → single borrower
//  POST   { full_name, id_number, type, email, phone }  — any logged-in user
//  PUT    { id, full_name, id_number, type, email, phone }  — Admin only
//  PATCH  { id, is_active }  → activate/deactivate (no hard delete) — Admin only
// ================================================================
require_once __DIR__ . '/config.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Any logged-in user (Admin or Staff) can look borrowers up — Staff
// need this to select a borrower on the Borrow page, and creating a
// brand-new borrower record is also part of that same Staff workflow
// (registering a walk-in guest mid-transaction) — so POST is open to
// any logged-in user. Editing an existing person's details or
// activating/deactivating them is roster *management*, which stays
// Admin-only.
requireLogin();
if (in_array($method, ['PUT', 'PATCH'], true)) {
    requireRole(ROLE_ADMIN);
}

// School's official student ID format, e.g. 2024-00123. Only enforced
// for type=Student — Faculty/Staff use the school's HR numbering (not
// specified here) and Guest is by definition someone outside that
// system, so neither is forced through the student ID shape.
function validateIdNumber(string $type, string $idNumber): void {
    if ($type === 'Student' && !preg_match('/^\d{4}-\d{5}$/', $idNumber)) {
        fail("Student ID must match the school's format: YYYY-XXXXX (e.g. 2024-00123). Got '$idNumber'.");
    }
}

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {

    // Single borrower — with full transaction history + on-time/late stats
    if (!empty($_GET['id']) && !empty($_GET['history'])) {
        $id = (int)$_GET['id'];

        $stmt = $db->prepare("SELECT b.*, e.course, e.section_name, e.cms_section_id
            FROM borrowers b
            LEFT JOIN borrower_enrollments e
              ON e.borrower_id = b.id AND e.is_active = 1
            WHERE b.id = ?
            ORDER BY e.id ASC
            LIMIT 1");
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) fail('Borrower not found.', 404);

        // Every transaction (borrow AND return events) for this borrower,
        // newest first. A 'borrow' row already carries its own due_date /
        // status / returned_at (updated in place when returned), so
        // on-time-vs-late is computed straight off that single row —
        // no need to pair it up with a separate return row for that part.
        $hist = $db->prepare("
            SELECT t.id, t.txn_id, t.type, t.status, t.`condition`, t.notes,
                   t.due_date, t.returned_at, t.created_at, t.qty, t.qty_returned,
                   tl.name AS tool_name, tl.code AS tool_code
            FROM transactions t
            LEFT JOIN tools tl ON tl.id = t.tool_id
            WHERE t.borrower_id = ?
            ORDER BY t.created_at DESC
        ");
        $hist->execute([$id]);
        $rows = $hist->fetchAll();

        // Stats, computed from the borrow rows only (they're the ones
        // with a due_date to judge against).
        $stats = ['on_time' => 0, 'late' => 0, 'overdue_now' => 0, 'damaged_or_minor' => 0];
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['flag'] = null;
            if ($r['type'] === 'borrow') {
                if ($r['status'] === 'returned' && $r['returned_at']) {
                    if (substr($r['returned_at'], 0, 10) > $r['due_date']) {
                        $r['flag'] = 'late';
                        $stats['late']++;
                    } else {
                        $r['flag'] = 'on_time';
                        $stats['on_time']++;
                    }
                } elseif ($r['status'] === 'active' && $r['due_date'] < $today) {
                    $r['flag'] = 'overdue';
                    $stats['overdue_now']++;
                }
            } elseif ($r['type'] === 'return' && in_array($r['condition'], ['minor', 'damaged', 'missing'], true)) {
                $stats['damaged_or_minor']++;
            }
        }
        unset($r);

        ok(['borrower' => $b, 'history' => $rows, 'stats' => $stats]);
    }

    // Single borrower — plain (used by the edit form, no history needed)
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

    $sql = 'SELECT b.*, e.course, e.section_name, e.cms_section_id,
              (SELECT COUNT(*) FROM transactions t
                 WHERE t.borrower_id = b.id AND t.type = "borrow"
                   AND t.status = "active" AND t.due_date < CURDATE()) AS overdue_count,
              (SELECT COUNT(*) FROM transactions t2
                 WHERE t2.borrower_id = b.id AND t2.type = "return"
                   AND t2.`condition` IN ("damaged","missing")) AS flagged_count
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
    validateIdNumber($type, $id_number);

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

    logAudit('borrower_create', "Added borrower $full_name ($id_number, $type)");

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
    if (!in_array($type, ['Student', 'Faculty', 'Staff', 'Guest'])) {
        fail("Invalid type. Must be Student, Faculty, Guest or Staff.");
    }
    validateIdNumber($type, $id_number);

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
    logAudit('borrower_edit', "Edited borrower $full_name ($id_number)");
    ok($stmt->fetch());
}

// ── PATCH (Activate / Deactivate) ──────────────────────────────
// Admin-only, manual override of is_active — separate from the
// CMS sync flow (receive_student_deletion.php) which does the same
// thing automatically when a student is deleted in the CMS.
if ($method === 'PATCH') {
    $b  = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id || !array_key_exists('is_active', $b)) {
        fail('id and is_active are required.');
    }
    $isActive = $b['is_active'] ? 1 : 0;

    $existing = $db->prepare('SELECT id, full_name FROM borrowers WHERE id = ?');
    $existing->execute([$id]);
    $existingRow = $existing->fetch();
    if (!$existingRow) fail('Borrower not found.', 404);

    $stmt = $db->prepare('UPDATE borrowers SET is_active = ? WHERE id = ?');
    $stmt->execute([$isActive, $id]);

    $stmt = $db->prepare('SELECT * FROM borrowers WHERE id = ?');
    $stmt->execute([$id]);
    logAudit($isActive ? 'borrower_reactivate' : 'borrower_deactivate', ($isActive ? 'Reactivated' : 'Deactivated') . " borrower {$existingRow['full_name']}");
    ok($stmt->fetch());
}

// Deliberately no DELETE handler — borrower records are never hard-
// deleted, to preserve transaction history (deleting used to NULL
// out borrower_id on every past transaction). Use PATCH above to
// deactivate a borrower instead.

fail('Method not allowed.', 405);
