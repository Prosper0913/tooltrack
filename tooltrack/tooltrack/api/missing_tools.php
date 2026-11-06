<?php
// ================================================================
//  api/missing_tools.php  -  Missing Tool workflow
//
//  GET  ?summary=1                      -> counters for badges / dashboard
//  GET  ?id=N                           -> one case + its full audit trail
//  GET  ?status=open|pending|confirmed|resolved|rejected&search=&page=&per_page=
//
//  POST { action:'report',  borrow_txn_id, qty, missing_date, reason, investigation_notes, request_token }
//         Staff or Admin. Creates a PENDING case. Inventory is NOT touched yet.
//       { action:'confirm', id, qty?, notes }       Admin. pending -> confirmed
//         Moves qty units borrowed -> missing (tools.missing_qty, transactions.qty_missing).
//       { action:'reject',  id, notes }             Admin. pending -> rejected (no inventory change)
//       { action:'recover', id, qty, to:'good'|'minor'|'damaged', allow_surplus?, notes }   Admin
//         Original tool found again: missing -> chosen condition. Units found AFTER they were
//         already closed by an earlier (historical) replacement are surplus: tools.quantity grows
//         (explicit allow_surplus required).
//
//  Inventory rules (each unit is in exactly ONE state):
//    quantity = available + damaged_qty + missing_qty + borrowed(on loan)
//    report -> nothing moves.  confirm -> borrowed - n, missing + n.
//    recover -> missing - n, bucket + n.
//    Case closes ("resolved") when missing - replaced(historical) - (recovered - surplus) = 0.
//    New stock is bought and added through Tools Management > Add Stock; it never closes a case.
// ================================================================
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/missing_lib.php';
require_once __DIR__ . '/replacement_payments.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
requireLogin();

// A business-rule failure thrown inside a transaction: the runner rolls back, then answers.
class MissingFail extends RuntimeException {
    public $httpCode;
    public function __construct(string $msg, int $code = 400) { parent::__construct($msg); $this->httpCode = $code; }
}
function mfail(string $msg, int $code = 400) { throw new MissingFail($msg, $code); }

// Runs $fn inside ONE DB transaction. $fn returns [$responseData, $auditAction, $auditMessage, $httpCode].
function runTx(PDO $db, callable $fn) {
    $db->beginTransaction();
    try {
        $res = $fn();
        $db->commit();
    } catch (MissingFail $e) {
        if ($db->inTransaction()) $db->rollBack();
        fail($e->getMessage(), $e->httpCode);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Missing-tool action failed: ' . $e->getMessage());   // details to the log, not the browser
        fail('Action failed. Please try again.', 500);
    }
    if (!empty($res[1])) logAudit($res[1], $res[2] ?? '');
    ok($res[0], $res[3] ?? 200);
}

function lockRow(PDO $db, string $table, int $id): array {
    $st = $db->prepare("SELECT * FROM $table WHERE id = ? FOR UPDATE");   // $table is a fixed literal from this file
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) mfail(ucfirst($table) . ' record not found.', 404);
    return $row;
}

// Full case (with names, loan info and derived quantities) for responses and the list.
const REPORT_SELECT = "
    SELECT mr.*,
           tl.name AS tool_name, tl.code AS tool_code,
           t.txn_id AS borrow_txn_ref, t.due_date, t.qty AS loan_qty,
           t.qty_returned AS loan_qty_returned, t.qty_missing AS loan_qty_missing,
           b.type AS borrower_type,
           COALESCE(b.full_name, mr.borrower_name_snapshot)       AS borrower,
           COALESCE(b.id_number, mr.borrower_id_number_snapshot)  AS borrower_id_number
    FROM missing_reports mr
    LEFT JOIN tools        tl ON tl.id = mr.tool_id
    LEFT JOIN transactions t  ON t.id  = mr.borrow_txn_id
    LEFT JOIN borrowers    b  ON b.id  = mr.borrower_id";

