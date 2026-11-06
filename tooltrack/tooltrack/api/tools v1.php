<?php
// ================================================================
//  api/tools.php  — scalable version
//  • status filter + pagination now done IN SQL (the old version loaded
//    EVERY tool into PHP memory on every page view)
//  • date filters are index-friendly (no DATE(col) wrapper)
//  • LIKE wildcards in the search box are escaped
//  • tool-code generation no longer loops one query per attempt
//  • quantity can't be edited below what is currently on loan / damaged / missing
//
//  Inventory model (see sql/001_inventory_conditions.sql):
//    quantity     total recorded units (incl. damaged + missing + on loan)
//    available    borrowable units on the shelf (good + minor)
//    minor_qty    subset of available with minor wear
//    damaged_qty  on hand, not borrowable until repaired + cleared
//    missing_qty  unaccounted for, still counted in quantity
//    good_qty     = available - minor_qty                          (derived)
//    borrowed_qty = quantity - available - damaged - missing       (derived)
//
//  PATCH { id, action:'repair'|'recover', qty, to, notes } (Admin) moves units
//  out of damaged / missing; PATCH { id, is_active } retires / reactivates.
//  Missing units now come from CONFIRMED missing-tool reports (api/missing_tools.php). Their
//  replacement / recovery goes through that workflow so the case record and audit trail
//  stay in sync; the manual 'recover' below is kept only for legacy missing units.
//  Outstanding loan units = qty - qty_returned - qty_missing.
// ================================================================
require_once __DIR__ . '/config.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
requireLogin();

// Same rules as before, expressed in SQL so MySQL can filter + page.
// available=0 is checked FIRST (0 is always <= min_stock).
const STATUS_SQL = "CASE WHEN available = 0 THEN 'out-of-stock'
                         WHEN available <= min_stock THEN 'low-stock'
                         ELSE 'available' END";

function toolStatus(array $t): string {
    if ((int)$t['available'] === 0) return 'out-of-stock';
    if ((int)$t['available'] <= (int)$t['min_stock']) return 'low-stock';
    return 'available';
}

// Who is acting, for the adjustment log. Reads the same session key index.php uses.
function currentUserId(): ?int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

