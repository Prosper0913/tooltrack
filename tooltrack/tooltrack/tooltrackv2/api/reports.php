<?php
// ================================================================
//  api/reports.php
//  GET ?type=monthly[&month=YYYY-MM] → Week 1-4 chart data for a month
//  GET ?type=trend[&months=6|12|24]  → monthly history + forecast
//  GET ?type=category               → doughnut chart data
//  GET ?type=inventory               → JSON {columns, rows} for on-screen preview
//  GET ?type=inventory&download=1    → same data as a CSV download
//  (same pattern for type=transactions | borrowers | overdue | missing | unresolved | conditions | tool_dates | integrity)
//
//  Any logged-in user (Admin or Staff) can view AND export every
//  report — reporting is read-only, so there's no reason to split
//  it by role the way write actions are split.
// ================================================================
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/missing_lib.php';
require_once dirname(__DIR__) . '/includes/stock_lib.php';
require_once __DIR__ . '/transaction_report_lib.php';

requireLogin();
if($_SERVER['REQUEST_METHOD']==='GET')session_write_close();

$db   = getDB();
$type = $_GET['type'] ?? 'monthly';
$dl   = !empty($_GET['download']);
if($type==='loss_history'){require_once __DIR__.'/loss_report.php';lossHistoryReport($db,$dl);}

