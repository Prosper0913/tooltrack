<?php

require_once __DIR__ . '/config.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Managing tools is the Staff workflow, but Admins can do it too —
// this endpoint just requires *someone* to be logged in.
requireLogin();

// ── Compute status from columns ───────────────────────────────
// IMPORTANT: order matters here — available===0 must be checked
// BEFORE the low-stock check, since 0 is always <= min_stock, which
// meant the old ordering could never actually reach "out of stock"
// (it always got caught by the low-stock branch first).
function toolStatus(array $t): string {
    if ((int)$t['available'] === 0) return 'out-of-stock';
    if ($t['available'] <= $t['min_stock']) return 'low-stock';
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
    if (!empty($_GET['date_from'])) {
        $where[]  = 'DATE(created_at) >= ?';
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where[]  = 'DATE(created_at) <= ?';
        $params[] = $_GET['date_to'];
    }

    $sql    = 'SELECT * FROM tools WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
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

// Short, memorable, guaranteed-unique code generated from the tool's
// name — e.g. "Spoon" -> "SP-101", "Frying Pan" -> "FP-101". Loops on
// the sequence number only (never the letters) until it finds one
// that doesn't collide, so it's always unique even if "SP-101" etc.
// already exists from an earlier tool with a similar name.
function generateToolCode(PDO $db, string $name): string {
    $words   = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($words as $w) {
        $clean = preg_replace('/[^A-Za-z]/', '', $w);
        if ($clean !== '') $letters .= strtoupper($clean[0]);
        if (strlen($letters) >= 2) break;
    }
    if (strlen($letters) < 2) {
        $clean   = preg_replace('/[^A-Za-z]/', '', $words[0] ?? '');
        $letters = strtoupper(substr($clean !== '' ? $clean : 'TL', 0, 2));
        if (strlen($letters) < 2) $letters = str_pad($letters, 2, 'X');
    }

    $n = 101;
    do {
        $code  = "$letters-$n";
        $check = $db->prepare('SELECT id FROM tools WHERE code = ?');
        $check->execute([$code]);
        $taken = (bool)$check->fetch();
        $n++;
    } while ($taken);

    return $code;
}

// ── POST (Create) ─────────────────────────────────────────────
if ($method === 'POST') {
    $b = body();

    $name        = trim($b['name']        ?? '');
    $category    = trim($b['category']    ?? '');
    $quantity    = (int)($b['quantity']   ?? 0);
    $min_stock   = (int)($b['min_stock']  ?? 1);
    $description = trim($b['description'] ?? '');

    if (!$name || !$category || $quantity < 1 || $min_stock < 1) {
        fail('All required fields must be filled.');
    }

    // The code is always system-generated, never client-supplied —
    // short and derived from the name so it's easy to remember, and
    // guaranteed collision-free since generateToolCode() checks the
    // DB itself.
    $code = generateToolCode($db, $name);

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

    logAudit('tool_create', "Added tool $name ($code), qty $quantity");

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

    logAudit('tool_edit', "Edited tool $name ($code)");

    ok($tool);
}

// ── PATCH (Retire / Reactivate) ────────────────────────────────
// Admin-only. Retiring hides a tool from the Borrow flow without
// deleting it or its transaction history (a hard DELETE used to
// cascade-delete every transaction row for that tool — this
// replaces that with a safe, reversible toggle instead).
if ($method === 'PATCH') {
    requireRole(ROLE_ADMIN);

    $b  = body();
    $id = (int)($b['id'] ?? 0);
    if (!$id || !array_key_exists('is_active', $b)) {
        fail('id and is_active are required.');
    }
    $isActive = $b['is_active'] ? 1 : 0;

    $existing = $db->prepare('SELECT id, name, code FROM tools WHERE id = ?');
    $existing->execute([$id]);
    $existingRow = $existing->fetch();
    if (!$existingRow) fail('Tool not found.', 404);

    $stmt = $db->prepare('UPDATE tools SET is_active = ? WHERE id = ?');
    $stmt->execute([$isActive, $id]);

    $stmt = $db->prepare('SELECT * FROM tools WHERE id = ?');
    $stmt->execute([$id]);
    $tool = $stmt->fetch();
    $tool['status'] = toolStatus($tool);
    logAudit($isActive ? 'tool_reactivate' : 'tool_retire', ($isActive ? 'Reactivated' : 'Retired') . " tool {$existingRow['name']} ({$existingRow['code']})");
    ok($tool);
}

fail('Method not allowed.', 405);
