<?php
// ============================================================
//  index.php — main app shell (dashboard, tools, borrowers,
//  borrow/return, reports, and the "Sync a Section" tab).
//
//  This page requires a logged-in session. Anyone not logged in
//  is redirected straight to the login page before any of the
//  markup below (including the CMS sync section) is rendered.
// ============================================================
require_once __DIR__ . '/api/bootstrap.php'; // session + DB + role constants — NOT api/config.php, which sends a JSON content-type header that would break this HTML page

if (empty($_SESSION['user_id'])) {
    header('Location: login_usa.html');
    exit;
}

$currentRole = $_SESSION['user_role'] ?? '';
$isAdmin     = ($currentRole === ROLE_ADMIN);

// ============================================================
//  "Sync a Section" tab logic (Admin-only).
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
//       IMPORTANT: this must match the shared secret in
//       _receive_common.php — rotate both together.
// ============================================================

// ── Config — EDIT THESE ─────────────────────────────────────
const CMS_API_BASE = 'http://localhost/classroomv2/api';
const CMS_API_KEY  = 'a888034173f952595606d57bf49804937dda9faea3d60c89426a7d0c4a239eda';

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

if ($course !== '' && $subject_name !== '' && !$isAdmin) {
    $fatal = 'Only Admin accounts can sync a section from the CMS.';
} elseif ($course !== '' && $subject_name !== '') {
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FPSTToolTrack - Inventory Management System</title>

<!-- Fonts & Icons -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Third-party libraries -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<!-- App styles -->
<link rel="stylesheet" href="css/styles.css">
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<aside class="sidebar">
  <div class="sidebar-header">
   <div class="sidebar-logo"><img src="FPST LOGO for sidebar.png" alt="Logo"></div>
   <div class="sidebar-title">FPST Tool<span>Track</span></div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">
      <div class="nav-section-title">Main Menu</div>
      <a class="nav-item active" data-page="dashboard"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
      <a class="nav-item" data-page="tools"><i class="fas fa-wrench"></i><span>Tools</span></a>
      <a class="nav-item" data-page="borrowers"><i class="fas fa-users"></i><span>Borrowers</span></a>
      <a class="nav-item" data-page="borrow"><i class="fas fa-hand-holding"></i><span>Borrow</span><span class="nav-badge" id="borrowBadge" style="display:none">0</span></a>
      <a class="nav-item" data-page="return"><i class="fas fa-undo"></i><span>Return</span></a>
      <?php if ($isAdmin): ?>
      <a class="nav-item" data-page="sync-section"><i class="fas fa-sync-alt"></i><span>Sync a Section</span></a>
      <?php endif; ?>
    </div>
    <div class="nav-section">
      <div class="nav-section-title">Analytics</div>
      <a class="nav-item" data-page="reports"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
    </div>
    <div class="nav-section">
      <div class="nav-section-title">Account</div>
      <?php if ($isAdmin): ?>
      <a class="nav-item" data-page="users"><i class="fas fa-user-shield"></i><span>User Accounts</span></a>
      <?php endif; ?>
      <a class="nav-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </div>
  </nav>

  <div class="sidebar-footer">
    <div class="user-card">
      <!-- Populated by api/auth.php GET → { name, role, initials } -->
      <div class="user-avatar" id="sidebarInitials">--</div>
      <div class="user-info">
        <div class="user-name" id="sidebarName">Loading…</div>
        <div class="user-role" id="sidebarRole"></div>
      </div>
    </div>
  </div>
</aside>

<!-- ═══════════════════════════════════════════════════════════
     MAIN CONTENT
═══════════════════════════════════════════════════════════ -->
<main class="main-content">

  <header class="header">
    <div class="header-left">
      <div>
        <h1 class="page-title" id="pageTitle">Dashboard</h1>
        <div class="breadcrumb"><a href="#">Home</a><i class="fas fa-chevron-right" style="font-size:10px"></i><span id="breadcrumbPage">Dashboard</span></div>
      </div>
    </div>
    <div class="header-right">
      <div class="search-box"><i class="fas fa-search"></i><input type="text" id="globalSearch" placeholder="Search tools, borrowers…"></div>
      <div style="display:flex;gap:8px">
        <button class="header-btn"><i class="fas fa-bell"></i><span class="badge"></span></button>
        <button class="header-btn"><i class="fas fa-cog"></i></button>
      </div>
      <div class="date-display"><i class="fas fa-calendar"></i><span id="currentDate"></span></div>
    </div>
  </header>

  <div class="page-content">

    <!-- ═══ DASHBOARD ═══ -->
    <section class="page active" id="dashboardPage">
      <div class="stats-grid">
        <!-- api/dashboard.php GET → { total_tools, available_tools, borrowed_tools, total_borrowers, low_stock_count, weekly_borrows[], weekly_returns[], most_borrowed[], recent_transactions[], low_stock_items[] } -->
        <div class="stat-card"><div class="stat-header"><div class="stat-icon purple"><i class="fas fa-tools"></i></div></div><div class="stat-value" id="dashTotal">—</div><div class="stat-label">Total Tools</div></div>
        <div class="stat-card"><div class="stat-header"><div class="stat-icon green"><i class="fas fa-check-circle"></i></div></div><div class="stat-value" id="dashAvailable">—</div><div class="stat-label">Available Tools</div></div>
        <div class="stat-card"><div class="stat-header"><div class="stat-icon orange"><i class="fas fa-hand-holding"></i></div></div><div class="stat-value" id="dashBorrowed">—</div><div class="stat-label">Borrowed Tools</div></div>
        <div class="stat-card"><div class="stat-header"><div class="stat-icon red"><i class="fas fa-users"></i></div></div><div class="stat-value" id="dashBorrowers">—</div><div class="stat-label">Total Borrowers</div></div>
      </div>

      <div class="content-grid">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Borrow Activity (This Week)</h3></div>
          <div class="card-body"><div class="chart-container"><canvas id="borrowChart"></canvas></div></div>
        </div>
        <div class="card">
          <div class="card-header"><h3 class="card-title">Most Borrowed Tools</h3></div>
          <div class="card-body"><div class="borrowed-list" id="dashMostBorrowed"><div class="empty-state"><i class="fa-solid fa-utensils"></i><p>Loading…</p></div></div></div>
        </div>
      </div>

      <div class="content-grid">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Recent Transactions</h3><button class="card-btn primary" onclick="navigateTo('borrow')"><i class="fas fa-plus"></i> New Borrow</button></div>
          <div class="card-body"><div id="dashRecentTx"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Loading…</p></div></div></div>
        </div>
        <div class="card">
          <div class="card-header"><h3 class="card-title">Low Stock Alerts</h3><span class="status-badge low-stock"><span class="status-dot"></span><span id="lowStockCount">0</span> Items</span></div>
          <div class="card-body"><div class="alert-list" id="dashAlerts"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Loading…</p></div></div></div>
        </div>
      </div>
    </section>

    <!-- ═══ TOOLS ═══ -->
    <section class="page" id="toolsPage">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Tools Inventory</h3>
          <div class="card-actions">
            <button class="card-btn secondary" onclick="exportToolsCSV()"><i class="fas fa-download"></i> Export CSV</button>
            <button class="card-btn primary" onclick="openAddToolModal()"><i class="fas fa-plus"></i> Add New Tool</button>
          </div>
        </div>
        <div class="filter-bar">
          <div class="filter-group"><span class="filter-label">Status:</span>
            <select class="filter-select" id="toolStatusFilter">
              <option value="">All Status</option>
              <option value="available">Available</option>
              <option value="borrowed">Borrowed</option>
              <option value="low-stock">Low Stock</option>
            </select>
          </div>
          <div class="filter-group"><span class="filter-label">Category:</span>
            <select class="filter-select" id="toolCategoryFilter">
              <option value="">All Categories</option>
              <option value="Utensils">Utensils</option>
              <option value="Cookware">Cookware</option>
              <option value="Measuring Tools">Measurement</option>
              <option value="Accessories">Accessories</option>
            </select>
          </div>
          <div class="filter-group"><input type="text" class="filter-input" id="toolSearchFilter" placeholder="Search tools…"></div>
          <button class="filter-btn apply" onclick="loadTools()">Apply</button>
          <button class="filter-btn clear" onclick="clearToolFilters()">Clear</button>
        </div>
        <div class="table-container">
          <table class="data-table">
            <thead><tr><th>Tool Name</th><th>Tool Code</th><th>Category</th><th>Total Qty</th><th>Available</th><th>Min Stock</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="toolsTableBody"><tr class="empty-row"><td colspan="8"><span class="spinner dark"></span> Loading…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination">
          <div class="pagination-info" id="toolsPaginationInfo"></div>
          <div class="pagination-btns" id="toolsPaginationBtns"></div>
        </div>
      </div>
    </section>

    <!-- ═══ BORROWERS ═══ -->
    <section class="page" id="borrowersPage">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Borrowers Directory</h3>
          <div class="card-actions">
            <button class="card-btn secondary" onclick="exportBorrowersCSV()"><i class="fas fa-download"></i> Export</button>
            <?php if ($isAdmin): ?>
            <button class="card-btn primary" onclick="openAddBorrowerModal()"><i class="fas fa-plus"></i> Add Borrower</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="filter-bar">
          <div class="filter-group"><span class="filter-label">Type:</span>
            <select class="filter-select" id="borrowerTypeFilter">
              <option value="">All Types</option>
              <option value="Student">Student</option>
              <option value="Faculty">Faculty</option>
              <option value="Staff">Staff</option>
            </select>
          </div>
          <div class="filter-group"><input type="text" class="filter-input" id="borrowerSearchFilter" placeholder="Search by name or ID…"></div>
          <button class="filter-btn apply" onclick="loadBorrowers()">Apply</button>
          <button class="filter-btn clear" onclick="clearBorrowerFilters()">Clear</button>
        </div>
        <div class="table-container">
          <table class="data-table">
            <thead><tr><th>Borrower Name</th><th>ID Number</th><th>Type</th><th>Course</th><th>Section</th><th>Contact Info</th><th>Active Borrows</th><th>Total Borrows</th><th>Actions</th></tr></thead>
            <tbody id="borrowerTableBody"><tr class="empty-row"><td colspan="9"><span class="spinner dark"></span> Loading…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination">
          <div class="pagination-info" id="borrowersPaginationInfo"></div>
          <div class="pagination-btns" id="borrowersPaginationBtns"></div>
        </div>
      </div>
    </section>

    <!-- ═══ BORROW ═══ -->
    <section class="page" id="borrowPage">
      <div class="br-layout">
        <div class="br-card">
          <div class="br-card-header"><div class="br-card-header-icon borrow"><i class="fas fa-hand-holding"></i></div><h3>Borrow Tool</h3></div>
          <div class="br-card-body">
            <label class="field-label"><i class="fas fa-video"></i> Camera Device</label>
            <select class="form-input-full" id="borrowCameraSelect"><option value="">Loading cameras…</option></select>

            <div class="scanner-zone">
              <div class="scanner-zone-label"><i class="fas fa-qrcode"></i> QR Code Scanner</div>
              <div class="scanner-controls">
                <button class="btn-scan start" id="borrowStartBtn" onclick="startBorrowScanner()"><i class="fas fa-camera"></i> Camera</button>
                <button class="btn-scan stop" id="borrowStopBtn" onclick="stopBorrowScanner()" style="display:none"><i class="fas fa-stop"></i> Stop</button>
                <button class="btn-upload" onclick="document.getElementById('borrowQrImageInput').click()"><i class="fas fa-image"></i> Upload QR</button>
                <input type="file" id="borrowQrImageInput" accept="image/*" style="display:none" onchange="borrowScanUploadedImage(event)">
              </div>
              <div class="qr-reader-viewport" id="borrowReader"></div>
              <div class="upload-preview" id="borrowUploadPreview"><span class="upload-preview-label">Scanning image…</span><img id="borrowUploadedQrImg" src="" alt="Uploaded QR"></div>
              <div class="scan-status" id="borrowScanStatus"><span class="pulse"></span><span id="borrowScanStatusText"></span></div>
            </div>

            <div class="or-divider">or enter manually</div>
            <label class="field-label">Tool Code</label>
            <div class="input-group">
              <input type="text" class="form-input-full" id="borrowToolId" placeholder="e.g. TL-DMM-001" oninput="onBorrowToolIdInput()">
              <button class="clear-btn" id="clearBorrowToolBtn" onclick="clearBorrowToolId()"><i class="fas fa-times-circle"></i></button>
            </div>

            <label class="field-label">Quantity</label>
            <input type="number" class="form-input-full" id="borrowQty" min="1" value="1">

            <label class="field-label">Borrower</label>
            
            <select class="form-input-full" id="borrowerSelect" onchange="onBorrowerSelectChange()"><option value="">Select Borrower…</option></select>

            <div class="or-divider">or add a new borrower</div>
            <label class="field-label">Borrower Name</label>
            <input type="text" class="form-input-full" id="borrowerNameInput" placeholder="Enter borrower's full name" oninput="onBorrowerNameInput()">

            <label class="field-label">ID Number</label>
          <input type="text" class="form-input-full" id="borrowerIdNumberInput" placeholder="e.g., 20222637" oninput="onBorrowerNameInput()">

          <label class="field-label">Type (optional)</label>
          <select class="form-input-full" id="borrowerTypeInput">
            <option value="">Not specified — will save as Guest</option>
            <option value="Student">Student</option>
            <option value="Faculty">Faculty</option>
            <option value="Staff">Staff</option>
            <option value="Guest">Guest</option>
          </select>

            <label class="field-label">Due Date</label>
            <input type="date" class="form-input-full" id="borrowDueDate">

            <label class="field-label">Notes (Optional)</label>
            <input type="text" class="form-input-full" id="borrowNotes" placeholder="Any special notes…">

            <button class="btn-primary-full" id="borrowSubmitBtn" onclick="handleBorrow()"><i class="fas fa-check-circle"></i> Confirm Borrow</button>
          </div>
        </div>
        <div class="br-card">
          <div class="br-card-header"><div class="br-card-header-icon borrow"><i class="fas fa-history"></i></div><h3>Borrow Activity</h3></div>
          <div class="br-card-body"><div id="borrowActivityFeed"><div class="empty-state"><i class="fas fa-hand-holding"></i><p>No borrows yet.<br>Process a borrow to see it here.</p></div></div></div>
        </div>
      </div>

      <div class="history-section">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Borrow Transaction History</h3>
            <!-- api/transactions.php?type=borrow&status= -->
            <select class="filter-select" id="borrowHistoryFilter" onchange="loadBorrowHistory()">
              <option value="">All</option><option value="active">Active</option><option value="returned">Returned</option>
            </select>
          </div>
          <div class="table-container"><table class="data-table"><thead><tr><th>Transaction ID</th><th>Tool Code</th><th>Tool Name</th><th>Borrower</th><th>Date &amp; Time</th><th>Due Date</th><th>Status</th></tr></thead><tbody id="borrowHistoryBody"><tr class="empty-row"><td colspan="7">No transactions yet.</td></tr></tbody></table></div>
        </div>
      </div>
    </section>

    <!-- ═══ RETURN ═══ -->
    <section class="page" id="returnPage">
      <div class="br-layout">
        <div class="br-card">
          <div class="br-card-header"><div class="br-card-header-icon return"><i class="fas fa-undo"></i></div><h3>Return Tool</h3></div>
          <div class="br-card-body">
            <label class="field-label"><i class="fas fa-video"></i> Camera Device</label>
            <select class="form-input-full" id="returnCameraSelect"><option value="">Loading cameras…</option></select>

            <div class="scanner-zone">
              <div class="scanner-zone-label"><i class="fas fa-qrcode"></i> QR Code Scanner</div>
              <div class="scanner-controls">
                <button class="btn-scan start" id="returnStartBtn" onclick="startReturnScanner()"><i class="fas fa-camera"></i> Camera</button>
                <button class="btn-scan stop" id="returnStopBtn" onclick="stopReturnScanner()" style="display:none"><i class="fas fa-stop"></i> Stop</button>
                <button class="btn-upload" onclick="document.getElementById('returnQrImageInput').click()"><i class="fas fa-image"></i> Upload QR</button>
                <input type="file" id="returnQrImageInput" accept="image/*" style="display:none" onchange="returnScanUploadedImage(event)">
              </div>
              <div class="qr-reader-viewport" id="returnReader"></div>
              <div class="upload-preview" id="returnUploadPreview"><span class="upload-preview-label">Scanning image…</span><img id="returnUploadedQrImg" src="" alt="Uploaded QR"></div>
              <div class="scan-status" id="returnScanStatus"><span class="pulse"></span><span id="returnScanStatusText"></span></div>
            </div>

            <div class="or-divider">or enter manually</div>
            <label class="field-label">Tool Code</label>
            <div class="input-group">
              <input type="text" class="form-input-full" id="returnToolId" placeholder="e.g. TL-DMM-001" oninput="onReturnToolIdInput()">
             <button class="clear-btn" id="clearReturnToolBtn" onclick="clearReturnToolId()"><i class="fas fa-times-circle"></i></button>
            </div>
           
            <label class="field-label">Quantity to Return</label>
           
            <input type="number" class="form-input-full" id="returnQty" min="1" value="1">
            <label class="field-label">Returnee Name *</label>
            <input type="text" class="form-input-full" id="returneeName" placeholder="Enter name of person returning the tool">
            <label class="field-label">Condition</label>
            <select class="form-input-full" id="returnCondition">
              <option value="good">Good — No Issues</option>
              <option value="minor">Minor Wear</option>
              <option value="damaged">Damaged — Needs Repair</option>
            </select>

            <label class="field-label">Return Notes (Optional)</label>
            <input type="text" class="form-input-full" id="returnNotes" placeholder="Any issues or comments…">

            <button class="btn-success-full" id="returnSubmitBtn" onclick="handleReturn()"><i class="fas fa-check-circle"></i> Confirm Return</button>
          </div>
        </div>
        <div class="br-card">
          <div class="br-card-header"><div class="br-card-header-icon return"><i class="fas fa-history"></i></div><h3>Return Activity</h3></div>
          <div class="br-card-body"><div id="returnActivityFeed"><div class="empty-state"><i class="fas fa-undo"></i><p>No returns yet.<br>Process a return to see it here.</p></div></div></div>
        </div>
      </div>

      <div class="history-section">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Return Transaction History</h3>
            <!-- api/transactions.php?type=return&condition= -->
            <select class="filter-select" id="returnHistoryFilter" onchange="loadReturnHistory()">
              <option value="">All</option><option value="good">Good Condition</option><option value="minor">Minor Wear</option><option value="damaged">Damaged</option>
            </select>
          </div>
          <div class="table-container"><table class="data-table"><thead><tr><th>Transaction ID</th><th>Tool Code</th><th>Returned By</th><th>Date &amp; Time</th><th>Condition</th><th>Notes</th></tr></thead><tbody id="returnHistoryBody"><tr class="empty-row"><td colspan="6">No returns yet.</td></tr></tbody></table></div>
        </div>
      </div>
    </section>

<?php if ($isAdmin): ?>
<!-- ═══ SYNC A SECTION ═══ -->
<section class="page" id="sync-sectionPage">
  <div class="card" style="padding: 25px;">
    <h1>Sync FPST Masterlist into Tooltrack</h1>
    <p>Pulls a course + subject roster from the CMS API and upserts it into <code>borrowers</code>.</p>

    <form method="GET">
      <input class="form-input-full"
        type="text"
        name="course"
        placeholder="Course, e.g. FPST"
        value="<?php echo htmlspecialchars($course); ?>"
        required
      >

      <input class="form-input-full"
        type="text"
        name="subject_name"
        placeholder="Subject name, e.g. Foods 9"
        value="<?php echo htmlspecialchars($subject_name); ?>"
        required
        style="min-width:220px;"
      >

      <button type="submit" class="btn-primary-full">Sync</button>
    </form>

    <?php if ($fatal): ?>
      <div class="box err">
        <strong>Error:</strong>
        <?php echo htmlspecialchars($fatal); ?>
      </div>
    <?php endif; ?>

    <?php if ($results): ?>
      <?php
        // Defensive: pull each bucket with ?? so a missing key never crashes
        // the page (e.g. if someone partially updates this file).
        $inserted    = $results['inserted']    ?? [];
        $updated     = $results['updated']     ?? [];
        $unchanged   = $results['unchanged']   ?? [];
        $deactivated = $results['deactivated'] ?? [];
        $groups      = $results['groups']      ?? [];
      ?>

      <div class="box">
        <strong>Matched groups:</strong>

        <ul>
          <?php foreach ($groups as $g): ?>
            <li><?php echo htmlspecialchars($g); ?></li>
          <?php endforeach; ?>
        </ul>

        <span class="tag"><?php echo count($inserted); ?> new</span>
        <span class="tag"><?php echo count($updated); ?> updated</span>
        <span class="tag"><?php echo count($unchanged); ?> unchanged</span>

        <span class="tag" style="background:#fee;border-color:#f88;">
          <?php echo count($deactivated); ?> deactivated
        </span>
      </div>

      <?php if ($inserted): ?>
        <div class="box">
          <strong>New borrowers</strong>
          <ul>
            <?php foreach ($inserted as $l): ?>
              <li><?php echo htmlspecialchars($l); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($updated): ?>
        <div class="box">
          <strong>Updated (name/type refreshed)</strong>
          <ul>
            <?php foreach ($updated as $l): ?>
              <li><?php echo htmlspecialchars($l); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($deactivated): ?>
        <div class="box">
          <strong>Deactivated enrollments (removed in CMS)</strong>
          <ul>
            <?php foreach ($deactivated as $l): ?>
              <li><?php echo htmlspecialchars($l); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</section>
<!-- ═══ END SYNC A SECTION ═══ -->
<?php endif; ?>

    <!-- ═══ REPORTS ═══ -->
    <section class="page" id="reportsPage">
      <div class="reports-grid">
        <div class="report-card" onclick="downloadReport('inventory')"><div class="report-icon report-icon-purple"><i class="fas fa-clipboard-list"></i></div><div class="report-info"><h3>Inventory Report</h3><p>Complete list of all tools with current status</p></div></div>
        <div class="report-card" onclick="downloadReport('transactions')"><div class="report-icon report-icon-green"><i class="fas fa-exchange-alt"></i></div><div class="report-info"><h3>Transaction Report</h3><p>All borrow and return transactions</p></div></div>
        <div class="report-card" onclick="downloadReport('borrowers')"><div class="report-icon report-icon-orange"><i class="fas fa-users"></i></div><div class="report-info"><h3>Borrower Activity</h3><p>Borrowing patterns and statistics</p></div></div>
        <div class="report-card" onclick="downloadReport('overdue')"><div class="report-icon report-icon-red"><i class="fas fa-exclamation-triangle"></i></div><div class="report-info"><h3>Overdue Report</h3><p>Tools past their due date</p></div></div>
      </div>
      <div class="content-grid" style="margin-top:32px">
        <div class="card"><div class="card-header"><h3 class="card-title">Monthly Statistics</h3></div><div class="card-body"><div class="chart-container"><canvas id="monthlyChart"></canvas></div></div></div>
        <div class="card"><div class="card-header"><h3 class="card-title">Category Distribution</h3></div><div class="card-body"><div class="chart-container"><canvas id="categoryChart"></canvas></div></div></div>
      </div>
    </section>

    <?php if ($isAdmin): ?>
    <!-- ═══ USERS ═══ -->
    <section class="page" id="usersPage">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">User Accounts</h3>
          <div class="card-actions">
            <button class="card-btn primary" onclick="openAddUserModal()"><i class="fas fa-plus"></i> Add User</button>
          </div>
        </div>
        <div class="table-container">
          <table class="data-table">
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody id="usersTableBody"><tr class="empty-row"><td colspan="5"><span class="spinner dark"></span> Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </section>
    <?php endif; ?>

  </div><!-- /page-content -->
</main>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Add / Edit Tool
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="toolModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="toolModalTitle">Add New Tool</h3>
      <button class="modal-close" onclick="closeModal('toolModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editToolId">
      <div class="form-group"><label class="field-label">Tool Name *</label><input type="text" id="t_name" class="form-input-full" placeholder="Enter tool name"></div>
      <div class="form-group"><label class="field-label">Tool Code *</label><input type="text" id="t_code" class="form-input-full" placeholder="e.g., TL-XXX-001"></div>
      <div class="form-group"><label class="field-label">Category *</label>
        <select id="t_category" class="form-input-full">
          <option value="">Select category</option>
          <option value="Electronics">Electronics</option>
          <option value="Mechanical">Mechanical</option>
          <option value="Measurement">Measurement</option>
          <option value="Accessories">Accessories</option>
        </select>
      </div>
      <div class="form-group"><label class="field-label">Total Quantity *</label><input type="number" id="t_qty" class="form-input-full" placeholder="Enter quantity" min="1"></div>
      <div class="form-group"><label class="field-label">Minimum Stock Level *</label><input type="number" id="t_min" class="form-input-full" placeholder="Alert when below this" min="1"></div>
      <div class="form-group"><label class="field-label">Description</label><input type="text" id="t_desc" class="form-input-full" placeholder="Brief description (optional)"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('toolModal')">Cancel</button>
      <button class="btn btn-blue" id="saveToolBtn" onclick="saveTool()">Add Tool</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Add / Edit Borrower
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="borrowerModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="borrowerModalTitle">Add New Borrower</h3>
      <button class="modal-close" onclick="closeModal('borrowerModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editBorrowerId">
      <input type="hidden" id="editEnrollmentId">
      <div class="form-group"><label class="field-label">Full Name *</label><input type="text" id="b_name" class="form-input-full" placeholder="Enter full name"></div>
      <div class="form-group"><label class="field-label">Course *</label><select id="b_course" class="form-input-full"><option value="">Select course</option></select></div>
      <div class="form-group"><label class="field-label">Section *</label><select id="b_section" class="form-input-full"><option value="">Select section</option></select></div>
      <div class="form-group"><label class="field-label">ID Number *</label><input type="text" id="b_idnum" class="form-input-full" placeholder="e.g., 2024-0001"></div>
      <div class="form-group"><label class="field-label">Type *</label>
        <select id="b_type" class="form-input-full">
          <option value="">Select type</option>
          <option value="Student">Student</option>
          <option value="Faculty">Faculty</option>
          <option value="Staff">Staff</option>
        </select>
      </div>
      <div class="form-group"><label class="field-label">Email</label><input type="email" id="b_email" class="form-input-full" placeholder="email@school.edu"></div>
      <div class="form-group"><label class="field-label">Phone Number</label><input type="tel" id="b_phone" class="form-input-full" placeholder="Contact number"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('borrowerModal')">Cancel</button>
      <button class="btn btn-blue" id="saveBorrowerBtn" onclick="saveBorrower()">Add Borrower</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Confirm Delete
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="confirmDeleteModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3 class="modal-title">Confirm Delete</h3><button class="modal-close" onclick="closeModal('confirmDeleteModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body"><p id="deleteConfirmText" style="color:var(--gray-600);font-size:14px;line-height:1.7"></p></div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('confirmDeleteModal')">Cancel</button>
      <button class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>

<?php if ($isAdmin): ?>
<!-- ═══════════════════════════════════════════════════════════
     MODAL: Add User
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="userModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title">Add User Account</h3>
      <button class="modal-close" onclick="closeModal('userModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group"><label class="field-label">Full Name *</label><input type="text" id="u_name" class="form-input-full" placeholder="Enter full name"></div>
      <div class="form-group"><label class="field-label">Username *</label><input type="text" id="u_username" class="form-input-full" placeholder="Login username" autocomplete="off"></div>
      <div class="form-group"><label class="field-label">Password *</label><input type="password" id="u_password" class="form-input-full" placeholder="At least 8 characters" autocomplete="new-password"></div>
      <div class="form-group"><label class="field-label">Role *</label>
        <select id="u_role" class="form-input-full">
          <option value="Staff">Staff</option>
          <option value="Admin">Admin</option>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('userModal')">Cancel</button>
      <button class="btn btn-blue" id="saveUserBtn" onclick="saveUser()">Add User</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Current user's role, for client-side UI gating (the API endpoints
     enforce the real access control — see api/config.php's requireRole).
     Client-side hiding here is only so Staff don't see buttons they'd
     just get a 403 from; it is NOT the security boundary. -->
<script>window.CURRENT_ROLE = <?php echo json_encode($currentRole); ?>;</script>
<script src="js/app.js"></script>
</body>
</html>
