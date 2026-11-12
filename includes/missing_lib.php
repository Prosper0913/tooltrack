<?php
// ================================================================
//  api/missing_lib.php  -  shared rules for Overdue & Missing Tools
//  Required by transactions.php, missing_tools.php and reports.php so
//  the "7 days" rule and the outstanding-quantity formula live in ONE place.
// ================================================================

// A loan becomes a FOLLOW-UP case once it is this many days past its due date.
// It is only a flag: nothing is ever marked missing automatically.
const OVERDUE_FOLLOWUP_DAYS = 7;
require_once __DIR__.'/borrow_session_lib.php';

// true  = an Admin may confirm a report they filed themselves (small teams).
// false = a different Admin must confirm (strict two-person rule).
const MISSING_ALLOW_SELF_CONFIRM = true;

// Return form: units typed into the "Missing" box create a missing-tool case (same table,
// audit trail as "Report Missing Tool").
//   true  = when an ADMIN records the return, the case is confirmed immediately (the loan and
//           tools.missing_qty are updated in the same transaction). Staff always create a
//           PENDING case that an Admin confirms later - nothing is lost until then.
//   false = every case from the return form stays pending until confirmed in Missing Tool Cases.
// (Admin auto-confirm is also skipped when MISSING_ALLOW_SELF_CONFIRM is false.)
const RETURN_MISSING_AUTOCONFIRM = true;

// true when sql/002_quantity_returns.sql has been run (transactions.return_batch exists).
// Lets the API keep working - old single-condition behaviour - if code is deployed before the migration.
function txnHasReturnBatch(): bool {
    static $has = null;
    if ($has !== null) return $has;
    try { $has = (bool)getDB()->query("SHOW COLUMNS FROM transactions LIKE 'return_batch'")->fetch(); }
    catch (\Throwable $e) { $has = false; }
    return $has;
}

// Count borrow checkouts and return events, not tool/condition rows. Borrow lines share
// their header's TXN ID; split returns share return_batch. Legacy IDs remain individual.
function sqlReturnEventCount(string $a = ''): string {
    $p = $a === '' ? '' : "$a.";
    if (!txnHasReturnBatch()) return 'COUNT(*)';
    return "COUNT(DISTINCT CASE WHEN {$p}type = 'return' THEN COALESCE({$p}return_batch, {$p}txn_id) ELSE {$p}txn_id END)";
}

// Units of a loan still physically out: borrowed - returned - confirmed missing.
function loanOutstanding(array $loan): int {
    return (int)$loan['qty'] - (int)$loan['qty_returned'] - (int)($loan['qty_missing'] ?? 0);
}

// SQL fragment for the same formula (alias = the transactions alias).
function sqlOutstanding(string $a = 't'): string {
    return "($a.qty - $a.qty_returned - $a.qty_missing)";
}

// Days past due, using the DATABASE clock (same clock as created_at / CURDATE() elsewhere).
function sqlOverdueDays(string $a = 't'): string {
    return "COALESCE(GREATEST(CEIL(TIMESTAMPDIFF(SECOND,".sqlDueAt($a).",NOW())/86400),0),0)";
}
function sqlDueAt(string $a='t'):string {return "TIMESTAMP($a.due_date,COALESCE($a.due_time,'23:59:59'))";}
function sqlIsOverdue(string $a='t'):string {return "($a.type='borrow' AND $a.status='active' AND ".sqlOutstanding($a).">0 AND ".sqlDueAt($a)."<NOW())";}
function loanCirculationState(array $loan):string {
    if($loan['type']!=='borrow')return 'returned';
    if($loan['status']!=='active'||loanOutstanding($loan)<=0)return 'returned';
    $due=($loan['due_date']??'').' '.($loan['due_time']??'23:59:59');
    $overdue=array_key_exists('is_overdue',$loan)?(bool)$loan['is_overdue']:(!empty($loan['due_date'])&&strtotime($due)<time());
    if($overdue)return 'overdue';
    return ($loan['due_date']??'')===($loan['db_today']??date('Y-m-d'))?'due_today':'borrowed';
}

// Who is acting. The name comes from users.name (same column replacement_requests.php joins),
// falling back to a session value, then "User #id".
function missingActor(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $id   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $name = null;
    if ($id) {
        try {
            $st = getDB()->prepare('SELECT name FROM users WHERE id = ?');
            $st->execute([$id]);
            $name = $st->fetchColumn() ?: null;
        } catch (\Throwable $e) { /* fall through to the session / id fallback */ }
    }
    $name = $name ?? ($_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['name'] ?? null);
    return $cache = [$id, $name !== null ? mb_substr((string)$name, 0, 120) : ($id ? "User #$id" : null)];
}

function missingIsAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === ROLE_ADMIN;
}

// Staff or Admin may report; only Admin may confirm, reject or recover.
function missingCanReport(): bool {
    $role  = $_SESSION['user_role'] ?? '';
    $staff = defined('ROLE_STAFF') ? ROLE_STAFF : 'Staff';
    return $role === ROLE_ADMIN || $role === $staff;
}

