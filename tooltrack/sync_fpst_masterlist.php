<?php
// ============================================================
//  sync_fpst_masterlist.php  (goes in the Tooltrack project)
//
//  Pulls a course+subject roster from the Classroom Management
//  System's API (GET /api/masterlist.php?course=&subject_name=)
//  and syncs it into tooltrack_db.borrowers, so those students
//  are immediately available to check tools out to — no manual
//  CSV export/import needed.
//
//  SETUP — fill these in before use:
//    1. CMS_API_BASE  → the CMS's API folder URL, e.g.
//       'http://localhost/classroomv2/api'  (same machine, per
//       your setup — adjust if CMS ever moves to a different host)
//    2. CMS_API_KEY   → the plaintext key from CMS's
//       admin/api_keys.php page (shown once at generation time).
//       Store this somewhere safer than hardcoded in a real
//       deployment (env var, a gitignored config file, etc.) —
//       it's inline here only to keep this a single drop-in file.
//    3. $db_host / $db_name / $db_user / $db_pass below, to match
//       however Tooltrack normally connects to tooltrack_db.
//
//  USAGE:
//    Visit this file in a browser with ?course=FPST&subject_name=Food+Test
//    e.g. http://localhost/tooltrack/sync_fpst_masterlist.php?course=FPST&subject_name=Food+Test
//    Or submit the form below if you load it with no query string.
// ============================================================

// ── Auth: Admin session required ────────────────────────────
// (Standalone session check — deliberately not including
// api/config.php, since its DB_* constants would collide with
// this file's own define()s below.)
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Admin') {
    http_response_code(403);
    exit('<p style="font-family:sans-serif;padding:40px">Admin login required. <a href="login_usa.html">Log in</a></p>');
}

// ── Config — EDIT THESE ─────────────────────────────────────
const CMS_API_BASE = 'http://localhost/classroomv2/api';
const CMS_API_KEY  = '9f4e025b6e5e2cf601125885e13622c837c3027f0169a0567ad427807868d960';
// ⚠ Rotated 2026-09-12 (previous key had been shared in a support
// conversation). This must match CMS's admin/api_keys.php value for
// this same integration, or masterlist pulls will get a 401.

$db_host = 'localhost';
$db_name = 'tooltrack_db';
$db_user = 'root';
$db_pass = '';
// ─────────────────────────────────────────────────────────────

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
$conn->set_charset('utf8mb4');

$course       = trim($_GET['course'] ?? $_POST['course'] ?? '');
$subject_name = trim($_GET['subject_name'] ?? $_POST['subject_name'] ?? '');
$results      = null;
$fatal        = null;

// ── Call the CMS masterlist API ─────────────────────────────
function fetch_cms_masterlist($course, $subject_name, &$fatal) {
    $url = CMS_API_BASE . '/masterlist.php?' . http_build_query([
        'course'       => $course,
        'subject_name' => $subject_name,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . CMS_API_KEY],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        $fatal = "Could not reach the CMS API: $curl_err. Check CMS_API_BASE and that CMS is running.";
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $fatal = "CMS API returned something that wasn't valid JSON (HTTP $http_code): " . substr($raw, 0, 300);
        return null;
    }
    if ($http_code !== 200 || empty($data['success'])) {
        $fatal = "CMS API error (HTTP $http_code): " . ($data['error'] ?? 'Unknown error');
        return null;
    }
    return $data;
}

// ── Upsert one student into borrowers ───────────────────────
// Matches on id_number (which has a UNIQUE key in tooltrack_db).
// Only touches full_name/type/updated_at on an existing row —
// never resets active_borrows/total_borrows, so borrow history
// isn't disturbed by re-running a sync.
function upsert_borrower($conn, $id_number, $full_name) {
    $stmt = $conn->prepare(
        "INSERT INTO borrowers (full_name, id_number, type, source, last_synced_at)
         VALUES (?, ?, 'Student', 'cms_push', NOW())
         ON DUPLICATE KEY UPDATE
            full_name = VALUES(full_name),
            type = VALUES(type),
            source = 'cms_push',
            last_synced_at = NOW()"
    );
    $stmt->bind_param('ss', $full_name, $id_number);
    $stmt->execute();
    return $stmt->affected_rows; // 1 = inserted, 2 = updated (MySQL's own convention), 0 = no change
}