// Adds the derived counts so every response (list, single, create, update)
// exposes the same numbers.
function addDerived(array $t): array {
    $t['status']       = toolStatus($t);
    $t['good_qty']     = (int)$t['available'] - (int)$t['minor_qty'];
    $t['borrowed_qty'] = (int)$t['quantity'] - (int)$t['available'] - (int)$t['damaged_qty'] - (int)$t['missing_qty'];
    return $t;
}

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {

    if (!empty($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM tools WHERE id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $tool = $stmt->fetch();
        if (!$tool) fail('Tool not found.', 404);
        ok(addDerived($tool));
    }

    $page     = max(1, (int)($_GET['page']     ?? 1));
    // 200 is what the Borrow / Return / Replacement pickers ask for.
    $perPage  = min(200, max(1, (int)($_GET['per_page'] ?? 10)));
    $offset   = ($page - 1) * $perPage;
    $status   = $_GET['status']   ?? '';
    $category = $_GET['category'] ?? '';
    $rawSearch = trim($_GET['search'] ?? '');
    $activeOnly = !empty($_GET['active_only']);   // pickers: hide retired tools

    $where  = ['1=1'];
    $params = [];

    if ($category !== '') { $where[] = 'category = ?'; $params[] = $category; }
    if ($activeOnly)      { $where[] = 'is_active = 1'; }
    if ($rawSearch !== '') {
        $like = '%' . addcslashes($rawSearch, '%_\\') . '%';
        $where[] = '(name LIKE ? OR code LIKE ? OR description LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    // Index-friendly date range (DATE(created_at) >= ? can't use an index).
    if (!empty($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])) {
        $where[] = 'created_at >= ?'; $params[] = $_GET['date_from'] . ' 00:00:00';
    }
    if (!empty($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])) {
        $where[] = 'created_at < ? + INTERVAL 1 DAY'; $params[] = $_GET['date_to'];
    }
    if (in_array($status, ['available', 'low-stock', 'out-of-stock'], true)) {
        $where[] = '(' . STATUS_SQL . ') = ?';
        $params[] = $status;
    }
    $whereSql = implode(' AND ', $where);

    $cs = $db->prepare("SELECT COUNT(*) FROM tools WHERE $whereSql");
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();

    // times_borrowed / units_borrowed_total = HISTORICAL borrow transactions of this tool;
    // current_loans = units out right now (from the loans themselves, so it can be
    // cross-checked against the stored counts by the Integrity report).
    // Subqueries run only for the page's rows, and use idx_txn_tool_type_status.
    // active_borrowers = UNIQUE people who still hold units of the tool right now.
    // Only open borrow rows count (status active with units not yet returned), so returned /
    // cancelled rows drop out and a person with several open transactions counts once. The
    // ID number (or the snapshot saved on the transaction) is the identity, so duplicate records count once and
    // a loan whose borrower_id is NULL is still counted.
    // (Kept out of the SQL string on purpose: no quotes/comments inside a PDO query.)
    $stmt = $db->prepare("
        SELECT id, name, code, category, quantity, original_quantity, available, minor_qty,
               damaged_qty, missing_qty, min_stock, price, description, is_active, created_at,
               " . STATUS_SQL . " AS status,
               (available - minor_qty) AS good_qty,
               (quantity - available - damaged_qty - missing_qty) AS borrowed_qty,
               (SELECT COUNT(*) FROM transactions x
                 WHERE x.tool_id = tools.id AND x.type = 'borrow') AS times_borrowed,
               (SELECT COALESCE(SUM(x.qty), 0) FROM transactions x
                 WHERE x.tool_id = tools.id AND x.type = 'borrow') AS units_borrowed_total,
               (SELECT COALESCE(SUM(x.qty - x.qty_returned - x.qty_missing), 0) FROM transactions x
                 WHERE x.tool_id = tools.id AND x.type = 'borrow' AND x.status = 'active') AS current_loans,
               (SELECT COUNT(DISTINCT COALESCE(NULLIF(TRIM(bw.id_number), ''),
                                               NULLIF(TRIM(x.borrower_id_number_snapshot), ''),
                                               CONCAT('b', x.borrower_id),
                                               CONCAT('t', x.id)))
                  FROM transactions x
                  LEFT JOIN borrowers bw ON bw.id = x.borrower_id
                 WHERE x.tool_id = tools.id AND x.type = 'borrow'
                   AND x.status = 'active' AND (x.qty - x.qty_returned - x.qty_missing) > 0) AS active_borrowers
        FROM tools
        WHERE $whereSql
        ORDER BY created_at DESC, id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$perPage, $offset]));

    // SUM() comes back from PDO as a string; send real numbers to the browser.
    $rows = array_map(function ($r) {
        foreach (['units_borrowed_total', 'current_loans', 'good_qty', 'borrowed_qty', 'times_borrowed', 'active_borrowers'] as $k) $r[$k] = (int)$r[$k];
        return $r;
    }, $stmt->fetchAll());

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

// Short unique code from the tool's name: "Spoon" -> "SP-101".
// One query to find the highest number already used for that prefix,
// instead of one query per collision. The UNIQUE index on tools.code
// (see indexes.sql) is the real guarantee; the caller retries on a clash.
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
    $q = $db->prepare("SELECT MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)) FROM tools WHERE code LIKE ?");
    $q->execute([strlen($letters) + 2, $letters . '-%']);
    $max = (int)$q->fetchColumn();
    return $letters . '-' . (max(100, $max) + 1);
}

function validateToolFields(array $b): array {
    $name  = trim($b['name'] ?? '');
    $cat   = trim($b['category'] ?? '');
    $qty   = (int)($b['quantity'] ?? 0);
    $min   = (int)($b['min_stock'] ?? 1);
    $price = round((float)($b['price'] ?? 0), 2);
    $desc  = trim($b['description'] ?? '');
    if (!$name || !$cat || $qty < 1 || $min < 1) fail('All required fields must be filled.');
    if ($qty > 100000)           fail('Quantity is too large.');
    if ($price < 0 || $price > 10000000) fail('Price is out of range.');
    if (mb_strlen($name) > 150)  fail('Name is too long (max 150).');
    if (mb_strlen($desc) > 1000) fail('Description is too long (max 1000).');
    return [$name, $cat, $qty, $min, $price, $desc];
}