function decorateReport(array $r): array {
    foreach (['id','borrow_txn_id','tool_id','qty_reported','qty_missing','qty_replaced','qty_recovered','qty_surplus'] as $k) $r[$k] = (int)$r[$k];
    $live = in_array($r['status'], ['confirmed', 'resolved'], true);
    $r['open_qty']  = $live ? max(0, missingOpenQty($r)) : 0;
    $r['unresolved'] = in_array($r['status'], ['pending', 'confirmed'], true);
    $gross=(int)($r['qty_written_off']??0);$late=(int)($r['qty_late_recovered']??0);
    $r['outcome']=['confirmed'=>'Missing','pending'=>'Pending Confirmation','resolved'=>'Resolved','rejected'=>'Rejected'][$r['status']]??ucfirst($r['status']);
    if($live&&($gross>0||((int)$r['qty_recovered']>0&&(int)$r['qty_replaced']===0)))$r['outcome']=$r['open_qty']>0?'Partially Closed':($gross-$late>0?((int)$r['qty_recovered']>0?'Closed — Mixed Outcome':'Closed — Lost'):'Closed — Recovered');
    return $r;
}

function loadReport(PDO $db, int $id): ?array {
    $st = $db->prepare(REPORT_SELECT . ' WHERE mr.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? decorateReport($r) : null;
}

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {

    if (!empty($_GET['summary'])) {
        $row = $db->query("
            SELECT
              (SELECT COUNT(*) FROM missing_reports WHERE status = 'pending')   AS pending_confirmation,
              (SELECT COUNT(*) FROM missing_reports WHERE status = 'confirmed') AS unresolved_cases,
              (SELECT COUNT(DISTINCT t.txn_id) FROM transactions t WHERE t.type = 'borrow' AND t.status = 'active'
                  AND " . sqlOutstanding('t') . " > 0 AND " . sqlDueAt('t') . " < NOW()) AS overdue_loans,
              (SELECT COUNT(DISTINCT t.txn_id) FROM transactions t WHERE t.type = 'borrow' AND t.status = 'active'
                  AND " . sqlOutstanding('t') . " > 0
                  AND DATEDIFF(CURDATE(), t.due_date) >= " . OVERDUE_FOLLOWUP_DAYS . ") AS followup_loans
        ")->fetch();
        $row = array_map('intval', $row);
        $row['followup_days'] = OVERDUE_FOLLOWUP_DAYS;
        ok($row);
    }

    if (!empty($_GET['id'])) {
        $r = loadReport($db, (int)$_GET['id']);
        if (!$r) fail('Missing-tool case not found.', 404);
        $ev = $db->prepare('SELECT id, action, qty, detail, user_id, user_name, created_at
                            FROM missing_report_events WHERE report_id = ? ORDER BY id ASC');
        $ev->execute([$r['id']]);
        $r['events'] = $ev->fetchAll();
        // Student charge cases linked to this loss (payments only; no replacement workflow).
        $rq = $db->prepare("SELECT id, quantity_needed, inventory_status, created_at FROM replacement_requests
                            WHERE missing_report_id = ? AND tool_id IS NOT NULL ORDER BY id ASC");
        $rq->execute([$r['id']]);
        $r['incident_cases'] = $rq->fetchAll();
        ok($r);
    }

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;
    $status  = (string)($_GET['status'] ?? '');
    $search  = trim((string)($_GET['search'] ?? ''));

    $where = ['1=1']; $params = [];
    if ($status === 'open') {
        $where[] = "mr.status IN ('pending','confirmed')";
    } elseif (in_array($status, ['pending', 'confirmed', 'resolved', 'rejected'], true)) {
        $where[] = 'mr.status = ?'; $params[] = $status;
    }
    if ($search !== '') {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $where[] = '(tl.name LIKE ? OR tl.code LIKE ? OR mr.report_no LIKE ? OR COALESCE(b.full_name, mr.borrower_name_snapshot) LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $cs = $db->prepare("SELECT COUNT(*) FROM missing_reports mr
                        LEFT JOIN tools tl ON tl.id = mr.tool_id
                        LEFT JOIN borrowers b ON b.id = mr.borrower_id WHERE $whereSql");
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();

    $st = $db->prepare(REPORT_SELECT . " WHERE $whereSql ORDER BY (mr.status = 'pending') DESC, mr.reported_at DESC, mr.id DESC LIMIT ? OFFSET ?");
    $st->execute(array_merge($params, [$perPage, $offset]));
    $rows = array_map('decorateReport', $st->fetchAll());

    echo json_encode(['success' => true, 'data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    exit;
}

if ($method !== 'POST') fail('Method not allowed.', 405);

// ── POST ──────────────────────────────────────────────────────
$b      = body();
$action = trim((string)($b['action'] ?? ''));
$notes  = mb_substr(trim((string)($b['notes'] ?? '')), 0, 500);
$id     = (int)($b['id'] ?? 0);
$rawQty = $b['qty'] ?? 0;
if (filter_var($rawQty, FILTER_VALIDATE_INT) === false) fail('Quantity must be a whole number.');
$qty = (int)$rawQty;
if ($qty < 0 || $qty > 100000) fail('Quantity is out of range.');

$adminOnly = ['confirm', 'reject', 'recover'];
if (!in_array($action, array_merge(['report'], $adminOnly), true)) fail('Unknown action.');
if (in_array($action, $adminOnly, true) && !missingIsAdmin()) fail('Only an Admin can do this.', 403);
if (!missingCanReport()) fail('You are not allowed to manage missing tools.', 403);
[$uid, $uname] = missingActor();

// ── REPORT (Staff or Admin) ───────────────────────────────────
if ($action === 'report') {
    $loanId  = (int)($b['borrow_txn_id'] ?? 0);
    $date    = trim((string)($b['missing_date'] ?? ''));
    $reason  = trim((string)($b['reason'] ?? ''));
    $inv     = mb_substr(trim((string)($b['investigation_notes'] ?? '')), 0, 2000);
    $token   = trim((string)($b['request_token'] ?? ''));

    if (!$loanId)                      fail('borrow_txn_id is required.');
    if ($qty < 1)                      fail('Quantity missing must be at least 1.');
    $dd = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dd || $dd->format('Y-m-d') !== $date) fail('Date missing must be a valid YYYY-MM-DD date.');
    if (mb_strlen($reason) < 3)        fail('Please give a reason (at least 3 characters).');
    if (mb_strlen($reason) > 255)      $reason = mb_substr($reason, 0, 255);
    if ($token !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) fail('Invalid request token.');
    if ($token === '') $token = null;
    $today = (string)$db->query('SELECT CURDATE()')->fetchColumn();
    if ($date > $today) fail('Date missing cannot be in the future.');

    runTx($db, function () use ($db, $loanId, $qty, $date, $reason, $inv, $token, $uid, $uname, $today) {
        // Lock the loan first: two staff reporting the same loan are serialised here.
        $loan = lockRow($db, 'transactions', $loanId);
        if ($loan['type'] !== 'borrow') mfail('That record is not a borrow transaction.', 422);

        // Double submit of the SAME form: answer with the case that already exists.
        if ($token !== null) {
            $dup = $db->prepare('SELECT id FROM missing_reports WHERE request_token = ?');
            $dup->execute([$token]);
            if ($existing = $dup->fetchColumn()) {
                return [['id' => (int)$existing, 'duplicate' => true], null, null, 200];
            }
        }
        if ($loan['status'] !== 'active') mfail('This borrow record is already closed - nothing is outstanding to report.', 409);
        $outstanding = loanOutstanding($loan);
        if ($outstanding < 1) mfail('No units of this borrow are outstanding.', 409);
        if ($qty > $outstanding) mfail("Cannot report $qty missing - only $outstanding unit(s) are still outstanding.", 409);
        if ($date < substr((string)$loan['created_at'], 0, 10)) mfail('Date missing cannot be before the tool was borrowed.');

        $pend = $db->prepare("SELECT report_no FROM missing_reports WHERE borrow_txn_id = ? AND status = 'pending' LIMIT 1");
        $pend->execute([$loanId]);
        if ($no = $pend->fetchColumn()) mfail("A missing-tool report ($no) is already waiting for confirmation on this borrow.", 409);

        $tool = lockRow($db, 'tools', (int)$loan['tool_id']);

        try {
            $db->prepare("INSERT INTO missing_reports
                (report_no, request_token, borrow_txn_id, tool_id, borrower_id, borrower_name_snapshot, borrower_id_number_snapshot,
                 qty_reported, missing_date, reason, investigation_notes, status, open_key, reported_by, reported_by_name)
                VALUES (?,?,?,?,?,?,?,?,?,?,?, 'pending', ?, ?, ?)")
               ->execute(['TMP-' . bin2hex(random_bytes(6)), $token, $loanId, (int)$loan['tool_id'], $loan['borrower_id'],
                          $loan['borrower_name_snapshot'], $loan['borrower_id_number_snapshot'],
                          $qty, $date, $reason, $inv !== '' ? $inv : null, $loanId, $uid, $uname]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') mfail('This report was already submitted (duplicate).', 409);
            throw $e;
        }
        $rid = (int)$db->lastInsertId();
        $no  = 'MR-' . str_pad((string)$rid, 5, '0', STR_PAD_LEFT);
        $db->prepare('UPDATE missing_reports SET report_no = ? WHERE id = ?')->execute([$no, $rid]);
        missingEvent($db, $rid, 'reported', $qty, "Reported by $uname: $reason");

        return [['id' => $rid, 'report_no' => $no, 'status' => 'pending', 'duplicate' => false],
                'missing_reported', "Reported $qty x {$tool['name']} ({$tool['code']}) missing from loan {$loan['txn_id']} - $no", 201];
    });
}

// Everything below works on an existing case.
if (!$id) fail('id is required.');
$peek = $db->prepare('SELECT borrow_txn_id, tool_id FROM missing_reports WHERE id = ?');
$peek->execute([$id]);
$ref = $peek->fetch();
if (!$ref) fail('Missing-tool case not found.', 404);

// ── CONFIRM (Admin) ───────────────────────────────────────────
if ($action === 'confirm') {
    runTx($db, function () use ($db, $id, $qty, $notes, $ref, $uid, $uname) {
        // Lock order: loan -> case -> tool (same as returns, so no deadlock between the two).
        $loan = lockRow($db, 'transactions', (int)$ref['borrow_txn_id']);
        $rep  = lockRow($db, 'missing_reports', $id);
        if ($rep['status'] !== 'pending') mfail("This report was already {$rep['status']} - refresh the page.", 409);
        if (!MISSING_ALLOW_SELF_CONFIRM && $uid !== null && (int)$rep['reported_by'] === $uid) {
            mfail('A different Admin must confirm a report you filed yourself.', 403);
        }
        $confirmQty = $qty > 0 ? $qty : (int)$rep['qty_reported'];
        if ($confirmQty < 1 || $confirmQty > (int)$rep['qty_reported']) mfail("Confirmed quantity must be between 1 and {$rep['qty_reported']}.");

        $outstanding = loanOutstanding($loan);
        if ($loan['status'] !== 'active' || $confirmQty > $outstanding) {
            mfail("Only $outstanding unit(s) of this borrow are still outstanding (some were returned since the report). "
                . "Confirm a smaller quantity or reject the report.", 409);
        }

        $tool = lockRow($db, 'tools', (int)$rep['tool_id']);
        $avl = (int)$tool['available']; $min = (int)$tool['minor_qty']; $dmg = (int)$tool['damaged_qty'];
        $mis = (int)$tool['missing_qty'] + $confirmQty;
        $borrowedAfter = (int)$tool['quantity'] - $avl - $dmg - $mis;     // units still on loan after the move
        if ($borrowedAfter < 0 || $min > $avl) {
            mfail('Inventory counts for this tool would become inconsistent - nothing was changed. Please check the Integrity report.', 409);
        }
        $db->prepare('UPDATE tools SET missing_qty = ? WHERE id = ?')->execute([$mis, $tool['id']]);

        $newMissing = (int)$loan['qty_missing'] + $confirmQty;
        $closes     = ((int)$loan['qty_returned'] + $newMissing) >= (int)$loan['qty'];
        $db->prepare('UPDATE transactions SET qty_missing = ?, status = ? WHERE id = ?')
           ->execute([$newMissing, $closes ? 'returned' : 'active', $loan['id']]);   // returned_at stays NULL: nothing was handed back
        if ($closes && $loan['borrower_id']) {
            refreshBorrowerBorrowCounts($db,(int)$loan['borrower_id']);
        }

        $db->prepare("UPDATE missing_reports SET status='confirmed', open_key=NULL, qty_missing=?, confirmed_by=?, confirmed_by_name=?,
                      confirmed_at=NOW(), decision_notes=? WHERE id=?")
           ->execute([$confirmQty, $uid, $uname, $notes !== '' ? $notes : null, $id]);
        missingEvent($db, $id, 'confirmed', $confirmQty, "Confirmed $confirmQty of {$rep['qty_reported']} reported" . ($notes !== '' ? " - $notes" : ''));

        return [['id' => $id, 'status' => 'confirmed', 'qty_missing' => $confirmQty, 'loan_closed' => $closes],
                'missing_confirmed', "Confirmed {$confirmQty}x {$tool['name']} ({$tool['code']}) missing - {$rep['report_no']}"];
    });
}

// ── REJECT (Admin) ────────────────────────────────────────────
if ($action === 'reject') {
    if (mb_strlen($notes) < 3) fail('Please give a reason for rejecting the report.');
    runTx($db, function () use ($db, $id, $notes, $ref, $uid, $uname) {
        lockRow($db, 'transactions', (int)$ref['borrow_txn_id']);
        $rep = lockRow($db, 'missing_reports', $id);
        if ($rep['status'] !== 'pending') mfail("This report was already {$rep['status']} - refresh the page.", 409);
        $db->prepare("UPDATE missing_reports SET status='rejected', open_key=NULL, confirmed_by=?, confirmed_by_name=?, confirmed_at=NOW(), decision_notes=? WHERE id=?")
           ->execute([$uid, $uname, $notes, $id]);
        missingEvent($db, $id, 'rejected', (int)$rep['qty_reported'], $notes);
        return [['id' => $id, 'status' => 'rejected'], 'missing_rejected', "Rejected missing report {$rep['report_no']} - $notes"];
    });
}

// ── RECOVER the original tool (Admin) ─────────────────────────
if ($action === 'recover') {
    $to = (string)($b['to'] ?? 'good');
    if (!in_array($to, ['good', 'minor', 'damaged'], true)) fail("'to' must be good, minor or damaged.");
    if ($qty < 1) fail('Quantity recovered must be at least 1.');
    $allowSurplus = !empty($b['allow_surplus']);
    runTx($db, function () use ($db, $id, $qty, $to, $notes, $ref, $allowSurplus, $uid) {
        // Lock order: linked charge cases -> the case -> the tool (same as the charge endpoints).
        if(bulkReady($db)){
            $parents=$db->prepare('SELECT DISTINCT parent_case_id FROM replacement_requests WHERE missing_report_id=? AND parent_case_id IS NOT NULL ORDER BY parent_case_id');$parents->execute([$id]);
            foreach($parents->fetchAll(PDO::FETCH_COLUMN) as $parent)$db->prepare('SELECT id FROM replacement_requests WHERE id=? FOR UPDATE')->execute([$parent]);
        }
        $db->prepare('SELECT id FROM replacement_requests WHERE missing_report_id = ? ORDER BY id FOR UPDATE')->execute([$id]);
        $rep = lockRow($db, 'missing_reports', $id);
        if (!in_array($rep['status'], ['confirmed', 'resolved'], true)) mfail('Only a confirmed case can be recovered.', 409);
        $notFound = (int)$rep['qty_missing'] - (int)$rep['qty_recovered'];    // originals not yet found
        if ($qty > $notFound) mfail("Only $notFound original unit(s) of this case are still unfound.", 409);

        $open      = max(0, missingOpenQty($rep));
        if((int)($rep['qty_written_off']??0)>0 && $qty>$open)mfail('Use Recovery after Write-off for written-off units. Recover active missing units separately.',409);
        $fromOpen  = min($qty, $open);        // units that were still counted as missing
        $surplus   = $qty - $fromOpen;        // found AFTER an earlier historical record had closed their slot
        if ($surplus > 0 && !$allowSurplus) {
            mfail("$surplus of these unit(s) were already closed by an earlier historical record. Recovering them adds $surplus unit(s) to total stock - tick the confirmation to continue.", 409);
        }

        $tool = lockRow($db, 'tools', (int)$ref['tool_id']);
        if ($fromOpen > (int)$tool['missing_qty']) mfail("The tool only has {$tool['missing_qty']} missing unit(s) recorded - check the Integrity report.", 409);
        [$avl, $min, $dmg] = bucketMove($tool, $to, $qty);
        $mis      = (int)$tool['missing_qty'] - $fromOpen;
        $quantity = (int)$tool['quantity'] + $surplus;
        if ($avl + $dmg + $mis > $quantity || $min > $avl) mfail('Inventory counts would become inconsistent - nothing was changed.', 409);
        $db->prepare('UPDATE tools SET quantity=?, available=?, minor_qty=?, damaged_qty=?, missing_qty=? WHERE id=?')
           ->execute([$quantity, $avl, $min, $dmg, $mis, $tool['id']]);

        $rep['qty_recovered'] = (int)$rep['qty_recovered'] + $qty;
        $rep['qty_surplus']   = (int)$rep['qty_surplus'] + $surplus;
        $newOpen = missingOpenQty($rep);
        $db->prepare('UPDATE missing_reports SET qty_recovered=?, qty_surplus=? WHERE id=?')->execute([$rep['qty_recovered'], $rep['qty_surplus'], $id]);
        $db->prepare("INSERT INTO inventory_adjustments (tool_id, action, from_state, to_state, qty, notes, user_id)
                      VALUES (?, 'recover', 'missing', ?, ?, ?, ?)")
           ->execute([$tool['id'], $to, $qty, mb_substr("Original recovered for {$rep['report_no']}" . ($surplus ? " ($surplus surplus added to total)" : '') . ($notes !== '' ? " - $notes" : ''), 0, 500), $uid]);
        missingEvent($db, $id, 'recovered', $qty, "Found again as $to" . ($surplus ? " ($surplus surplus unit(s) added to total stock)" : '') . ($notes !== '' ? " - $notes" : ''));
        rpRecoveryReview($db,$id,$qty,$to);
        $resolved = missingFinishIfResolved($db, $id, $newOpen);

        return [['id' => $id, 'open_qty' => $newOpen, 'surplus_added' => $surplus, 'resolved' => $resolved],
                'missing_recovered', "Recovered {$qty}x {$tool['name']} ({$tool['code']}) as $to - {$rep['report_no']}"];
    });
}

fail('Unknown action.');
