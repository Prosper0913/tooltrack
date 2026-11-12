<?php
// ================================================================
//  api/dashboard.php
//  GET  → all stats, charts data, recent transactions, alerts
// ================================================================
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/missing_lib.php';

requireLogin();
session_write_close();

$db = getDB();

// ── Stats ─────────────────────────────────────────────────────
// Retired tools are excluded: they can't be borrowed, so counting them made
// "low stock" / totals wrong forever. One query instead of three.
$ts = $db->query("SELECT COUNT(*) AS tool_types, COALESCE(SUM(quantity),0) AS total, COALESCE(SUM(available),0) AS avail,
                         COALESCE(SUM(available <= min_stock),0) AS low,
                         COALESCE(SUM(missing_qty),0) AS missing,
                         COALESCE(SUM(damaged_qty),0) AS damaged
                  FROM tools WHERE is_active = 1")->fetch();
$totalTools     = (int)$ts['total'];
$availableTools = (int)$ts['avail'];
// Match Tools Management: unavailable units also include damaged and missing stock.
$damagedTools   = (int)$ts['damaged'];
$borrowedTools  = $totalTools - $availableTools - $damagedTools - (int)$ts['missing'];
$lowStockCount  = (int)$ts['low'];
$missingTools   = (int)$ts['missing'];   // units confirmed missing (tools.missing_qty); still counted in total_tools
$totalBorrowers = (int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn();
$activeBorrows  = (int)$db->query("SELECT COUNT(DISTINCT txn_id) FROM transactions WHERE type='borrow' AND status='active'")->fetchColumn();

// ── Last-7-days borrow / return chart ─────────────────────────
// ONE grouped query instead of 14. The day range is taken from the
// DATABASE clock (CURDATE) so it always matches how created_at was
// stored — PHP and MySQL can run in different time zones, which used
// to push borrows onto the wrong day (or hide them).
$today = $db->query('SELECT CURDATE()')->fetchColumn();

$days = [];                                   // 'Y-m-d' => ['label', borrows, returns]
for ($i = 6; $i >= 0; $i--) {
    $dt = new DateTime($today);
    $dt->modify("-$i days");
    $days[$dt->format('Y-m-d')] = [$dt->format('D'), 0, 0];
}

$wq = $db->query("
    SELECT DATE(created_at) AS d, type, ".sqlReturnEventCount()." AS cnt
    FROM transactions
    WHERE type IN ('borrow','return')
      AND created_at >= CURDATE() - INTERVAL 6 DAY
      AND created_at <  CURDATE() + INTERVAL 1 DAY
    GROUP BY d, type
");
foreach ($wq->fetchAll() as $r) {
    if (!isset($days[$r['d']])) continue;
    $days[$r['d']][$r['type'] === 'borrow' ? 1 : 2] = (int)$r['cnt'];
}

$labels  = array_column($days, 0);
$borrows = array_column($days, 1);
$returns = array_column($days, 2);
$dates   = array_keys($days);                 // full dates, used for chart tooltips

// ── Most borrowed tools (all time) ────────────────────────────
$mbStmt = $db->query("
    SELECT tl.name, COUNT(*) AS cnt
    FROM transactions t
    JOIN tools tl ON tl.id = t.tool_id
    WHERE t.type = 'borrow'
      AND t.created_at >= CURDATE() - INTERVAL 90 DAY
    GROUP BY tl.id, tl.name
    ORDER BY cnt DESC
    LIMIT 5
");
$mostBorrowed = $mbStmt->fetchAll();
$maxCount     = $mostBorrowed ? (int)$mostBorrowed[0]['cnt'] : 1;
$mostBorrowed = array_map(fn($r) => [
    'name'  => $r['name'],
    'count' => (int)$r['cnt'],
    'pct'   => round($r['cnt'] / $maxCount * 100),
], $mostBorrowed);

// ── Recent 5 transactions ─────────────────────────────────────
$recentStmt = $db->query("
    SELECT MIN(t.type) type, MIN(t.condition) `condition`, MIN(t.created_at) created_at, MIN(t.returnee_name) returnee_name,
           GROUP_CONCAT(DISTINCT tl.name ORDER BY t.id SEPARATOR ', ') AS tool_name,
           GROUP_CONCAT(DISTINCT tl.code ORDER BY t.id SEPARATOR ', ') AS tool_code,
           MIN(COALESCE(b.full_name, t.borrower_name_snapshot)) AS borrower
    FROM transactions t
    LEFT JOIN tools     tl ON tl.id = t.tool_id
    LEFT JOIN borrowers b  ON b.id  = t.borrower_id
    GROUP BY CASE WHEN t.type='borrow' THEN CONCAT('B:',t.txn_id) ELSE CONCAT('R:',t.id) END
    ORDER BY MIN(t.created_at) DESC, MAX(t.id) DESC
    LIMIT 5
");
$recentTx = $recentStmt->fetchAll();

// ── Low stock items ───────────────────────────────────────────
$lowStmt = $db->query("
    SELECT name, available, min_stock
    FROM tools
    WHERE is_active = 1 AND available <= min_stock
    ORDER BY available ASC
    LIMIT 10
");
$lowStockItems = $lowStmt->fetchAll();

ok([
    'total_tool_types'    => (int)$ts['tool_types'],
    'total_units'         => $totalTools,
    // Preserve the quantity field for existing stock/report clients.
    'total_tools'         => $totalTools,
    'available_tools'     => $availableTools,
    'borrowed_tools'      => $borrowedTools,
    'damaged_tools'       => $damagedTools,
    'missing_tools'       => $missingTools,
    'total_borrowers'     => $totalBorrowers,
    'low_stock_count'     => $lowStockCount,
    'active_borrows'      => $activeBorrows,
    'weekly_labels'       => $labels,
    'weekly_dates'        => $dates,
    'weekly_borrows'      => $borrows,
    'weekly_returns'      => $returns,
    'most_borrowed'       => $mostBorrowed,
    'recent_transactions' => $recentTx,
    'low_stock_items'     => $lowStockItems,
]);
