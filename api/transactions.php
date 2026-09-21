<?php
// ================================================================
//  api/transactions.php  —  Borrow & Return
//
//  GET  ?type=borrow&status=active|returned&page=1&per_page=50
//       ?type=return&condition=good|minor|damaged&page=1&per_page=50
//
//  POST { type:'borrow', tool_code, borrower_id, due_date, notes }
//       { type:'return', tool_code, condition, notes }
// ================================================================
require_once __DIR__ . '/config.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Borrowing/returning tools is the Staff workflow, but Admins can do
// it too — this endpoint just requires *someone* to be logged in.
requireLogin();

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $type      = $_GET['type']      ?? 'borrow';
    $status    = $_GET['status']    ?? '';
    $condition = $_GET['condition'] ?? '';
    $page      = max(1, (int)($_GET['page']     ?? 1));
    $perPage   = min(200, max(1, (int)($_GET['per_page'] ?? 50)));
    $offset    = ($page - 1) * $perPage;

    $where  = ["t.type = ?"];
    $params = [$type];

    if ($type === 'borrow' && $status) {
        $where[]  = "t.status = ?";
        $params[] = $status;
    }
    if ($type === 'return' && $condition) {
        $where[]  = "t.`condition` = ?";
        $params[] = $condition;
    }

    $sql = "
        SELECT
           t.id, t.txn_id, t.type, t.status, t.is_late, t.`condition`, t.notes,
            t.returnee_name,
            t.due_date, t.returned_at, t.created_at, t.qty, t.qty_returned,
            tl.name  AS tool_name,
            tl.code  AS tool_code,
            b.full_name AS borrower,
            b.id_number AS borrower_id_number
        FROM transactions t
        LEFT JOIN tools     tl ON tl.id = t.tool_id
        LEFT JOIN borrowers b  ON b.id  = t.borrower_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $params[] = $perPage;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Count for pagination
    $countSql  = "SELECT COUNT(*) FROM transactions t WHERE " . implode(' AND ', array_slice($where, 0));
    $countParams = array_slice($params, 0, -2);
    $cStmt = $db->prepare($countSql);
    $cStmt->execute($countParams);
    $total = (int)$cStmt->fetchColumn();

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

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $b    = body();
    $type = trim($b['type'] ?? '');

    if (!in_array($type, ['borrow', 'return'])) {
        fail("type must be 'borrow' or 'return'.");
    }

    // ── BORROW ──────────────────────────────────────────────
    if ($type === 'borrow') {
        $tool_code   = trim($b['tool_code']   ?? '');
        $borrower_id = (int)($b['borrower_id'] ?? 0);
        $due_date    = trim($b['due_date']     ?? '');
        $notes       = trim($b['notes']        ?? '');
        $qty         = (int)($b['qty'] ?? 1);

        if (!$tool_code)   fail('tool_code is required.');
        if (!$borrower_id) fail('borrower_id is required.');
        if (!$due_date)    fail('due_date is required.');
        if ($qty < 1) fail('Quantity must be at least 1.');

        // Find tool
        $ts = $db->prepare('SELECT * FROM tools WHERE code = ?');
        $ts->execute([$tool_code]);
        $tool = $ts->fetch();
        if (!$tool) fail("Tool '$tool_code' not found.");
        if (!(int)$tool['is_active']) fail("'{$tool['name']}' has been retired and can no longer be borrowed.");
        if ((int)$tool['available'] < $qty) fail("Only {$tool['available']} unit(s) of '$tool_code' available.");
        // Find borrower
        $bs = $db->prepare('SELECT * FROM borrowers WHERE id = ?');
        $bs->execute([$borrower_id]);
        $borrower = $bs->fetch();
        if (!$borrower) fail('Borrower not found.');
        if (!(int)$borrower['is_active']) {
            fail("{$borrower['full_name']} is deactivated and cannot borrow tools.");
        }

        $db->beginTransaction();
        try {
            // Insert transaction
            $txn_id = generateTxnId();
            $ins = $db->prepare('
                INSERT INTO transactions (txn_id, type, tool_id, borrower_id, status, due_date, notes, qty)
                VALUES (?, "borrow", ?, ?, "active", ?, ?, ?)
            ');
            $ins->execute([$txn_id, $tool['id'], $borrower_id, $due_date, $notes, $qty]);
            $txn_db_id = (int)$db->lastInsertId();

            // Decrease available
            $db->prepare('UPDATE tools SET available = available - ? WHERE id = ?')
               ->execute([$qty, $tool['id']]);

            // Increase borrower counters
            $db->prepare('UPDATE borrowers SET active_borrows = active_borrows + 1, total_borrows = total_borrows + 1 WHERE id = ?')
               ->execute([$borrower_id]);

            $db->commit();

            ok([
                'id'          => $txn_db_id,
                'txn_id'      => $txn_id,
                'tool_name'   => $tool['name'],
                'tool_code'   => $tool['code'],
                'borrower'    => $borrower['full_name'],
                'due_date'    => $due_date,
            ], 201);

        } catch (\Throwable $e) {
            $db->rollBack();
            fail('Borrow failed: ' . $e->getMessage(), 500);
        }
    }

    // ── RETURN ──────────────────────────────────────────────
    if ($type === 'return') {

        $borrow_txn_id = (int)($b['borrow_txn_id'] ?? 0);
        $tool_code     = trim($b['tool_code'] ?? '');
        $condition     = trim($b['condition'] ?? 'good');
        $notes         = trim($b['notes']     ?? '');
        $returnee_name = trim($b['returnee_name'] ?? '');
        $qty           = (int)($b['qty'] ?? 1);

        if (!$borrow_txn_id && !$tool_code) fail('borrow_txn_id or tool_code is required.');
        if (!in_array($condition, ['good', 'minor', 'damaged', 'missing'])) {
            fail("condition must be good, minor, damaged, or missing.");
        }
        if ($qty < 1) fail('Quantity must be at least 1.');
        if (!$returnee_name) fail('Returnee name is required — who is handing the tool back?');

        if ($borrow_txn_id) {
            // Preferred path (used by the Return page's "Borrowed Item"
            // picker): match the EXACT loan being returned, not just
            // "whichever active borrow of this tool code is newest" —
            // that old fallback could credit the wrong borrower's
            // return when the same tool (by code) was checked out to
            // more than one person at once (a tool can have qty > 1).
            $active = $db->prepare("
                SELECT t.*, b.full_name AS borrower_name
                FROM transactions t
                LEFT JOIN borrowers b ON b.id = t.borrower_id
                WHERE t.id = ? AND t.type = 'borrow' AND t.status = 'active'
            ");
            $active->execute([$borrow_txn_id]);
            $borrow = $active->fetch();
            if (!$borrow) fail('This borrow record is no longer active (it may have already been returned) — refresh the Return page and try again.');

            $ts = $db->prepare('SELECT * FROM tools WHERE id = ?');
            $ts->execute([$borrow['tool_id']]);
            $tool = $ts->fetch();
            if (!$tool) fail('Tool for this borrow record was not found.');
        } else {
            // Legacy fallback (e.g. a direct API call with no specific
            // loan chosen) — matches the most recent active borrow of
            // this tool code. Kept for backward compatibility only;
            // the app's own UI always sends borrow_txn_id now.
            $ts = $db->prepare('SELECT * FROM tools WHERE code = ?');
            $ts->execute([$tool_code]);
            $tool = $ts->fetch();
            if (!$tool) fail("Tool '$tool_code' not found.");

            $active = $db->prepare("
                SELECT t.*, b.full_name AS borrower_name
                FROM transactions t
                LEFT JOIN borrowers b ON b.id = t.borrower_id
                WHERE t.tool_id = ? AND t.type = 'borrow' AND t.status = 'active'
                ORDER BY t.created_at DESC LIMIT 1
            ");
            $active->execute([$tool['id']]);
            $borrow = $active->fetch();
        }

        if ($borrow) {
            $outstanding = (int)$borrow['qty'] - (int)$borrow['qty_returned'];
            if ($qty > $outstanding) fail("Cannot return $qty — only $outstanding unit(s) outstanding.");
        }

        // A damaged or missing item is not fit to lend out again — it
        // should NOT go back into the available pool, and should come
        // off the total count entirely (the physical unit is gone/
        // unusable). 'good' and 'minor wear' both go back into service.
        $removeFromStock = in_array($condition, ['damaged', 'missing'], true);

        // Was this specific loan returned after its due date? Computed
        // once here and persisted on the borrow row (rather than only
        // ever computed on the fly), so "late returns" can be filtered/
        // reported on directly.
        $isLate = $borrow && !empty($borrow['due_date']) && date('Y-m-d') > $borrow['due_date'];

        $db->beginTransaction();
        try {
            $txn_id = generateTxnId();
            $now    = date('Y-m-d H:i:s');

            // Insert return transaction
            $ins = $db->prepare('
                INSERT INTO transactions (txn_id, type, tool_id, borrower_id, status, `condition`, notes, returnee_name, returned_at, qty)
                VALUES (?, "return", ?, ?, "returned", ?, ?, ?, ?, ?)
            ');
            $ins->execute([
                $txn_id,
                $tool['id'],
                $borrow ? $borrow['borrower_id'] : null,
                $condition,
                $notes,
                $returnee_name,
                $now,
                $qty,
            ]);
            $txn_db_id = (int)$db->lastInsertId();

            // Update original borrow row
            if ($borrow) {
                $newReturned    = (int)$borrow['qty_returned'] + $qty;
                $fullyReturned  = $newReturned >= (int)$borrow['qty'];
                $db->prepare("UPDATE transactions SET qty_returned=?, status=?, returned_at=?, is_late=? WHERE id=?")
                   ->execute([$newReturned, $fullyReturned ? 'returned' : 'active', $fullyReturned ? $now : null, $isLate ? 1 : 0, $borrow['id']]);

                if ($fullyReturned) {
                    $db->prepare('UPDATE borrowers SET active_borrows = GREATEST(active_borrows - 1, 0) WHERE id = ?')
                       ->execute([$borrow['borrower_id']]);
                }
            }

            if ($removeFromStock) {
                // Take it off the books entirely: total quantity drops,
                // and it was never added back to `available` in the
                // first place (it stays at whatever it was while on
                // loan — i.e. still "out" — since it's not coming back).
                $db->prepare('UPDATE tools SET quantity = GREATEST(quantity - ?, 0) WHERE id = ?')
                   ->execute([$qty, $tool['id']]);
            } else {
                // Good / minor wear — back into the lendable pool.
                $db->prepare('UPDATE tools SET available = LEAST(available + ?, quantity) WHERE id = ?')
                   ->execute([$qty, $tool['id']]);
            }
            $db->commit();

            ok([
                'id'            => $txn_db_id,
                'txn_id'        => $txn_id,
                'tool_name'     => $tool['name'],
                'tool_code'     => $tool['code'],
                'returned_by'   => $borrow['borrower_name'] ?? null,
                'returnee_name' => $returnee_name,
                'condition'     => $condition,
                'is_late'       => $isLate,
                'due_date'      => $borrow['due_date'] ?? null,
                'removed_from_stock' => $removeFromStock,
            ], 201);

        } catch (\Throwable $e) {
            $db->rollBack();
            fail('Return failed: ' . $e->getMessage(), 500);
        }
    }
}

fail('Method not allowed.', 405);