// ── Upsert one enrollment row ────────────────────────────────
// Mirrors receive_masterlist.php's enrollment upsert so manual
// sync and push sync produce identical state.
function upsert_enrollment($conn, $borrower_id, $cms_section_id, $cms_subject_id,
                           $course, $section_name, $subject_code, $subject_name) {
    $stmt = $conn->prepare(
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
    $stmt->bind_param('iisssss',
        $borrower_id, $cms_section_id, $cms_subject_id,
        $course, $section_name, $subject_code, $subject_name
    );
    $stmt->execute();
}

// ── Deactivate enrollments not in the payload ────────────────
// For one (section, subject) pair: flip is_active to 0 on every
// enrollment row whose borrower_id is NOT in $payload_ids. This
// is what makes the manual sync page actually reflect removals —
// before this, an empty roster would silently do nothing.
function deactivate_missing_enrollments($conn, $cms_section_id, $cms_subject_id, $payload_ids) {
    if (empty($payload_ids)) {
        $stmt = $conn->prepare(
            "UPDATE borrower_enrollments
             SET is_active = 0, synced_at = NOW()
             WHERE cms_section_id = ? AND cms_subject_id = ? AND is_active = 1"
        );
        $stmt->bind_param('ii', $cms_section_id, $cms_subject_id);
        $stmt->execute();
        return $stmt->affected_rows;
    }
    $ph = implode(',', array_fill(0, count($payload_ids), '?'));
    $types = str_repeat('i', count($payload_ids));
    $sql = "UPDATE borrower_enrollments
            SET is_active = 0, synced_at = NOW()
            WHERE cms_section_id = ? AND cms_subject_id = ?
              AND is_active = 1
              AND borrower_id NOT IN ($ph)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii' . $types, $cms_section_id, $cms_subject_id, ...$payload_ids);
    $stmt->execute();
    return $stmt->affected_rows;
}

// CMS returns "Last, First MI." — reformat to "First MI. Last" to
// match the "First Last" style already used in your borrowers data
// (e.g. your existing row "Bonjorno casipe").
function reformat_name($row) {
    $mi = trim((string)($row['middle_initial'] ?? ''));
    $parts = array_filter([$row['first_name'], $mi !== '' ? $mi . '.' : '', $row['last_name']]);
    return implode(' ', $parts);
}

if ($course !== '' && $subject_name !== '') {
    $data = fetch_cms_masterlist($course, $subject_name, $fatal);

    if ($data !== null) {
        $results = ['inserted' => [], 'updated' => [], 'unchanged' => [], 'deactivated' => [], 'groups' => []];
        foreach ($data['groups'] as $group) {
            $cms_section_id = (int)$group['section']['id'];
            $cms_subject_id = (int)$group['subject']['id'];
            $section_name   = $group['section']['name'];
            $subject_code   = $group['subject']['code'];
            $subject_name_g = $group['subject']['name'];
            $group_label    = "$section_name — $subject_name_g ({$group['count']} students)";
            $results['groups'][] = $group_label;

            $payload_borrower_ids = [];
            foreach ($group['students'] as $stu) {
                $full_name = reformat_name($stu);
                $affected  = upsert_borrower($conn, $stu['student_id'], $full_name);

                // Look up the borrower_id (auto-increment PK in tooltrack)
                // so we can upsert the enrollment row + track it for the
                // deactivation pass below.
                $look = $conn->prepare("SELECT id FROM borrowers WHERE id_number = ? LIMIT 1");
                $look->bind_param('s', $stu['student_id']);
                $look->execute();
                $borrower_id = (int)($look->get_result()->fetch_assoc()['id'] ?? 0);
                if ($borrower_id > 0) {
                    $payload_borrower_ids[] = $borrower_id;
                    upsert_enrollment(
                        $conn, $borrower_id, $cms_section_id, $cms_subject_id,
                        $course, $section_name, $subject_code, $subject_name_g
                    );
                }

                $label = "$full_name ({$stu['student_id']}) — $section_name / $subject_name_g";
                if ($affected === 1) $results['inserted'][] = $label;
                elseif ($affected === 2) $results['updated'][] = $label;
                else $results['unchanged'][] = $label;
            }

            // Deactivate enrollment rows for this section+subject whose
            // borrower is NOT in the payload (i.e. was removed in CMS).
            // This is the fix for the "empty roster does nothing" bug.
            $deact_count = deactivate_missing_enrollments(
                $conn, $cms_section_id, $cms_subject_id, $payload_borrower_ids
            );
            if ($deact_count > 0) {
                $results['deactivated'][] = "$deact_count enrollment(s) deactivated for $section_name / $subject_name_g";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sync FPST Masterlist — Tooltrack</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 16px; color: #222; }
  h1 { font-size: 20px; }
  form { display: flex; gap: 8px; flex-wrap: wrap; margin: 16px 0 24px; }
  input { padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
  button { padding: 8px 16px; border: none; border-radius: 6px; background: #2563eb; color: #fff; font-size: 14px; cursor: pointer; }
  .box { border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
  .err { background: #fee; border-color: #f88; }
  ul { margin: 4px 0 16px; padding-left: 20px; font-size: 13px; }
  .tag { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 99px; background: #eef; margin-right: 6px; }
</style>
</head>
<body>
  <h1>Sync FPST Masterlist into Tooltrack</h1>
  <p>Pulls a course + subject roster from the CMS API and upserts it into <code>borrowers</code>.</p>

  <form method="GET">
    <input type="text" name="course" placeholder="Course, e.g. FPST" value="<?php echo htmlspecialchars($course); ?>" required>
    <input type="text" name="subject_name" placeholder="Subject name, e.g. Food Test" value="<?php echo htmlspecialchars($subject_name); ?>" required style="min-width:220px;">
    <button type="submit">Sync</button>
  </form>

  <?php if ($fatal): ?>
    <div class="box err"><strong>Error:</strong> <?php echo htmlspecialchars($fatal); ?></div>
  <?php endif; ?>

  <?php if ($results): ?>
    <?php
      // Defensive: pull each bucket with ?? so a missing key never crashes
      // the page (e.g. if someone partially updates this file).
      $inserted     = $results['inserted']     ?? [];
      $updated      = $results['updated']      ?? [];
      $unchanged    = $results['unchanged']    ?? [];
      $deactivated  = $results['deactivated']  ?? [];
      $groups       = $results['groups']       ?? [];
    ?>
    <div class="box">
      <strong>Matched groups:</strong>
      <ul><?php foreach ($groups as $g) echo '<li>' . htmlspecialchars($g) . '</li>'; ?></ul>
      <span class="tag"><?php echo count($inserted); ?> new</span>
      <span class="tag"><?php echo count($updated); ?> updated</span>
      <span class="tag"><?php echo count($unchanged); ?> unchanged</span>
      <span class="tag" style="background:#fee;border-color:#f88;"><?php echo count($deactivated); ?> deactivated</span>
    </div>
    <?php if ($inserted): ?>
      <div class="box"><strong>New borrowers</strong>
        <ul><?php foreach ($inserted as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
      </div>
    <?php endif; ?>
    <?php if ($updated): ?>
      <div class="box"><strong>Updated (name/type refreshed)</strong>
        <ul><?php foreach ($updated as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
      </div>
    <?php endif; ?>
    <?php if ($deactivated): ?>
      <div class="box"><strong>Deactivated enrollments (removed in CMS)</strong>
        <ul><?php foreach ($deactivated as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</body>
</html>
