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
//  recovery goes through that workflow so the case record and audit trail
//  stay in sync; the manual 'recover' below is kept only for legacy missing units.
//  Outstanding loan units = qty - qty_returned - qty_missing.
//
//  STOCK HISTORY (sql/002_stock_history.sql): every change to the total quantity made through
//  PUT (Edit Tool +/- buttons) is logged in `stock_history` inside the SAME transaction as the
//  UPDATE, so a stock change and its history row either both happen or neither does.
//  GET ?stock_history=1[&tool_id=][&page=][&per_page=] lists it, newest first.
// ================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sku_lib.php';
require_once dirname(__DIR__) . '/includes/stock_lib.php';
require_once __DIR__ . '/tool_disposal_lib.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
requireLogin();
if($method==='GET')session_write_close();

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

function currentUserName(PDO $db): ?string {
    $uid = currentUserId();
    if (!$uid) return null;
    $q = $db->prepare('SELECT name FROM users WHERE id = ?');
    $q->execute([$uid]);
    $n = $q->fetchColumn();
    return $n === false ? null : (string)$n;
}

// Adds the derived counts so every response (list, single, create, update)
// exposes the same numbers.
function addDerived(array $t): array {
    $t['edit_version'] = toolEditVersion($t);
    $t['status']       = toolStatus($t);
    $t['good_qty']     = (int)$t['available'] - (int)$t['minor_qty'];
    $t['borrowed_qty'] = (int)$t['quantity'] - (int)$t['available'] - (int)$t['damaged_qty'] - (int)$t['missing_qty'];
    return $t;
}

