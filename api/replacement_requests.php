<?php
// ================================================================
//  api/replacement_requests.php
//
//  For damaged/lost/worn-out tools that need replacing — separate
//  from "+ Add New Tool", which is for genuinely new inventory.
//
//  GET    → list all requests (any logged-in user; newest first)
//  POST   { tool_id?, tool_name?, reason, quantity_needed, notes }
//         — any logged-in user (this is Staff's day-to-day workflow)
//  PATCH  { id, status, resolution_notes? }  — Admin only
// ================================================================
require_once __DIR__ . '/config.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$me     = requireLogin();

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $db->query("
        SELECT r.*,
               tl.code AS tool_code, tl.is_active AS tool_still_active,
               u1.name AS requested_by_name,
               u2.name AS resolved_by_name
        FROM replacement_requests r
        LEFT JOIN tools tl ON tl.id = r.tool_id
        LEFT JOIN users u1 ON u1.id = r.requested_by
        LEFT JOIN users u2 ON u2.id = r.resolved_by
        ORDER BY
            FIELD(r.status, 'pending', 'approved', 'rejected', 'fulfilled'),
            r.created_at DESC
    ");
    ok($stmt->fetchAll());
}

// ── POST (Create) ────────────────────────────────────────────
if ($method === 'POST') {
    $b = body();

    $tool_id     = !empty($b['tool_id']) ? (int)$b['tool_id'] : null;
    $tool_name   = trim($b['tool_name'] ?? '');
    $reason      = trim($b['reason'] ?? '');
    $qty         = max(1, (int)($b['quantity_needed'] ?? 1));
    $notes       = trim($b['notes'] ?? '');

    if (!in_array($reason, ['damaged', 'lost', 'worn_out', 'other'], true)) {
        fail('Reason must be damaged, lost, worn_out, or other.');
    }

    // Snapshot the tool's name at request time — kept even if the
    // tool row is later edited/removed, so old requests still make
    // sense to read.
    if ($tool_id) {
        $ts = $db->prepare('SELECT name FROM tools WHERE id = ?');
        $ts->execute([$tool_id]);
        $t = $ts->fetch();
        if (!$t) fail('Tool not found.', 404);
        $tool_name = $t['name'];
    }
    if (!$tool_name) fail('Select a tool, or type its name if it\'s no longer in the system.');

    $stmt = $db->prepare('
        INSERT INTO replacement_requests
            (tool_id, tool_name_snapshot, reason, quantity_needed, notes, requested_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$tool_id, $tool_name, $reason, $qty, $notes, $me['id']]);

    $id = (int)$db->lastInsertId();
    ok(['id' => $id], 201);
}

// ── PATCH (Admin resolves a request) ─────────────────────────
if ($method === 'PATCH') {
    requireRole(ROLE_ADMIN);
    $b = body();

    $id     = (int)($b['id'] ?? 0);
    $status = trim($b['status'] ?? '');
    $resNotes = trim($b['resolution_notes'] ?? '');

    if (!$id || !in_array($status, ['approved', 'rejected', 'fulfilled'], true)) {
        fail('id and a valid status (approved, rejected, or fulfilled) are required.');
    }

    $stmt = $db->prepare('SELECT * FROM replacement_requests WHERE id = ?');
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    if (!$req) fail('Request not found.', 404);

    $db->prepare('
        UPDATE replacement_requests
        SET status = ?, resolution_notes = ?, resolved_by = ?, resolved_at = NOW()
        WHERE id = ?
    ')->execute([$status, $resNotes, $me['id'], $id]);

    // Fulfilling a request for an existing tool means the replacement
    // has arrived — add the requested quantity back into stock.
    if ($status === 'fulfilled' && $req['tool_id']) {
        $db->prepare('UPDATE tools SET quantity = quantity + ?, available = available + ? WHERE id = ?')
           ->execute([$req['quantity_needed'], $req['quantity_needed'], $req['tool_id']]);
    }

    ok(['message' => 'Request updated.']);
}

fail('Method not allowed.', 405);