// Append one row to the audit trail (call inside the same DB transaction as the change).
function missingEvent(PDO $db, int $reportId, string $action, int $qty = 0, ?string $detail = null): void {
    [$uid, $uname] = missingActor();
    $db->prepare('INSERT INTO missing_report_events (report_id, action, qty, detail, user_id, user_name) VALUES (?,?,?,?,?,?)')
       ->execute([$reportId, $action, $qty, $detail !== null ? mb_substr($detail, 0, 500) : null, $uid, $uname]);
}

// Units of a confirmed case that are still unaccounted for.
//   open = missing - replaced (historical records only) - (recovered - surplus)
function missingOpenQty(array $r): int {
    return (int)$r['qty_missing'] - (int)$r['qty_replaced'] - ((int)$r['qty_recovered'] - (int)$r['qty_surplus']) - (int)($r['qty_written_off']??0) + (int)($r['qty_late_recovered']??0);
}

// The bucket names accepted when units come back into stock.
function bucketMove(array $tool, string $to, int $qty): array {
    $avl = (int)$tool['available']; $min = (int)$tool['minor_qty']; $dmg = (int)$tool['damaged_qty'];
    if ($to === 'damaged') { $dmg += $qty; }
    else { $avl += $qty; if ($to === 'minor') $min += $qty; }
    return [$avl, $min, $dmg];
}

// Closes the case once nothing is left unaccounted for.
function missingFinishIfResolved(PDO $db, int $id, int $open): bool {
    if ($open < 0) throw new RuntimeException('Missing case quantities are inconsistent; resolution was refused.');
    if ($open > 0) return false;
    $u = $db->prepare("UPDATE missing_reports SET status='resolved', resolved_at=NOW() WHERE id = ? AND status = 'confirmed'");
    $u->execute([$id]);
    if ($u->rowCount() === 1) missingEvent($db, $id, 'resolved', 0, 'All missing units recovered or otherwise accounted for');
    return true;
}

// Missing units typed into the Return form. Creates the case (always starts as 'pending', exactly like
// api/missing_tools.php action=report) and, when $confirmNow, confirms it straight away.
// It does NOT touch the loan or the tool row: the caller (api/transactions.php) applies the loan counters
// and tools.missing_qty ONCE together with the returned units, inside the same DB transaction.
// $loan and $tool are the LOCKED rows. Returns [reportId, reportNo, status].
function missingCreateFromReturn(PDO $db, array $loan, array $tool, int $qty, string $date, string $reason,
                                 ?string $details, ?string $token, bool $confirmNow): array {
    if ($qty < 1 || $qty > loanOutstanding($loan)) throw new RuntimeException('Invalid missing quantity for this loan.');
    $pending = $db->prepare("SELECT id FROM missing_reports WHERE borrow_txn_id = ? AND status = 'pending' LIMIT 1");
    $pending->execute([(int)$loan['id']]);
    if ($pending->fetchColumn()) throw new RuntimeException('A missing report is already pending for this loan.');
    [$uid, $uname] = missingActor();
    $db->prepare("INSERT INTO missing_reports
            (report_no, request_token, borrow_txn_id, tool_id, borrower_id, borrower_name_snapshot, borrower_id_number_snapshot,
             qty_reported, missing_date, reason, investigation_notes, status, open_key, reported_by, reported_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,?, 'pending', ?, ?, ?)")
       ->execute(['TMP-' . bin2hex(random_bytes(6)), $token, (int)$loan['id'], (int)$loan['tool_id'], $loan['borrower_id'],
                  $loan['borrower_name_snapshot'], $loan['borrower_id_number_snapshot'],
                  $qty, $date, mb_substr($reason, 0, 255), $details !== null && $details !== '' ? mb_substr($details, 0, 2000) : null,
                  (int)$loan['id'], $uid, $uname]);
    $rid = (int)$db->lastInsertId();
    $no  = 'MR-' . str_pad((string)$rid, 5, '0', STR_PAD_LEFT);
    $db->prepare('UPDATE missing_reports SET report_no = ? WHERE id = ?')->execute([$no, $rid]);
    missingEvent($db, $rid, 'reported', $qty, "Reported by $uname during a return: $reason");

    if ($confirmNow) {
        $db->prepare("UPDATE missing_reports SET status='confirmed', open_key=NULL, qty_missing=?, confirmed_by=?, confirmed_by_name=?,
                      confirmed_at=NOW(), decision_notes=? WHERE id=?")
           ->execute([$qty, $uid, $uname, 'Confirmed at return', $rid]);
        missingEvent($db, $rid, 'confirmed', $qty, "Confirmed $qty of $qty reported (recorded by an Admin on the Return form)");
    }
    caseAutoCreate($db,'lost',$rid,$loan,$tool,$qty,$reason);
    return [$rid, $no, $confirmNow ? 'confirmed' : 'pending'];
}
