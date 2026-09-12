<?php
// ============================================================
//  tooltrack/receive_student_deletion.php
//  Called by CMS when an admin deletes a student outright from
//  classroom_db2.students. (Distinct from removing a student
//  from a single section, which is handled by re-pushing the
//  affected rosters to receive_masterlist.php.)
//
//  Auth:  X-Tooltrack-Key header
//  Body:  { "student_id": "01" }
//
//  Behavior:
//    1. Look up the borrower in tooltrack_db by id_number.
//       If not found, return success with a soft note —
//       deleting a non-existent row is idempotent.
//    2. Set borrowers.is_active = 0 (soft-deactivate).
//       Do NOT delete the row — transactions reference it
//       (transactions.borrower_id is FK ON DELETE SET NULL,
//        but we still want the historical name to display on
//        old transactions, so we keep the row).
//    3. Set is_active = 0 on ALL borrower_enrollments rows for
//       this borrower (across every section+subject).
//
//  Returns: { success, deactivated_borrower, deactivated_enrollments }
// ============================================================
require_once __DIR__ . '/_receive_common.php';
authenticate_request();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail(405, 'Only POST is supported.');
}

$body = read_json_body();
$student_id = trim((string)($body['student_id'] ?? ''));
if ($student_id === '') {
    json_fail(400, 'student_id is required.');
}

$conn->begin_transaction();
try {
    // Lookup borrower
    $look = $conn->prepare("SELECT id FROM borrowers WHERE id_number = ? LIMIT 1");
    $look->bind_param('s', $student_id);
    $look->execute();
    $row = $look->get_result()->fetch_assoc();
    if (!$row) {
        $conn->commit();
        json_out([
            'success' => true,
            'note'    => "No borrower with id_number='$student_id' in tooltrack — nothing to deactivate.",
            'deactivated_borrower' => false,
            'deactivated_enrollments' => 0,
        ]);
    }
    $borrower_id = (int)$row['id'];

    // Soft-deactivate the borrower
    $upB = $conn->prepare(
        "UPDATE borrowers SET is_active = 0, last_synced_at = NOW() WHERE id = ?"
    );
    $upB->bind_param('i', $borrower_id);
    $upB->execute();

    // Deactivate all their enrollments
    $upE = $conn->prepare(
        "UPDATE borrower_enrollments SET is_active = 0, synced_at = NOW() WHERE borrower_id = ?"
    );
    $upE->bind_param('i', $borrower_id);
    $upE->execute();
    $deactivated_enrollments = $upE->affected_rows;

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    json_fail(500, 'Database error: ' . $e->getMessage());
}

json_out([
    'success' => true,
    'deactivated_borrower' => true,
    'borrower_id' => $borrower_id,
    'deactivated_enrollments' => $deactivated_enrollments,
]);
