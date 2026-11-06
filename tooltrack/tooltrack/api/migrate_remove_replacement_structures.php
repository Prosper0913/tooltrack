<?php
/*
 * Removes database structures that only the retired replacement/resolution workflow used.
 *
 *   php api/migrate_remove_replacement_structures.php --database="tooltrack v2_db" --backup="C:\path\to\fresh_backup_file"            (dry run: changes nothing)
 *   php api/migrate_remove_replacement_structures.php --database="tooltrack v2_db" --backup="C:\path\to\fresh_backup_file" --apply    (executes)
 *
 * Safety rules
 *  - Requires a non-empty backup file written within the last 24 hours (take it with `php backup.php backup`).
 *  - Everything removed is first copied, row by row, into `legacy_replacement_archive` and re-read to prove the copy.
 *  - Payment, case, borrower, transaction, stock and audit data is fingerprinted before and after; any difference is an error.
 *  - Shared structures are never touched: replacement_requests (except the legacy columns below), replacement_payment_claims,
 *    replacement_incident_sources, replacement_request_events, inventory_adjustments, stock_history, missing_reports.qty_replaced.
 *  - Repeatable: already-removed structures are skipped.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/bootstrap.php';

$opts = [];
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true; }
$apply = isset($opts['apply']);
$db = getDB();
if (($opts['database'] ?? '') !== DB_NAME) throw new RuntimeException('Confirm the configured database using --database="' . DB_NAME . '"');
$bk = (string)($opts['backup'] ?? '');
if ($bk === '' || !is_file($bk) || filesize($bk) < 1 || filemtime($bk) < time() - 86400)
    throw new RuntimeException('A non-empty backup file written within the last 24 hours is required: --backup="path". Run: php backup.php backup');
echo 'Database: ' . $db->query('SELECT DATABASE()')->fetchColumn() . ($apply ? '  [APPLY]' : '  [DRY RUN - nothing will change]') . PHP_EOL;
echo 'Backup file: ' . $bk . ' (' . filesize($bk) . ' bytes)' . PHP_EOL;

$hasTable = fn(string $t): bool => (bool)$db->query('SHOW TABLES LIKE ' . $db->quote($t))->fetchColumn();
$cols     = fn(string $t): array => $hasTable($t) ? array_column($db->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field') : [];

// ---- What may be removed -------------------------------------------------------------
$dropTables = ['inventory_resolution_receipts', 'inventory_resolution_events', 'inventory_resolutions'];   // children first
$dropCols = [   // columns used only by the retired workflow; none is read or written by current code
    'replacement_requests' => ['case_completed_at', 'case_completed_by', 'writeoff_applied', 'resolution_notes', 'resolved_by', 'resolved_at'],
    'tools'                => ['replaceable_qty'],
];
$dropIndexes = ['replacement_requests' => ['idx_case_completed']];
$dropFkColumns = ['replacement_requests' => ['resolved_by']];   // foreign keys on columns being dropped

// ---- Tables whose retained data must be identical afterwards --------------------------
$preserve = ['borrowers', 'borrower_enrollments', 'users', 'tools', 'transactions', 'missing_reports', 'missing_report_events',
    'replacement_requests', 'replacement_payment_claims', 'replacement_incident_sources', 'replacement_request_events',
    'stock_history', 'inventory_adjustments', 'tool_disposals', 'audit_log'];
$fingerprint = function (string $t, array $exclude) use ($db, $cols): array {
    $c = array_values(array_diff($cols($t), $exclude));
    $expr = implode(",'#',", array_map(fn($x) => "COALESCE(CAST(`$x` AS CHAR),'~')", $c));
    $pk = in_array('id', $c, true) ? 'id' : $c[0];
    $h = hash_init('sha256'); $n = 0;
    $st = $db->query("SELECT CONCAT($expr) FROM `$t` ORDER BY `$pk`");
    while (($v = $st->fetchColumn()) !== false) { hash_update($h, $v . "\n"); $n++; }
    return [$n, hash_final($h)];
};
$exclude = fn(string $t): array => $dropCols[$t] ?? [];
$money = fn() => $db->query("SELECT COUNT(*), COALESCE(SUM(amount),0), COALESCE(SUM(CASE WHEN status='confirmed' THEN amount END),0) FROM replacement_payment_claims")->fetch(PDO::FETCH_NUM);

$before = [];
foreach ($preserve as $t) if ($hasTable($t)) $before[$t] = $fingerprint($t, $exclude($t));
$moneyBefore = $hasTable('replacement_payment_claims') ? $money() : null;

// ---- Plan -----------------------------------------------------------------------------
echo "\nPlan\n";
foreach ($dropTables as $t) echo $hasTable($t) ? sprintf("  DROP TABLE %-34s (%d rows archived first)\n", $t, $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()) : "  skip  $t (not present)\n";
foreach ($dropCols as $t => $list) foreach ($list as $c) {
    if (!in_array($c, $cols($t), true)) { echo "  skip  $t.$c (not present)\n"; continue; }
    $n = $db->query("SELECT COUNT(*) FROM `$t` WHERE `$c` IS NOT NULL AND `$c` NOT IN (0,'')")->fetchColumn();
    echo sprintf("  DROP COLUMN %s.%s (%d non-empty value(s) archived first)\n", $t, $c, $n);
}
if (!$apply) { echo "\nDry run only. Re-run with --apply to execute.\n"; exit(0); }

// ---- 1. Archive -----------------------------------------------------------------------
$db->exec("CREATE TABLE IF NOT EXISTS legacy_replacement_archive (
    id INT AUTO_INCREMENT PRIMARY KEY, source_table VARCHAR(80) NOT NULL, source_key VARCHAR(40) NOT NULL,
    row_json LONGTEXT NOT NULL, archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lra_source (source_table, source_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$ins = $db->prepare('INSERT IGNORE INTO legacy_replacement_archive (source_table, source_key, row_json) VALUES (?,?,?)');
$expected = [];   // source => [key => json]
$db->beginTransaction();
try {
    foreach ($dropTables as $t) {
        if (!$hasTable($t)) continue;
        foreach ($db->query("SELECT * FROM `$t` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ins->execute([$t, (string)$row['id'], $json]); $expected[$t][(string)$row['id']] = $json;
        }
    }
    foreach ($dropCols as $t => $list) {
        $present = array_values(array_intersect($list, $cols($t)));
        if (!$present) continue;
        $sel = implode(',', array_map(fn($c) => "`$c`", $present));
        foreach ($db->query("SELECT id,$sel FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $keep = [];
            foreach ($present as $c) if ($row[$c] !== null && $row[$c] !== '' && (string)$row[$c] !== '0') $keep[$c] = $row[$c];
            if (!$keep) continue;
            $src = "$t.legacy_columns"; $json = json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ins->execute([$src, (string)$row['id'], $json]); $expected[$src][(string)$row['id']] = $json;
        }
    }
    $db->commit();
} catch (Throwable $e) { $db->rollBack(); throw $e; }
// Prove the archive: every expected row is present and byte-identical.
$chk = $db->prepare('SELECT row_json FROM legacy_replacement_archive WHERE source_table=? AND source_key=?'); $archived = 0;
foreach ($expected as $src => $rows) foreach ($rows as $k => $json) {
    $chk->execute([$src, (string)$k]);
    if ($chk->fetchColumn() !== $json) throw new RuntimeException("Archive verification failed for $src #$k; nothing has been dropped.");
    $archived++;
}
echo "\nArchived and verified $archived row(s) in legacy_replacement_archive.\n";

// ---- 2. Remove structures --------------------------------------------------------------
foreach ($dropIndexes as $t => $list) foreach ($list as $ix) {
    if ($db->query("SHOW INDEX FROM `$t` WHERE Key_name=" . $db->quote($ix))->fetch()) { $db->exec("ALTER TABLE `$t` DROP INDEX `$ix`"); echo "Dropped index $t.$ix\n"; }
}
foreach ($dropFkColumns as $t => $list) foreach ($list as $c) {
    $q = $db->prepare('SELECT constraint_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name=? AND column_name=? AND referenced_table_name IS NOT NULL');
    $q->execute([$t, $c]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $fk) { $db->exec("ALTER TABLE `$t` DROP FOREIGN KEY `$fk`"); echo "Dropped foreign key $t.$fk\n"; }
}
foreach ($dropCols as $t => $list) foreach ($list as $c) {
    if (in_array($c, $cols($t), true)) { $db->exec("ALTER TABLE `$t` DROP COLUMN `$c`"); echo "Dropped column $t.$c\n"; }
}
foreach ($dropTables as $t) if ($hasTable($t)) { $db->exec("DROP TABLE `$t`"); echo "Dropped table $t\n"; }

// ---- 3. Verify -------------------------------------------------------------------------
$problems = [];
foreach ($before as $t => $b) if ($fingerprint($t, $exclude($t)) !== $b) $problems[] = "$t changed";
if ($moneyBefore !== null && $money() !== $moneyBefore) $problems[] = 'payment totals changed';
$orph = [
    'payments without a case'     => 'SELECT COUNT(*) FROM replacement_payment_claims p LEFT JOIN replacement_requests r ON r.id=p.request_id WHERE r.id IS NULL',
    'evidence without a case'     => 'SELECT COUNT(*) FROM replacement_incident_sources s LEFT JOIN replacement_requests r ON r.id=s.request_id WHERE r.id IS NULL',
    'cases without a borrower'    => "SELECT COUNT(*) FROM replacement_requests r LEFT JOIN borrowers b ON b.id=r.responsible_borrower_id WHERE r.responsible_borrower_id IS NOT NULL AND b.id IS NULL",
    'confirmed without verifier'  => "SELECT COUNT(*) FROM replacement_payment_claims WHERE status='confirmed' AND verified_by IS NULL",
];
foreach ($orph as $label => $sql) if ((int)$db->query($sql)->fetchColumn() !== 0) $problems[] = $label;
foreach ($dropTables as $t) if ($hasTable($t)) $problems[] = "$t still exists";
if ($problems) throw new RuntimeException('Integrity verification FAILED: ' . implode('; ', $problems) . '. Restore from the backup: ' . $bk);
echo "\nVerified: " . count($before) . " tables fingerprint-identical on every retained column; payment count/total/confirmed total unchanged ("
    . implode(' / ', $moneyBefore ?? []) . "); no orphan cases, payments or evidence.\n";
echo "Done. Archived history stays in legacy_replacement_archive (drop it later only after your own review).\n";
