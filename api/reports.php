<?php
// ================================================================
//  api/reports.php
//  GET ?type=monthly                → chart data
//  GET ?type=category               → doughnut chart data
//  GET ?type=inventory               → JSON {columns, rows} for on-screen preview
//  GET ?type=inventory&download=1    → same data as a CSV download
//  (same pattern for type=transactions | borrowers | overdue)
//
//  Any logged-in user (Admin or Staff) can view AND export every
//  report — reporting is read-only, so there's no reason to split
//  it by role the way write actions are split.
// ================================================================
require_once __DIR__ . '/config.php';

requireLogin();

$db   = getDB();
$type = $_GET['type'] ?? 'monthly';
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

// ── Tabular reports (inventory / transactions / borrowers / overdue) ──
// One definition per report: display column labels + the SQL that
// produces rows in that same order. Used for both the on-screen
// preview (JSON) and the CSV export, so the two can never drift apart.
$reportDefs = [
    'inventory' => [
        'filename' => 'inventory',
        'columns'  => ['Name', 'Code', 'Category', 'Quantity', 'Available', 'Min Stock', 'Description'],
        'sql'      => 'SELECT name, code, category, quantity, available, min_stock, description
                        FROM tools ORDER BY name',
    ],
    'transactions' => [
        'filename' => 'transactions',
        'columns'  => ['TXN ID', 'Type', 'Tool Code', 'Tool Name', 'Borrower', 'Status', 'Condition', 'Notes', 'Due Date', 'Returned At', 'Created At'],
        'sql'      => "SELECT t.txn_id, t.type, tl.code AS tool_code, tl.name AS tool_name,
                               b.full_name AS borrower, t.status, t.condition, t.notes,
                               t.due_date, t.returned_at, t.created_at
                        FROM transactions t
                        LEFT JOIN tools tl ON tl.id = t.tool_id
                        LEFT JOIN borrowers b ON b.id = t.borrower_id
                        ORDER BY t.created_at DESC",
    ],
    'borrowers' => [
        'filename' => 'borrowers',
        'columns'  => ['Full Name', 'ID Number', 'Type', 'Email', 'Phone', 'Active Borrows', 'Total Borrows'],
        'sql'      => 'SELECT full_name, id_number, type, email, phone, active_borrows, total_borrows
                        FROM borrowers ORDER BY full_name',
    ],
    'overdue' => [
        'filename' => 'overdue',
        'columns'  => ['TXN ID', 'Tool Code', 'Tool Name', 'Borrower Name', 'Borrower ID', 'Due Date', 'Borrowed On'],
        'sql'      => "SELECT t.txn_id, tl.code, tl.name, b.full_name, b.id_number, t.due_date, t.created_at
                        FROM transactions t
                        LEFT JOIN tools tl ON tl.id = t.tool_id
                        LEFT JOIN borrowers b ON b.id = t.borrower_id
                        WHERE t.type='borrow' AND t.status='active' AND t.due_date < CURDATE()
                        ORDER BY t.due_date ASC",
    ],
];

if (isset($reportDefs[$type])) {
    $def  = $reportDefs[$type];
    $rows = $db->query($def['sql'])->fetchAll(PDO::FETCH_NUM);

    if ($dl) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $def['filename'] . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $def['columns']);
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }

    ok(['columns' => $def['columns'], 'rows' => $rows]);
}

fail('Unknown report type.', 400);
