<?php
// ============================================================
//  tooltrack/receive_masterlist.php
//  Inbound endpoint — CMS POSTs a section+subject roster here
//  whenever an admin enrolls/removes students in classroom_db2.
//
//  Auth:  X-Tooltrack-Key header (shared secret, timing-safe
//         compared in _receive_common.php)
//  Body:  JSON, shape:
//    {
//      "section":  { "id": 12, "name": "FPST-1A", "course": "FPST" },
//      "subject":  { "id": 11, "code": "SIA102", "name": "Systems..." },
//      "students": [
//        { "student_id":"01","first_name":"Ivan Paul",
//          "middle_initial":"Ablen","last_name":"Abiera" }, ...
//      ]
//    }
//
//  Behavior:
//    1. UPSERT each student into `borrowers` (matched by
//       id_number UNIQUE). Never touches active_borrows /
//       total_borrows — borrow history is preserved across
//       re-syncs. Sets source='cms_push' and last_synced_at.
//    2. UPSERT each (borrower, section, subject) into
//       `borrower_enrollments` with is_active=1.
//    3. SOFT-DEACTIVATE any borrower_enrollments row for this
//       exact (cms_section_id, cms_subject_id) pair whose
//       borrower_id is NOT in the payload. This is the
//       "withdrawal" mechanism — when a student is removed
//       from a section in CMS, the next push omits them and
//       their enrollment row here flips is_active=0.
//       The borrower record itself stays is_active=1 because
//       they may still be enrolled in OTHER subjects, and
//       because they may still have tools checked out.
//
//  Returns: { success, inserted, updated, deactivated, unchanged }
// ============================================================
require_once __DIR__ . '/_receive_common.php';
authenticate_request();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail(405, 'Only POST is supported.');
}

$body = read_json_body();

// ── Validate payload shape ───────────────────────────────────
foreach (['section', 'subject', 'students'] as $f) {
    if (!isset($body[$f]) || !is_array($body[$f])) {
        json_fail(400, "Missing or invalid field: $f");
    }
}
$section = $body['section'];
$subject = $body['subject'];
$students = $body['students'];

foreach (['id', 'name', 'course'] as $f) {
    if (!isset($section[$f])) json_fail(400, "section.$f is required.");
}
foreach (['id', 'code', 'name'] as $f) {
    if (!isset($subject[$f])) json_fail(400, "subject.$f is required.");
}
if (!is_array($students)) {
    json_fail(400, 'students must be an array (empty array is allowed for a full withdrawal).');
}

$cms_section_id = (int)$section['id'];
$cms_subject_id = (int)$subject['id'];
$course         = trim((string)$section['course']);
$section_name   = trim((string)$section['name']);
$subject_code   = trim((string)$subject['code']);
$subject_name   = trim((string)$subject['name']);

// Only FPST pushes are accepted (per the user's #5 answer: FPST
// is the only course that flows to tooltrack). Any other course
// in the payload is rejected so a misconfigured push can't
// pollute tooltrack's borrowers table with non-FPST students.
if (strtoupper($course) !== 'FPST') {
    json_fail(403, "This endpoint only accepts FPST pushes (got course='$course').");
}

// ── Sync ─────────────────────────────────────────────────────
$inserted = 0;
$updated  = 0;
$unchanged = 0;
$deactivated = 0;

$conn->begin_transaction();
try {
    $payload_student_ids = [];

    foreach ($students as $stu) {
        $student_id = trim((string)($stu['student_id'] ?? ''));
        if ($student_id === '') continue;

        $full_name = reformat_name(
            $stu['first_name']     ?? '',
            $stu['middle_initial'] ?? '',
            $stu['last_name']      ?? ''
        );

        // ── 1. Upsert borrower ──
        $upB = $conn->prepare(
            "INSERT INTO borrowers (full_name, id_number, type, source, last_synced_at)
             VALUES (?, ?, 'Student', 'cms_push', NOW())
             ON DUPLICATE KEY UPDATE
                full_name = VALUES(full_name),
                type = 'Student',
                source = 'cms_push',
                last_synced_at = NOW()"
        );
        $upB->bind_param('ss', $full_name, $student_id);
        $upB->execute();
        // affected_rows: 1=inserted, 2=updated, 0=no change (MySQL convention)
        $aff = $upB->affected_rows;
        if ($aff === 1) $inserted++;
        elseif ($aff === 2) $updated++;
        else $unchanged++;

        // Look up the borrower_id (auto-increment PK in tooltrack)
        $look = $conn->prepare("SELECT id FROM borrowers WHERE id_number = ? LIMIT 1");
        $look->bind_param('s', $student_id);
        $look->execute();
        $borrower_id = (int)($look->get_result()->fetch_assoc()['id'] ?? 0);
        if ($borrower_id === 0) continue; // shouldn't happen, defensive

        // ── 2. Upsert enrollment row ──
        $upE = $conn->prepare(
            "INSERT INTO borrower_enrollments
                (borrower_id, cms_section_id, cms_subject_id, course, section_name,
                 subject_code, subject_name, is_active, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
                course = VALUES(course),
                section_name = VALUES(section_name),
                subject_code = VALUES(subject_code),
                subject_name = VALUES(subject_name),
                is_active = 1,
                synced_at = NOW()"
        );
        $upE->bind_param('iisssss',
            $borrower_id, $cms_section_id, $cms_subject_id,
            $course, $section_name, $subject_code, $subject_name
        );
        $upE->execute();

        $payload_student_ids[] = $borrower_id;
    }

    // ── 3. Deactivate enrollments for students NOT in payload ──
    // Only touches rows for THIS exact section+subject pair, so
    // enrollments in other subjects (or other sections) are safe.
    if (empty($payload_student_ids)) {
        $deact = $conn->prepare(
            "UPDATE borrower_enrollments
             SET is_active = 0, synced_at = NOW()
             WHERE cms_section_id = ? AND cms_subject_id = ? AND is_active = 1"
        );
        $deact->bind_param('ii', $cms_section_id, $cms_subject_id);
        $deact->execute();
        $deactivated = $deact->affected_rows;
    } else {
        // Build a NOT IN (...) placeholder list
        $ph = implode(',', array_fill(0, count($payload_student_ids), '?'));
        $types = str_repeat('i', count($payload_student_ids));
        $sql = "UPDATE borrower_enrollments
                SET is_active = 0, synced_at = NOW()
                WHERE cms_section_id = ? AND cms_subject_id = ?
                  AND is_active = 1
                  AND borrower_id NOT IN ($ph)";
        $deact = $conn->prepare($sql);
        $deact->bind_param('ii' . $types, $cms_section_id, $cms_subject_id, ...$payload_student_ids);
        $deact->execute();
        $deactivated = $deact->affected_rows;
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    json_fail(500, 'Database error: ' . $e->getMessage());
}

json_out([
    'success'      => true,
    'section'      => ['id' => $cms_section_id, 'name' => $section_name, 'course' => $course],
    'subject'      => ['id' => $cms_subject_id, 'code' => $subject_code, 'name' => $subject_name],
    'students_in_payload' => count($payload_student_ids),
    'inserted'     => $inserted,
    'updated'      => $updated,
    'unchanged'    => $unchanged,
    'deactivated'  => $deactivated,
]);