function toolEditVersion(array $t): string {
    $values=[];
    foreach(['id','name','category','quantity','available','minor_qty','damaged_qty','missing_qty','min_stock','price','description','is_active'] as $field)$values[]=(string)($t[$field]??'');
    return hash('sha256',json_encode($values));
}

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    if(isset($_GET['disposed_history'])){
        requireRole(ROLE_ADMIN);
        if(!toolDisposalReady($db))fail('Run api/migrate_tool_disposals.php before viewing disposal history.',503);
        try{ok(listToolDisposals($db,$_GET));}catch(ToolDisposalError $e){fail($e->getMessage(),422);}
    }
    if(isset($_GET['disposal_cases'])){
        requireRole(ROLE_ADMIN);
        try{ok(listToolDisposalCases($db,(int)($_GET['tool_id']??0)));}catch(ToolDisposalError $e){fail($e->getMessage(),422);}
    }
    if(!empty($_GET['sku_preview'])){
        $category=trim((string)($_GET['category']??''));
        if(!isset(toolSkuCategories()[$category]))fail('Select a supported tool category.',422);
        try{$sku=toolSkuPreview($db,$category);}
        catch(Throwable $e){error_log('SKU preview failed: '.$e->getMessage());fail('SKU preview is unavailable. Please try again.',503);}
        ok(['code'=>$sku,'preview'=>true]);
    }

    // ── Stock update history (newest first) ───────────────────
    if (!empty($_GET['stock_history'])) {
        $hPage    = max(1, (int)($_GET['page'] ?? 1));
        $hPer     = min(100, max(1, (int)($_GET['per_page'] ?? 10)));
        $hWhere   = '1=1';
        $hParams  = [];
        if (!empty($_GET['tool_id'])) { $hWhere .= ' AND h.tool_id = ?'; $hParams[] = (int)$_GET['tool_id']; }

        $hc = $db->prepare("SELECT COUNT(*) FROM stock_history h WHERE $hWhere");
        $hc->execute($hParams);
        $hTotal = (int)$hc->fetchColumn();

        $hs = $db->prepare("
            SELECT h.id, h.tool_id, h.tool_name, t.code AS tool_code, h.transaction_type, h.quantity,
                   h.previous_stock, h.updated_stock, h.remarks,
                   COALESCE(u.name, h.user_name) AS performed_by, h.created_at
            FROM stock_history h
            LEFT JOIN tools t ON t.id = h.tool_id
            LEFT JOIN users u ON u.id = h.user_id
            WHERE $hWhere
            ORDER BY h.created_at DESC, h.id DESC
            LIMIT ? OFFSET ?");
        $hs->execute(array_merge($hParams, [$hPer, ($hPage - 1) * $hPer]));

        http_response_code(200);
        echo json_encode(['success' => true, 'data' => $hs->fetchAll(), 'total' => $hTotal, 'page' => $hPage, 'per_page' => $hPer]);
        exit;
    }

    if (!empty($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM tools WHERE id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $tool = $stmt->fetch();
        if (!$tool) fail('Tool not found.', 404);
        ok(addDerived($tool));
    }

    $page     = max(1, (int)($_GET['page']     ?? 1));
    // 200 is what the Borrow / Return pickers ask for.
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
               (SELECT COALESCE(SUM(w.quantity),0) FROM loss_writeoffs w WHERE w.tool_id=tools.id AND w.status='approved') AS gross_written_off,
               (SELECT COALESCE(SUM(w.recovered_quantity),0) FROM loss_writeoffs w WHERE w.tool_id=tools.id AND w.status='approved') AS later_loss_recoveries,
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

function validateToolFields(array $b): array {
    $name  = trim($b['name'] ?? '');
    $cat   = trim($b['category'] ?? '');
    $qty   = (int)($b['quantity'] ?? 0);
    $min   = (int)($b['min_stock'] ?? 1);
    $price = round((float)($b['price'] ?? 0), 2);
    $desc  = trim($b['description'] ?? '');
    if (!$name || !$cat || $qty < 1 || $min < 1) fail('All required fields must be filled.');
    if ($qty > 100000)           fail('Quantity is too large.');
    if ($price < 0 || $price > 10000000) fail('Unit Price is out of range.');
    if (mb_strlen($name) > 150)  fail('Name is too long (max 150).');
    if (mb_strlen($desc) > 1000) fail('Description is too long (max 1000).');
    return [$name, $cat, $qty, $min, $price, $desc];
}

// ── POST (Create) ─────────────────────────────────────────────
// Admin restocking adds a delta under a row lock; its persistent token makes retries safe.
if($method==='POST'){
    $toolBody=body();
    if(($toolBody['action']??'')==='dispose_damaged'){
        $actor=requireRole(ROLE_ADMIN);
        if(!toolDisposalReady($db))fail('Run api/migrate_tool_disposals.php before disposing inventory.',503);
        try{ok(disposeDamagedTool($db,$toolBody,$actor));}
        catch(ToolDisposalError $e){fail($e->getMessage(),409);}
        catch(PDOException $e){error_log('Damaged tool disposal SQL: '.$e->getMessage());fail($e->getCode()==='23000'?'This disposal request was already recorded. Refresh disposal history.':'Disposal failed. Nothing was committed; refresh and retry.',409);}
        catch(Throwable $e){error_log('Damaged tool disposal failed: '.$e->getMessage());fail('Disposal failed. Nothing was committed; refresh and retry.',500);}
    }
    if(($toolBody['action']??'')==='add_stock'){
        $actor=requireRole(ROLE_ADMIN);
        $toolId=(int)($toolBody['id']??0);$rawQty=$toolBody['quantity']??null;
        if((!is_int($rawQty)&&!(is_string($rawQty)&&ctype_digit($rawQty)))||(int)$rawQty<1||(int)$rawQty>100000)fail('Quantity added must be a whole number between 1 and 100,000.',422);
        $token=trim((string)($toolBody['request_token']??''));
        if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/',$token))fail('Invalid stock request token. Reopen Add Stock.',422);
        if($toolId<1)fail('Choose an existing tool.',422);
        $remarks=trim((string)($toolBody['remarks']??''));
        if(mb_strlen($remarks)>450)fail('Remarks must be at most 450 characters.',422);
        $expectedStock=null;
        if(array_key_exists('expected_stock',$toolBody)){
            $expectedStock=$toolBody['expected_stock'];
            if(!is_array($expectedStock)||!isset($expectedStock['quantity'],$expectedStock['available'])||!is_int($expectedStock['quantity'])||!is_int($expectedStock['available']))fail('Invalid reviewed stock counts.',422);
        }
        try{$result=addToolStock($db,$toolId,(int)$rawQty,$token,$remarks,(int)$actor['id'],currentUserName($db)??$actor['name'],true,$expectedStock);}
        catch(StockInputError $e){fail($e->getMessage(),409);}
        catch(Throwable $e){error_log('Restock failed: '.$e->getMessage());fail('Stock could not be saved. Retry the same request; it will not be added twice.',500);}
        if(!$result['duplicate']&&empty($result['stock_changed']))logAudit('stock_added',"Added {$rawQty}x {$result['tool']['name']} ({$result['tool']['code']}), total {$result['tool']['quantity']}");
        $result['tool']=addDerived($result['tool']);ok($result);
    }
}
if ($method === 'POST') {
    [$name, $category, $quantity, $min_stock, $price, $description] = validateToolFields($toolBody);

    if(!isset(toolSkuCategories()[$category]))fail('Select a supported tool category.',422);
    $id=0;$code='';
    for($attempt=0;$attempt<3;$attempt++){
        $db->beginTransaction();
        try {
            for($collision=0;$collision<100;$collision++){
                $code=allocateToolSku($db,$category);
                try{
                    $stmt=$db->prepare('INSERT INTO tools(name,code,category,quantity,original_quantity,available,min_stock,price,description) VALUES (?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([$name,$code,$category,$quantity,$quantity,$quantity,$min_stock,$price,$description]);
                    $id=(int)$db->lastInsertId();
                    $db->prepare('UPDATE tool_sku_issued SET tool_id=? WHERE sku=?')->execute([$id,$code]);
                    recordToolRegistration($db,$id,currentUserId(),currentUserName($db)??'Unknown');
                    break;
                }catch(PDOException $e){
                    if((int)($e->errorInfo[1]??0)!==1062)throw $e;
                    $existing=$db->prepare('SELECT id FROM tools WHERE code=?');$existing->execute([$code]);
                    if(!$existing->fetchColumn())throw $e;
                    // An external import used this code: reserve it permanently and allocate the next.
                }
            }
            if(!$id)throw new RuntimeException('Could not allocate a unique tool SKU.');
            $db->commit();break;
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            $id=0;
            if($e instanceof PDOException && in_array((int)($e->errorInfo[1]??0),[1205,1213],true) && $attempt<2){usleep(20000*($attempt+1));continue;}
            error_log('Tool creation failed: '.$e->getMessage());
            fail('Could not save the tool. Nothing was saved; please try again.',500);
        }
    }
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
    if (!$id) fail('Tool id is required.');
    $remarks = mb_substr(trim((string)($b['remarks'] ?? '')), 0, 500);
    // Quantity the editor was looking at when they pressed +/-. If someone else changed the stock
    // in the meantime we refuse instead of silently overwriting their adjustment.
    $prevSeen = isset($b['previous_quantity']) ? (int)$b['previous_quantity'] : null;

    $db->beginTransaction();
    try {
        $existing = $db->prepare('SELECT * FROM tools WHERE id = ? FOR UPDATE');
        $existing->execute([$id]);
        $old = $existing->fetch();
        if (!$old) { $db->rollBack(); fail('Tool not found.', 404); }

        if (isset($b['edit_version']) && (!is_string($b['edit_version']) || !hash_equals(toolEditVersion($old),$b['edit_version']))) {
            $db->rollBack();
            fail('This tool changed after you opened it. Your entries have been retained. Reopen the tool and review the current values before saving.',409);
        }

        if ($prevSeen !== null && $prevSeen !== (int)$old['quantity']) {
            $db->rollBack();
            fail("Stock for this tool just changed (now {$old['quantity']}). Close this window, reopen the tool and try again.", 409);
        }

        if(array_key_exists('code',$b) && $code!==$old['code']){
            $db->rollBack();fail('The SKU is permanent and cannot be changed when editing a tool.',409);
        }
        $code=$old['code'];
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
            SET name=?, category=?, quantity=?, available=?, minor_qty=?, min_stock=?, price=?, description=?
            WHERE id=?
        ');
        $stmt->execute([$name, $category, $quantity, $available, $minor, $min_stock, $price, $description, $id]);

        if ($quantity !== (int)$old['quantity']) {
            $db->prepare("INSERT INTO inventory_adjustments (tool_id, action, from_state, to_state, qty, notes, user_id)
                          VALUES (?, 'qty_edit', ?, ?, ?, ?, ?)")
               ->execute([$id, (string)$old['quantity'], (string)$quantity, abs($quantity - (int)$old['quantity']),
                          'Total quantity edited', currentUserId()]);

            // Stock update history: one row per adjustment, same transaction as the UPDATE above.
            $delta = $quantity - (int)$old['quantity'];
            $db->prepare("INSERT INTO stock_history
                              (tool_id, tool_name, transaction_type, quantity, previous_stock, updated_stock, remarks, user_id, user_name)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$id, $name, $delta > 0 ? 'Stock Added' : 'Stock Removed', abs($delta),
                          (int)$old['quantity'], $quantity, $remarks !== '' ? $remarks : null,
                          currentUserId(), currentUserName($db)]);
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

    logAudit('tool_edit', "Edited tool $name ($code)" . ($quantity !== (int)$old['quantity']
        ? sprintf(' - stock %d -> %d (%s %d)%s', $old['quantity'], $quantity, $quantity > (int)$old['quantity'] ? 'added' : 'removed', abs($quantity - (int)$old['quantity']), $remarks !== '' ? " - $remarks" : '')
        : ''));
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
            if($action==='repair'){
                $reserved=$db->prepare("SELECT COALESCE(SUM(GREATEST(0,COALESCE(incident_quantity,quantity_needed)-resolved_quantity)),0) FROM replacement_requests WHERE tool_id=? AND workflow_mode IN ('payment','payment_item','tracking') AND inventory_status IN ('Pending Review','Under Repair','Recovered','Awaiting Replacement') AND (reason='damaged' OR inventory_status='Recovered')");
                $reserved->execute([$id]);$have=max(0,$have-(int)$reserved->fetchColumn());
                if($qty>$have){$db->rollBack();fail('These units belong to Damage / Loss cases. Record their inspection in the case details.',409);}
            }
            if ($action === 'recover') {
                // Missing units that belong to a missing-tool CASE must be recovered through that case
                // (api/missing_tools.php) so its counters, audit trail and status stay in step.
                // Only legacy / unlinked missing units (recorded before the workflow existed) can be moved here.
                $lk = $db->prepare("SELECT COALESCE(SUM(GREATEST(qty_missing - qty_replaced - (qty_recovered - qty_surplus) - qty_written_off + qty_late_recovered, 0)), 0)
                                    FROM missing_reports WHERE tool_id = ? AND status IN ('confirmed','resolved')");
                $lk->execute([$id]);
                $linked = (int)$lk->fetchColumn();
                $manual=$db->prepare("SELECT COALESCE(SUM(GREATEST(COALESCE(incident_quantity,quantity_needed)-resolved_quantity,0)),0) FROM replacement_requests WHERE tool_id=? AND reason='lost' AND missing_report_id IS NULL AND inventory_status IN ('Missing','Confirmed Lost','Partially Closed')");$manual->execute([$id]);$linked+=(int)$manual->fetchColumn();
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
