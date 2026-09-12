<?php

require_once __DIR__ . '/config.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── Compute status from columns ───────────────────────────────
function toolStatus(array $t): string {
    if ($t['available'] <= $t['min_stock']) return 'low-stock';
    if (($t['quantity'] - $t['available']) > 0 && $t['available'] === 0) return 'borrowed';
    return 'available';
}

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {

    // Single tool by id
    if (!empty($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM tools WHERE id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $tool = $stmt->fetch();
        if (!$tool) fail('Tool not found.', 404);
        $tool['status'] = toolStatus($tool);
        ok($tool);
    }

    // List with filters
    $page     = max(1, (int)($_GET['page']     ?? 1));
    $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 10)));
    $offset   = ($page - 1) * $perPage;
    $status   = $_GET['status']   ?? '';
    $category = $_GET['category'] ?? '';
    $search   = '%' . trim($_GET['search'] ?? '') . '%';

    $where  = ['1=1'];
    $params = [];

    if ($category) {
        $where[]  = 'category = ?';
        $params[] = $category;
    }
    if (trim($_GET['search'] ?? '') !== '') {
        $where[]  = '(name LIKE ? OR code LIKE ? OR description LIKE ?)';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $sql    = 'SELECT * FROM tools WHERE ' . implode(' AND ', $where);
    $stmt   = $db->prepare($sql);
    $stmt->execute($params);
    $tools  = $stmt->fetchAll();

    // Apply status filter in PHP (computed field)
    if ($status) {
        $tools = array_values(array_filter($tools, fn($t) => toolStatus($t) === $status));
    }

    $total      = count($tools);
    $paged      = array_slice($tools, $offset, $perPage);

    // Attach computed status
    $paged = array_map(function($t) {
        $t['status'] = toolStatus($t);
        return $t;
    }, $paged);

    ok($paged);
    // Note: frontend also needs total. Extend ok() or return differently:
    http_response_code(200);
    echo json_encode([
        'success'  => true,
        'data'     => $paged,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
    exit;
}

// ── POST (Create) ─────────────────────────────────────────────
if ($method === 'POST') {
    $b = body();

    $name        = trim($b['name']        ?? '');
    $code        = trim($b['code']        ?? '');
    $category    = trim($b['category']    ?? '');
    $quantity    = (int)($b['quantity']   ?? 0);
    $min_stock   = (int)($b['min_stock']  ?? 1);
    $description = trim($b['description'] ?? '');

    if (!$name || !$code || !$category || $quantity < 1 || $min_stock < 1) {
        fail('All required fields must be filled.');
    }

    // Check duplicate code
    $check = $db->prepare('SELECT id FROM tools WHERE code = ?');
    $check->execute([$code]);
    if ($check->fetch()) fail("Tool code '$code' already exists.");

    $stmt = $db->prepare('
        INSERT INTO tools (name, code, category, quantity, available, min_stock, description)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$name, $code, $category, $quantity, $quantity, $min_stock, $description]);

    $id   = (int)$db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM tools WHERE id = ?');
    $stmt->execute([$id]);
    $tool = $stmt->fetch();
    $tool['status'] = toolStatus($tool);

    ok($tool, 201);
}

// ── PUT (Update) ──────────────────────────────────────────────
if ($method === 'PUT') {
    $b = body();

    $id          = (int)($b['id']          ?? 0);
    $name        = trim($b['name']        ?? '');
    $code        = trim($b['code']        ?? '');
    $category    = trim($b['category']    ?? '');
    $quantity    = (int)($b['quantity']   ?? 0);
    $min_stock   = (int)($b['min_stock']  ?? 1);
    $description = trim($b['description'] ?? '');

    if (!$id || !$name || !$code || !$category || $quantity < 1 || $min_stock < 1) {
        fail('All required fields must be filled.');
    }

    // Fetch existing to recalculate available
    $existing = $db->prepare('SELECT * FROM tools WHERE id = ?');
    $existing->execute([$id]);
    $old = $existing->fetch();
    if (!$old) fail('Tool not found.', 404);

    // Check duplicate code (excluding self)
    $check = $db->prepare('SELECT id FROM tools WHERE code = ? AND id != ?');
    $check->execute([$code, $id]);
    if ($check->fetch()) fail("Tool code '$code' is already used by another tool.");

    // Keep borrowed count the same, adjust available
    $borrowed  = $old['quantity'] - $old['available'];
    $available = max(0, $quantity - $borrowed);

    $stmt = $db->prepare('
        UPDATE tools
        SET name=?, code=?, category=?, quantity=?, available=?, min_stock=?, description=?
        WHERE id=?
    ');
    $stmt->execute([$name, $code, $category, $quantity, $available, $min_stock, $description, $id]);

    $stmt = $db->prepare('SELECT * FROM tools WHERE id = ?');
    $stmt->execute([$id]);
    $tool = $stmt->fetch();
    $tool['status'] = toolStatus($tool);

    ok($tool);
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('Missing tool id.');

    // Prevent delete if tool has active borrows
    $check = $db->prepare("SELECT COUNT(*) FROM transactions WHERE tool_id = ? AND status = 'active'");
    $check->execute([$id]);
    if ((int)$check->fetchColumn() > 0) {
        fail('Cannot delete: this tool has active borrows. Return it first.');
    }

    $stmt = $db->prepare('DELETE FROM tools WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) fail('Tool not found.', 404);
    ok(['deleted_id' => $id]);
}

fail('Method not allowed.', 405);
