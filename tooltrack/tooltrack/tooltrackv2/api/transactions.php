<?php
// ================================================================
//  api/transactions.php  —  Borrow & Return
//
//  GET  ?type=borrow&status=active|returned&page=1&per_page=50
//       ?type=return&condition=good|minor|damaged&page=1&per_page=50
//
//  POST { type:'borrow', tool_code, borrower_id, due_date, notes }
//       { type:'borrow_batch', borrower_id, due_date, notes, items:[{ tool_code, qty }, ...] }
//         (Borrow cart: several tools, each with its own quantity, ALL-OR-NOTHING in one DB transaction)
//       { type:'return', borrow_txn_id, returnee_name, notes, request_token,
//         items:{good,minor,damaged,missing}, missing_reason, missing_date }   (old {condition,qty} still accepted)
//
//       { type:'return_batch', borrower_id, returnee_name, notes, request_token, missing_reason, missing_date,
//         items:[{ borrow_txn_id, good, minor, damaged, missing }, ...] }   (Return All Items: ALL-OR-NOTHING in one DB transaction)
//  GET  ?type=student_loans&borrower_id=ID   every loan of one student that still has units outstanding
//
//  Where returned units go (tools.quantity is NEVER reduced by a return):
//    good    -> available             (borrowable again)
//    minor   -> available + minor_qty (borrowable again, tracked separately)
//    damaged -> damaged_qty           (kept in quantity, NOT borrowable until repaired)
//  MISSING units typed on the Return form are NOT stored as a return row: they open a Missing Tool
//  case (Admin = confirmed at once, Staff = pending). Missing tools can also be reported separately in
//  api/missing_tools.php, which moves units borrowed -> missing_qty and records them in
//  transactions.qty_missing (never in qty_returned). Outstanding units of a loan are
//  qty - qty_returned - qty_missing.
//  Borrowing takes good units first, then minor-wear units.
// ================================================================
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/missing_lib.php';
require_once __DIR__ . '/replacement_payments.php';
require_once dirname(__DIR__) . '/includes/circulation_lib.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Borrowing/returning tools is the Staff workflow, but Admins can do
// it too — this endpoint just requires *someone* to be logged in.
requireLogin();
if($method==='GET')session_write_close();

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    if(($_GET['type']??'borrow')==='borrow'&&!empty($_GET['grouped'])){
        require_once __DIR__.'/borrow_history_lib.php';
        try{echo json_encode(borrowSessionHistory($db,$_GET));exit;}
        catch(Throwable $e){error_log('Borrow history: '.$e->getMessage());fail('Could not load checkout history. Please try again.',500);}
    }
    $type      = $_GET['type']      ?? 'borrow';
    $status    = $_GET['status']    ?? '';
    $condition = $_GET['condition'] ?? '';
    $search    = trim($_GET['search'] ?? '');
    $page      = max(1, (int)($_GET['page']     ?? 1));
    $perPage   = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
    $offset    = ($page - 1) * $perPage;

    // Return-page overview uses the same outstanding and overdue rules as loan lists.
    if($type==='return_overview'){
        $loans=$db->query("SELECT COALESCE(SUM(".sqlOutstanding('t')."),0) units_on_loan,
            COUNT(DISTINCT CASE WHEN ".sqlIsOverdue('t')." THEN t.txn_id END) overdue_loans
            FROM transactions t WHERE t.type='borrow' AND t.status='active'")->fetch();
        $returned=(int)$db->query("SELECT COALESCE(SUM(qty),0) FROM transactions WHERE type='return'
            AND created_at>=CURDATE() AND created_at<CURDATE()+INTERVAL 1 DAY")->fetchColumn();
        ok(['units_on_loan'=>(int)$loans['units_on_loan'],'overdue_loans'=>(int)$loans['overdue_loans'],'returned_today'=>$returned]);
    }

    // Return All Items: the student's open loans (fresh from the DB, with what is still outstanding).
    if ($type === 'student_loans') {
        $bid = (int)($_GET['borrower_id'] ?? 0);
        if ($bid < 1) fail('borrower_id is required.');
        $bs = $db->prepare('SELECT id, full_name, id_number, type, is_active FROM borrowers WHERE id = ?');
        $bs->execute([$bid]);
        $bor = $bs->fetch();
        if (!$bor) fail('Borrower not found.', 404);
        $ls = $db->prepare("
            SELECT t.id, t.txn_id, t.tool_id, t.qty, t.qty_returned, t.qty_missing,
                   " . sqlOutstanding('t') . " AS outstanding,
                   t.due_date, t.due_time, t.created_at,
                   ".sqlIsOverdue('t')." AS is_overdue,
                   CASE WHEN t.due_date IS NOT NULL THEN " . sqlOverdueDays('t') . " ELSE 0 END AS overdue_days,
                   (SELECT mr.id FROM missing_reports mr WHERE mr.borrow_txn_id = t.id AND mr.status = 'pending' LIMIT 1) AS pending_report_id,
                   tl.name AS tool_name, tl.code AS tool_code
            FROM transactions t
            LEFT JOIN tools tl ON tl.id = t.tool_id
            WHERE t.type = 'borrow' AND t.status = 'active' AND t.borrower_id = ?
              AND " . sqlOutstanding('t') . " > 0
            ORDER BY (t.due_date IS NULL), t.due_date ASC, t.id ASC
        ");
        $ls->execute([$bid]);
        $loans = $ls->fetchAll();
        foreach ($loans as &$l) {
            if($l['due_date'])$l['due_label']=(new DateTimeImmutable($l['due_date'].' '.($l['due_time']?:'23:59:59'),new DateTimeZone(APP_TIMEZONE)))->format('M j, Y g:i A T');
            foreach (['id', 'tool_id', 'qty', 'qty_returned', 'qty_missing', 'outstanding', 'overdue_days'] as $k) $l[$k] = (int)$l[$k];
            $l['pending_report_id'] = $l['pending_report_id'] !== null ? (int)$l['pending_report_id'] : null;
        }
        unset($l);
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => ['borrower' => $bor, 'loans' => $loans]]);
        exit;
    }

    if (!in_array($type, ['borrow', 'return'], true)) fail("type must be 'borrow' or 'return'.");

    $where  = ["t.type = ?"];
    $params = [$type];

    if ($type === 'borrow' && in_array($status, ['active', 'returned'], true)) {
        $where[]  = "t.status = ?";
        $params[] = $status;
    }
    // Workflow filters (computed on the DB clock, never on the browser's):
    //   overdue  = still out and past its due date
    //   followup = overdue for OVERDUE_FOLLOWUP_DAYS or more (needs staff follow-up)
    //   missing  = loans that have confirmed-missing units
    if ($type === 'borrow' && $status === 'overdue') {
        $where[] = "t.status = 'active' AND " . sqlDueAt('t') . " < NOW() AND " . sqlOutstanding('t') . " > 0";
    }
    if ($type === 'borrow' && $status === 'followup') {
        $where[] = "t.status = 'active' AND " . sqlOutstanding('t') . " > 0 AND DATEDIFF(CURDATE(), t.due_date) >= " . OVERDUE_FOLLOWUP_DAYS;
    }
    if($type==='borrow'&&$status==='due_today')$where[]="t.status='active' AND ".sqlOutstanding('t').">0 AND t.due_date=CURDATE() AND ".sqlDueAt('t').">=NOW()";
    if ($type === 'borrow' && $status === 'missing') {
        $where[] = "t.qty_missing > 0";
    }
    // 'missing' stays filterable so OLD return rows (recorded before the missing-tool workflow) remain visible.
    if ($type === 'return' && in_array($condition, ['good', 'minor', 'damaged', 'missing'], true)) {
        $where[]  = "t.`condition` = ?";
        $params[] = $condition;
    }
    // Server-side search so the Return page never has to download every
    // active loan (the old per_page=200 cap silently hid older loans).
    if ($search !== '') {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $where[] = '(t.txn_id LIKE ? OR tl.name LIKE ? OR tl.code LIKE ? OR COALESCE(b.full_name, t.borrower_name_snapshot) LIKE ? OR COALESCE(b.id_number, t.borrower_id_number_snapshot) LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    // Only join what the page actually needs. The count shares the same
    // joins only when a search needs them.
    $joins = "
        LEFT JOIN tools     tl ON tl.id = t.tool_id
        LEFT JOIN borrowers b  ON b.id  = t.borrower_id";

    // Return-event context (only when sql/002 has been applied): the other units of the same return and
    // the missing case opened by it, so each condition row can say "part of a return of 10, 2 missing".
    $batchSel = txnHasReturnBatch() ? "
            t.return_batch,
            (SELECT COALESCE(SUM(x.qty),0) FROM transactions x WHERE x.type = 'return' AND t.return_batch IS NOT NULL
                AND x.return_batch = t.return_batch) AS batch_units,
            (SELECT mr.report_no FROM missing_reports mr WHERE t.return_batch IS NOT NULL AND CONVERT(mr.request_token USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(t.return_batch USING utf8mb4) COLLATE utf8mb4_unicode_ci LIMIT 1) AS batch_missing_no,
            (SELECT mr.qty_reported FROM missing_reports mr WHERE t.return_batch IS NOT NULL AND CONVERT(mr.request_token USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(t.return_batch USING utf8mb4) COLLATE utf8mb4_unicode_ci LIMIT 1) AS batch_missing_qty,
            (SELECT mr.status FROM missing_reports mr WHERE t.return_batch IS NOT NULL AND CONVERT(mr.request_token USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(t.return_batch USING utf8mb4) COLLATE utf8mb4_unicode_ci LIMIT 1) AS batch_missing_status," : '';

    $sql = "
        SELECT
            $batchSel
            t.id, t.txn_id, t.type, t.status, t.is_late, t.`condition`, t.notes,
            t.returnee_name,
            t.due_date, t.due_time, t.returned_at, t.created_at, t.qty, t.qty_returned, t.qty_missing,
            (t.qty - t.qty_returned - t.qty_missing) AS outstanding,
            CASE WHEN t.type = 'borrow' AND t.status = 'active' AND t.due_date IS NOT NULL
                 THEN " . sqlOverdueDays('t') . " ELSE 0 END AS overdue_days,
            ".sqlIsOverdue('t')." AS is_overdue,CURDATE() db_today,
            (SELECT mr.id FROM missing_reports mr
              WHERE mr.borrow_txn_id = t.id AND mr.status = 'pending' LIMIT 1) AS pending_report_id,
            (SELECT COUNT(*) FROM missing_reports mr
              WHERE mr.borrow_txn_id = t.id AND mr.status = 'confirmed') AS open_missing_cases,
            (SELECT COUNT(*) FROM missing_reports mr
              WHERE mr.borrow_txn_id = t.id AND mr.status = 'resolved') AS resolved_missing_cases,
            tl.name  AS tool_name,
            tl.code  AS tool_code,
            COALESCE(b.full_name, t.borrower_name_snapshot) AS borrower,
            COALESCE(b.id_number, t.borrower_id_number_snapshot) AS borrower_id_number
        FROM transactions t $joins
        WHERE $whereSql
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $stmt->fetchAll();

    // One status the UI can show as a badge: borrowed | overdue | followup | missing | resolved | returned.
    //   missing  = a confirmed case on this loan is still unresolved (beats overdue)
    //   resolved = it had missing units and every case is closed (replaced / recovered)
    foreach ($rows as &$r) {
        if($r['due_date'])$r['due_label']=(new DateTimeImmutable($r['due_date'].' '.($r['due_time']?:'23:59:59'),new DateTimeZone(APP_TIMEZONE)))->format('M j, Y g:i A T');
        $r['circulation_status']=loanCirculationState($r);
        $r['is_overdue']=$r['circulation_status']==='overdue';
        $r['overdue_days'] = (int)$r['overdue_days'];
        $r['outstanding']  = (int)$r['outstanding'];
        $r['needs_followup'] = $r['overdue_days'] >= OVERDUE_FOLLOWUP_DAYS && $r['outstanding'] > 0;
        if ($r['type'] !== 'borrow')                         $r['loan_state'] = null;
        elseif ((int)$r['open_missing_cases'] > 0)           $r['loan_state'] = 'missing';
        elseif ($r['status'] === 'active' && $r['overdue_days'] >= OVERDUE_FOLLOWUP_DAYS) $r['loan_state'] = 'followup';
        elseif ($r['status'] === 'active' && $r['overdue_days'] > 0)                      $r['loan_state'] = 'overdue';
        elseif ($r['status'] === 'active')                   $r['loan_state'] = 'borrowed';
        elseif ((int)$r['resolved_missing_cases'] > 0)       $r['loan_state'] = 'resolved';
        else                                                 $r['loan_state'] = 'returned';
    }
    unset($r);

    $countSql = $search !== ''
        ? "SELECT COUNT(*) FROM transactions t $joins WHERE $whereSql"
        : "SELECT COUNT(*) FROM transactions t WHERE $whereSql";
    $cStmt = $db->prepare($countSql);
    $cStmt->execute($params);
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

    if (!in_array($type, ['borrow', 'borrow_batch', 'return', 'return_batch'])) {
        fail("type must be 'borrow', 'borrow_batch', 'return' or 'return_batch'.");
    }

    // ── BORROW ──────────────────────────────────────────────
    if ($type === 'borrow') {
        $tool_code   = trim($b['tool_code']   ?? '');
        $borrower_id = (int)($b['borrower_id'] ?? 0);
        $due_date    = trim($b['due_date']     ?? '');
        $notes       = trim($b['notes']        ?? '');
        $qty         = filter_var($b['qty'] ?? 1,FILTER_VALIDATE_INT);

        if (!$tool_code)   fail('tool_code is required.');
        if (!$borrower_id) fail('borrower_id is required.');
        if (!$due_date)    fail('due_date is required.');
        // Optional for older API clients; new Borrow form supplies a minute-precision time.
        $due_time = trim((string)($b['due_time'] ?? ''));
        if ($due_time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $due_time)) {
            fail('due_time must be a valid HH:MM time.');
        }
        $due_time = $due_time === '' ? null : $due_time . ':00';
        if ($qty < 1 || $qty > 1000) fail('Quantity must be between 1 and 1000.');
        $dd = DateTime::createFromFormat('Y-m-d', $due_date);
        if (!$dd || $dd->format('Y-m-d') !== $due_date) fail('due_date must be a valid YYYY-MM-DD date.');
        if ($due_date < $db->query('SELECT CURDATE()')->fetchColumn()) fail('due_date cannot be in the past.');
        if (mb_strlen($notes) > 500) $notes = mb_substr($notes, 0, 500);

        // Cheap early checks (friendly messages). The authoritative checks run again
        // below on the LOCKED row, so two staff can never both take the last unit.
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
            // Lock the tool row, then decide from the locked values.
            $lk = $db->prepare('SELECT * FROM tools WHERE id = ? FOR UPDATE');
            $lk->execute([$tool['id']]);
            $tool = $lk->fetch();
            circulationCheckout($db,$b,$borrower_id,[[$tool,$qty]],$due_date.' '.($due_time??'23:59:59'));
            if (!(int)$tool['is_active']) { $db->rollBack(); fail("'{$tool['name']}' has been retired and can no longer be borrowed."); }
            if ((int)$tool['available'] < $qty) {
                $db->rollBack();
                fail("Only {$tool['available']} unit(s) of '$tool_code' available.", 409);
            }
            // Good units are lent first; minor-wear units only once the good ones run out.
            $good      = (int)$tool['available'] - (int)$tool['minor_qty'];
            $fromMinor = max(0, $qty - $good);
            $newMinor  = (int)$tool['minor_qty'] - $fromMinor;
            $newAvail  = (int)$tool['available'] - $qty;

            // Insert transaction
            $session=createBorrowSession($db,$borrower);
            $txn_id = $session['txn_id'];
            $ins = $db->prepare('
                INSERT INTO transactions (txn_id, type, tool_id, borrower_id, status, due_date, notes, qty,
                                          borrower_name_snapshot, borrower_id_number_snapshot, due_time,borrow_session_id,created_at)
                VALUES (?, "borrow", ?, ?, "active", ?, ?, ?, ?, ?, ?,?,?)
            ');
            // Name + ID number are copied onto the row, so history still reads correctly
            // even if the borrower record is ever removed.
            $ins->execute([$txn_id, $tool['id'], $borrower_id, $due_date, $notes, $qty,
                           $borrower['full_name'], $borrower['id_number'], $due_time,$session['id'],$session['created_at']]);
            $txn_db_id = (int)$db->lastInsertId();

            // The "available >= ?" guard stays as a second line of defence.
            $dec = $db->prepare('UPDATE tools SET available = ?, minor_qty = ? WHERE id = ? AND available >= ?');
            $dec->execute([$newAvail, $newMinor, $tool['id'], $qty]);
            if ($dec->rowCount() !== 1) {
                $db->rollBack();
                fail("Not enough stock — someone else may have just borrowed it. Refresh and try again.", 409);
            }

            // Increase borrower counters
            refreshBorrowerBorrowCounts($db,$borrower_id);

            $db->commit();

            logAudit('borrow', "Borrowed {$qty}x {$tool['name']} ({$tool['code']}) for {$borrower['full_name']}, due $due_date");

            ok([
                'id'          => $txn_db_id,
                'session_id'  => $session['id'],
                'txn_id'      => $txn_id,
                'tool_name'   => $tool['name'],
                'tool_code'   => $tool['code'],
                'borrower'    => $borrower['full_name'],
                'due_date'    => $due_date,
                'due_time'    => $due_time,
            ], 201);

        } catch (CirculationError $e) {
            if($db->inTransaction())$db->rollBack();fail($e->getMessage(),409);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Borrow failed: ' . $e->getMessage());   // details go to the server log, not the browser
            fail('Borrow failed. Please try again.', 500);
        }
    }

    // ── BORROW BATCH (cart) ─────────────────────────────────
    //  One submission borrows several tools for ONE borrower, each with its own quantity. Every line follows
    //  the single-borrow rules (good units first, then minor wear; one `borrow` row per tool, so returns,
    //  overdue tracking and reports keep working unchanged) but the whole cart is ALL-OR-NOTHING:
    //  any line that fails (retired tool, not enough stock...) rolls everything back and nothing is saved.
    //  Lock order: tools in ascending id (same order every batch uses, so two carts cannot deadlock).
    if ($type === 'borrow_batch') {
        $submissionToken=(string)($b['request_token']??'');
        if($submissionToken!==''&&!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$submissionToken))fail('Invalid checkout submission token.');
        $submissionBody=$b;unset($submissionBody['csrf']);ksort($submissionBody);
        $submissionHash=hash('sha256',json_encode($submissionBody,JSON_THROW_ON_ERROR));
        $borrower_id = (int)($b['borrower_id'] ?? 0);
        $due_date    = trim($b['due_date'] ?? '');
        $notes       = trim($b['notes'] ?? '');
        $inItems     = $b['items'] ?? null;

        if (!$borrower_id) fail('borrower_id is required.');
        if (!$due_date)    fail('due_date is required.');
        // Optional for older API clients; new Borrow form supplies a minute-precision time.
        $due_time = trim((string)($b['due_time'] ?? ''));
        if ($due_time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $due_time)) {
            fail('due_time must be a valid HH:MM time.');
        }
        $due_time = $due_time === '' ? null : $due_time . ':00';
        if (!is_array($inItems) || !$inItems) fail('Add at least one tool to borrow.');
        if (count($inItems) > 50) fail('Too many tools in one borrow (max 50).');
        $dd = DateTime::createFromFormat('Y-m-d', $due_date);
        if (!$dd || $dd->format('Y-m-d') !== $due_date) fail('due_date must be a valid YYYY-MM-DD date.');
        if ($due_date < $db->query('SELECT CURDATE()')->fetchColumn()) fail('due_date cannot be in the past.');
        if (mb_strlen($notes) > 500) $notes = mb_substr($notes, 0, 500);

        // Same tool listed twice -> one line with the summed quantity.
        $lines = [];   // lowercase code => ['code' => as typed, 'qty' => n]
        foreach ($inItems as $it) {
            if (!is_array($it)) fail('Invalid item in the request.');
            $code = trim((string)($it['tool_code'] ?? ''));
            if ($code === '') fail('Every item needs a tool_code.');
            $v = $it['qty'] ?? 1;
            if (is_string($v) && ctype_digit($v)) $v = (int)$v;
            if (!is_int($v) || $v < 1 || $v > 1000) fail("Quantity for '$code' must be a whole number between 1 and 1000.");
            $k = mb_strtolower($code);
            if (isset($lines[$k])) $lines[$k]['qty'] += $v; else $lines[$k] = ['code' => $code, 'qty' => $v];
        }
        foreach ($lines as $ln) if ($ln['qty'] > 1000) fail("Quantity for '{$ln['code']}' must be between 1 and 1000.");

        $bs = $db->prepare('SELECT * FROM borrowers WHERE id = ?');
        $bs->execute([$borrower_id]);
        $borrower = $bs->fetch();
        if (!$borrower) fail('Borrower not found.');
        if (!(int)$borrower['is_active']) fail("{$borrower['full_name']} is deactivated and cannot borrow tools.");

        $db->beginTransaction();
        try {
            if($submissionToken!==''){
                $db->prepare('INSERT INTO borrow_submissions(request_token,payload_hash,user_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE request_token=VALUES(request_token)')->execute([$submissionToken,$submissionHash,(int)$_SESSION['user_id']]);
                $s=$db->prepare('SELECT * FROM borrow_submissions WHERE request_token=? FOR UPDATE');$s->execute([$submissionToken]);$saved=$s->fetch();
                if(!hash_equals($saved['payload_hash'],$submissionHash)||(int)$saved['user_id']!==(int)$_SESSION['user_id'])throw new CirculationError('This submission token was already used with different checkout details.');
                if($saved['response_json']!==null){$db->commit();ok(json_decode($saved['response_json'],true)+['duplicate'=>true]);}
            }
            // 1. lock every tool in the cart (ascending id) and decide from the LOCKED values
            $codes = array_column($lines, 'code');
            $ph    = implode(',', array_fill(0, count($codes), '?'));
            $ts    = $db->prepare("SELECT * FROM tools WHERE code IN ($ph) ORDER BY id FOR UPDATE");
            $ts->execute($codes);
            $byCode = [];
            foreach ($ts->fetchAll() as $row) $byCode[mb_strtolower((string)$row['code'])] = $row;
            foreach ($lines as $k => $ln) {
                if (!isset($byCode[$k])) { $db->rollBack(); fail("Tool '{$ln['code']}' not found — nothing was saved."); }
            }

            $checkoutLines=[];foreach($byCode as $k=>$tool)$checkoutLines[]=[$tool,$lines[$k]['qty']];
            circulationCheckout($db,$b,$borrower_id,$checkoutLines,$due_date.' '.($due_time??'23:59:59'));
            // 2. validate every line BEFORE writing anything
            $plan = [];
            foreach ($byCode as $k => $tool) {
                $qty = $lines[$k]['qty'];
                if (!(int)$tool['is_active']) {
                    $db->rollBack(); fail("'{$tool['name']}' has been retired and can no longer be borrowed — nothing was saved.");
                }
                if ((int)$tool['available'] < $qty) {
                    $db->rollBack();
                    fail("Only {$tool['available']} unit(s) of '{$tool['name']}' ({$tool['code']}) available, you asked for $qty — nothing was saved.", 409);
                }
                $plan[] = [$tool, $qty];
            }


            // 3. write every line (good units are lent first; minor-wear units only once the good ones run out)
            $out = []; $totalUnits = 0;
            $session=createBorrowSession($db,$borrower);
            $ins = $db->prepare('
                INSERT INTO transactions (txn_id, type, tool_id, borrower_id, status, due_date, notes, qty,
                                          borrower_name_snapshot, borrower_id_number_snapshot, due_time,borrow_session_id,created_at)
                VALUES (?, "borrow", ?, ?, "active", ?, ?, ?, ?, ?, ?,?,?)
            ');
            $dec = $db->prepare('UPDATE tools SET available = ?, minor_qty = ? WHERE id = ? AND available >= ?');
            foreach ($plan as [$tool, $qty]) {
                $good      = (int)$tool['available'] - (int)$tool['minor_qty'];
                $fromMinor = max(0, $qty - $good);
                $newMinor  = (int)$tool['minor_qty'] - $fromMinor;
                $newAvail  = (int)$tool['available'] - $qty;

                $txn_id = $session['txn_id'];
                $ins->execute([$txn_id, $tool['id'], $borrower_id, $due_date, $notes, $qty,
                               $borrower['full_name'], $borrower['id_number'], $due_time,$session['id'],$session['created_at']]);
                $txnDbId = (int)$db->lastInsertId();

                $dec->execute([$newAvail, $newMinor, $tool['id'], $qty]);
                if ($dec->rowCount() !== 1) {
                    $db->rollBack();
                    fail("Not enough stock for '{$tool['name']}' — someone else may have just borrowed it. Nothing was saved; refresh and try again.", 409);
                }
                $totalUnits += $qty;
                $out[] = ['id' => $txnDbId, 'txn_id' => $txn_id, 'tool_name' => $tool['name'],
                          'tool_code' => $tool['code'], 'qty' => $qty];
            }

            // Cache the exact outcome in the same transaction as all stock and loan writes.
            $response=['txn_id'=>$session['txn_id'],'session_id'=>$session['id'],'borrower'=>$borrower['full_name'],'due_date'=>$due_date,'due_time'=>$due_time,'items'=>$out,'tools_count'=>count($out),'total_units'=>$totalUnits];
            if($submissionToken!=='')$db->prepare('UPDATE borrow_submissions SET response_json=? WHERE request_token=?')->execute([json_encode($response,JSON_THROW_ON_ERROR),$submissionToken]);
            // 4. Count the checkout once; numeric loan IDs remain separate for tool actions.
            $n = count($out);
            refreshBorrowerBorrowCounts($db,$borrower_id);

            $db->commit();

            foreach ($out as $o) {
                logAudit('borrow', "Borrowed {$o['qty']}x {$o['tool_name']} ({$o['tool_code']}) for {$borrower['full_name']}, due $due_date [cart of $n]");
            }

            ok([
                'txn_id'     => $session['txn_id'],
                'session_id' => $session['id'],
                'borrower'    => $borrower['full_name'],
                'due_date'    => $due_date,
                'due_time'    => $due_time,
                'items'       => $out,
                'tools_count' => $n,
                'total_units' => $totalUnits,
            ], 201);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if($e instanceof CirculationError)fail($e->getMessage(),409);
            error_log('Borrow batch failed: ' . $e->getMessage());
            fail('Borrow failed — nothing was saved. Please try again.', 500);
        }
    }

    // ── RETURN ──────────────────────────────────────────────
    //  One submission can split the returned units across conditions:
    //    { type:'return', borrow_txn_id, returnee_name, notes, request_token,
    //      items:{ good:4, minor:1, damaged:3, missing:2 },
    //      missing_reason:'...', missing_date:'YYYY-MM-DD', missing_notes:'...' }
    //  (The old { condition, qty } body still works: it is treated as items:{condition:qty}.)
    //
    //  good / minor / damaged  -> physically handed back. Each non-zero one is stored as its OWN
    //                             `return` row (condition + qty) sharing one return_batch, so the
    //                             Conditions report and borrower history that
    //                             read condition/qty keep working.
    //  missing                 -> NOT handed back. Becomes a Missing Tool case (api/missing_lib.php):
    //                             Admin = confirmed at once, Staff = pending until an Admin confirms.
    //
    //  Stock moves (tools.quantity is never reduced by a return):
    //     good    -> available + n                    minor -> available + n, minor_qty + n
    //     damaged -> damaged_qty + n  (NOT available)  missing (confirmed) -> missing_qty + n
    //  Everything below runs in ONE transaction on locked loan + tool rows.
    if ($type === 'return') {

        $borrow_txn_id = (int)($b['borrow_txn_id'] ?? 0);
        $tool_code     = trim($b['tool_code'] ?? '');
        $notes         = trim($b['notes']     ?? '');
        $returnee_name = trim($b['returnee_name'] ?? '');
        $token         = trim((string)($b['request_token'] ?? ''));

        if (!$borrow_txn_id && !$tool_code) fail('borrow_txn_id or tool_code is required.');
        if (!$returnee_name) fail('Returnee name is required — who is handing the tool back?');
        if (mb_strlen($notes) > 500) $notes = mb_substr($notes, 0, 500);
        if (mb_strlen($returnee_name) > 120) $returnee_name = mb_substr($returnee_name, 0, 120);
        if ($token !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) fail('Invalid request token.');
        if ($token === '') $token = 'RB' . bin2hex(random_bytes(10));

        // ── 1. quantities per condition ──
        $labels = ['good' => 'Good', 'minor' => 'Minor Wear', 'damaged' => 'Damaged', 'missing' => 'Missing'];
        if (isset($b['items']) && is_array($b['items'])) {
            $in = $b['items'];
        } else {
            // Legacy single-condition body.
            $cond = trim($b['condition'] ?? 'good');
            if (!isset($labels[$cond])) fail('condition must be good, minor, damaged or missing.');
            $in = [$cond => $b['qty'] ?? 1];
        }
        $q = [];
        foreach ($labels as $k => $label) {
            $v = $in[$k] ?? 0;
            if ($v === null || $v === '') $v = 0;
            if (is_string($v) && ctype_digit($v)) $v = (int)$v;
            if (!is_int($v) || $v < 0) fail("$label quantity must be a whole number, 0 or more.");
            if ($v > 100000) fail("$label quantity is too large.");
            $q[$k] = $v;
        }
        $handed = $q['good'] + $q['minor'] + $q['damaged'];   // units physically handed back
        $total  = $handed + $q['missing'];                      // every unit this submission accounts for
        if ($total < 1) fail('Enter the quantity for at least one condition (Good, Minor Wear, Damaged or Missing).');

        $missDate = trim((string)($b['missing_date'] ?? ''));
        $missReason = trim((string)($b['missing_reason'] ?? ''));
        $missNotes  = trim((string)($b['missing_notes'] ?? ''));
        if ($q['missing'] > 0) {
            if (!missingCanReport()) fail('You are not allowed to report missing tools.', 403);
            if (mb_strlen($missReason) < 3) fail('Please give a reason for the missing unit(s) (at least 3 characters).');
            if ($missDate !== '') {
                $md = DateTime::createFromFormat('Y-m-d', $missDate);
                if (!$md || $md->format('Y-m-d') !== $missDate) fail('Date missing must be a valid YYYY-MM-DD date.');
            }
        }
        if (!txnHasReturnBatch() && ($q['missing'] > 0 || count(array_filter([$q['good'], $q['minor'], $q['damaged']])) > 1)) {
            fail('Quantity-based returns need the database update: run sql/002_quantity_returns.sql first.', 500);
        }

        // ── 2. which loan ──
        if (!$borrow_txn_id) {
            $f = $db->prepare("
                SELECT t.id FROM transactions t
                JOIN tools tl ON tl.id = t.tool_id
                WHERE tl.code = ? AND t.type = 'borrow' AND t.status = 'active'
                ORDER BY t.created_at DESC LIMIT 1
            ");
            $f->execute([$tool_code]);
            $borrow_txn_id = (int)$f->fetchColumn();
            if (!$borrow_txn_id) fail("No active borrow found for '$tool_code' — nothing to return.");
        }

        $today = $db->query('SELECT CURDATE()')->fetchColumn();
        if ($q['missing'] > 0) {
            if ($missDate === '') $missDate = $today;
            if ($missDate > $today) fail('Date missing cannot be in the future.');
        }

        $db->beginTransaction();
        try {
            // Lock the loan row (any status first, so a double-click answers "already recorded"
            // instead of "no longer active"). Two simultaneous returns queue up here.
            $lq = $db->prepare("
                SELECT t.*, b.full_name AS borrower_name
                FROM transactions t
                LEFT JOIN borrowers b ON b.id = t.borrower_id
                WHERE t.id = ? AND t.type = 'borrow'
                FOR UPDATE
            ");
            $lq->execute([$borrow_txn_id]);
            $borrow = $lq->fetch();
            if (!$borrow) { $db->rollBack(); fail('Borrow record not found.', 404); }

            // Same form submitted twice (double click / retry): answer with what was already saved.
            $dupRows = [];
            if (txnHasReturnBatch()) {
                $ds = $db->prepare("SELECT txn_id, `condition`, qty FROM transactions
                                    WHERE type = 'return' AND return_batch = ? AND borrow_txn_id = ? ORDER BY id");
                $ds->execute([$token, $borrow['id']]);
                $dupRows = $ds->fetchAll();
            }
            $dm = $db->prepare('SELECT report_no, status FROM missing_reports WHERE request_token = ? AND borrow_txn_id = ? LIMIT 1');
            $dm->execute([$token, $borrow['id']]);
            $dupMissing = $dm->fetch();
            if ($dupRows || $dupMissing) {
                $db->rollBack();
                ok(['duplicate' => true, 'return_batch' => $token, 'rows' => $dupRows,
                    'missing' => $dupMissing ?: null, 'tool_name' => null,
                    'outstanding_after' => loanOutstanding($borrow)], 200);
            }

            if ($borrow['status'] !== 'active') {
                $db->rollBack();
                fail('This borrow record is no longer active (it may have already been returned) — refresh the Return page and try again.', 409);
            }
            $outstanding = loanOutstanding($borrow);   // qty - returned - confirmed missing
            if ($total > $outstanding) {
                $db->rollBack();
                fail("You entered $total unit(s) (Good {$q['good']} + Minor Wear {$q['minor']} + Damaged {$q['damaged']} + Missing {$q['missing']}) "
                   . "but only $outstanding unit(s) are still outstanding on this borrow.", 409);
            }

            $ts = $db->prepare('SELECT * FROM tools WHERE id = ? FOR UPDATE');
            $ts->execute([$borrow['tool_id']]);
            $tool = $ts->fetch();
            if (!$tool) { $db->rollBack(); fail('Tool for this borrow record was not found.', 404); }
            if ($tool_code !== '' && strcasecmp($tool_code, (string)$tool['code']) !== 0) {
                $db->rollBack();
                fail("This borrow is for '{$tool['code']}', not '$tool_code'.", 409);
            }

            // Missing units: who confirms, and the one-case-per-loan rule (same as Report Missing Tool).
            $confirmNow = false;
            if ($q['missing'] > 0) {
                $pn = $db->prepare("SELECT report_no FROM missing_reports WHERE borrow_txn_id = ? AND status = 'pending' LIMIT 1");
                $pn->execute([$borrow['id']]);
                if ($no = $pn->fetchColumn()) {
                    $db->rollBack();
                    fail("A missing-tool report ($no) is already waiting for confirmation on this borrow. Ask an Admin to confirm or reject it first, or return without a Missing quantity.", 409);
                }
                if ($missDate < substr((string)$borrow['created_at'], 0, 10)) {
                    $db->rollBack();
                    fail('Date missing cannot be before the tool was borrowed.', 422);
                }
                $confirmNow = RETURN_MISSING_AUTOCONFIRM && MISSING_ALLOW_SELF_CONFIRM && missingIsAdmin();
            }

            $isLate = loanCirculationState($borrow)==='overdue';

            // ── 3. loan counters (computed once) ──
            $newReturned = (int)$borrow['qty_returned'] + $handed;
            $newMissing  = (int)$borrow['qty_missing'] + ($confirmNow ? $q['missing'] : 0);
            $closes      = ($newReturned + $newMissing) >= (int)$borrow['qty'];

            // ── 4. tool buckets (computed once, validated before anything is written) ──
            $avl = (int)$tool['available'];   $min = (int)$tool['minor_qty'];
            $dmg = (int)$tool['damaged_qty']; $mis = (int)$tool['missing_qty'];
            $avl += $q['good'] + $q['minor'];
            $min += $q['minor'];
            $dmg += $q['damaged'];
            if ($confirmNow) $mis += $q['missing'];
            if ($avl < 0 || $min < 0 || $dmg < 0 || $mis < 0 || $min > $avl || $avl + $dmg + $mis > (int)$tool['quantity']) {
                $db->rollBack();
                fail('Inventory counts for this tool would become inconsistent - return not recorded. Please contact an Admin.', 409);
            }

            // ── 5. one return row per physical condition ──
            $rowsOut = [];
            $firstId = null; $firstTxn = null;
            $batchCol = txnHasReturnBatch();
            foreach (['good', 'minor', 'damaged'] as $c) {
                if ($q[$c] < 1) continue;
                $txn_id = generateTxnId();
                $cols = 'txn_id, type, tool_id, borrower_id, status, `condition`, notes, returnee_name, returned_at, qty, borrow_txn_id,
                         borrower_name_snapshot, borrower_id_number_snapshot' . ($batchCol ? ', return_batch' : '');
                $ph   = '?, "return", ?, ?, "returned", ?, ?, ?, NOW(), ?, ?, ?, ?' . ($batchCol ? ', ?' : '');
                $args = [$txn_id, $tool['id'], $borrow['borrower_id'], $c, $notes, $returnee_name, $q[$c], $borrow['id'],
                         $borrow['borrower_name_snapshot'], $borrow['borrower_id_number_snapshot']];
                if ($batchCol) $args[] = $token;
                $db->prepare("INSERT INTO transactions ($cols) VALUES ($ph)")->execute($args);
                if ($c === 'damaged') caseAutoCreate($db,'damaged',(int)$db->lastInsertId(),$borrow,$tool,$q[$c],$notes ?? '');
                if ($firstId === null) { $firstId = (int)$db->lastInsertId(); $firstTxn = $txn_id; }
                $rowsOut[] = ['txn_id' => $txn_id, 'condition' => $c, 'qty' => $q[$c]];
            }

            // ── 6. loan row ──
            $returnedAtSql = ($closes && $handed > 0) ? 'NOW()' : 'returned_at';   // fixed literals, not user input
            $db->prepare("UPDATE transactions SET qty_returned=?, qty_missing=?, status=?, returned_at=$returnedAtSql, is_late=? WHERE id=?")
               ->execute([$newReturned, $newMissing, $closes ? 'returned' : 'active',
                          $handed > 0 ? ($isLate ? 1 : 0) : (int)$borrow['is_late'], $borrow['id']]);

            if ($closes) {
                refreshBorrowerBorrowCounts($db,(int)$borrow['borrower_id']);
                // Everything is accounted for: a report still waiting on this loan has nothing left to confirm.
                $pend = $db->prepare("SELECT id FROM missing_reports WHERE borrow_txn_id = ? AND status = 'pending' FOR UPDATE");
                $pend->execute([$borrow['id']]);
                foreach ($pend->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $db->prepare("UPDATE missing_reports SET status='rejected', open_key=NULL, confirmed_at=NOW(),
                                  decision_notes='Auto-closed: the remaining units were returned.' WHERE id = ?")->execute([$pid]);
                    missingEvent($db, (int)$pid, 'rejected', 0, 'Auto-closed: the remaining units were returned.');
                }
            }

            // ── 7. tool row ──
            $db->prepare('UPDATE tools SET available=?, minor_qty=?, damaged_qty=?, missing_qty=? WHERE id=?')
               ->execute([$avl, $min, $dmg, $mis, $tool['id']]);

            // ── 8. missing case (audit trail lives there) ──
            $missingOut = null;
            if ($q['missing'] > 0) {
                [$rid, $rno, $rstatus] = missingCreateFromReturn(
                    $db, $borrow, $tool, $q['missing'], $missDate, $missReason,
                    trim("Recorded on the Return form ($token)" . ($missNotes !== '' ? ": $missNotes" : '')),
                    $token, $confirmNow
                );
                $missingOut = ['id' => $rid, 'report_no' => $rno, 'status' => $rstatus, 'qty' => $q['missing']];
            }
            $db->commit();

            $parts = [];
            foreach ($labels as $k => $label) if ($q[$k] > 0) $parts[] = "$label {$q[$k]}";
            $lateNote = ($handed > 0 && $isLate) ? ' (LATE)' : '';
            logAudit('return', "Returned {$total}x {$tool['name']} ({$tool['code']}) by $returnee_name — " . implode(', ', $parts)
                     . ($missingOut ? " [{$missingOut['report_no']} {$missingOut['status']}]" : '') . $lateNote);

            $legacyCond = count($rowsOut) ? $rowsOut[0]['condition'] : 'missing';
            ok([
                'id'            => $firstId,
                'txn_id'        => $firstTxn,
                'return_batch'  => $token,
                'rows'          => $rowsOut,
                'items'         => $q,
                'tool_name'     => $tool['name'],
                'tool_code'     => $tool['code'],
                'returned_by'   => $borrow['borrower_name'] ?? $borrow['borrower_name_snapshot'] ?? null,
                'returnee_name' => $returnee_name,
                'condition'     => $legacyCond,                       // kept for old clients
                'is_late'       => $handed > 0 && $isLate,
                'due_date'      => $borrow['due_date'] ?? null,
                'unavailable'   => $q['damaged'] > 0,                 // damaged units kept in total, not borrowable
                'units_added_to_available' => $q['good'] + $q['minor'],
                'missing'       => $missingOut,
                'loan_closed'   => $closes,
                'outstanding_after' => $outstanding - $total,
                'stock'         => ['total' => (int)$tool['quantity'], 'available' => $avl, 'minor' => $min, 'damaged' => $dmg, 'missing' => $mis],
                'removed_from_stock' => false,                        // kept for old clients; units are never removed from the total
            ], 201);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Return failed: ' . $e->getMessage());
            fail('Return failed. Please try again.', 500);
        }
    }

    // ── RETURN ALL ITEMS (batch) ────────────────────────────
    //  One submission settles several open loans of ONE student. Every loan goes through the same rules as a
    //  single return (good/minor -> available, damaged -> damaged_qty and NOT available, missing -> a Missing
    //  Tool case + missing_qty), but everything is ALL-OR-NOTHING: any invalid line rolls the whole batch back.
    //  A loan with nothing entered is simply left outstanding (partial return).
    //  Duplicate protection: each loan gets a token derived from (batch token, loan id), so a retry/double click
    //  is answered with duplicate:true and changes nothing; the locked outstanding check also rejects replays.
    //  Lock order: loans (ascending id) -> tools (ascending id) - same loan->tool order as a single return.
    if ($type === 'return_batch') {
        $borrowerId    = (int)($b['borrower_id'] ?? 0);
        $notes         = trim($b['notes'] ?? '');
        $returnee_name = trim($b['returnee_name'] ?? '');
        $token         = trim((string)($b['request_token'] ?? ''));
        $inItems       = $b['items'] ?? null;

        if ($borrowerId < 1) fail('borrower_id is required.');
        if (!$returnee_name) fail('Returnee name is required — who is handing the tools back?');
        if (!is_array($inItems) || !$inItems) fail('Choose at least one borrowed item to return.');
        if (count($inItems) > 100) fail('Too many items in one submission (max 100).');
        if (mb_strlen($notes) > 500) $notes = mb_substr($notes, 0, 500);
        if (mb_strlen($returnee_name) > 120) $returnee_name = mb_substr($returnee_name, 0, 120);
        if ($token !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) fail('Invalid request token.');
        if ($token === '') $token = 'RB' . bin2hex(random_bytes(10));
        if (!txnHasReturnBatch()) fail('Return All Items needs the database update: run sql/002_quantity_returns.sql first.', 500);

        $labels = ['good' => 'Good', 'minor' => 'Minor Wear', 'damaged' => 'Damaged', 'missing' => 'Missing'];
        $lines = [];                       // borrow id => ['good'=>n,'minor'=>n,'damaged'=>n,'missing'=>n]
        foreach ($inItems as $it) {
            if (!is_array($it)) fail('Invalid item in the request.');
            $lid = (int)($it['borrow_txn_id'] ?? 0);
            if ($lid < 1) fail('Every item needs a borrow_txn_id.');
            if (isset($lines[$lid])) fail('The same borrowed item was sent twice — refresh the page and try again.');
            $qq = [];
            foreach ($labels as $k => $label) {
                $v = $it[$k] ?? 0;
                if ($v === null || $v === '') $v = 0;
                if (is_string($v) && ctype_digit($v)) $v = (int)$v;
                if (!is_int($v) || $v < 0) fail("$label quantity must be a whole number, 0 or more.");
                if ($v > 100000) fail("$label quantity is too large.");
                $qq[$k] = $v;
            }
            if (array_sum($qq) < 1) continue;          // nothing entered for this loan: it stays outstanding
            $lines[$lid] = $qq;
        }
        if (!$lines) fail('Enter a quantity for at least one item (Good, Minor Wear, Damaged or Missing).');
        ksort($lines);

        $anyMissing = false;
        foreach ($lines as $qq) if ($qq['missing'] > 0) { $anyMissing = true; break; }
        $missDate = trim((string)($b['missing_date'] ?? ''));
        $missReason = trim((string)($b['missing_reason'] ?? ''));
        $missNotes  = trim((string)($b['missing_notes'] ?? ''));
        $today = $db->query('SELECT CURDATE()')->fetchColumn();
        if ($anyMissing) {
            if (!missingCanReport()) fail('You are not allowed to report missing tools.', 403);
            if (mb_strlen($missReason) < 3) fail('Please give a reason for the missing unit(s) (at least 3 characters).');
            if ($missDate !== '') {
                $md = DateTime::createFromFormat('Y-m-d', $missDate);
                if (!$md || $md->format('Y-m-d') !== $missDate) fail('Date missing must be a valid YYYY-MM-DD date.');
            } else {
                $missDate = $today;
            }
            if ($missDate > $today) fail('Date missing cannot be in the future.');
        }
        $itemToken = static function (int $lid) use ($token): string { return 'RB' . substr(sha1($token . '|' . $lid), 0, 20); };

        $db->beginTransaction();
        try {
            // 1. lock every loan in the batch (ascending id)
            $ids = array_keys($lines);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $lq  = $db->prepare("
                SELECT t.*, b.full_name AS borrower_name
                FROM transactions t
                LEFT JOIN borrowers b ON b.id = t.borrower_id
                WHERE t.id IN ($ph) AND t.type = 'borrow'
                ORDER BY t.id
                FOR UPDATE");
            $lq->execute($ids);
            $loans = [];
            foreach ($lq->fetchAll() as $row) $loans[(int)$row['id']] = $row;
            if (count($loans) !== count($ids)) { $db->rollBack(); fail('One or more borrow records were not found — refresh the page and try again.', 404); }

            // 2. the same submission already saved? (double click / retry) -> change nothing
            $dr = $db->prepare("SELECT 1 FROM transactions WHERE type = 'return' AND return_batch = ? AND borrow_txn_id = ? LIMIT 1");
            $dm = $db->prepare('SELECT 1 FROM missing_reports WHERE request_token = ? AND borrow_txn_id = ? LIMIT 1');
            $dups = 0;
            foreach ($ids as $lid) {
                $tk = $itemToken($lid);
                $dr->execute([$tk, $lid]); $hit = (bool)$dr->fetchColumn();
                $dm->execute([$tk, $lid]); $hit = $hit || (bool)$dm->fetchColumn();
                if ($hit) $dups++;
            }
            if ($dups) {
                $db->rollBack();
                ok(['duplicate' => true, 'return_batch' => $token, 'items_already_recorded' => $dups], 200);
            }

            // 3. lock the tools (ascending id); several loans can share one tool, so keep running totals in memory
            $toolIds = [];
            foreach ($loans as $l) $toolIds[(int)$l['tool_id']] = true;
            $toolIds = array_keys($toolIds); sort($toolIds);
            $tph = implode(',', array_fill(0, count($toolIds), '?'));
            $ts  = $db->prepare("SELECT * FROM tools WHERE id IN ($tph) ORDER BY id FOR UPDATE");
            $ts->execute($toolIds);
            $T = []; $T0 = [];
            foreach ($ts->fetchAll() as $row) { $T[(int)$row['id']] = $row; $T0[(int)$row['id']] = $row; }
            if (count($T) !== count($toolIds)) { $db->rollBack(); fail('A tool on one of these borrow records was not found.', 404); }

            $confirmNow = $anyMissing && RETURN_MISSING_AUTOCONFIRM && MISSING_ALLOW_SELF_CONFIRM && missingIsAdmin();
            $batchCol   = true;
            $pendQ      = $db->prepare("SELECT report_no FROM missing_reports WHERE borrow_txn_id = ? AND status = 'pending' LIMIT 1");
            $results = []; $sum = ['good' => 0, 'minor' => 0, 'damaged' => 0, 'missing' => 0]; $closedLoans = 0;

            // 4. every loan: validate, then write
            foreach ($lines as $lid => $q) {
                $borrow = $loans[$lid];
                $tid    = (int)$borrow['tool_id'];
                $toolNm = (string)$T[$tid]['name'];
                if ((int)$borrow['borrower_id'] !== $borrowerId) {
                    $db->rollBack(); fail("'$toolNm' does not belong to this borrower — refresh the page and try again.", 409);
                }
                if ($borrow['status'] !== 'active') {
                    $db->rollBack(); fail("'$toolNm' is no longer active (already returned?) — refresh the page and try again.", 409);
                }
                $handed = $q['good'] + $q['minor'] + $q['damaged'];
                $total  = $handed + $q['missing'];
                $outstanding = loanOutstanding($borrow);
                if ($total > $outstanding) {
                    $db->rollBack();
                    fail("'$toolNm': you entered $total unit(s) but only $outstanding are still outstanding — nothing was saved.", 409);
                }
                $cn = $confirmNow && $q['missing'] > 0;
                if ($q['missing'] > 0) {
                    $pendQ->execute([$borrow['id']]);
                    if ($no = $pendQ->fetchColumn()) {
                        $db->rollBack();
                        fail("'$toolNm': a missing-tool report ($no) is already waiting for confirmation. Ask an Admin to confirm or reject it first, or return this item without a Missing quantity.", 409);
                    }
                    if ($missDate < substr((string)$borrow['created_at'], 0, 10)) {
                        $db->rollBack(); fail("'$toolNm': Date missing cannot be before the tool was borrowed.", 422);
                    }
                }
                $isLate = loanCirculationState($borrow)==='overdue';
                $tk     = $itemToken($lid);

                // loan counters
                $newReturned = (int)$borrow['qty_returned'] + $handed;
                $newMissing  = (int)$borrow['qty_missing'] + ($cn ? $q['missing'] : 0);
                $closes      = ($newReturned + $newMissing) >= (int)$borrow['qty'];

                // tool buckets (running totals: good/minor -> available, damaged -> damaged_qty, confirmed missing -> missing_qty)
                $T[$tid]['available']   = (int)$T[$tid]['available'] + $q['good'] + $q['minor'];
                $T[$tid]['minor_qty']   = (int)$T[$tid]['minor_qty'] + $q['minor'];
                $T[$tid]['damaged_qty'] = (int)$T[$tid]['damaged_qty'] + $q['damaged'];
                if ($cn) $T[$tid]['missing_qty'] = (int)$T[$tid]['missing_qty'] + $q['missing'];

                // one return row per physical condition (same columns as a single return, shared per-loan batch token)
                $rowsOut = [];
                foreach (['good', 'minor', 'damaged'] as $c) {
                    if ($q[$c] < 1) continue;
                    $txn_id = generateTxnId();
                    $db->prepare('INSERT INTO transactions (txn_id, type, tool_id, borrower_id, status, `condition`, notes, returnee_name, returned_at, qty, borrow_txn_id,
                                  borrower_name_snapshot, borrower_id_number_snapshot, return_batch)
                                  VALUES (?, "return", ?, ?, "returned", ?, ?, ?, NOW(), ?, ?, ?, ?, ?)')
                       ->execute([$txn_id, $tid, $borrow['borrower_id'], $c, $notes, $returnee_name, $q[$c], $borrow['id'],
                                  $borrow['borrower_name_snapshot'], $borrow['borrower_id_number_snapshot'], $tk]);
                    if ($c === 'damaged') caseAutoCreate($db,'damaged',(int)$db->lastInsertId(),$borrow,$T[$tid],$q[$c],$notes ?? '');
                    $rowsOut[] = ['txn_id' => $txn_id, 'condition' => $c, 'qty' => $q[$c]];
                }

                // loan row
                $returnedAtSql = ($closes && $handed > 0) ? 'NOW()' : 'returned_at';   // fixed literals, not user input
                $db->prepare("UPDATE transactions SET qty_returned=?, qty_missing=?, status=?, returned_at=$returnedAtSql, is_late=? WHERE id=?")
                   ->execute([$newReturned, $newMissing, $closes ? 'returned' : 'active',
                              $handed > 0 ? ($isLate ? 1 : 0) : (int)$borrow['is_late'], $borrow['id']]);
                if ($closes) {
                    $closedLoans++;
                    refreshBorrowerBorrowCounts($db,(int)$borrow['borrower_id']);
                    $pend = $db->prepare("SELECT id FROM missing_reports WHERE borrow_txn_id = ? AND status = 'pending' FOR UPDATE");
                    $pend->execute([$borrow['id']]);
                    foreach ($pend->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                        $db->prepare("UPDATE missing_reports SET status='rejected', open_key=NULL, confirmed_at=NOW(),
                                      decision_notes='Auto-closed: the remaining units were returned.' WHERE id = ?")->execute([$pid]);
                        missingEvent($db, (int)$pid, 'rejected', 0, 'Auto-closed: the remaining units were returned.');
                    }
                }

                // missing case (audit trail lives there)
                $missingOut = null;
                if ($q['missing'] > 0) {
                    [$rid, $rno, $rstatus] = missingCreateFromReturn(
                        $db, $borrow, $T[$tid], $q['missing'], $missDate, $missReason,
                        trim("Recorded on the Return All Items form ($token)" . ($missNotes !== '' ? ": $missNotes" : '')),
                        $tk, $cn
                    );
                    $missingOut = ['id' => $rid, 'report_no' => $rno, 'status' => $rstatus, 'qty' => $q['missing']];
                }

                foreach ($q as $k => $v) $sum[$k] += $v;
                $results[] = ['borrow_txn_id' => (int)$lid, 'tool_id' => $tid, 'tool_name' => $toolNm, 'tool_code' => $T[$tid]['code'],
                              'items' => $q, 'rows' => $rowsOut, 'missing' => $missingOut, 'loan_closed' => $closes,
                              'is_late' => $handed > 0 && $isLate, 'outstanding_after' => $outstanding - $total, '_parts' => $q];
            }

            // 5. tool rows: validate the final counts once per tool, then write each tool once
            foreach ($T as $tid => $tool) {
                $avl = (int)$tool['available']; $min = (int)$tool['minor_qty']; $dmg = (int)$tool['damaged_qty']; $mis = (int)$tool['missing_qty'];
                if ($avl < 0 || $min < 0 || $dmg < 0 || $mis < 0 || $min > $avl || $avl + $dmg + $mis > (int)$tool['quantity']) {
                    $db->rollBack();
                    fail("Inventory counts for '{$tool['name']}' would become inconsistent — nothing was saved. Please contact an Admin.", 409);
                }
                if ($avl !== (int)$T0[$tid]['available'] || $min !== (int)$T0[$tid]['minor_qty']
                    || $dmg !== (int)$T0[$tid]['damaged_qty'] || $mis !== (int)$T0[$tid]['missing_qty']) {
                    $db->prepare('UPDATE tools SET available=?, minor_qty=?, damaged_qty=?, missing_qty=? WHERE id=?')
                       ->execute([$avl, $min, $dmg, $mis, $tid]);
                }
            }
            $db->commit();

            // 6. audit log (after commit, like a single return): one line per loan
            foreach ($results as &$r) {
                $parts = [];
                foreach ($labels as $k => $label) if ($r['_parts'][$k] > 0) $parts[] = "$label {$r['_parts'][$k]}";
                logAudit('return', 'Returned ' . array_sum($r['_parts']) . "x {$r['tool_name']} ({$r['tool_code']}) by $returnee_name — " . implode(', ', $parts)
                         . ($r['missing'] ? " [{$r['missing']['report_no']} {$r['missing']['status']}]" : '')
                         . ($r['is_late'] ? ' (LATE)' : '') . " [batch $token]");
                $tid = (int)$r['tool_id'];
                $r['stock'] = ['total' => (int)$T[$tid]['quantity'], 'available' => (int)$T[$tid]['available'], 'minor' => (int)$T[$tid]['minor_qty'],
                               'damaged' => (int)$T[$tid]['damaged_qty'], 'missing' => (int)$T[$tid]['missing_qty']];
                unset($r['_parts']);
            }
            unset($r);

            ok([
                'return_batch' => $token,
                'borrower'     => $loans[$ids[0]]['borrower_name'] ?? $loans[$ids[0]]['borrower_name_snapshot'] ?? null,
                'returnee_name'=> $returnee_name,
                'items'        => $results,
                'totals'       => $sum,
                'loans_in_batch' => count($results),
                'loans_closed' => $closedLoans,
            ], 201);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Return batch failed: ' . $e->getMessage());
            fail('Return failed — nothing was saved. Please try again.', 500);
        }
    }
}

fail('Method not allowed.', 405);
