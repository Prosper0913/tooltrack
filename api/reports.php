<?php
// ================================================================
//  api/reports.php
//  GET ?type=monthly         → chart data
//  GET ?type=category        → doughnut chart data
//  GET ?type=inventory&download=1   → CSV download
//  GET ?type=transactions&download=1
//  GET ?type=borrowers&download=1
//  GET ?type=overdue&download=1
// ================================================================
require_once __DIR__ . '/config.php';

$db   = getDB();
$type = $_GET['type']     ?? 'monthly';
$dl   = !empty($_GET['download']);

// ── Monthly borrow/return (last 4 weeks) ─────────────────────
if ($type === 'monthly') {
    $labels  = [];
    $borrows = [];
    $returns = [];
    for ($i = 3; $i >= 0; $i--) {
        $start = date('Y-m-d', strtotime("monday -$i week"));
        $end   = date('Y-m-d', strtotime("sunday -$i week"));
        $label = 'Wk ' . date('W', strtotime($start));
        $labels[] = $label;

        $b = $db->prepare("SELECT COUNT(*) FROM transactions WHERE type='borrow' AND DATE(created_at) BETWEEN ? AND ?");
        $b->execute([$start, $end]);
        $borrows[] = (int)$b->fetchColumn();

        $r = $db->prepare("SELECT COUNT(*) FROM transactions WHERE type='return' AND DATE(created_at) BETWEEN ? AND ?");
        $r->execute([$start, $end]);
        $returns[] = (int)$r->fetchColumn();
    }
    ok(['labels' => $labels, 'borrows' => $borrows, 'returns' => $returns]);
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

// ── CSV downloads ─────────────────────────────────────────────
function csvHeaders(string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}

function csvOut(array $headers, array $rows): void {
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

if ($type === 'inventory' && $dl) {
    csvHeaders('inventory_' . date('Ymd') . '.csv');
    $rows = getDB()->query('SELECT name,code,category,quantity,available,min_stock,description FROM tools ORDER BY name')->fetchAll();
    csvOut(['Name','Code','Category','Quantity','Available','Min Stock','Description'], $rows);
}

if ($type === 'transactions' && $dl) {
    csvHeaders('transactions_' . date('Ymd') . '.csv');
    $rows = getDB()->query("
        SELECT t.txn_id, t.type, tl.code AS tool_code, tl.name AS tool_name,
               b.full_name AS borrower, t.status, t.condition, t.notes,
               t.due_date, t.returned_at, t.created_at
        FROM transactions t
        LEFT JOIN tools tl ON tl.id = t.tool_id
        LEFT JOIN borrowers b ON b.id = t.borrower_id
        ORDER BY t.created_at DESC
    ")->fetchAll();
    csvOut(['TXN ID','Type','Tool Code','Tool Name','Borrower','Status','Condition','Notes','Due Date','Returned At','Created At'], $rows);
}

if ($type === 'borrowers' && $dl) {
    csvHeaders('borrowers_' . date('Ymd') . '.csv');
    $rows = getDB()->query('SELECT full_name,id_number,type,email,phone,active_borrows,total_borrows FROM borrowers ORDER BY full_name')->fetchAll();
    csvOut(['Full Name','ID Number','Type','Email','Phone','Active Borrows','Total Borrows'], $rows);
}

if ($type === 'overdue' && $dl) {
    csvHeaders('overdue_' . date('Ymd') . '.csv');
    $rows = getDB()->query("
        SELECT t.txn_id, tl.code, tl.name, b.full_name, b.id_number, t.due_date, t.created_at
        FROM transactions t
        LEFT JOIN tools tl ON tl.id = t.tool_id
        LEFT JOIN borrowers b ON b.id = t.borrower_id
        WHERE t.type='borrow' AND t.status='active' AND t.due_date < CURDATE()
        ORDER BY t.due_date ASC
    ")->fetchAll();
    csvOut(['TXN ID','Tool Code','Tool Name','Borrower Name','Borrower ID','Due Date','Borrowed On'], $rows);
}

fail('Unknown report type.', 400);