// ── POST (Create) ─────────────────────────────────────────────
if ($method === 'POST') {
    [$name, $category, $quantity, $min_stock, $price, $description] = validateToolFields(body());

    $id = 0; $code = '';
    for ($try = 0; $try < 5 && !$id; $try++) {
        $code = generateToolCode($db, $name);
        try {
            $stmt = $db->prepare('
                INSERT INTO tools (name, code, category, quantity, original_quantity, available, min_stock, price, description)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$name, $code, $category, $quantity, $quantity, $quantity, $min_stock, $price, $description]);
            $id = (int)$db->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;   // only retry on duplicate-code clash
        }
    }
    if (!$id) fail('Could not generate a unique tool code. Please try again.', 500);

    $stmt = $db->prepare('SELECT * FROM tools WHERE id = ?');
    $stmt->execute([$id]);
    $tool = addDerived($stmt->fetch());

    logAudit('tool_create', "Added tool $name ($code), qty $quantity, price $price");
    ok($tool, 201);
}

// ── PUT (Update) ──────────────────────────────────────────────
if ($method === 'PUT') {
    $b    = body();
    $id   = (int)($b['id'] ?? 0);
    $code = trim($b['code'] ?? '');
    [$name, $category, $quantity, $min_stock, $price, $description] = validateToolFields($b);
    if (!$id || !$code) fail('All required fields must be filled.');

    $db->beginTransaction();
    try {
        $existing = $db->prepare('SELECT * FROM tools WHERE id = ? FOR UPDATE');
        $existing->execute([$id]);
        $old = $existing->fetch();
        if (!$old) { $db->rollBack(); fail('Tool not found.', 404); }

        $check = $db->prepare('SELECT id FROM tools WHERE code = ? AND id != ?');
        $check->execute([$code, $id]);
        if ($check->fetch()) { $db->rollBack(); fail("Tool code '$code' is already used by another tool."); }

        // Total quantity can't drop below the units that physically/logically still
        // exist in another state: on loan, damaged or missing. (The old code silently
        // clamped `available` to 0, which corrupted the borrowed count and later returns.)
        $borrowed = (int)$old['quantity'] - (int)$old['available'] - (int)$old['damaged_qty'] - (int)$old['missing_qty'];
        $floor    = $borrowed + (int)$old['damaged_qty'] + (int)$old['missing_qty'];
        if ($quantity < $floor) {
            $db->rollBack();
            fail("Quantity can't be lower than $floor: {$borrowed} borrowed + {$old['damaged_qty']} damaged + {$old['missing_qty']} missing.");
        }
        $available = $quantity - $floor;
        // Keep minor_qty a subset of available (only matters if stock is reduced).
        $minor     = min((int)$old['minor_qty'], $available);

        // created_at and original_quantity are deliberately NOT in this UPDATE:
        // the date added and the starting quantity are never rewritten by an edit.
        $stmt = $db->prepare('
            UPDATE tools
            SET name=?, code=?, category=?, quantity=?, available=?, minor_qty=?, min_stock=?, price=?, description=?
            WHERE id=?
        ');
        $stmt->execute([$name, $code, $category, $quantity, $available, $minor, $min_stock, $price, $description, $id]);

        if ($quantity !== (int)$old['quantity']) {
            $db->prepare("INSERT INTO inventory_adjustments (tool_id, action, from_state, to_state, qty, notes, user_id)
                          VALUES (?, 'qty_edit', ?, ?, ?, ?, ?)")
               ->execute([$id, (string)$old['quantity'], (string)$quantity, abs($quantity - (int)$old['quantity']),
                          'Total quantity edited', currentUserId()]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Tool update failed: ' . $e->getMessage());
        fail('Update failed. Please try again.', 500);
    }

    $stmt = $db->prepare('SELECT * FROM tools WHERE id = ?');
    $stmt->execute([$id]);
    $tool = addDerived($stmt->fetch());

    logAudit('tool_edit', "Edited tool $name ($code)");
    ok($tool);
}

// ── PATCH (Retire / Reactivate) — Admin only ──────────────────
if ($method === 'PATCH') {
    requireRole(ROLE_ADMIN);

    $b  = body();
    $id = (int)($b['id'] ?? 0);

    // ── Move units OUT of damaged / missing ─────────────────────────────
    //   repair : damaged -> good | minor      (repaired and cleared for lending)
    //   recover: missing -> good | minor | damaged   (found again)
    // Each unit is in exactly one state: the source count goes down, the target goes up.
    if (isset($b['action'])) {
        $action = (string)$b['action'];
        $qty    = (int)($b['qty'] ?? 0);
        $to     = (string)($b['to'] ?? 'good');
        $notes  = mb_substr(trim((string)($b['notes'] ?? '')), 0, 500);
        if (!$id) fail('id is required.');
        if (!in_array($action, ['repair', 'recover'], true)) fail("action must be 'repair' or 'recover'.");
        if ($qty < 1 || $qty > 100000) fail('Quantity must be at least 1.');
        $allowedTo = $action === 'repair' ? ['good', 'minor'] : ['good', 'minor', 'damaged'];
        if (!in_array($to, $allowedTo, true)) fail("'to' must be one of: " . implode(', ', $allowedTo) . '.');

        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM tools WHERE id = ? FOR UPDATE');
            $q->execute([$id]);
            $t = $q->fetch();
            if (!$t) { $db->rollBack(); fail('Tool not found.', 404); }

            $from  = $action === 'repair' ? 'damaged' : 'missing';
            $have  = (int)$t[$from . '_qty'];
            if ($action === 'recover') {
                // Missing units that belong to a missing-tool CASE must be recovered through that case
                // (api/missing_tools.php) so its counters, audit trail and replacement status stay in step.
                // Only legacy / unlinked missing units (recorded before the workflow existed) can be moved here.
                $lk = $db->prepare("SELECT COALESCE(SUM(GREATEST(qty_missing - qty_replaced - (qty_recovered - qty_surplus), 0)), 0)
                                    FROM missing_reports WHERE tool_id = ? AND status IN ('confirmed','resolved')");
                $lk->execute([$id]);
                $linked = (int)$lk->fetchColumn();
                $have   = max(0, $have - $linked);
                if ($qty > $have) {
                    $db->rollBack();
                    fail($linked > 0
                        ? "Only $have missing unit(s) of '{$t['code']}' are unlinked. The other $linked belong to open missing-tool cases - record the recovery from the case on the Return page."
                        : "Only $have $from unit(s) of '{$t['code']}' - can't move $qty.", 409);
                }
            }
            if ($qty > $have) {
                $db->rollBack();
                fail("Only $have $from unit(s) of '{$t['code']}' - can't move $qty.", 409);
            }
            $dmg = (int)$t['damaged_qty']; $mis = (int)$t['missing_qty'];
            $avl = (int)$t['available'];   $min = (int)$t['minor_qty'];
            if ($from === 'damaged') $dmg -= $qty; else $mis -= $qty;
            if ($to === 'damaged')   $dmg += $qty;
            else { $avl += $qty; if ($to === 'minor') $min += $qty; }

            if ($avl + $dmg + $mis > (int)$t['quantity'] || $min > $avl) {   // can't happen unless data was already off
                $db->rollBack();
                fail('Inventory counts for this tool are inconsistent - check the Integrity report.', 409);
            }
            $db->prepare('UPDATE tools SET available=?, minor_qty=?, damaged_qty=?, missing_qty=? WHERE id=?')
               ->execute([$avl, $min, $dmg, $mis, $id]);
            $db->prepare("INSERT INTO inventory_adjustments (tool_id, action, from_state, to_state, qty, notes, user_id)
                          VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([$id, $action, $from, $to, $qty, $notes, currentUserId()]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Tool condition update failed: ' . $e->getMessage());
            fail('Update failed. Please try again.', 500);
        }

        $st = $db->prepare('SELECT * FROM tools WHERE id = ?');
        $st->execute([$id]);
        $tool = addDerived($st->fetch());
        logAudit('tool_condition_update', ucfirst($action) . "ed {$qty}x {$t['name']} ({$t['code']}): $from -> $to" . ($notes !== '' ? " - $notes" : ''));
        ok($tool);
    }

    if (!$id || !array_key_exists('is_active', $b)) fail('id and is_active are required.');
    $isActive = $b['is_active'] ? 1 : 0;

    $existing = $db->prepare('SELECT id, name, code FROM tools WHERE id = ?');
    $existing->execute([$id]);
    $existingRow = $existing->fetch();
    if (!$existingRow) fail('Tool not found.', 404);

    $db->prepare('UPDATE tools SET is_active = ? WHERE id = ?')->execute([$isActive, $id]);

    $stmt = $db->prepare('SELECT * FROM tools WHERE id = ?');
    $stmt->execute([$id]);
    $tool = addDerived($stmt->fetch());
    logAudit($isActive ? 'tool_reactivate' : 'tool_retire', ($isActive ? 'Reactivated' : 'Retired') . " tool {$existingRow['name']} ({$existingRow['code']})");
    ok($tool);
}

fail('Method not allowed.', 405);
