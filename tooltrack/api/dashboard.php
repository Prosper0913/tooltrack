<?php
// ================================================================
//  api/dashboard.php
//  GET  → all stats, charts data, recent transactions, alerts
// ================================================================
require_once __DIR__ . '/config.php';

requireLogin();

$db = getDB();

// ── Stats ─────────────────────────────────────────────────────
$totalTools     = (int)$db->query('SELECT SUM(quantity)  FROM tools')->fetchColumn();
$availableTools = (int)$db->query('SELECT SUM(available) FROM tools')->fetchColumn();
$borrowedTools  = $totalTools - $availableTools;
$totalBorrowers = (int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn();
$lowStockCount  = (int)$db->query('SELECT COUNT(*) FROM tools WHERE available <= min_stock')->fetchColumn();
$activeBorrows  = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE type='borrow' AND status='active'")->fetchColumn();

// ── Weekly borrow / return chart (last 7 days) ────────────────
$labels  = [];
$borrows = [];
$returns = [];

for ($i = 6; $i >= 0; $i--) {
    $date    = date('Y-m-d', strtotime("-$i days"));
    $label   = date('D', strtotime($date));
    $labels[] = $label;

    $bStmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE type='borrow' AND DATE(created_at)=?");
    $bStmt->execute([$date]);
    $borrows[] = (int)$bStmt->fetchColumn();

    $rStmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE type='return' AND DATE(created_at)=?");
    $rStmt->execute([$date]);
    $returns[] = (int)$rStmt->fetchColumn();
}

// ── Most borrowed tools (all time) ────────────────────────────
$mbStmt = $db->query("
    SELECT tl.name, COUNT(*) AS cnt
    FROM transactions t
    JOIN tools tl ON tl.id = t.tool_id
    WHERE t.type = 'borrow'
    GROUP BY tl.id
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
    SELECT t.type, t.condition, t.created_at,
           tl.name  AS tool_name,
           tl.code  AS tool_code,
           b.full_name AS borrower
    FROM transactions t
    LEFT JOIN tools     tl ON tl.id = t.tool_id
    LEFT JOIN borrowers b  ON b.id  = t.borrower_id
    ORDER BY t.created_at DESC
    LIMIT 5
");
$recentTx = $recentStmt->fetchAll();

// ── Low stock items ───────────────────────────────────────────
$lowStmt = $db->query("
    SELECT name, available, min_stock
    FROM tools
    WHERE available <= min_stock
    ORDER BY available ASC
    LIMIT 10
");
$lowStockItems = $lowStmt->fetchAll();

ok([
    'total_tools'         => $totalTools,
    'available_tools'     => $availableTools,
    'borrowed_tools'      => $borrowedTools,
    'total_borrowers'     => $totalBorrowers,
    'low_stock_count'     => $lowStockCount,
    'active_borrows'      => $activeBorrows,
    'weekly_labels'       => $labels,
    'weekly_borrows'      => $borrows,
    'weekly_returns'      => $returns,
    'most_borrowed'       => $mostBorrowed,
    'recent_transactions' => $recentTx,
    'low_stock_items'     => $lowStockItems,
]);
