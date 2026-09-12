<?php
// api/borrower_enrollments.php
// Enrollment management for borrowers. Course/section membership lives here,
// not on borrowers.course_section.
require_once __DIR__ . '/config.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $borrowerId = (int)($_GET['borrower_id'] ?? 0);

    if ($borrowerId) {
        $stmt = $db->prepare('SELECT * FROM borrower_enrollments WHERE borrower_id = ? AND is_active = 1 ORDER BY course, section_name, subject_name');
        $stmt->execute([$borrowerId]);
        ok($stmt->fetchAll());
    }

    // Available course/section combinations already known by the CMS sync.
    $stmt = $db->query('SELECT MIN(id) AS enrollment_id, MIN(cms_section_id) AS cms_section_id,
                               course, section_name
                        FROM borrower_enrollments
                        WHERE is_active = 1
                          AND course IS NOT NULL AND course <> ""
                          AND section_name IS NOT NULL AND section_name <> ""
                        GROUP BY course, section_name
                        ORDER BY course, section_name');
    ok($stmt->fetchAll());
}

if ($method === 'POST') {
    $b = body();
    $borrowerId   = (int)($b['borrower_id'] ?? 0);
    $cmsSectionId = (int)($b['cms_section_id'] ?? 0);
    $course       = trim($b['course'] ?? '');
    $sectionName  = trim($b['section_name'] ?? '');

    if (!$borrowerId || !$cmsSectionId || !$course || !$sectionName) {
        fail('Borrower, course, section, and CMS section are required.');
    }

    $stmt = $db->prepare('SELECT id FROM borrowers WHERE id = ?');
    $stmt->execute([$borrowerId]);
    if (!$stmt->fetch()) fail('Borrower not found.', 404);

    // A manual section assignment is represented by cms_subject_id = 0.
    // Existing CMS-synced subject enrollments remain untouched.
    $stmt = $db->prepare('SELECT id FROM borrower_enrollments
                          WHERE borrower_id = ? AND cms_section_id = ? AND cms_subject_id = 0');
    $stmt->execute([$borrowerId, $cmsSectionId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $db->prepare('UPDATE borrower_enrollments
                              SET course=?, section_name=?, is_active=1, synced_at=NOW()
                              WHERE id=?');
        $stmt->execute([$course, $sectionName, $existing['id']]);
        $id = (int)$existing['id'];
    } else {
        $stmt = $db->prepare('INSERT INTO borrower_enrollments
            (borrower_id, cms_section_id, cms_subject_id, course, section_name,
             subject_code, subject_name, is_active, synced_at)
            VALUES (?, ?, 0, ?, ?, "", "Manual Section Enrollment", 1, NOW())');
        $stmt->execute([$borrowerId, $cmsSectionId, $course, $sectionName]);
        $id = (int)$db->lastInsertId();
    }

    $stmt = $db->prepare('SELECT * FROM borrower_enrollments WHERE id = ?');
    $stmt->execute([$id]);
    ok($stmt->fetch(), 201);
}

if ($method === 'PUT') {
    $b = body();
    $id          = (int)($b['id'] ?? 0);
    $cmsSectionId = (int)($b['cms_section_id'] ?? 0);
    $course      = trim($b['course'] ?? '');
    $sectionName = trim($b['section_name'] ?? '');

    if (!$id || !$cmsSectionId || !$course || !$sectionName) {
        fail('Enrollment, course, section, and CMS section are required.');
    }

    $stmt = $db->prepare('UPDATE borrower_enrollments
                          SET cms_section_id=?, course=?, section_name=?, is_active=1, synced_at=NOW()
                          WHERE id=? AND cms_subject_id=0');
    $stmt->execute([$cmsSectionId, $course, $sectionName, $id]);
    if ($stmt->rowCount() === 0) fail('Manual enrollment not found.', 404);

    $stmt = $db->prepare('SELECT * FROM borrower_enrollments WHERE id = ?');
    $stmt->execute([$id]);
    ok($stmt->fetch());
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('Missing enrollment id.');

    $stmt = $db->prepare('UPDATE borrower_enrollments SET is_active=0, synced_at=NOW() WHERE id=? AND cms_subject_id=0');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) fail('Manual enrollment not found.', 404);
    ok(['deleted_id' => $id]);
}

fail('Method not allowed.', 405);