if($type==='damage_loss'){
    fail('Financial reports have been retired. Use Missing Tools, Return Conditions, or Inventory reports.',410);
}
// Registration and additions share one paginated report; daily totals cover every match.
if($type==='stock_history'){
    $from=trim((string)($_GET['date_from']??''));$to=trim((string)($_GET['date_to']??''));$params=[];$where='1=1';
    foreach([$from,$to] as $date){if($date!==''){$d=DateTime::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)fail('Use valid YYYY-MM-DD report dates.',422);}}
    if($from!==''&&$to!==''&&$from>$to)fail('From date cannot be after To date.',422);
    if($from!==''){$where.=' AND e.created_at>=?';$params[]=$from.' 00:00:00';}
    if($to!==''){$where.=' AND e.created_at<DATE_ADD(?,INTERVAL 1 DAY)';$params[]=$to;}
    $source='('.stockHistoryUnion().') e';
    $columns=['Date / Time','Event','Tool Name','SKU','Quantity','Previous Total','Updated Total','Recorded By','Remarks'];
    $sql="SELECT DATE_FORMAT(e.created_at,'%m/%d/%Y %l:%i %p'),e.event,e.tool_name,e.sku,COALESCE(CAST(e.quantity AS CHAR),'Unknown'),
        COALESCE(CAST(e.previous_stock AS CHAR),'Unknown'),COALESCE(CAST(e.updated_stock AS CHAR),'Unknown'),e.performed_by,e.remarks FROM $source WHERE $where ORDER BY e.created_at DESC,e.event_id DESC";
    if($dl){
        $stmt=$db->prepare($sql);$stmt->execute($params);
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="stock_history_'.date('Ymd').'.csv"');
        $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$columns);
        while($row=$stmt->fetch(PDO::FETCH_NUM)){fputcsv($out,array_map(function($value){return is_string($value)&&preg_match('/^[=+@\-\t\r]/',$value)?"'".$value:$value;},$row));}fclose($out);exit;
    }
    $originalWhere=$where;
    $search=trim((string)($_GET['search']??''));$event=trim((string)($_GET['category']??''));
    if($search!==''){$where.=' AND (LOCATE(?,e.tool_name)>0 OR LOCATE(?,e.sku)>0)';$params[]=$search;$params[]=$search;}
    if($event!==''){$where.=' AND e.event=?';$params[]=$event;}
    $sorts=['Date / Time'=>'e.created_at','Tool'=>'e.tool_name','Event'=>'e.event','Quantity'=>'e.quantity','Previous Total'=>'e.previous_stock','Updated Total'=>'e.updated_stock','Recorded By'=>'e.performed_by','Remarks'=>'e.remarks'];
    $sort=$sorts[(string)($_GET['sort']??'Date / Time')]??'e.created_at';
    $direction=strtolower((string)($_GET['direction']??'desc'))==='asc'?'ASC':'DESC';
    $size=(int)($_GET['per_page']??25);if(!in_array($size,[25,50,100],true))$size=25;
    $stmt=$db->prepare("SELECT COUNT(*) FROM $source WHERE $where");$stmt->execute($params);$total=(int)$stmt->fetchColumn();
    $page=max(1,min(max(1,(int)ceil($total/$size)),(int)($_GET['page']??1)));$offset=($page-1)*$size;
    $sql=str_replace("WHERE $originalWhere ORDER BY e.created_at DESC,e.event_id DESC","WHERE $where ORDER BY $sort $direction,e.event_id DESC",$sql);
    $stmt=$db->prepare($sql." LIMIT $size OFFSET $offset");$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_NUM);
    $dailySql="SELECT DATE(e.created_at) AS day,DATE_FORMAT(e.created_at,'%m/%d/%Y') AS date,
       SUM(CASE WHEN e.event IN ('New Tool','Stock Added','Recovery after Write-off') THEN COALESCE(e.quantity,0) ELSE 0 END) AS units_added,
       SUM(e.event='New Tool') AS tools_registered,
       SUM(CASE WHEN e.event='Stock Added' THEN e.quantity ELSE 0 END) AS restocked_units,
       SUM(CASE WHEN e.event IN ('Stock Removed','Write Off as Lost') THEN e.quantity ELSE 0 END) AS units_removed,
       SUM(e.event='New Tool' AND e.quantity IS NULL) AS unknown_registrations
       FROM $source WHERE $where GROUP BY DATE(e.created_at) ORDER BY day DESC";
    $stmt=$db->prepare($dailySql);$stmt->execute($params);$daily=$stmt->fetchAll();
    $totals=['units_added'=>0,'tools_registered'=>0,'restocked_units'=>0,'units_removed'=>0,'unknown_registrations'=>0];
    foreach($daily as &$day){foreach($totals as $key=>$unused){$day[$key]=(int)$day[$key];$totals[$key]+=$day[$key];}}unset($day);
    ok(['columns'=>$columns,'rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$size,'categories'=>['New Tool','Stock Added','Stock Removed','Write Off as Lost','Recovery after Write-off'],'daily'=>$daily,'totals'=>$totals]);
}
// ONE clock for the whole file: the database's. created_at is stamped by MySQL,
// so month boundaries / "this month" / pace must be computed from the same clock
// (PHP's date() can disagree near midnight / month-end if time zones differ).
$dbToday = (string)$db->query('SELECT CURDATE()')->fetchColumn();   // 'YYYY-MM-DD'
$nowTs   = strtotime($dbToday);
$curYmDb = date('Y-m', $nowTs);

// ── Helpers for the monthly / trend reports ──────────────────
function monthLabel(string $ym): string {
    return date('M Y', strtotime($ym . '-01'));
}

// Next-N-periods forecast from a list of past monthly totals.
//  • 3+ months of history → least-squares trend line (captures growth/decline)
//  • 1-2 months           → simple average (too little data for a trend)
// Results are clamped to 0..2× the historical max so one odd month can't
// produce a silly projection.
function forecastSeries(array $y, int $h): array {
    $y = array_values($y);
    $n = count($y);
    if ($n === 0) return array_fill(0, $h, 0);
    if ($n < 3)   return array_fill(0, $h, (int)round(array_sum($y) / $n));
    $cap = max($y) * 2;
    $sx = $sy = $sxy = $sxx = 0;
    foreach ($y as $x => $v) { $sx += $x; $sy += $v; $sxy += $x * $v; $sxx += $x * $x; }
    $den   = $n * $sxx - $sx * $sx;
    $slope = $den ? ($n * $sxy - $sx * $sy) / $den : 0;
    $icpt  = ($sy - $slope * $sx) / $n;
    $out = [];
    for ($k = 0; $k < $h; $k++) {
        $out[] = (int)round(max(0, min($cap, $icpt + $slope * ($n + $k))));
    }
    return $out;
}

// ── Monthly borrow/return, Week 1–4 of a chosen month ────────
//  GET ?type=monthly&month=YYYY-MM   (defaults to the current month)
//  Week 1 = days 1-7, Week 2 = 8-14, Week 3 = 15-21,
//  Week 4 = day 22 to the END of the month (so days 29-31 are never lost).
if ($type === 'monthly') {
    $month = $_GET['month'] ?? $curYmDb;
    if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        fail('Invalid month. Use YYYY-MM.', 400);
    }
    $monthStart  = $month . '-01';
    $monthEnd    = date('Y-m-d', strtotime($monthStart . ' +1 month')); // exclusive
    $prevStart   = date('Y-m-d', strtotime($monthStart . ' -1 month'));
    $daysInMonth = (int)date('t', strtotime($monthStart));

    $labels  = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];
    $ranges  = ['1–7', '8–14', '15–21', '22–' . $daysInMonth];
    $borrows = [0, 0, 0, 0];
    $returns = [0, 0, 0, 0];

    // One grouped query; MySQL assigns each day to a bucket 0..3.
    $q = $db->prepare("
        SELECT LEAST(FLOOR((DAY(created_at) - 1) / 7), 3) AS wk, type, " . sqlReturnEventCount() . " AS cnt
        FROM transactions
        WHERE type IN ('borrow','return')
          AND created_at >= ? AND created_at < ?
        GROUP BY wk, type
    ");
    $q->execute([$monthStart . ' 00:00:00', $monthEnd . ' 00:00:00']);
    foreach ($q->fetchAll() as $r) {
        $w = (int)$r['wk'];
        if ($w < 0 || $w > 3) continue;
        if ($r['type'] === 'borrow') $borrows[$w] += (int)$r['cnt'];
        else                         $returns[$w] += (int)$r['cnt'];
    }

    // Previous month's totals, for the "vs last month" comparison.
    $pq = $db->prepare("
        SELECT type, " . sqlReturnEventCount() . " AS cnt FROM transactions
        WHERE type IN ('borrow','return') AND created_at >= ? AND created_at < ?
        GROUP BY type
    ");
    $pq->execute([$prevStart . ' 00:00:00', $monthStart . ' 00:00:00']);
    $prev = ['borrow' => 0, 'return' => 0];
    foreach ($pq->fetchAll() as $r) $prev[$r['type']] = (int)$r['cnt'];

    // Months the dropdown can offer: first month with any transaction → now.
    $minRow = $db->query("SELECT MIN(created_at) FROM transactions")->fetchColumn();
    $ym     = $curYmDb;
    $floor  = $minRow ? date('Y-m', strtotime($minRow)) : $ym;
    $avail  = [];
    for ($i = 0; $i < 36 && $ym >= $floor; $i++) {
        $avail[] = ['value' => $ym, 'label' => monthLabel($ym)];
        $ym = date('Y-m', strtotime($ym . '-01 -1 month'));
    }

    ok([
        'month'            => $month,
        'month_label'      => monthLabel($month),
        'is_current'       => $month === $curYmDb,
        'labels'           => $labels,
        'ranges'           => $ranges,
        'borrows'          => $borrows,
        'returns'          => $returns,
        'total_borrows'    => array_sum($borrows),
        'total_returns'    => array_sum($returns),
        'prev_borrows'     => $prev['borrow'],
        'prev_returns'     => $prev['return'],
        'available_months' => $avail,
    ]);
}

// ── Monthly trend + forecast ─────────────────────────────────
//  GET ?type=trend&months=6|12|24
//  History = last N COMPLETE months + the current month (in progress).
//  Forecast = this month (full-month estimate) and the next two, modelled
//  on complete months only so a half-finished month doesn't skew it.
if ($type === 'trend') {
    $n = (isset($_GET['months']) && is_string($_GET['months'])) ? (int)$_GET['months'] : 6;
    if (!in_array($n, [6, 12, 24], true)) $n = 6;

    $curYm   = $curYmDb;
    $firstYm = date('Y-m', strtotime($curYm . '-01 -' . $n . ' months'));

    $months = [];
    for ($ym = $firstYm; $ym <= $curYm; $ym = date('Y-m', strtotime($ym . '-01 +1 month'))) {
        $months[$ym] = ['b' => 0, 'r' => 0];
    }

    $rangeEnd = date('Y-m-d', strtotime($curYm . '-01 +1 month'));
    $q = $db->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, type, " . sqlReturnEventCount() . " AS cnt
        FROM transactions
        WHERE type IN ('borrow','return') AND created_at >= ? AND created_at < ?
        GROUP BY ym, type
    ");
    $q->execute([$firstYm . '-01 00:00:00', $rangeEnd . ' 00:00:00']);
    foreach ($q->fetchAll() as $r) {
        if (!isset($months[$r['ym']])) continue;
        $months[$r['ym']][$r['type'] === 'borrow' ? 'b' : 'r'] = (int)$r['cnt'];
    }

    $keys   = array_keys($months);
    $L      = count($keys);                      // includes the current month
    $labels = array_map('monthLabel', $keys);
    $bAct   = array_column($months, 'b');
    $rAct   = array_column($months, 'r');

    // Model input: complete months only, starting at the first month that
    // had any activity (so a system that launched recently isn't dragged
    // down by empty months before it existed).
    $cb = array_slice($bAct, 0, $L - 1);
    $cr = array_slice($rAct, 0, $L - 1);
    $first = 0;
    while ($first < count($cb) && $cb[$first] + $cr[$first] === 0) $first++;
    $cb = array_slice($cb, $first);
    $cr = array_slice($cr, $first);
    $used = count($cb);

    // Axis = history (incl. current) + 2 future months.
    $fy1 = date('Y-m', strtotime($curYm . '-01 +1 month'));
    $fy2 = date('Y-m', strtotime($curYm . '-01 +2 month'));
    $labels[] = monthLabel($fy1);
    $labels[] = monthLabel($fy2);

    $borrows = array_merge($bAct, [null, null]);
    $returns = array_merge($rAct, [null, null]);
    $fB = $fR = array_fill(0, $L + 2, null);
    $forecast = [];

    if ($used > 0) {
        $pb = forecastSeries($cb, 3);
        $pr = forecastSeries($cr, 3);
        // Line starts at the last complete month so it visually connects.
        if ($L >= 2) { $fB[$L - 2] = $bAct[$L - 2]; $fR[$L - 2] = $rAct[$L - 2]; }
        for ($k = 0; $k < 3; $k++) {
            $fB[$L - 1 + $k] = $pb[$k];
            $fR[$L - 1 + $k] = $pr[$k];
            $ymK = [$curYm, $fy1, $fy2][$k];
            $forecast[] = [
                'month'      => $ymK,
                'label'      => monthLabel($ymK),
                'borrows'    => $pb[$k],
                'returns'    => $pr[$k],
                'is_current' => $k === 0,
            ];
        }
    }

    // "On pace" = this month's activity so far, scaled to a full month.
    $elapsed = max(1, (int)date('j', $nowTs));
    $dim     = (int)date('t', $nowTs);
    $pace = [
        'borrows'       => (int)round($bAct[$L - 1] / $elapsed * $dim),
        'returns'       => (int)round($rAct[$L - 1] / $elapsed * $dim),
        'days_elapsed'  => $elapsed,
        'days_in_month' => $dim,
    ];

    ok([
        'labels'           => $labels,
        'borrows'          => $borrows,
        'returns'          => $returns,
        'forecast_borrows' => $fB,
        'forecast_returns' => $fR,
        'current_index'    => $L - 1,
        'forecast'         => $forecast,
        'months_used'      => $used,
        'confidence'       => $used === 0 ? 'none' : ($used < 3 ? 'low' : ($used < 6 ? 'medium' : 'good')),
        'pace'             => $pace,
    ]);
}

// ── Category distribution ─────────────────────────────────────
if ($type === 'category') {
    $stmt = $db->query("SELECT category, SUM(quantity) AS total FROM tools GROUP BY category ORDER BY total DESC");
    $rows = $stmt->fetchAll();
    ok([
        'labels' => array_column($rows, 'category'),
        'values' => array_map(fn($r) => (int)$r['total'], $rows),
    ]);
}

// ── Overdue summary for the notification bell ───────────────
//  Returns the TRUE count plus only the 20 most overdue rows, so the bell
//  never downloads the whole overdue list.
if ($type === 'overdue_summary') {
    $outst = sqlOutstanding('t');
    $count = (int)$db->query("SELECT COUNT(DISTINCT t.txn_id) FROM transactions t
                              WHERE t.type='borrow' AND t.status='active' AND $outst > 0 AND " . sqlDueAt('t') . " < NOW()")->fetchColumn();
    $follow = (int)$db->query("SELECT COUNT(DISTINCT t.txn_id) FROM transactions t
                              WHERE t.type='borrow' AND t.status='active' AND $outst > 0
                                AND DATEDIFF(CURDATE(), t.due_date) >= " . OVERDUE_FOLLOWUP_DAYS)->fetchColumn();
    $pendingMissing = (int)$db->query("SELECT COUNT(*) FROM missing_reports WHERE status='pending'")->fetchColumn();
    // Row order is positional (the bell reads it by index); the new days-overdue value is appended LAST.
    $st = $db->query("SELECT t.txn_id, tl.code, tl.name, COALESCE(b.full_name, t.borrower_name_snapshot), COALESCE(b.id_number, t.borrower_id_number_snapshot), t.due_date,
                             ".sqlOverdueDays('t').", ".sqlOutstanding('t').",t.created_at,t.due_time
                      FROM transactions t
                      LEFT JOIN tools tl ON tl.id = t.tool_id
                      LEFT JOIN borrowers b ON b.id = t.borrower_id
                      WHERE t.type='borrow' AND t.status='active' AND $outst > 0 AND " . sqlDueAt('t') . " < NOW()
                      ORDER BY t.due_date ASC LIMIT 20");
    $dueWhere="t.type='borrow' AND t.status='active' AND $outst>0 AND t.due_date=CURDATE() AND ".sqlDueAt('t').">=NOW()";
    $dueToday=(int)$db->query("SELECT COUNT(DISTINCT t.txn_id) FROM transactions t WHERE $dueWhere")->fetchColumn();
    $dueRows=$db->query("SELECT t.txn_id,tl.code,tl.name,COALESCE(b.full_name,t.borrower_name_snapshot),t.due_date,t.due_time,$outst outstanding FROM transactions t LEFT JOIN tools tl ON tl.id=t.tool_id LEFT JOIN borrowers b ON b.id=t.borrower_id WHERE $dueWhere ORDER BY t.due_time,t.id LIMIT 20")->fetchAll(PDO::FETCH_NUM);
    $activeBorrows=(int)$db->query("SELECT COUNT(DISTINCT t.txn_id) FROM transactions t WHERE t.type='borrow' AND t.status='active' AND $outst>0")->fetchColumn();
    ok(['active_borrows'=>$activeBorrows,'due_today_count'=>$dueToday,'due_today_rows'=>$dueRows,'count' => $count, 'followup_count' => $follow, 'followup_days' => OVERDUE_FOLLOWUP_DAYS,
        'pending_missing' => $pendingMissing, 'rows' => $st->fetchAll(PDO::FETCH_NUM)]);
}

// ── Tabular reports (inventory / transactions / borrowers / overdue) ──
// One definition per report: display column labels + the SQL that
// produces rows in that same order. Used for both the on-screen
// preview (JSON) and the CSV export, so the two can never drift apart.
$reportDefs = [
    'inventory' => [
        'filename' => 'inventory',
        // Total = Good + Minor Wear + Damaged + Missing + Borrowed (each unit is in exactly one).
        // Available = Good + Minor Wear (what can be lent right now).
        'columns'  => ['Name', 'Code', 'Category', 'Total Quantity', 'Good', 'Minor Wear', 'Damaged', 'Missing', 'Borrowed', 'Available', 'Min Stock', 'Description'],
        'count'    => 'SELECT COUNT(*) FROM tools',
        'sql'      => 'SELECT name, code, category, quantity,
                              (available - minor_qty), minor_qty, damaged_qty, missing_qty,
                              (quantity - available - damaged_qty - missing_qty), available,
                              min_stock, description
                        FROM tools ORDER BY name',
    ],
    // Date-added lives here now (it was removed from the Tools table).
    'tool_dates' => [
        'filename' => 'tools_date_added',
        'columns'  => ['Tool Name', 'Tool ID', 'Category', 'Original Quantity', 'Current Quantity', 'Date Added', 'Last Updated'],
        'count'    => 'SELECT COUNT(*) FROM tools',
        'sql'      => "SELECT name, code, category, COALESCE(original_quantity, 'Unknown'), quantity, created_at, updated_at
                        FROM tools ORDER BY created_at DESC, id DESC",
    ],
    // Tools whose stored counts disagree with their real active loans (should be empty).
    'integrity' => [
        'filename' => 'inventory_integrity',
        'columns'  => ['Tool Name', 'Code', 'Total', 'Available', 'Damaged', 'Missing', 'Borrowed (by counts)', 'Borrowed (by loans)', 'Problem'],
        'count'    => "SELECT COUNT(*) FROM tools t
                        LEFT JOIN (SELECT tool_id, SUM(qty - qty_returned - qty_missing) AS o FROM transactions
                                   WHERE type='borrow' AND status='active' GROUP BY tool_id) l ON l.tool_id = t.id
                        WHERE (t.quantity - t.available - t.damaged_qty - t.missing_qty) <> COALESCE(l.o, 0)
                           OR t.available < 0 OR t.minor_qty < 0 OR t.damaged_qty < 0 OR t.missing_qty < 0
                           OR t.minor_qty > t.available
                           OR t.available + t.damaged_qty + t.missing_qty > t.quantity",
        'sql'      => "SELECT t.name, t.code, t.quantity, t.available, t.damaged_qty, t.missing_qty,
                              (t.quantity - t.available - t.damaged_qty - t.missing_qty),
                              COALESCE(l.o, 0),
                              CASE WHEN t.available < 0 OR t.minor_qty < 0 OR t.damaged_qty < 0 OR t.missing_qty < 0 THEN 'Negative count'
                                   WHEN t.minor_qty > t.available THEN 'Minor wear exceeds available'
                                   WHEN t.available + t.damaged_qty + t.missing_qty > t.quantity THEN 'Counts exceed total'
                                   ELSE 'Borrowed count differs from active loans' END
                        FROM tools t
                        LEFT JOIN (SELECT tool_id, SUM(qty - qty_returned - qty_missing) AS o FROM transactions
                                   WHERE type='borrow' AND status='active' GROUP BY tool_id) l ON l.tool_id = t.id
                        WHERE (t.quantity - t.available - t.damaged_qty - t.missing_qty) <> COALESCE(l.o, 0)
                           OR t.available < 0 OR t.minor_qty < 0 OR t.damaged_qty < 0 OR t.missing_qty < 0
                           OR t.minor_qty > t.available
                           OR t.available + t.damaged_qty + t.missing_qty > t.quantity
                        ORDER BY t.name",
    ],
    'transactions' => [
        'filename' => 'transactions',
        // "Borrowed At": for a borrow row this is just its own
        // created_at; for a return row it's the ORIGINAL borrow's
        // created_at, found via the borrow_txn_id link set when the
        // return was recorded (see transactions.php). Falls back to
        // the row's own created_at if that link isn't set (e.g. a
        // return recorded before this link existed and not covered
        // by the backfill migration).
        'columns'  => ['TXN ID', 'Type', 'Tool Code', 'Tool Name', 'Borrower', 'Status', 'Condition', 'Notes', 'Due Date', 'Returned At', 'Borrowed At', 'Qty Borrowed', 'Qty Returned', 'Qty Missing'],
        'count'    => 'SELECT COUNT(*) FROM transactions',
        'sql'      => "SELECT t.txn_id, t.type, tl.code AS tool_code, tl.name AS tool_name,
                               COALESCE(b.full_name, t.borrower_name_snapshot) AS borrower, t.status, t.condition, t.notes,
                               t.due_date, t.returned_at,
                               COALESCE(bt.created_at, t.created_at) AS borrowed_at,
                               CASE WHEN t.type='borrow' THEN t.qty ELSE 0 END AS qty_borrowed,
                               CASE WHEN t.type='return' THEN CASE WHEN t.`condition`='missing' THEN 0 ELSE t.qty END ELSE t.qty_returned END AS qty_returned,
                               t.qty_missing
                        FROM transactions t
                        LEFT JOIN tools tl ON tl.id = t.tool_id
                        LEFT JOIN borrowers b ON b.id = t.borrower_id
                        LEFT JOIN transactions bt ON bt.id = t.borrow_txn_id
                        ORDER BY t.created_at DESC, t.id DESC",
    ],
    'borrowers' => [
        'filename' => 'borrowers',
        'columns'  => ['Full Name', 'ID Number', 'Type', 'Phone', 'Active Borrows', 'Total Borrows'],
        'count'    => 'SELECT COUNT(*) FROM borrowers',
        'sql'      => 'SELECT full_name, id_number, type, phone, active_borrows, total_borrows
                        FROM borrowers ORDER BY full_name',
    ],
    // Returns whose condition was NOT "good": minor wear, damaged, or missing/lost.
    'conditions' => [
        'filename' => 'tool_conditions',
        'columns'  => ['TXN ID', 'Tool Code', 'Tool Name', 'Condition', 'Qty Returned', 'Returned By', 'Notes', 'Date Returned'],
        'count'    => "SELECT COUNT(*) FROM transactions WHERE type='return' AND `condition` IN ('minor','damaged','missing')",
        'sql'      => "SELECT t.txn_id, tl.code, tl.name,
                               CASE t.`condition`
                                   WHEN 'minor'   THEN 'Minor Wear'
                                   WHEN 'damaged' THEN 'Damaged'
                                   WHEN 'missing' THEN 'Missing / Lost'
                                   ELSE t.`condition`
                               END AS cond_label,
                               t.qty,
                               COALESCE(b.full_name, t.borrower_name_snapshot),
                               t.notes, t.created_at
                        FROM transactions t
                        LEFT JOIN tools tl ON tl.id = t.tool_id
                        LEFT JOIN borrowers b ON b.id = t.borrower_id
                        WHERE t.type='return' AND t.`condition` IN ('minor','damaged','missing')
                        ORDER BY t.created_at DESC, t.id DESC",
    ],
    // One row per RETURN EVENT with the quantity in each condition (Good / Minor / Damaged / Missing).
    'returns' => [
        'filename' => 'returns_by_condition',
        'columns'  => ['Tool Name', 'Borrower', 'Return Date', 'Good', 'Minor Wear', 'Damaged at Return', 'Missing Reported', 'Handed Back', 'Tool Code', 'Returned By', 'Return Ref', 'Loan TXN', 'Missing Case', 'Missing Case Status', 'Notes'],
        'count'    => "SELECT COUNT(DISTINCT COALESCE(return_batch, txn_id)) FROM transactions WHERE type='return'",
        'sql'      => "SELECT tl.name, COALESCE(b.full_name, MIN(r.borrower_name_snapshot)), MIN(r.created_at),
                               SUM(CASE WHEN r.`condition`='good' THEN r.qty ELSE 0 END),
                               SUM(CASE WHEN r.`condition`='minor' THEN r.qty ELSE 0 END),
                               SUM(CASE WHEN r.`condition`='damaged' THEN r.qty ELSE 0 END),
                               MAX(mr.qty_reported), SUM(r.qty),tl.code,MIN(r.returnee_name),COALESCE(r.return_batch, MIN(r.txn_id)),bt.txn_id,
                               MAX(mr.report_no), MAX(mr.status), MIN(r.notes)
                        FROM transactions r
                        LEFT JOIN transactions bt ON bt.id = r.borrow_txn_id
                        LEFT JOIN tools tl ON tl.id = r.tool_id
                        LEFT JOIN borrowers b ON b.id = r.borrower_id
                        LEFT JOIN missing_reports mr ON CONVERT(mr.request_token USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(r.return_batch USING utf8mb4) COLLATE utf8mb4_unicode_ci AND r.return_batch IS NOT NULL
                        WHERE r.type='return'
                        GROUP BY COALESCE(r.return_batch, r.txn_id), bt.txn_id, tl.code, tl.name, b.full_name
                        ORDER BY MIN(r.created_at) DESC",
    ],
    // Active loans past their due date. FOLLOW-UP = overdue OVERDUE_FOLLOWUP_DAYS or more.
    // Nothing here is "missing" - that only happens through a confirmed missing-tool case.
    'overdue' => [
        'filename' => 'overdue',
        'columns'  => ['TXN ID', 'Tool Code', 'Tool Name', 'Borrower Name', 'Borrower ID', 'Due Date', 'Borrowed On', 'Qty Outstanding', 'Days Overdue', 'Status', 'Pending Missing Report'],
        'count'    => "SELECT COUNT(*) FROM transactions t WHERE t.type='borrow' AND t.status='active' AND (t.qty - t.qty_returned - t.qty_missing) > 0 AND " . sqlDueAt('t') . " < NOW()",
        'sql'      => "SELECT t.txn_id, tl.code, tl.name, COALESCE(b.full_name, t.borrower_name_snapshot), COALESCE(b.id_number, t.borrower_id_number_snapshot), t.due_date, t.created_at,
                               (t.qty - t.qty_returned - t.qty_missing),
                               ".sqlOverdueDays('t').",
                               CASE WHEN DATEDIFF(CURDATE(), t.due_date) >= " . OVERDUE_FOLLOWUP_DAYS . " THEN 'Follow-up needed' ELSE 'Overdue' END,
                               (SELECT mr.report_no FROM missing_reports mr WHERE mr.borrow_txn_id = t.id AND mr.status = 'pending' LIMIT 1)
                        FROM transactions t
                        LEFT JOIN tools tl ON tl.id = t.tool_id
                        LEFT JOIN borrowers b ON b.id = t.borrower_id
                        WHERE t.type='borrow' AND t.status='active' AND (t.qty - t.qty_returned - t.qty_missing) > 0 AND " . sqlDueAt('t') . " < NOW()
                        ORDER BY t.due_date ASC",
    ],
    // Every missing-tool case (history is kept): who reported / confirmed / recovered and when.
    'missing' => [
        'filename' => 'missing_tools',
        'columns'  => ['Case No', 'Status', 'Tool Code', 'Tool Name', 'Borrower', 'Borrower ID', 'Loan TXN', 'Date Missing', 'Qty Reported', 'Qty Confirmed Missing',
                       'Qty Closed (historical)', 'Qty Recovered', 'Qty Still Missing', 'Reason', 'Investigation Notes',
                       'Reported By', 'Reported At', 'Confirmed/Rejected By', 'Confirmed/Rejected At',
                       'Last Recovered By', 'Last Recovered At', 'Resolved At'],
        'count'    => 'SELECT COUNT(*) FROM missing_reports',
        'sql'      => "SELECT mr.report_no,
                               CASE mr.status WHEN 'pending' THEN 'Pending confirmation' WHEN 'confirmed' THEN 'Missing (unresolved)'
                                              WHEN 'resolved' THEN 'Resolved' ELSE 'Rejected' END,
                               tl.code, tl.name, COALESCE(b.full_name, mr.borrower_name_snapshot), COALESCE(b.id_number, mr.borrower_id_number_snapshot),
                               t.txn_id, mr.missing_date, mr.qty_reported, mr.qty_missing, mr.qty_replaced, mr.qty_recovered,
                               CASE WHEN mr.status IN ('confirmed','resolved') THEN GREATEST(mr.qty_missing - mr.qty_replaced - (mr.qty_recovered - mr.qty_surplus) - mr.qty_written_off + mr.qty_late_recovered, 0) ELSE 0 END,
                               mr.reason, mr.investigation_notes,
                               mr.reported_by_name, mr.reported_at, mr.confirmed_by_name, mr.confirmed_at,
                               (SELECT e.user_name  FROM missing_report_events e WHERE e.report_id = mr.id AND e.action = 'recovered' ORDER BY e.id DESC LIMIT 1),
                               (SELECT e.created_at FROM missing_report_events e WHERE e.report_id = mr.id AND e.action = 'recovered' ORDER BY e.id DESC LIMIT 1),
                               mr.resolved_at
                        FROM missing_reports mr
                        LEFT JOIN tools tl ON tl.id = mr.tool_id
                        LEFT JOIN transactions t ON t.id = mr.borrow_txn_id
                        LEFT JOIN borrowers b ON b.id = mr.borrower_id
                        ORDER BY mr.reported_at DESC, mr.id DESC",
    ],
    // Only the cases still needing action: waiting for confirmation, or confirmed and not yet recovered.
    'unresolved' => [
        'filename' => 'unresolved_missing_tools',
        'columns'  => ['Case No', 'Status', 'Tool Code', 'Tool Name', 'Borrower', 'Date Missing', 'Qty Still Missing', 'Days Open', 'Reported By', 'Reason'],
        'count'    => "SELECT COUNT(*) FROM missing_reports WHERE status IN ('pending','confirmed')",
        'sql'      => "SELECT mr.report_no, CASE mr.status WHEN 'pending' THEN 'Pending confirmation' ELSE 'Missing (unresolved)' END,
                               tl.code, tl.name, COALESCE(b.full_name, mr.borrower_name_snapshot), mr.missing_date,
                               CASE WHEN mr.status = 'pending' THEN mr.qty_reported
                                    ELSE GREATEST(mr.qty_missing - mr.qty_replaced - (mr.qty_recovered - mr.qty_surplus) - mr.qty_written_off + mr.qty_late_recovered, 0) END,
                               DATEDIFF(CURDATE(), mr.missing_date), mr.reported_by_name, mr.reason
                        FROM missing_reports mr
                        LEFT JOIN tools tl ON tl.id = mr.tool_id
                        LEFT JOIN borrowers b ON b.id = mr.borrower_id
                        WHERE mr.status IN ('pending','confirmed')
                        ORDER BY mr.missing_date ASC, mr.id ASC",
    ],
];

// Spreadsheet apps treat a cell starting with = + - @ as a FORMULA. Notes,
// names etc. are free text typed by users, so neutralise them in exports.
function csvSafe($v) {
    if (is_string($v) && $v !== '' && !is_numeric($v) && strpos("=+-@\t\r", $v[0]) !== false) return "'" . $v;
    return $v;
}

if ($type === 'returns' && !txnHasReturnBatch()) fail('Run sql/002_quantity_returns.sql to enable this report.', 400);
if (isset($reportDefs[$type])) {
    $def = $reportDefs[$type];
    if($type==='missing'){
        require_once __DIR__.'/writeoff_lib.php';
        $old="CASE mr.status WHEN 'pending' THEN 'Pending confirmation' WHEN 'confirmed' THEN 'Missing (unresolved)'\n                                              WHEN 'resolved' THEN 'Resolved' ELSE 'Rejected' END";
        $def['sql']=str_replace($old,lossOutcomeSql('missing','mr',$old),$def['sql']);
        array_push($def['columns'],'Qty Written Off','Qty Later Recovered','Net Outstanding Loss','Write-off Reason','Approved By','Approval Date');
        $extra="mr.resolved_at, mr.qty_written_off, mr.qty_late_recovered, GREATEST(0,mr.qty_written_off-mr.qty_late_recovered),
           (SELECT GROUP_CONCAT(w.reason SEPARATOR '; ') FROM loss_writeoffs w WHERE w.source='missing' AND w.incident_id=mr.id AND w.status='approved'),
           (SELECT GROUP_CONCAT(w.decision_name SEPARATOR '; ') FROM loss_writeoffs w WHERE w.source='missing' AND w.incident_id=mr.id AND w.status='approved'),
           (SELECT MAX(w.decided_at) FROM loss_writeoffs w WHERE w.source='missing' AND w.incident_id=mr.id AND w.status='approved')";
        $def['sql']=str_replace('mr.resolved_at',$extra,$def['sql']);
    }

    if ($dl) {
        // STREAM the export row by row. The old code fetchAll()'d the entire
        // table into PHP memory first, which dies (memory_limit) once a report
        // reaches tens of thousands of rows.
        @set_time_limit(0);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $def['filename'] . '_' . date('Ymd') . '.csv"');
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");                        // BOM so Excel reads UTF-8 (ñ, é…)
        fputcsv($out, $def['columns']);
        $stmt = $db->query($def['sql']);
        $i = 0;
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            fputcsv($out, array_map('csvSafe', $row));
            if ((++$i % 2000) === 0) flush();
        }
        fclose($out);
        exit;
    }

    if($type==='inventory'){
        $search=trim((string)($_GET['search']??''));$category=trim((string)($_GET['category']??''));
        $perPage=(int)($_GET['per_page']??25);if(!in_array($perPage,[25,50,100],true))$perPage=25;
        $sort=(string)($_GET['sort']??'Name');$sortIndex=array_search($sort,$def['columns'],true);
        if($sortIndex===false)$sortIndex=0;
        $direction=strtolower((string)($_GET['direction']??'asc'))==='desc'?'DESC':'ASC';
        $where=[];$params=[];
        if($search!==''){$where[]='(LOCATE(?,name)>0 OR LOCATE(?,code)>0)';$params[]=$search;$params[]=$search;}
        if($category!==''){$where[]='category=?';$params[]=$category;}
        $filter=$where?' WHERE '.implode(' AND ',$where):'';
        $count=$db->prepare('SELECT COUNT(*) FROM tools'.$filter);$count->execute($params);$total=(int)$count->fetchColumn();
        $page=max(1,min(max(1,(int)ceil($total/$perPage)),(int)($_GET['page']??1)));$offset=($page-1)*$perPage;
        $sql=substr($def['sql'],0,strrpos($def['sql'],' ORDER BY name')).$filter.' ORDER BY '.($sortIndex+1).' '.$direction.', id ASC LIMIT '.$perPage.' OFFSET '.$offset;
        $stmt=$db->prepare($sql);$stmt->execute($params);
        $categories=$db->query("SELECT DISTINCT category FROM tools WHERE category IS NOT NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
        ok(['columns'=>$def['columns'],'rows'=>$stmt->fetchAll(PDO::FETCH_NUM),'total'=>$total,'page'=>$page,'per_page'=>$perPage,'categories'=>$categories]);
    }

    if(in_array($type,['transactions','overdue','missing'],true)){
        // Alias the existing SELECT expressions for safe outer filtering/sorting.
        // Keep the shared report SQL (and CSV fields/calculations) authoritative.
        $base=substr($def['sql'],0,strrpos($def['sql'],'ORDER BY'));
        $depth=0;$quote='';$parts=[];$part='';$from='';
        for($i=6,$length=strlen($base);$i<$length;$i++){
            $ch=$base[$i];
            if($quote!==''){
                $part.=$ch;
                if($ch==='\\'&&$i+1<$length){$part.=$base[++$i];continue;}
                if($ch===$quote)$quote='';
                continue;
            }
            if(in_array($ch,["'",'"','`'],true)){$quote=$ch;$part.=$ch;continue;}
            if($ch==='(')$depth++;if($ch===')')$depth--;
            if($depth===0&&preg_match('/^\s+FROM\s/i',substr($base,$i))){$parts[]=trim($part);$from=substr($base,$i);break;}
            if($depth===0&&$ch===','){$parts[]=trim($part);$part='';}else $part.=$ch;
        }
        if(count($parts)!==count($def['columns']))throw new RuntimeException('Report column mapping mismatch.');
        $select=[];foreach($parts as $i=>$expression)$select[]=preg_replace('/\s+AS\s+[A-Za-z_][A-Za-z0-9_]*\s*$/i','',$expression).' AS c'.$i;
        $missing=$type==='missing';$alias=$missing?'mr':'t';
        $select[]="$alias.id AS _row_id";
        $select[]=($missing?'mr.reported_at':'t.created_at').' AS _created';
        if(!$missing){
            $returnEvent=txnHasReturnBatch()?"COALESCE(t.return_batch,t.txn_id)":'t.txn_id';
            $select[]="CASE WHEN t.type='borrow' THEN CONCAT('B:',t.txn_id) ELSE CONCAT('R:',$returnEvent) END AS _event_key";
            foreach(['type','status','qty','qty_returned','qty_missing','due_date'] as $field)$select[]="t.$field AS _$field";
            $select[]=sqlDueAt('t').' AS _due_at';
            $select[]=sqlOverdueDays('t').' AS _overdue_days';
            $select[]="(SELECT r.id FROM missing_reports r WHERE r.borrow_txn_id=t.id AND r.status='pending' LIMIT 1) AS _pending_id";
            $select[]="(SELECT COUNT(*) FROM missing_reports r WHERE r.borrow_txn_id=t.id AND r.status='confirmed') AS _open_missing";
            $select[]="(SELECT COUNT(*) FROM missing_reports r WHERE r.borrow_txn_id=t.id AND r.status='resolved') AS _resolved_missing";
            if($type==='transactions'){
                $borrowGroup=borrowSessionsReady($db)?"COALESCE(CONCAT('B:S:',t.borrow_session_id),CONCAT('B:T:',t.txn_id))":"CONCAT('B:T:',t.txn_id)";
                $select[]="CONVERT(CASE WHEN t.type='borrow' THEN $borrowGroup ELSE CONCAT('R:',$returnEvent) END USING utf8mb4) COLLATE utf8mb4_unicode_ci AS _group_key";
                $select[]="CASE WHEN t.type='borrow' THEN CONCAT('L:',t.id) ELSE COALESCE(CONCAT('L:',t.borrow_txn_id),CONCAT('E:',t.id)) END AS _detail_key";
                $select[]='t.tool_id AS _tool_id';
                $select[]="CASE WHEN t.type='borrow' THEN t.id ELSE t.borrow_txn_id END AS _borrow_line_id";
                $select[]="CASE WHEN t.type='borrow' THEN t.txn_id ELSE bt.txn_id END AS _original_txn_id";
                $select[]="COALESCE(bt.qty,t.qty) AS _original_qty";
                $select[]="CASE WHEN t.type='borrow' THEN (SELECT COALESCE(SUM(d.qty),0) FROM transactions d WHERE d.borrow_txn_id=t.id AND d.type='return' AND d.`condition`='damaged') WHEN t.`condition`='damaged' THEN t.qty ELSE 0 END AS _qty_damaged";
                $select[]=sqlIsOverdue('t').' AS _is_overdue';
                $reported=txnHasReturnBatch()?"(SELECT COALESCE(SUM(m.qty_reported),0) FROM missing_reports m WHERE CONVERT(m.request_token USING utf8mb4) COLLATE utf8mb4_unicode_ci=CONVERT(t.return_batch USING utf8mb4) COLLATE utf8mb4_unicode_ci AND m.borrow_txn_id=t.borrow_txn_id)":'0';
                $select[]="CASE WHEN t.type='borrow' THEN t.qty_missing WHEN t.`condition`='missing' THEN t.qty ELSE $reported END AS _missing_quantity";
            }
        }
        $source='(SELECT '.implode(',',$select).$from.') report_rows';
        if($type==='transactions')ok(groupedTransactionReport($db,$source,$def['columns'],$_GET));
        $where=[];$params=[];$search=trim((string)($_GET['search']??''));
        if($search!==''){
            $searchParts=[];foreach($def['columns'] as $i=>$column){$searchParts[]="LOCATE(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(COALESCE(c$i,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)>0";$params[]=$search;}
            $where[]='('.implode(' OR ',$searchParts).')';
        }
        $statusIndex=array_search('Status',$def['columns'],true);
        $status=trim((string)($_GET['category']??''));
        if($type==='transactions'&&$status!==''){
            $loan="_type='borrow'";$outstanding='(_qty-_qty_returned-_qty_missing)>0';
            if($status==='overdue')$where[]="$loan AND _status='active' AND $outstanding AND _due_at<NOW()";
            elseif($status==='followup')$where[]="$loan AND _status='active' AND $outstanding AND DATEDIFF(CURDATE(),DATE(_due_at))>=".OVERDUE_FOLLOWUP_DAYS;
            elseif($status==='due_today')$where[]="$loan AND _status='active' AND $outstanding AND DATE(_due_at)=CURDATE() AND _due_at>=NOW()";
            elseif($status==='missing')$where[]="$loan AND _qty_missing>0";
            else{$where[]="$loan AND _status=?";$params[]=$status;}
        }elseif($status!==''&&$statusIndex!==false){$where[]='c'.$statusIndex.'=?';$params[]=$status;}
        $filter=$where?' WHERE '.implode(' AND ',$where):'';
        $stmt=$db->prepare("SELECT COUNT(*) FROM $source$filter");$stmt->execute($params);$total=(int)$stmt->fetchColumn();
        $transactionCount=null;
        if(!$missing){$stmt=$db->prepare("SELECT COUNT(DISTINCT _event_key) FROM $source$filter");$stmt->execute($params);$transactionCount=(int)$stmt->fetchColumn();}
        $size=(int)($_GET['per_page']??25);if(!in_array($size,[25,50,100],true))$size=25;
        $page=max(1,min(max(1,(int)ceil($total/$size)),(int)($_GET['page']??1)));$offset=($page-1)*$size;
        $sortIndex=array_search((string)($_GET['sort']??''),$def['columns'],true);
        $sort=$sortIndex===false?'_created':'c'.$sortIndex;
        if($type==='transactions'&&($_GET['sort']??'')==='Transaction Date'){
            $borrowed=array_search('Borrowed At',$def['columns'],true);$returned=array_search('Returned At',$def['columns'],true);
            $sort="CASE WHEN _type='borrow' THEN c$borrowed WHEN _type='return' THEN c$returned ELSE NULL END";
        }
        $direction=strtolower((string)($_GET['direction']??'desc'))==='asc'?'ASC':'DESC';
        $stmt=$db->prepare("SELECT * FROM $source$filter ORDER BY $sort $direction,_row_id $direction LIMIT $size OFFSET $offset");$stmt->execute($params);
        $rows=[];$actions=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $values=[];foreach($def['columns'] as $i=>$unused)$values[]=$row['c'.$i];$rows[]=$values;
            $loanState=$missing?null:((int)$row['_open_missing']>0?'missing':($row['_status']==='active'?((int)$row['_overdue_days']>=OVERDUE_FOLLOWUP_DAYS?'followup':((int)$row['_overdue_days']>0?'overdue':'borrowed')):((int)$row['_resolved_missing']>0?'resolved':'returned')));
            $actions[]=$missing?['case_id'=>(int)$row['_row_id']]:[
                'id'=>(int)$row['_row_id'],'type'=>$row['_type'],'status'=>$row['_status'],
                'qty'=>(int)$row['_qty'],'qty_returned'=>(int)$row['_qty_returned'],'qty_missing'=>(int)$row['_qty_missing'],
                'due_date'=>$row['_due_date'],'created_at'=>$row['_created'],'overdue_days'=>(int)$row['_overdue_days'],'loan_state'=>$loanState,
                'txn_id'=>$values[array_search('TXN ID',$def['columns'],true)],
                'tool_code'=>$values[array_search('Tool Code',$def['columns'],true)],
                'tool_name'=>$values[array_search('Tool Name',$def['columns'],true)],
                'borrower'=>$values[array_search($type==='overdue'?'Borrower Name':'Borrower',$def['columns'],true)],
                'pending_report_id'=>$row['_pending_id']===null?null:(int)$row['_pending_id']
            ];
        }
        $categories=$statusIndex===false?[]:$db->query("SELECT DISTINCT c$statusIndex FROM $source ORDER BY c$statusIndex")->fetchAll(PDO::FETCH_COLUMN);
        if($type==='transactions')$categories=['active','overdue','due_today','followup','missing','returned'];
        ok(['columns'=>$def['columns'],'rows'=>$rows,'actions'=>$actions,'total'=>$total,'transaction_count'=>$transactionCount,'page'=>$page,'per_page'=>$size,'categories'=>$categories]);
    }

    // On-screen preview: only the newest PREVIEW_LIMIT rows. Rendering
    // thousands of <tr> through innerHTML freezes the browser; the full data
    // is always available via the CSV download.
    $limit = 200;
    $total = (int)$db->query($def['count'])->fetchColumn();
    $rows  = $db->query($def['sql'] . ' LIMIT ' . $limit)->fetchAll(PDO::FETCH_NUM);
    ok(['columns' => $def['columns'], 'rows' => $rows, 'total' => $total, 'limit' => $limit, 'truncated' => $total > count($rows)]);
}

fail('Unknown report type.', 400);
