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
    header('Location: Login.html');
    exit;
}

$currentRole = $_SESSION['user_role'] ?? '';
$isAdmin     = ($currentRole === ROLE_ADMIN);

// ============================================================
//  "Sync a Section" tab logic (Admin-only).
//  Pulls a course+subject roster from the Classroom Management
//  System's API (GET /api/masterlist.php?course=&subject_name=)
//  and syncs it into the borrowers table, so those students
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

// Use the same database settings as the rest of the app (api/bootstrap.php)
// so this page can never point at a different database than the API.
$db_host = DB_HOST;
$db_name = DB_NAME;
$db_user = DB_USER;
$db_pass = DB_PASS;
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
// Matches on id_number (which has a UNIQUE key in the borrowers table).
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

        $insCount = count($results['inserted']);
        $updCount = count($results['updated']);
        logAudit('cms_sync', "Synced $course / $subject_name from CMS — $insCount new, $updCount updated");
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
<!-- Chart.js Core -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Chart.js Datalabels Plugin -->
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<!-- App styles -->
<link rel="stylesheet" href="css/styles.css"><link rel="stylesheet" href="css/incident-ui.css">
<meta name="theme-color" content="#6426df">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" href="assets/pwa/icon-192.png">
<link rel="apple-touch-icon" href="assets/pwa/icon-192.png">
<script src="assets/js/pwa.js" defer></script>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<aside class="sidebar">
  <div class="sidebar-header">
   <div class="sidebar-logo"><img src="assets/FPST logo for sidebar.png" alt="Logo"></div>
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
      <a class="nav-item" data-page="replacements"><i class="fas fa-rotate"></i><span>Damage &amp; Loss Tracking</span></a>
      <?php if ($isAdmin): ?>
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
      <!-- <a class="nav-item" data-page="audit"><i class="fas fa-clipboard-list"></i><span>Audit Trail</span></a> -->
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

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ═══════════════════════════════════════════════════════════
     MAIN CONTENT
═══════════════════════════════════════════════════════════ -->
<main class="main-content">

  <header class="header">
    <div class="header-left">
      <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleSidebar()" aria-label="Menu"><i class="fas fa-bars"></i></button>
      <div>
        <h1 class="page-title" id="pageTitle">Dashboard</h1>
        <div class="breadcrumb"><a href="#" onclick="navigateTo('dashboard');return false;">Home</a><i class="fas fa-chevron-right" style="font-size:10px"></i><span id="breadcrumbPage">Dashboard</span></div>
      </div>
    </div>
    <div class="header-right">
      <div class="search-box"><i class="fas fa-search"></i><input type="text" id="globalSearch" name="tool_search_q" placeholder="Search for tools" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true"></div>
      <div style="display:flex;gap:8px;position:relative">
        <button class="header-btn" id="notifBtn" onclick="toggleNotifPanel()"><i class="fas fa-bell"></i><span id="notifBadge" style="display:none;position:absolute;top:2px;right:2px;min-width:16px;height:16px;padding:0 4px;background:var(--danger,#ef4444);color:#fff;border-radius:9px;border:2px solid #fff;font-size:10px;font-weight:700;align-items:center;justify-content:center;line-height:1"></span></button>
        <button class="header-btn" onclick="openSettingsModal()"><i class="fas fa-cog"></i></button>
        <div id="notifPanel" style="display:none;position:absolute;top:44px;right:0;width:340px;max-width:calc(100vw - 32px);max-height:420px;overflow:auto;background:#fff;border:1px solid var(--gray-200,#e5e7eb);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.15);z-index:50">
          <div style="padding:12px 16px;font-weight:600;border-bottom:1px solid var(--gray-100,#f0f0f0)">Notifications</div>
          <div id="notifList" style="padding:8px"></div>
        </div>
      </div>
      <div class="date-display"><i class="fas fa-calendar"></i><span id="currentDate"></span></div>
    </div>
  </header>

  <div class="page-content">

    <!-- ═══ DASHBOARD ═══ -->
    <section class="page active" id="dashboardPage">
      <div class="stats-grid">
        <!-- api/dashboard.php: tool types count active inventory records; unit cards retain existing stock calculations. -->
        <div class="stat-card"><div class="stat-header"><div class="stat-icon purple"><i class="fas fa-tools"></i></div></div><div class="stat-value" id="dashTotal">—</div><div class="stat-label">Total Tool Types</div></div>
        <div class="stat-card"><div class="stat-header"><div class="stat-icon purple"><i class="fas fa-cubes"></i></div></div><div class="stat-value" id="dashTotalUnits">—</div><div class="stat-label">Total Units</div></div>
        <div class="stat-card"><div class="stat-header"><div class="stat-icon green"><i class="fas fa-check-circle"></i></div></div><div class="stat-value" id="dashAvailable">—</div><div class="stat-label">Available Units</div></div>
        <div class="stat-card"><div class="stat-header"><div class="stat-icon orange"><i class="fas fa-hand-holding"></i></div></div><div class="stat-value" id="dashBorrowed">—</div><div class="stat-label">Borrowed Units</div></div>
        <div class="stat-card"><div class="stat-header"><div class="stat-icon red"><i class="fas fa-screwdriver-wrench"></i></div></div><div class="stat-value" id="dashDamaged">—</div><div class="stat-label">Damaged Units</div></div>
        <div class="stat-card"><div class="stat-header"><div class="stat-icon red"><i class="fas fa-magnifying-glass-minus"></i></div></div><div class="stat-value" id="dashMissing">—</div><div class="stat-label">Missing Units</div></div>
        <div class="stat-card"><div class="stat-header"><div class="stat-icon red"><i class="fas fa-users"></i></div></div><div class="stat-value" id="dashBorrowers">—</div><div class="stat-label">Total Borrowers</div></div>
      </div>

      <div class="content-grid">
        <div class="card">
          <div class="card-header wrap">
            <h3 class="card-title">Borrow Activity <span class="title-sub" id="dashChartBadge"></span></h3>
            <div class="dash-chart-controls">
              <div class="seg" role="group" aria-label="Chart range">
                <button type="button" class="seg-btn active" id="dashModeMonth" onclick="dashSetMode('month')">Week 1–4</button>
                <button type="button" class="seg-btn" id="dashModeWeek" onclick="dashSetMode('week')">Last 7 days</button>
              </div>
              <div class="month-nav" id="dashMonthNav">
                <button type="button" class="nav-arrow" id="dashPrevBtn" aria-label="Previous month" onclick="dashShiftMonth(1)"><i class="fas fa-chevron-left"></i></button>
                <select class="filter-select" id="dashMonthSelect" aria-label="Select month" onchange="loadDashMonth(this.value)"></select>
                <button type="button" class="nav-arrow" id="dashNextBtn" aria-label="Next month" onclick="dashShiftMonth(-1)"><i class="fas fa-chevron-right"></i></button>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="chart-container"><canvas id="borrowChart"></canvas></div>
            <p class="forecast-note" id="dashChartNote"></p>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><h3 class="card-title">Top Borrowed Tools <span style="font-weight:400;color:var(--gray-400);font-size:12px">(Top 5)</span></h3></div>
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
            <?php if ($isAdmin): ?><button class="card-btn secondary" type="button" onclick="openToolDisposalHistory()"><i class="fas fa-box-open"></i> Disposed Tools</button><?php endif; ?>
            <button class="card-btn secondary" onclick="exportToolsCSV()"><i class="fas fa-download"></i> Export CSV</button>
            <button class="card-btn primary" onclick="openAddToolModal()"><i class="fas fa-plus"></i> Add New Tool</button>
          </div>
        </div>
        <div class="filter-bar">
          <div class="filter-group"><span class="filter-label">Status:</span>
            <select class="filter-select" id="toolStatusFilter">
              <option value="">All Status</option>
              <option value="available">Available</option>
              <option value="low-stock">Low Stock</option>
              <option value="out-of-stock">Out of Stock</option>
            </select>
          </div>
          <div class="filter-group"><span class="filter-label">Category:</span>
            <select class="filter-select" id="toolCategoryFilter" onchange="applyToolFilters()">
              <option value="">All Categories</option>
              <option value="Utensils">Utensils</option>
              <option value="Cookware">Cookware</option>
              <option value="Measuring Tools">Measurement</option>
              <option value="Accessories">Accessories</option>
              <option value="Dinnerware">Dinnerware</option>
              <option value="Cutleries">Cutleries</option>
              <option value="Glassware">Glassware</option>
            </select>
          </div>
          <div class="filter-group"><input type="text" class="filter-input" id="toolSearchFilter" oninput="searchToolsAsYouType()" placeholder="Search tools…"></div>
          <button class="filter-btn apply" onclick="applyToolFilters()">Apply</button>
          <button class="filter-btn clear" onclick="clearToolFilters()">Clear</button>
        </div>
        <div class="table-container">
          <table class="data-table">
            <thead><tr><th>Tool Name</th><th>Tool Codes</th><th>Total Quantity</th><th>Good Condition</th><th>Minor Wear</th><th>Damaged</th><th>Missing</th><th>Unit Price</th><th>Active Borrowers</th><th>Current Loans</th><th class="tool-actions-th"><span class="sr-only-txt">Actions</span></th></tr></thead>
            <tbody id="toolsTableBody"><tr class="empty-row"><td colspan="11"><span class="spinner dark"></span> Loading…</td></tr></tbody>
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
          <div class="card-actions borrowers-header-actions">
            <button class="card-btn secondary" onclick="exportBorrowersCSV()"><i class="fas fa-download"></i> Export</button>
            <button class="card-btn secondary" onclick="openStudentImportModal()"><i class="fas fa-file-import"></i> Import Masterlist</button>
            <?php if ($isAdmin): ?>
            <button class="card-btn secondary" onclick="openSectionsModal()"><i class="fas fa-layer-group"></i> Update Sections</button>
            <?php endif; ?>
            <button class="card-btn primary" onclick="openAddBorrowerModal()"><i class="fas fa-plus"></i> Add Borrower</button>
          </div>
        </div>
        <div class="borrowers-mobile-toolbar"><button type="button" class="borrowers-filter-toggle" aria-expanded="false" aria-controls="borrowersFilters" onclick="toggleBorrowersFilters(this)"><i class="fas fa-sliders"></i> Filters <i class="fas fa-chevron-down"></i></button></div>
        <div class="filter-bar" id="borrowersFilters">
          <div class="filter-group"><span class="filter-label">Type:</span>
            <select class="filter-select" id="borrowerTypeFilter" onchange="applyBorrowerFilters()">
              <option value="">All Types</option>
              <option value="Student">Student</option>
              <option value="Faculty">Faculty</option>
              <option value="Staff">Staff</option>
              <!-- <option value="Guest">Guest</option> -->
            </select>
          </div>
          <div class="filter-group"><span class="filter-label">Program:</span>
            <select class="filter-select" id="borrowerCourseFilter" onchange="onBorrowerCourseFilterChange()">
              <option value="">All</option>
            </select>
          </div>
          <div class="filter-group"><span class="filter-label">Section:</span>
            <select class="filter-select" id="borrowerSectionFilter" onchange="applyBorrowerFilters()" disabled>
              <option value="">All</option>
            </select>
          </div>
          <div class="filter-group"><span class="filter-label">Status:</span>
            <select class="filter-select" id="borrowerStatusFilter" onchange="applyBorrowerFilters()">
              <option value="">All</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="filter-group"><input type="text" class="filter-input" id="borrowerSearchFilter" placeholder="Search by name or ID…" autocomplete="off" oninput="onBorrowerFilterInput()" onkeydown="if(event.key==='Enter'){applyBorrowerFilters()}"></div>
          <button class="filter-btn apply" onclick="applyBorrowerFilters()">Apply</button>
          <button class="filter-btn clear" onclick="clearBorrowerFilters()">Clear</button>
          <div class="filter-group" style="margin-left:auto"><span class="filter-label">Rows:</span>
            <select class="filter-select" id="borrowerPageSize" onchange="changeBorrowerPageSize()">
              <option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option>
            </select>
          </div>
        </div>
        <div class="table-container borrowers-table-container">
          <table class="data-table borrowers-responsive-table">
            <thead><tr><th data-sort="full_name" onclick="sortBorrowers('full_name')" style="cursor:pointer;user-select:none">Borrower Name <i class="fas fa-sort" style="opacity:.4"></i></th><th data-sort="id_number" onclick="sortBorrowers('id_number')" style="cursor:pointer;user-select:none">ID Number <i class="fas fa-sort" style="opacity:.4"></i></th><th data-sort="type" onclick="sortBorrowers('type')" style="cursor:pointer;user-select:none">Type <i class="fas fa-sort" style="opacity:.4"></i></th><th>Course</th><th>Section</th><th>Year Level</th><th>Contact Info</th><th data-sort="active_borrows" onclick="sortBorrowers('active_borrows')" style="cursor:pointer;user-select:none">Active Borrows <i class="fas fa-sort" style="opacity:.4"></i></th><th data-sort="total_borrows" onclick="sortBorrowers('total_borrows')" style="cursor:pointer;user-select:none">Total Borrows <i class="fas fa-sort" style="opacity:.4"></i></th><th>Actions</th></tr></thead>
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
      <p class="rp-note">Manage checkout, active loans, overdue tools.</p>
      <div class="rp-actions circulation-tabs" role="group" aria-label="Borrowing views">
       <button type="button" class="card-btn secondary" data-circulation-tab="checkout" aria-pressed="true" onclick="circulationTab('checkout')">Borrow / Scan</button>
       <button type="button" class="card-btn secondary" data-circulation-tab="active" aria-pressed="false" onclick="circulationTab('active')">Active Borrowings</button>
       <button type="button" class="card-btn secondary" data-circulation-tab="overdue" aria-pressed="false" onclick="circulationTab('overdue')">Overdue</button>
       <button type="button" class="card-btn secondary" data-circulation-tab="history" aria-pressed="false" onclick="circulationTab('history')">History</button>
      </div>
      <div id="circulationLoansHost" hidden><div class="history-section" id="circulationLoanHistory">
        <div class="card">
          <div class="card-header wrap">
            <h3 class="card-title">Borrowing history</h3>
            <input type="search" id="borrowHistorySearch" class="form-input-full" style="max-width:260px" placeholder="Search TXN, tool or borrower..." aria-label="Search borrowing history" oninput="searchBorrowHistory()">
            <!-- api/transactions.php?type=borrow&status= -->
            <button type="button" class="card-btn secondary" onclick="exportLoanHistory()" title="Download the full transaction report as CSV"><i class="fas fa-download"></i> Export CSV</button>
            <select class="filter-select" id="borrowHistoryFilter" onchange="loadBorrowHistory()">
              <option value="">All</option><option value="active">Active</option><option value="overdue">Overdue</option><option value="due_today">Due Today</option><option value="followup">Follow-up (7+ days)</option><option value="missing">Has missing units</option><option value="returned">Closed</option>
            </select>
          </div>
          <div class="table-container" id="borrowHistoryTableContainer"><table class="data-table"><thead><tr><th>Transaction ID</th><th>Tool Code</th><th>Tool Name</th><th>Borrower</th><th>Date &amp; Time</th><th>Due Date</th><th>Qty Borrowed</th><th>Qty Returned</th><th>Status</th><th>Actions</th></tr></thead><tbody id="borrowHistoryBody"><tr class="empty-row"><td colspan="10">No transactions yet.</td></tr></tbody></table></div>
        </div>
      </div>

</div>
      <div class="br-layout">

        <!-- Phone / tablet start strip. Hidden on desktop, where the whole form is already on screen. -->
        <div class="bw-home" id="bwHome">
          <div class="bw-hero">
            <div class="bw-hero-text">
              <h2 class="bw-hero-title">Ready to borrow?</h2>
              <p class="bw-hero-sub">Scan a tool or student ID to start.</p>
            </div>
            <button type="button" class="bw-hero-btn" onclick="brQuick('tool')"><i class="fas fa-qrcode"></i> Scan QR or Barcode</button>
          </div>

          <h3 class="bw-h">Quick actions</h3>
          <div class="bw-quick">
            <button type="button" class="bw-q" onclick="brQuick('tool')"><span class="bw-q-ico"><i class="fas fa-qrcode"></i></span><span class="bw-q-t">Borrow</span><span class="bw-q-s">Scan tool</span></button>
            <button type="button" class="bw-q" onclick="brQuick('id')"><span class="bw-q-ico"><i class="fas fa-id-card"></i></span><span class="bw-q-t">Borrow</span><span class="bw-q-s">Scan ID</span></button>
            <button type="button" class="bw-q" onclick="brQuick('cart')"><span class="bw-q-ico"><i class="fas fa-basket-shopping"></i><span class="bw-q-badge" id="bwCartBadge" hidden>0</span></span><span class="bw-q-t">Multiple borrow</span><span class="bw-q-s">Selected tools</span></button>
            <button type="button" class="bw-q" onclick="brOpenHistory()"><span class="bw-q-ico"><i class="fas fa-clock-rotate-left"></i></span><span class="bw-q-t">View history</span><span class="bw-q-s">Recent borrows</span></button>
          </div>

          <h3 class="bw-h">Overview</h3>
          <div class="bw-stats">
            <div class="bw-stat ok"><i class="fas fa-circle-check"></i><b id="bwAvail">—</b><span>Available units</span></div>
            <div class="bw-stat"><i class="fas fa-hand-holding"></i><b id="bwBorrowed">—</b><span>Borrowed units</span></div>
            <div class="bw-stat late"><i class="fas fa-triangle-exclamation"></i><b id="bwOverdue">—</b><span>Overdue loans</span></div>
          </div>
        </div>

        <div class="br-card br-form">
          <div class="br-card-header"><div class="br-card-header-icon borrow"><i class="fas fa-hand-holding"></i></div><h3>Borrow Tool</h3></div>
          <div class="br-card-body">

            <!-- ── Tool: scan or search, then set a quantity ── -->
            <div class="br-sec" id="brSecTool">
              <h3 class="br-sec-title"><i class="fas fa-toolbox"></i> Tool</h3>
              <div id="borrowCameraField" style="display:none">
                <label class="field-label"><i class="fas fa-video"></i> Camera Device</label>
                <select class="form-input-full" id="borrowCameraSelect"><option value="">Press "Camera" to enable</option></select>
              </div>

              <div class="scanner-zone" id="toolScanZone">
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

              <label class="field-label" for="borrowToolId">Scanned Tool Code</label>
              <div class="input-group">
                <input type="text" class="form-input-full" id="borrowToolId" placeholder="Scanned code will appear here." readonly autocomplete="off" spellcheck="false">
                <button type="button" class="clear-btn" id="clearBorrowToolBtn" onclick="clearBorrowToolId()" aria-label="Clear tool code"><i class="fas fa-times-circle"></i></button>
              </div>

              <div class="or-divider">or search manually</div>
              <label class="field-label" for="borrowToolSearchInput">Tool *</label>
              <div id="borrowToolSearchWrap" style="position:relative">
                <input type="text" class="form-input-full" id="borrowToolSearchInput"
                       placeholder="Search tool by name or code..." autocomplete="off"
                       oninput="filterBorrowToolDropdown()" onfocus="openBorrowToolDropdown()">
                <div id="borrowToolDropdownList" class="br-dd tool" style="display:none"></div>
              </div>
              <p id="borrowToolSelectHint" class="br-hint"></p>

              <!-- Shown once a tool is picked (scan, code or search): the "Tool found" card -->
              <div class="br-tool" id="brToolPreview" hidden>
                <span class="bt-ico"><i class="fas fa-toolbox" id="brToolIco"></i></span>
                <div class="br-tool-main"><b id="brToolName"></b><small id="brToolMeta"></small></div>
                <span class="br-avail" id="brToolAvail"></span>
              </div>

              <label class="field-label" for="borrowQty">Quantity</label>
              <div class="bt-addrow">
                <div class="br-qty">
                  <button type="button" class="br-qty-btn" id="borrowQtyDec" onclick="brQtyStep(-1)" aria-label="Decrease quantity"><i class="fas fa-minus"></i></button>
                  <input type="number" class="form-input-full" id="borrowQty" min="1" value="1" inputmode="numeric" onchange="brQtyClamp()">
                  <button type="button" class="br-qty-btn" id="borrowQtyInc" onclick="brQtyStep(1)" aria-label="Increase quantity"><i class="fas fa-plus"></i></button>
                </div>
                <button type="button" class="card-btn primary bt-add" id="borrowAddBtn" onclick="btAddFromForm()"><i class="fas fa-plus"></i> Add Tool</button>
              </div>
            </div>

            <!-- ── Borrower: scan the ID or search ── -->
            <div class="br-sec" id="brSecBorrower">
              <h3 class="br-sec-title"><i class="fas fa-user"></i> Borrower</h3>
              <!-- ID scan: its own reader element (borrowReader belongs to the tool scanner) -->
              <div class="scanner-zone" id="idScanZone">
                <div class="scanner-zone-label"><i class="fas fa-id-card"></i> Scan Student ID</div>
                <div id="idCameraField" style="display:none">
                  <label class="field-label"><i class="fas fa-video"></i> Camera Device</label>
                  <select class="form-input-full" id="idCameraSelect"><option value="">Press "Scan Student ID" to enable</option></select>
                </div>
                <div class="scanner-controls">
                  <button class="btn-scan start" id="idStartBtn" onclick="startIdScanner()"><i class="fas fa-camera"></i> Scan Student ID</button>
                  <button class="btn-scan stop" id="idStopBtn" onclick="stopIdScanner()" style="display:none"><i class="fas fa-stop"></i> Stop</button>
                </div>
                <div class="qr-reader-viewport" id="idReader"></div>
                <input type="text" class="form-input-full" id="idScanInput" placeholder="Scan ID here (USB or phone scanner)"
                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true"
                       style="margin-top:10px">
                <div class="scan-status" id="idScanStatus"><span class="pulse"></span><span id="idScanStatusText"></span></div>
                <p id="idScanInfo" class="br-hint" style="margin:6px 0 0"></p>
              </div>

              <label class="field-label" for="borrowerSearchInput" id="borrowerSearchLabel">Borrower</label>
              <div id="borrowerSearchWrap" style="position:relative">
                <input type="text" class="form-input-full" id="borrowerSearchInput"
                       placeholder="Search borrower by name or ID…" autocomplete="off"
                       oninput="filterBorrowerDropdown()" onfocus="openBorrowerDropdown()">
                <div id="borrowerDropdownList" class="br-dd" style="display:none"></div>
              </div>
              <!-- Real value still lives here — the search box above just drives it,
                   so every place in app.js that reads borrowerSelect.value / .options
                   keeps working unchanged. -->
              <select class="form-input-full" id="borrowerSelect" style="display:none">
                <option value="">Select Borrower…</option>
              </select>

              <!-- Borrower information card: filled once a borrower is picked (search or ID scan) -->
              <div class="br-bcard" id="brBorrowerCard" hidden></div>
            </div>

            <!-- ── Loan details ── -->
            <div class="br-sec" id="brSecLoan">
              <h3 class="br-sec-title"><i class="fas fa-calendar-day"></i> Loan details</h3>
              <label class="field-label" for="borrowDueDate">Due Date</label>
              <input type="date" class="form-input-full" id="borrowDueDate">
              <label class="field-label" for="borrowDueTime">Due Time</label>
              <input type="time" class="form-input-full" id="borrowDueTime" step="60" required>
              <dialog id="brTimePicker" aria-labelledby="brTimeTitle">
                <div class="br-time-body">
                  <h3 id="brTimeTitle">Set due time</h3>
                  <p>Choose when the tools should be returned.</p>
                  <div class="br-time-fields">
                    <label>Hour<select id="brTimeHour"></select></label>
                    <label>Minute<select id="brTimeMinute"></select></label>
                    <label>AM / PM<select id="brTimePeriod"><option>AM</option><option>PM</option></select></label>
                  </div>
                  <div class="br-time-actions">
                    <button type="button" class="br-btn-outline" onclick="document.getElementById('brTimePicker').close()">Cancel</button>
                    <button type="button" class="btn-primary-full" onclick="brSaveDueTime()">Save time</button>
                  </div>
                </div>
              </dialog>

              <label class="field-label" for="borrowNotes">Notes (Optional)</label>
              <input type="text" class="form-input-full" id="borrowNotes" placeholder="Any special notes…" maxlength="500"><?php if($isAdmin): ?><label class="field-label" for="borrowOverrideReason">Admin policy override reason (optional)</label><input id="borrowOverrideReason" class="form-input-full" maxlength="500" placeholder="Required when overriding a limit, duration or overdue restriction"><?php endif; ?>
            </div>

            <!-- Sticky confirm bar. One button for every screen size: phones/tablets review first, desktop submits straight away. -->
            <div class="br-bar" id="brBar">
              <div class="br-bar-sum" aria-live="polite"><b id="brBarCount">No tools yet</b><small id="brBarWho">Select a borrower</small></div>
              <button type="button" class="btn-primary-full" id="borrowSubmitBtn" onclick="borrowConfirm()" disabled><i class="fas fa-check-circle"></i> Confirm Borrow</button>
            </div>
          </div>
        </div>

        <!-- ═══ SELECTED TOOLS: the borrow cart. Confirm Borrow sends every line at once (api/transactions.php type=borrow_batch, all-or-nothing) ═══ -->
        <div class="br-card bt-card" id="borrowCartCard">
          <div class="br-card-header"><div class="br-card-header-icon borrow"><i class="fas fa-toolbox"></i></div><h3>Selected Tools</h3><span class="bt-count" id="btCount">0 tool types</span></div>
          <div class="br-card-body">
            <div id="btCartList" aria-live="polite"></div>
            <div class="bt-total"><span>Total requested quantity</span><b id="btTotal">0</b></div>
          </div>
        </div>
      </div>

      <!-- ── Phone scan mode: appears over the page only while a camera is running (see brScanLive) ── -->
      <div class="br-scan" id="brScan" aria-hidden="true">
        <div class="br-scan-top">
          <button type="button" class="br-scan-back" onclick="brScanClose()" aria-label="Close scanner"><i class="fas fa-arrow-left"></i></button>
          <span class="br-scan-title">Scan</span>
        </div>
        <div class="br-scan-panel">
          <div class="br-seg" role="group" aria-label="What to scan">
            <button type="button" id="brSegTool" class="on" aria-pressed="true" onclick="brSwitchScan('tool')">Tool QR</button>
            <button type="button" id="brSegId" aria-pressed="false" onclick="brSwitchScan('id')">ID BARCODE</button>
          </div>
          <div class="br-scan-status" id="brScanMirror" aria-live="polite"></div>
          <p class="br-scan-tip"><i class="fas fa-lightbulb"></i> Make sure the code is clear and well lit.</p>
          <button type="button" class="br-scan-upload" id="brScanUpload" onclick="document.getElementById('borrowQrImageInput').click()"><i class="fas fa-image"></i> Upload QR image</button>
        </div>
      </div>

      <!-- ── Review step (phones / tablets): borrower + items before the loan is recorded ── -->
      <div class="br-sheet" id="brReview" role="dialog" aria-modal="true" aria-labelledby="brReviewTitle" hidden>
        <div class="br-sheet-card">
          <div class="br-sheet-top">
            <button type="button" class="br-sheet-x" onclick="brCloseReview()" aria-label="Back to the borrow form"><i class="fas fa-arrow-left"></i></button>
            <h2 id="brReviewTitle">Confirm Borrow</h2>
          </div>
          <div class="br-sheet-body">
            <h3 class="br-sheet-h"><i class="fas fa-user"></i> Borrower information</h3>
            <div class="br-bcard" id="brReviewBorrower"></div>
            <h3 class="br-sheet-h"><i class="fas fa-toolbox"></i><span>Items (<span id="brReviewCount">0</span>)</span></h3>
            <div id="brReviewItems" class="br-ritems"></div>
            <dl class="br-rmeta">
              <div><dt>Due date</dt><dd id="brReviewDue">—</dd></div>
              <div><dt>Notes</dt><dd id="brReviewNotes">—</dd></div>
            </dl>
            <p class="br-note"><i class="fas fa-circle-info"></i> Please check the items and borrower information before confirming.</p>
          </div>
          <div class="br-sheet-foot">
            <button type="button" class="btn-primary-full" id="brReviewConfirm" onclick="handleBorrow()"><i class="fas fa-check-circle"></i> Confirm Borrow</button>
            <button type="button" class="br-btn-outline" id="brReviewCancel" onclick="brCloseReview()">Cancel</button>
          </div>
        </div>
      </div>

      <!-- ── Borrow history (phones / tablets): the same data as Reports → Transaction Report ── -->
      <div class="br-sheet" id="brHistory" role="dialog" aria-modal="true" aria-labelledby="brHistTitle" hidden>
        <div class="br-sheet-card">
          <div class="br-sheet-top">
            <button type="button" class="br-sheet-x" onclick="brCloseSheet('brHistory')" aria-label="Close history"><i class="fas fa-arrow-left"></i></button>
            <h2 id="brHistTitle">Borrow History</h2>
          </div>
          <div class="br-sheet-body">
            <div class="br-hsearch"><i class="fas fa-search"></i><input type="search" id="brHistSearch" placeholder="Search tool, borrower or ID…" autocomplete="off" oninput="brHistRender()" aria-label="Search borrow history"></div>
            <div class="br-chips" id="brHistChips" role="group" aria-label="Date range">
              <button type="button" class="on" data-r="all" aria-pressed="true" onclick="brHistRange('all')">All</button>
              <button type="button" data-r="today" aria-pressed="false" onclick="brHistRange('today')">Today</button>
              <button type="button" data-r="week" aria-pressed="false" onclick="brHistRange('week')">This week</button>
              <button type="button" data-r="month" aria-pressed="false" onclick="brHistRange('month')">This month</button>
            </div>
            <div id="brHistList" aria-live="polite"></div>
          </div>
          <div class="br-sheet-foot">
            <button type="button" class="br-btn-outline" onclick="brHistFull()"><i class="fas fa-table-list"></i> Open full report</button>
          </div>
        </div>
      </div>
    </section>

    <!-- ═══ RETURN ═══ -->
    <style>
      /* Tools table: kebab (⋮) actions menu */
      .tool-actions-th{width:1%;white-space:nowrap}
      .sr-only-txt{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
      td.tool-actions-td{width:1%;white-space:nowrap;text-align:right}
      .kebab-btn{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:8px;background:transparent;color:var(--gray-600,#4b5563);cursor:pointer;font-size:15px}
      .kebab-btn:hover,.kebab-btn[aria-expanded="true"]{background:var(--gray-100,#f3f4f6);border-color:var(--gray-200,#e5e7eb)}
      .kebab-btn:focus-visible,.tool-menu-item:focus-visible{outline:2px solid var(--primary,#2563eb);outline-offset:1px}
      .tool-menu{position:fixed;z-index:1200;min-width:210px;max-width:calc(100vw - 16px);padding:6px;background:#fff;border:1px solid var(--gray-200,#e5e7eb);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.14)}
      .tool-menu[hidden]{display:none}
      .tool-menu-item{display:flex;align-items:center;gap:10px;width:100%;padding:9px 10px;border:0;border-radius:7px;background:transparent;font:inherit;font-size:13.5px;color:var(--gray-800,#1f2937);text-align:left;cursor:pointer}
      .tool-menu-item i{width:16px;text-align:center;color:var(--gray-500,#6b7280)}
      .tool-menu-item:hover,.tool-menu-item:focus{background:var(--gray-100,#f3f4f6)}
      .tool-menu-item.danger,.tool-menu-item.danger i{color:#b91c1c}
      .tool-menu-sep{height:1px;margin:4px 6px;background:var(--gray-200,#e5e7eb)}
      /* Return All Items */
      .ra-card{margin:0}
      .ra-search{display:flex;gap:10px;align-items:center}
      .ra-search .form-input-full{flex:1;min-width:0;margin:0}
      .ra-hint{font-size:13px;color:var(--gray-500,#6b7280);margin:12px 0 0}
      .ra-cands{display:flex;flex-direction:column;gap:6px;margin-top:10px}
      .ra-cand{display:flex;justify-content:space-between;gap:10px;align-items:center;width:100%;text-align:left;padding:10px 12px;border:1px solid var(--gray-200,#e5e7eb);border-radius:8px;background:#fff;font:inherit;font-size:13.5px;cursor:pointer}
      .ra-cand:hover{background:var(--gray-100,#f3f4f6)}
      .ra-cand small{color:var(--gray-500,#6b7280)}
      .ra-student{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;justify-content:space-between;margin:14px 0 10px;padding:10px 12px;border-radius:10px;background:var(--gray-50,#f9fafb);border:1px solid var(--gray-200,#e5e7eb)}
      .ra-student b{font-size:15px}
      .ra-student small{color:var(--gray-500,#6b7280);font-size:12.5px}
      .ra-link{border:0;background:none;color:var(--primary,#6d28d9);font:inherit;font-size:13px;cursor:pointer;padding:4px 6px}
      .ra-table td,.ra-table th{vertical-align:middle}
      .ra-table small{display:block;color:var(--gray-500,#6b7280);font-size:12px;margin-top:2px}
      .ra-table input.ra-q{width:62px;padding:7px 6px;text-align:center;border:1px solid var(--gray-300,#d1d5db);border-radius:8px;font:inherit}
      .ra-table input.ra-q:disabled{background:var(--gray-100,#f3f4f6);cursor:not-allowed}
      .ra-table tr.ra-bad input.ra-q{border-color:#dc2626;background:#fef2f2}
      .ra-c-good{color:#15803d}.ra-c-minor{color:#b45309}.ra-c-damaged{color:#b91c1c}.ra-c-missing{color:#4b5563}
      .ra-badge{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;font-size:11.5px;background:var(--gray-100,#f3f4f6);color:var(--gray-600,#4b5563)}
      .ra-badge.late{background:#fee2e2;color:#b91c1c}
      .ra-mini{border:1px solid var(--gray-300,#d1d5db);background:#fff;border-radius:8px;padding:6px 9px;font:inherit;font-size:12px;cursor:pointer;white-space:nowrap}
      .ra-mini:hover{background:var(--gray-100,#f3f4f6)}
      .ra-legend{font-size:12.5px;color:var(--gray-500,#6b7280);margin:10px 0 0}
      .ra-extra,.ra-missing{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
      .ra-err{color:#b91c1c;font-size:13px;margin-top:6px;min-height:0}
      @media (max-width:640px){.ra-extra,.ra-missing{grid-template-columns:1fr}.ra-search{flex-wrap:wrap}.ra-search .card-btn{width:100%;justify-content:center}}
      .rt-summary{display:flex;flex-wrap:wrap;gap:8px;margin:-4px 0 14px}
      .rt-pill{background:var(--gray-100,#f3f4f6);border-radius:999px;padding:4px 10px;font-size:12px;color:var(--gray-700,#374151)}
      .rt-pill b{font-weight:700}.rt-pill.warn{background:#fef3c7;color:#92400e}
      .rt-box{border:1px solid var(--gray-200,#e5e7eb);border-radius:12px;padding:12px;margin:0 0 14px;min-width:0}
      .rt-box legend{font-size:13px;font-weight:600;padding:0 6px;color:var(--gray-700,#374151)}
      .rt-box:disabled{opacity:.55}
      .rt-quick{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px}
      .rt-chip{border:1px solid var(--gray-200,#e5e7eb);background:#fff;border-radius:999px;padding:6px 12px;font-size:12px;cursor:pointer;min-height:36px}
      .rt-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 8px;border-radius:10px;border-left:4px solid transparent;margin-bottom:6px;background:var(--gray-50,#f9fafb)}
      .rt-row.rt-good{border-left-color:#16a34a}.rt-row.rt-minor{border-left-color:#d97706}
      .rt-row.rt-damaged{border-left-color:#dc2626}.rt-row.rt-missing{border-left-color:#6b7280}
      .rt-row-label{display:flex;gap:10px;align-items:flex-start;min-width:0}
      .rt-row-label i{margin-top:3px}.rt-row-label small{display:block;color:var(--gray-500,#6b7280);font-size:11.5px;line-height:1.3}
      .rt-stepper{display:flex;align-items:center;flex:0 0 auto}
      .rt-stepper input{width:64px;height:44px;text-align:center;font-size:16px;border:1px solid var(--gray-200,#e5e7eb);border-radius:8px;margin:0 4px;-moz-appearance:textfield}
      .rt-stepper input::-webkit-outer-spin-button,.rt-stepper input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
      .rt-step{width:44px;height:44px;border-radius:8px;border:1px solid var(--gray-200,#e5e7eb);background:#fff;font-size:20px;line-height:1;cursor:pointer}
      .rt-cond{margin-bottom:8px}
      .rt-cond>summary{list-style:none;display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px 12px;border:1px solid var(--gray-200,#e5e7eb);border-radius:10px;background:#fff;min-height:44px}
      .rt-cond>summary::-webkit-details-marker{display:none}
      .rt-cond-title{font-size:13px;font-weight:600;white-space:nowrap}
      .rt-cond-sum{flex:1;min-width:0;font-size:12.5px;color:var(--gray-500,#6b7280);text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .rt-cond-chev{font-size:12px;color:var(--gray-500,#6b7280);transition:transform .15s}
      .rt-cond[open] .rt-cond-chev{transform:rotate(180deg)}
      .rt-cond[open]>summary{margin-bottom:8px}
      .rt-missing-extra{margin-top:6px;padding:10px;border-radius:10px;background:#fffbeb}
      .rt-note{font-size:12px;color:#92400e;margin:6px 0 0}
      .rt-total{margin-top:10px}.rt-bar{height:8px;border-radius:99px;background:var(--gray-200,#e5e7eb);overflow:hidden}
      .rt-bar span{display:block;height:100%;width:0;background:#16a34a;transition:width .15s}
      .rt-bar.over span{background:#dc2626}
      .rt-total-line{font-size:13px;font-weight:600;margin-top:6px}.rt-total-line.bad{color:#b91c1c}
      .rt-impact{font-size:12px;color:var(--gray-500,#6b7280);margin-top:2px;line-height:1.5}
      .rt-batch{display:block;font-size:11px;color:var(--gray-500,#6b7280)}
      @media (max-width:520px){.rt-row{flex-direction:column;align-items:stretch}.rt-stepper{justify-content:flex-end}.rt-stepper input{flex:1;max-width:none}}

      /* ── Return page: Find Borrower (narrow) | Return by Student (wide), side by side ── */
      #returnPage .return-layout{display:grid;grid-template-columns:minmax(280px,1fr) minmax(0,2fr);gap:24px;align-items:start}
      #returnPage .return-layout>.br-card{min-width:0;margin:0}
      @media (max-width:992px){#returnPage .return-layout{grid-template-columns:minmax(0,1fr);gap:16px}}
      /* Return All Items inside a half-width column: each loan becomes a compact card instead of a 9-column table */
      #returnAllCard .ra-search .form-input-full{flex:1 1 180px}
      #returnAllCard .ra-search .card-btn{flex:0 0 auto}
      #returnAllCard .ra-extra,#returnAllCard .ra-missing{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
      #returnAllCard .ra-table-wrap,#returnAllCard .table-container{overflow-x:visible}
      #returnAllCard .ra-table,#returnAllCard .ra-table tbody{display:block;width:100%;min-width:0}
      #returnAllCard .ra-table thead{display:none}
      #returnAllCard .ra-table tr{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end;padding:12px;margin:0 0 10px;border:1px solid var(--gray-200,#e5e7eb);border-radius:10px;background:#fff}
      #returnAllCard .ra-table tr.ra-bad{border-color:#dc2626;background:#fef2f2}
      #returnAllCard .ra-table td{display:block;min-width:0;padding:0;border:0;text-align:left}
      #returnAllCard .ra-table td::before{content:attr(data-label);display:block;margin:0 0 3px;font-size:11px;font-weight:600;letter-spacing:.02em;text-transform:uppercase;color:var(--gray-500,#6b7280)}
      #returnAllCard .ra-table td:not([data-label])::before{display:none}
      #returnAllCard .ra-table td[data-label="Item"]{grid-column:1/-1;order:1}
      #returnAllCard .ra-table td[data-label="Item"]::before{display:none}
      #returnAllCard .ra-table td[data-label="Borrowed"]{order:2}
      #returnAllCard .ra-table td[data-label="Outstanding"]{order:3}
      #returnAllCard .ra-table td[data-label="Left after"]{order:4}
      #returnAllCard .ra-table td:not([data-label]){order:5}
      #returnAllCard .ra-table td[data-label="Good"]{order:6}
      #returnAllCard .ra-table td[data-label="Minor Wear"]{order:7}
      #returnAllCard .ra-table td[data-label="Damaged"]{order:8}
      #returnAllCard .ra-table td[data-label="Missing"]{order:9}
      #returnAllCard .ra-table td[data-label="Good"]::before{color:#15803d}
      #returnAllCard .ra-table td[data-label="Minor Wear"]::before{color:#b45309}
      #returnAllCard .ra-table td[data-label="Damaged"]::before{color:#b91c1c}
      #returnAllCard .ra-table td[data-label="Missing"]::before{color:#4b5563}
      #returnAllCard .ra-table input.ra-q{width:100%;min-width:0;box-sizing:border-box}
      #returnAllCard .ra-table .ra-mini{width:100%;padding:8px 6px}
      @media (max-width:480px){#returnAllCard .ra-table tr{grid-template-columns:repeat(2,minmax(0,1fr))}}
      /* Return by Student: Return all / Partial toggle, mode banner, per-row tick box */
      #returnAllCard .ra-mode{display:flex;width:100%;max-width:420px;padding:3px;margin:0 0 10px;border:1px solid var(--gray-200,#e5e7eb);border-radius:10px;background:var(--gray-50,#f9fafb)}
      #returnAllCard .ra-mode-btn{flex:1 1 0;min-height:40px;padding:8px 12px;border:0;border-radius:8px;background:transparent;font:inherit;font-size:13.5px;font-weight:600;color:var(--gray-600,#4b5563);cursor:pointer}
      #returnAllCard .ra-mode-btn i{margin-right:6px}
      #returnAllCard .ra-mode-btn[aria-pressed="true"]{background:#fff;color:var(--primary,#6d28d9);box-shadow:0 1px 3px rgba(0,0,0,.14)}
      #returnAllCard .ra-mode-btn:focus-visible,#returnAllCard .ra-pick:focus-visible{outline:2px solid var(--primary,#6d28d9);outline-offset:1px}
      #returnAllCard .ra-banner{display:none;margin:0 0 12px;padding:9px 12px;border-radius:8px;font-size:13px;line-height:1.45}
      #returnAllCard .ra-banner.full{display:block;background:#ecfdf5;color:#047857}
      #returnAllCard .ra-banner.partial{display:block;background:#fffbeb;color:#92400e}
      #returnAllCard .ra-banner.hint{display:block;background:var(--gray-100,#f3f4f6);color:var(--gray-600,#4b5563)}
      #returnAllCard .ra-item-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
      #returnAllCard .ra-pick{width:20px;height:20px;margin:0;flex:0 0 auto;accent-color:var(--primary,#6d28d9);cursor:pointer}
    </style>
    <section class="page" id="returnPage">
      <p class="rp-note">Manage borrower returns, inspect tool conditions and update inventory.</p>
      <div class="rp-actions circulation-tabs" role="group" aria-label="Return views">
        <button type="button" class="card-btn secondary" id="rtReturnTab" aria-pressed="true" onclick="rtSetView('return')">Return / Scan</button>
        <button type="button" class="card-btn secondary" id="rtHistoryTab" aria-pressed="false" onclick="rtSetView('history')">Return History</button>
      </div>
      <div class="bw-home return-home" id="rtHome">
        <div class="bw-hero"><div class="bw-hero-text"><h2 class="bw-hero-title">Ready to return?</h2><p class="bw-hero-sub">Scan a student ID, select outstanding tools and inspect their condition.</p></div><button type="button" class="bw-hero-btn" onclick="rtQuick('scan')"><i class="fas fa-id-card" aria-hidden="true"></i> Scan Student ID</button></div>
        <h3 class="bw-h">Quick actions</h3>
        <div class="bw-quick">
          <button type="button" class="bw-q" onclick="rtQuick('scan')"><span class="bw-q-ico"><i class="fas fa-qrcode"></i></span><span class="bw-q-t">Scan ID</span><span class="bw-q-s">Student QR or barcode</span></button>
          <button type="button" class="bw-q" onclick="rtQuick('find')"><span class="bw-q-ico"><i class="fas fa-magnifying-glass"></i></span><span class="bw-q-t">Find Borrower</span><span class="bw-q-s">Search name or ID</span></button>
          <button type="button" class="bw-q" onclick="rtQuick('tools')"><span class="bw-q-ico"><i class="fas fa-boxes-stacked"></i></span><span class="bw-q-t">Return Tools</span><span class="bw-q-s">Select and inspect</span></button>
          <button type="button" class="bw-q" onclick="rtSetView('history')"><span class="bw-q-ico"><i class="fas fa-clock-rotate-left"></i></span><span class="bw-q-t">View History</span><span class="bw-q-s">Recent returns</span></button>
        </div>
        <h3 class="bw-h">Overview</h3>
        <div class="bw-stats" aria-label="Return overview">
          <div class="bw-stat"><i class="fas fa-hand-holding"></i><b id="rtUnitsOnLoan">—</b><span>Units on Loan</span></div>
          <div class="bw-stat late"><i class="fas fa-triangle-exclamation"></i><b id="rtOverdueLoans">—</b><span>Overdue Loans</span></div>
          <div class="bw-stat ok" title="Physically returned units today, including damaged returns; missing reports are excluded"><i class="fas fa-circle-check"></i><b id="rtReturnedToday">—</b><span>Returned Today</span></div>
        </div>
      </div>
      <div id="rtWorkspace">
      <ol class="rt-mobile-only rt-steps" aria-label="Return process">
        <li id="rtStepFind" aria-current="step"><span>1</span> Find Borrower</li>
        <li id="rtStepSelect"><span>2</span> Select Tools &amp; Conditions</li>
        <li id="rtStepConfirm"><span>3</span> Confirm Return</li>
      </ol>
      <div class="br-layout return-layout">
        <div class="br-card" id="rtBorrowerCard">
          <div class="br-card-header"><div class="br-card-header-icon return"><i class="fas fa-id-card"></i></div><h3><span class="rt-desktop-only">Find Borrower</span><span class="rt-mobile-only">1. Find Borrower</span></h3></div>
          <div class="br-card-body">

            <div id="rtMobileFinder"></div>
            <!-- Camera ID scan: finds the borrower and loads their open loans into "Return by Student". USB / phone scanners type into the borrower search box. -->
            <div class="scanner-zone" id="returnIdScanZone">
              <div class="scanner-zone-label"><i class="fas fa-id-card"></i> Scan Student ID</div>
              <div id="returnIdCameraField" style="display:none">
                <label class="field-label"><i class="fas fa-video"></i> Camera Device</label>
                <select class="form-input-full" id="returnIdCameraSelect"><option value="">Press "Scan Student ID" to enable</option></select>
              </div>
              <div class="scanner-controls">
                <button class="btn-scan start" id="returnIdStartBtn" onclick="startReturnIdScanner()"><i class="fas fa-camera"></i> Scan Student ID</button>
                <button class="btn-scan stop" id="returnIdStopBtn" onclick="stopReturnIdScanner()" style="display:none"><i class="fas fa-stop"></i> Stop</button>
              </div>
              <div class="qr-reader-viewport" id="returnIdReader"></div>
              <div class="scan-status" id="returnIdScanStatus"><span class="pulse"></span><span id="returnIdScanStatusText"></span></div>
              <p id="returnIdScanInfo" style="font-size:12px;color:var(--gray-500);margin:6px 0 0;min-height:16px"></p>
            </div>
            <section class="return-borrower-details" aria-label="Selected borrower"><h4>Borrower details</h4><div class="ra-student" id="raStudent"><small>Select a borrower to see their active loans.</small></div></section>


          </div>
        </div>
        <!-- ═══ RETURN ALL ITEMS: every open loan of one student, one all-or-nothing submission (api/transactions.php type=return_batch) ═══ -->
        <div class="br-card ra-card" id="returnAllCard">
          <div class="br-card-header"><div class="br-card-header-icon return"><i class="fas fa-boxes-stacked"></i></div><h3 tabindex="-1" id="rtToolsHeading">Outstanding Tools <small>Select quantities and inspect conditions</small></h3></div>
          <div class="br-card-body">
          <div id="rtFinderAnchor" hidden></div>
          <div id="rtBorrowerFinder">
          <label class="field-label" for="raSearch">Borrower name or Student ID</label>
          <div class="ra-search">
            <input type="text" class="form-input-full" id="raSearch" placeholder="Scan or type a student ID (or name), then press Enter"
                   autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();raFind();}">
            <button type="button" class="card-btn primary" id="raFindBtn" onclick="raFind()"><i class="fas fa-search"></i> Find</button>
          </div>
          <div class="ra-cands" id="raCandidates" style="display:none"></div>
          <p class="ra-hint" id="raEmpty">Scan an ID or search by borrower name. Full and partial returns are supported.</p>

          </div>
          <div id="rtOutstandingEmpty" class="empty-state"><i class="fas fa-boxes-stacked" aria-hidden="true"></i><p>Select a borrower to see outstanding tools and return conditions.</p></div>
          <div id="raPanel" style="display:none">
            
            <div id="raNone" class="ra-hint" style="display:none"></div>
            <div id="raForm">
              <div class="ra-mode" role="group" aria-label="Return mode">
                <button type="button" class="ra-mode-btn" id="raModeFull" aria-pressed="false" onclick="raSetMode('full')"><i class="fas fa-check-double"></i> Return all</button>
                <button type="button" class="ra-mode-btn" id="raModePartial" aria-pressed="true" onclick="raSetMode('partial')"><i class="fas fa-list-check"></i> Partial return</button>
              </div>
              <div class="ra-banner" id="raModeBanner" aria-live="polite"></div>
              <div class="table-container">
                <table class="data-table ra-table">
                  <thead><tr>
                    <th>Borrowed item</th><th>Borrowed</th><th>Outstanding</th>
                    <th class="ra-c-good">Good</th><th class="ra-c-minor">Minor Wear</th><th class="ra-c-damaged">Damaged</th><th class="ra-c-missing">Missing</th>
                    <th>Left after</th><th></th>
                  </tr></thead>
                  <tbody id="raBody"></tbody>
                </table>
              </div>
              <p class="ra-legend"><b>Good</b> and <b>Minor Wear</b> go back to available stock · <b>Damaged</b> is kept in the total but is NOT available · <b>Missing</b> is recorded as a loss (a Missing Tool case) and is NOT added to stock. Leave a row at 0 to keep it outstanding (partial return).</p>
              <h3 class="rt-mobile-only rt-confirm-title">3. Confirm Return</h3>
              <div class="ra-extra">
                <div>
                  <label class="field-label" for="raReturnee">Returnee Name *</label>
                  <input type="text" class="form-input-full" id="raReturnee" maxlength="120" autocomplete="off">
                </div>
                <div>
                  <label class="field-label" for="raNotes">Return Notes (Optional)</label>
                  <input type="text" class="form-input-full" id="raNotes" maxlength="500" placeholder="Any issues or comments…">
                </div>
              </div>
              <div class="ra-missing" id="raMissingExtra" style="display:none">
                <div>
                  <label class="field-label" for="raMissingReason">Why are the missing unit(s) missing? *</label>
                  <input type="text" class="form-input-full" id="raMissingReason" maxlength="255" placeholder="e.g. Borrower says it was left at the lab">
                </div>
                <div>
                  <label class="field-label" for="raMissingDate">Date missing</label>
                  <input type="date" class="form-input-full" id="raMissingDate">
                </div>
              </div>

            </div>
          </div>
          </div>
        </div>
      </div>

      <section class="return-summary" aria-label="Confirm return">
        <div class="return-summary-main">
          <div><h3>Return Summary</h3><p id="rtSummaryBorrower" class="rp-note">Select a borrower to begin.</p></div>
          <div class="rt-total" aria-live="polite">
            <div class="rt-total-line" id="raTotalLine"></div>
            <div class="rt-impact" id="raImpact"></div>
            <div class="ra-err" id="raErr"></div>
          </div>
          <button class="btn-primary-full" id="raSubmitBtn" disabled type="button" onclick="raSubmit()"><i class="fas fa-check-double"></i> Confirm Return</button>
        </div>
        <details class="return-review-details">
          <summary>Review selected tools and conditions</summary>
          <section class="rt-review" id="rtMobileReview" aria-label="Return summary" aria-live="polite"></section>
        </details>
      </section>
      </div>
      <div class="history-section" id="returnHistorySection" hidden>
        <div class="card">
          <div class="card-header" onclick="toggleReturnHistory()" style="cursor:pointer">
            <h3 class="card-title"><i class="fas fa-chevron-right history-toggle-icon" id="returnHistoryChevron"></i> Return Transaction History</h3>
            <!-- api/transactions.php?type=return&condition= -->
            <select class="filter-select" id="returnHistoryFilter" onchange="loadReturnHistory()" onclick="event.stopPropagation()">
              <option value="">All</option><option value="good">Good Condition</option><option value="minor">Minor Wear</option><option value="damaged">Damaged</option><option value="missing">Missing / Lost</option>
            </select>
          </div>
          <div class="table-container collapsed" id="returnHistoryTableContainer"><table class="data-table"><thead><tr><th>Transaction ID</th><th>Tool Code</th><th>Returned By</th><th>Date &amp; Time</th><th>Condition</th><th>Qty</th><th>Notes</th></tr></thead><tbody id="returnHistoryBody"><tr class="empty-row"><td colspan="7">No returns yet.</td></tr></tbody></table></div>
        </div>
      </div>
    </section>

    <!-- ═══ DAMAGE & LOSS CHARGES / MISSING CASES ═══ -->
    <section class="page" id="replacementsPage">
      <div class="history-section" id="replacementRequestsSection"><div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-list-check"></i> Damage &amp; Loss Tracking <span class="rr-count" id="rrListCount"></span></h3>
          <div class="card-actions"><button type="button" class="card-btn primary" onclick="rpOpenIncident()"><i class="fas fa-triangle-exclamation"></i> Manual Incident</button></div>
        </div>
        <p class="rp-note" style="padding:0 24px">Track incidents and physical resolution. Unit counts cover inventory; request and review counts cover cases. Borrower accountability is retained separately.</p>
        <div id="cwSummary" class="iu-cards" aria-live="polite"></div><div class="iu-tabs cw-tabs" role="tablist" aria-label="Incident cases"><button id="cwTab-active" role="tab" aria-selected="true" aria-controls="cwResults" onclick="cwSelectTab('active')">Active Cases</button><button id="cwTab-pending" role="tab" aria-selected="false" tabindex="-1" aria-controls="cwResults" onclick="cwSelectTab('pending')">Pending Approval</button><button id="cwTab-closed" role="tab" aria-selected="false" tabindex="-1" aria-controls="cwResults" onclick="cwSelectTab('closed')">Closed Cases</button><button id="cwTab-all" role="tab" aria-selected="false" tabindex="-1" aria-controls="cwResults" onclick="cwSelectTab('all')">All Cases</button></div><div class="cw-filters" style="padding:12px 24px;display:flex;gap:12px;flex-wrap:wrap">
          <input id="cwCaseFilter" type="hidden" value="all">
          <select id="cwIncidentFilter" class="filter-select" aria-label="Incident type" onchange="loadChargeCases(1)"><option value="">All incident types</option><option value="damaged">Damaged</option><option value="lost">Missing</option></select>
          <select id="cwInventoryFilter" class="filter-select" aria-label="Case status" onchange="loadChargeCases(1)"><option value="">All case statuses</option><option>Pending Confirmation</option><option>Pending Review</option><option>Under Repair</option><option>Missing</option><option>Confirmed Lost</option><option>Partially Closed</option><option>Closed — Lost</option><option>Closed — Recovered</option><option>Closed — Mixed Outcome</option><option>Active missing</option><option>Needs Review</option><option>Pending write-off</option><option>Recovered</option><option>Awaiting Replacement</option><option>Resolved</option><option>Rejected</option><option>Historical Return</option></select>
          <select id="cwSort" class="filter-select" aria-label="Date order" onchange="loadChargeCases(1)"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select>
        </div>
        <div class="cw-search"><label for="cwSearch">Search incidents</label><input type="search" id="cwSearch" class="form-input-full" maxlength="150" placeholder="Borrower, case ID, tool or SKU" oninput="clearTimeout(window.cwSearchTimer);window.cwSearchTimer=setTimeout(()=>loadChargeCases(1),250)"></div>
        <div id="cwResults" role="tabpanel" aria-labelledby="cwTab-active"><div class="table-container" id="rrListContainer" tabindex="0" aria-label="Incident cases; scroll for more columns"><table class="data-table"><thead><tr><th>Case ID</th><th>Tool</th><th>Borrower</th><th>Incident</th><th>Quantity</th><th>Case Status</th><th>Age</th><th>Actions</th></tr></thead><tbody id="rrListBody"><tr class="empty-row"><td colspan="8">Loading incidents…</td></tr></tbody></table></div></div>
      </div></div>
    </section>


   


    <!-- ═══ REPORTS ═══ -->
    <section class="page" id="reportsPage">
      <div class="reports-heading">
        <div><h2>Reports overview</h2><p>Open the reports you use most.</p></div>
      </div>
      <div class="reports-grid reports-grid-primary"><button type="button" class="report-card" onclick="viewReport('loss_history','Written Off / Lost History')"><span class="report-icon report-icon-red"><i class="fas fa-clock-rotate-left"></i></span><span class="report-info"><h3>Written Off / Lost History</h3><p>Requests, decisions, later recoveries and accountability. Case totals repeat per request.</p></span></button>
        <div class="report-card report-card-group"><span class="report-icon report-icon-purple"><i class="fas fa-clipboard-list" aria-hidden="true"></i></span><div class="report-info"><h3><a href="#reports/inventory" onclick="viewReport('inventory','Inventory Report');return false">Inventory</a></h3><p>Tools, quantities and current status</p><a class="report-secondary-link" href="#reports/stock_history" onclick="viewReport('stock_history','Stock History');return false">View Stock History</a></div></div>
        <button type="button" class="report-card" onclick="viewReport('overdue','Overdue Report')"><span class="report-icon report-icon-red"><i class="fas fa-exclamation-triangle"></i></span><span class="report-info"><h3>Overdue Loans</h3><p>Past-due tools and follow-ups</p></span></button>
        <button type="button" class="report-card" onclick="viewReport('missing','Missing Tools Report')"><span class="report-icon report-icon-red"><i class="fas fa-magnifying-glass-minus"></i></span><span class="report-info"><h3>Missing Tools</h3><p>Reported, confirmed and resolved cases</p></span></button>
        <div class="report-card report-card-group"><span class="report-icon report-icon-green"><i class="fas fa-exchange-alt" aria-hidden="true"></i></span><div class="report-info"><h3><a href="#reports/transactions" id="transactionReportCard" onclick="viewReport('transactions','Transactions');return false">Transactions</a></h3><p>Borrow history, filters and CSV export</p><a class="report-secondary-link" href="#reports/returns" onclick="viewReport('returns','Returns by Condition');return false">View Return Conditions</a></div></div>
      </div>

      <h3 class="reports-section-title">Analytics &amp; trends</h3>

      <div class="content-grid" style="margin-top:32px">
        <div class="card">
          <div class="card-header wrap">
            <h3 class="card-title">Monthly Statistics <span class="title-sub" id="monthlyBadge"></span></h3>
            <div class="month-nav">
              <button type="button" class="nav-arrow" id="monthPrevBtn" aria-label="Previous month" onclick="shiftMonth(1)"><i class="fas fa-chevron-left"></i></button>
              <select class="filter-select" id="monthSelect" aria-label="Select month" onchange="loadMonthlyStats(this.value)"></select>
              <button type="button" class="nav-arrow" id="monthNextBtn" aria-label="Next month" onclick="shiftMonth(-1)"><i class="fas fa-chevron-right"></i></button>
            </div>
          </div>
          <div class="card-body">
            <div class="kpi-row" id="monthlyKpis"></div>
            <div class="chart-container"><canvas id="monthlyChart"></canvas></div>
          </div>
        </div>
        <div class="card"><div class="card-header"><h3 class="card-title">Category Distribution</h3></div><div class="card-body"><div class="chart-container chart-tall"><canvas id="categoryChart"></canvas></div></div></div>
      </div>

      <div class="card" style="margin-top:24px">
        <div class="card-header wrap">
          <h3 class="card-title">Monthly Trend &amp; Forecast <span class="title-sub" id="trendConfidence"></span></h3>
          <div class="filter-group">
            <label class="filter-label" for="trendRange">History</label>
            <select class="filter-select" id="trendRange" onchange="loadTrend(+this.value)">
              <option value="6">Last 6 months</option>
              <option value="12">Last 12 months</option>
              <option value="24">Last 24 months</option>
            </select>
          </div>
        </div>
        <div class="card-body">
          <div class="chart-container"><canvas id="trendChart"></canvas></div>
          <div class="forecast-grid" id="forecastCards"></div>
          <p class="forecast-note" id="forecastNote"></p>
        </div>
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

     <?php if ($isAdmin): ?>
    <!-- ═══ AUDIT TRAIL ═══ -->
    <section class="page" id="auditPage">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Audit Trail</h3>
          <div class="card-actions">
            <button class="card-btn secondary" onclick="exportAuditLogCSV()"><i class="fas fa-download"></i> Export CSV</button>
          </div>
        </div>
        <div class="filter-bar">
          <div class="filter-group"><span class="filter-label">Action:</span>
            <select class="filter-select" id="auditActionFilter">
              <option value="">All Actions</option>
            </select>
          </div>
          <div class="filter-group"><span class="filter-label">From:</span>
            <input type="date" class="filter-input" id="auditDateFrom">
          </div>
          <div class="filter-group"><span class="filter-label">To:</span>
            <input type="date" class="filter-input" id="auditDateTo">
          </div>
          <div class="filter-group"><input type="text" class="filter-input" id="auditSearchFilter" placeholder="Search user, IP, details…"></div>
          <button class="filter-btn apply" onclick="applyAuditFilters()">Apply</button>
          <button class="filter-btn clear" onclick="clearAuditFilters()">Clear</button>
        </div>
        <div class="table-container">
          <table class="data-table">
            <thead><tr><th>Timestamp</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP Address</th><th>Device</th></tr></thead>
            <tbody id="auditTableBody"><tr class="empty-row"><td colspan="7"><span class="spinner dark"></span> Loading…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination">
          <div class="pagination-info" id="auditPaginationInfo"></div>
          <div class="pagination-btns" id="auditPaginationBtns"></div>
        </div>
      </div>
    </section>
    <?php endif; ?> 

  </div><!-- /page-content -->
</main>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Add / Edit Tool
═══════════════════════════════════════════════════════════ -->
<!-- Restock an existing tool without changing its SKU. -->
<div class="modal-overlay" id="addStockModal">
 <div class="modal stock-add-modal">
  <div class="modal-header"><h3 class="modal-title">Add Stock</h3><button class="modal-close" aria-label="Close" onclick="closeModal('addStockModal')"><i class="fas fa-times"></i></button></div>
  <div class="modal-body">
   <h4 id="as_name"></h4><p id="as_sku" class="stock-sku"></p>
   <div id="as_entry">
   <div class="stock-preview"><div><span>Current total</span><b id="as_total">0</b></div><div><span>Currently available</span><b id="as_available">0</b></div></div>
   <label class="field-label" for="as_qty">Quantity to add *</label>
   <div class="stock-presets" role="group" aria-label="Quick stock quantities"><button type="button" onclick="setStockAddition(1)">+1</button><button type="button" onclick="setStockAddition(5)">+5</button><button type="button" onclick="setStockAddition(10)">+10</button><button type="button" onclick="setStockAddition(20)">+20</button></div>
   <input type="number" id="as_qty" class="form-input-full" min="1" max="100000" step="1" value="0" inputmode="numeric" oninput="updateStockPreview()">
   <div class="stock-preview stock-result" aria-live="polite"><div><span>Updated total</span><b id="as_new_total">0</b></div><div><span>Updated available</span><b id="as_new_available">0</b></div></div>
   <label class="field-label" for="as_notes">Remarks (optional)</label><input class="form-input-full" id="as_notes" maxlength="450" placeholder="Delivery or purchase reference">
   <p class="stock-note">Adds good units to this tool. Its SKU, Unit Price and existing borrowed, damaged and missing quantities stay the same.</p>
   </div>
   <div id="as_review" hidden aria-live="polite">
    <div class="stock-preview"><div><span>Quantity to add</span><b id="as_review_qty"></b></div></div>
    <div class="stock-preview stock-result"><div><span>Total stock</span><b id="as_review_total"></b></div><div><span>Available stock</span><b id="as_review_available"></b></div></div>
    <label class="field-label">Remarks</label><p id="as_review_notes" style="white-space:pre-wrap;overflow-wrap:anywhere"></p>
   </div>
   <p id="as_error" role="alert" class="stock-error"></p>
  </div>
  <div class="modal-footer"><button class="btn btn-gray" onclick="closeModal('addStockModal')">Cancel</button><button class="btn btn-gray" id="as_back" hidden onclick="editStockAddition()">Back to Edit</button><button class="btn btn-blue" id="as_save" onclick="reviewStockAddition()">Review Stock</button><button class="btn btn-blue" id="as_submit" hidden onclick="saveAddedStock()">Submit Stock</button></div>
 </div>
</div>
<div class="modal-overlay" id="toolModal">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="toolModalTitle">Add New Tool</h3>
      <button class="modal-close" onclick="closeModal('toolModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editToolId">
      <div class="form-group"><label class="field-label">Tool Name *</label><input type="text" id="t_name" class="form-input-full" placeholder="Enter tool name"></div>
      <div class="form-group"><label class="field-label" for="t_code">SKU / Tool Code</label><input type="text" id="t_code" class="form-input-full" placeholder="Select a category" readonly aria-describedby="toolSkuHint" style="background:var(--gray-50,#f9fafb);cursor:not-allowed"><p id="toolSkuHint" style="font-size:12px;color:var(--gray-500);margin-top:6px" aria-live="polite">Select a category to preview the SKU. The final SKU is assigned when you save.</p></div>
      <div class="form-group"><label class="field-label">Category *</label>
        <select id="t_category" class="form-input-full" onchange="updateToolSkuPreview()">
          <option value="">All Categories</option>
          <option value="Utensils">Utensils</option>
          <option value="Cookware">Cookware</option>
          <option value="Measuring Tools">Measurement</option>
          <option value="Accessories">Accessories</option>
          <option value="Dinnerware">Dinnerware</option>
          <option value="Cutleries">Cutleries</option>
          <option value="Glassware">Glassware</option>
        </select>
      </div>
      <div class="form-group"><label class="field-label">Total Quantity *</label><input type="number" id="t_qty" class="form-input-full" placeholder="Enter quantity" min="1"><p style="font-size:11px;color:var(--gray-500);margin-top:4px">All units owned, including damaged, missing and borrowed ones.</p></div>
      <div class="form-group"><label class="field-label">Minimum Stock Level *</label><input type="number" id="t_min" class="form-input-full" placeholder="Alert when below this" min="1"></div>
      <div class="form-group"><label class="field-label" for="t_price">Unit Price (₱) *</label><input type="number" id="t_price" class="form-input-full" placeholder="0.00" min="0" step="0.01"></div>
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
      <div class="form-group"><label class="field-label">Last Name *</label><input type="text" id="b_last" class="form-input-full" placeholder="Enter last name" autocomplete="off"></div>
      <div class="form-group"><label class="field-label">First Name *</label><input type="text" id="b_first" class="form-input-full" placeholder="Enter first name" autocomplete="off"></div>
      <div class="form-group"><label class="field-label">Middle Name</label><input type="text" id="b_middle" class="form-input-full" placeholder="Enter middle name (optional)" autocomplete="off"></div>
      <div class="form-group"><label class="field-label" for="b_course">Program *</label><select id="b_course" class="form-input-full"><option value="">None</option></select></div>
<div class="form-group">
  <label class="field-label" for="b_section">Section *</label>
  <select id="b_section" class="form-input-full" disabled>
    <option value="">Select section</option>
  </select>
</div>
      <div class="form-group">
        <label class="field-label">Year / Grade Level (FPST / TVL only)</label>
        <select id="b_year" class="form-input-full" disabled>
          <option value="">Select year level</option>
        </select>
      </div>
      <div class="form-group"><label class="field-label">ID Number *</label><input type="text" id="b_idnum" class="form-input-full" placeholder="Students: e.g., 20240093"><p style="font-size:11px;color:var(--gray-500);margin-top:4px">Id must be the same school format</p></div>
      <div class="form-group"><label class="field-label">Type *</label>
         <select id="b_type" class="form-input-full">
          <option value="">Select type</option>
          <option value="Student">Student</option>
          <option value="Faculty">Faculty</option>
          <option value="Staff">Staff</option>
        </select>
        <p id="b_typeHint" style="display:none;font-size:11px;color:var(--gray-500);margin-top:4px">Type can only be set when a borrower is first created.</p>
      </div>
      <div class="form-group"><label class="field-label">Phone Number</label><input type="tel" id="b_phone" class="form-input-full" placeholder="Contact number"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('borrowerModal')">Cancel</button>
      <button class="btn btn-blue" id="saveBorrowerBtn" onclick="saveBorrower()">Add Borrower</button>
    </div>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     MODAL: Import Student Masterlist (CSV / XLSX) — Admin and Staff
═══════════════════════════════════════════════════════════ -->
<style>
  .smi-note{font-size:12.5px;color:var(--gray-500,#6b7280);line-height:1.5}
  .smi-privacy{background:rgba(124,58,237,.07);border:1px solid rgba(124,58,237,.25);border-radius:8px;padding:10px 12px;margin:12px 0}
  .smi-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
  .smi-chip{border:1px solid var(--gray-200,#e5e7eb);border-radius:999px;padding:4px 12px;font-size:12.5px;cursor:pointer;background:transparent;color:inherit}
  .smi-chip.on{border-color:#7c3aed;background:rgba(124,58,237,.12);font-weight:600}
  .smi-opts{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:12px}
  .smi-opts fieldset{border:1px solid var(--gray-200,#e5e7eb);border-radius:8px;padding:8px 12px;margin:0}
  .smi-opts legend{font-size:12px;font-weight:600;padding:0 6px}
  .smi-opts label{display:block;font-size:13px;margin:4px 0;cursor:pointer}
  .smi-issue{font-size:12px;line-height:1.4}
  .smi-issue.err{color:var(--danger,#dc2626)} .smi-issue.warn{color:#b45309}
  .smi-map{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px 12px;margin:6px 0 4px}
  .smi-map-item{display:flex;flex-direction:column;gap:2px;font-size:12px;font-weight:600}
  .smi-map-item.sub span{font-weight:500;opacity:.75}
  .smi-map-item select{width:100%;font-weight:400}
  .smi-mapbox{border:1px solid var(--gray-200,#e5e7eb);border-radius:8px;padding:8px 12px;margin-bottom:12px}
  .smi-mapbox summary{cursor:pointer;font-size:13px;font-weight:600}
  .smi-maperr{color:#b45309;font-size:12.5px;margin:6px 0 0;min-height:0}
  .smi-result{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin:14px 0}
  .smi-result div{border:1px solid var(--gray-200,#e5e7eb);border-radius:8px;padding:10px;text-align:center}
  .smi-result b{display:block;font-size:22px}

  /* ── Student import: friendly layout ── */
  .smi-stepper{display:flex;gap:8px;align-items:center;margin:0 0 16px;font-size:12.5px;flex-wrap:wrap}
  .smi-st{display:flex;align-items:center;gap:6px;opacity:.45;font-weight:600}
  .smi-st i{width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;border:2px solid currentColor;font-style:normal;font-size:11px}
  .smi-st.on{opacity:1;color:#7c3aed}.smi-st.done{opacity:1;color:#16a34a}
  .smi-st-line{flex:1;height:2px;background:var(--gray-200,#e5e7eb);max-width:56px;min-width:16px}
  .smi-how{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:0 0 14px}
  .smi-how div{background:rgba(124,58,237,.06);border-radius:10px;padding:10px 12px;font-size:12.5px;line-height:1.45}
  .smi-how b{display:block;margin-bottom:2px}
  .smi-drop{border:2px dashed var(--gray-300,#cbd5e1);border-radius:12px;padding:34px 16px;text-align:center;cursor:pointer;transition:.15s}
  .smi-drop:hover,.smi-drop.over{border-color:#7c3aed;background:rgba(124,58,237,.06)}
  .smi-drop .big{font-size:32px;color:#7c3aed;margin-bottom:8px;display:block}
  .smi-drop b{display:block;font-size:15px;margin-bottom:2px}
  .smi-filecard{display:flex;align-items:center;gap:12px;border:1px solid var(--gray-200,#e5e7eb);border-radius:10px;padding:12px 14px}
  .smi-filecard .ic{font-size:26px;color:#16a34a}
  .smi-filecard .nm{font-weight:600;word-break:break-all}
  .smi-filecard .rm{margin-left:auto}
  .smi-more{border:1px solid var(--gray-200,#e5e7eb);border-radius:8px;padding:8px 12px;margin:12px 0}
  .smi-more summary{cursor:pointer;font-size:13px;font-weight:600}
  .smi-summary{font-size:14px;line-height:1.5;margin:0 0 12px;padding:10px 14px;border-radius:10px;background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.25)}
  .smi-chips{gap:10px}
  .smi-card{display:flex;flex-direction:column;align-items:center;gap:2px;min-width:104px;padding:10px 12px;border:1.5px solid var(--gray-200,#e5e7eb);border-radius:12px;background:transparent;color:inherit;cursor:pointer;font-size:12px;transition:.12s}
  .smi-card i{font-size:15px}.smi-card b{font-size:22px;line-height:1.1}
  .smi-card:hover{transform:translateY(-1px)}
  .smi-card.on{border-color:#7c3aed;background:rgba(124,58,237,.10)}
  .smi-card.ok i{color:#16a34a}.smi-card.info i{color:#2563eb}.smi-card.bad i{color:#dc2626}.smi-card.warn i{color:#d97706}.smi-card.mute i{opacity:.55}
  .smi-presets{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin:0 0 10px}
  .smi-preset{display:flex;gap:10px;align-items:flex-start;border:1.5px solid var(--gray-200,#e5e7eb);border-radius:10px;padding:10px 12px;cursor:pointer;margin:0}
  .smi-preset:has(input:checked){border-color:#7c3aed;background:rgba(124,58,237,.08)}
  .smi-preset b{display:block;font-size:13.5px}.smi-preset small{display:block;font-size:12px;opacity:.75;line-height:1.4}
  .smi-search{flex:1;min-width:180px;max-width:320px}
  .smi-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px;border:1px solid var(--gray-200,#e5e7eb);border-radius:10px;padding:10px 14px;margin:0 0 6px}
  .smi-meta legend{font-size:12px;font-weight:600;padding:0 6px;color:#7c3aed}
  .smi-drop.locked{opacity:.5;cursor:not-allowed;pointer-events:auto}
  .smi-drop.locked:hover{border-color:var(--gray-300,#cbd5e1);background:transparent}
  .smi-result{grid-template-columns:repeat(auto-fit,minmax(120px,1fr))}
</style>
<div class="modal-overlay" id="smiModal">
  <div class="modal" style="max-width:1020px">
    <div class="modal-header">
      <h3 class="modal-title">Import Student List</h3>
      <button class="modal-close" onclick="closeStudentImportModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">

      <div class="smi-stepper">
        <span class="smi-st on"><i>1</i> Choose file</span><span class="smi-st-line"></span>
        <span class="smi-st"><i>2</i> Review changes</span><span class="smi-st-line"></span>
        <span class="smi-st"><i>3</i> Done</span>
      </div>

      <!-- Step 1: choose file -->
      <div id="smiStep1">
        <div class="smi-how">
          <div><b>1. Choose defaults if needed</b>Defaults fill missing columns in ID/name-only lists. Mixed lists use each row's Program, Year Level and Section.</div>
          <div><b>2. Pick your list</b>A .csv or .xlsx with Student ID and Name, plus academic columns for mixed programs. Nothing is saved yet.</div>
          <div><b>3. Check &amp; confirm</b>Students are matched by Student ID, so nobody is added twice.</div>
        </div>

        <fieldset class="smi-meta">
          <legend>Defaults for columns not supplied in the file</legend>
          <label class="smi-map-item"><span>Program</span>
            <select id="smiCourse" class="filter-select" onchange="smiCourseChanged()">
              <option value="">Use values from file</option><option value="FPST">FPST</option><option value="TVL">TVL</option><option value="TESDA">TESDA</option>
            </select></label>
          <label class="smi-map-item"><span>Year Level (FPST / TVL)</span>
            <select id="smiYear" class="filter-select" onchange="smiMetaChanged()" disabled>
              <option value="">Select a course first</option>
            </select></label>
          <label class="smi-map-item"><span>Section / Batch</span>
            <input type="text" id="smiSection" class="form-input-full" maxlength="80" placeholder="e.g. Rizal, Bonifacio, A or B" autocomplete="off" oninput="smiMetaChanged()" style="margin:0"></label>
        </fieldset>
        <p class="smi-maperr" id="smiMetaErr"></p>

        <div class="smi-drop" id="smiDrop" onclick="smiPickFile()" ondragover="smiDragOver(event)" ondragleave="smiDragLeave()" ondrop="smiDrop(event)">
          <i class="fas fa-cloud-arrow-up big"></i>
          <b>Drag &amp; drop your file here</b>
          <span class="smi-note">or click to browse · .csv or .xlsx · up to 5 MB / 5,000 students</span>
        </div>
        <div class="smi-filecard" id="smiFileCard" style="display:none">
          <i class="fas fa-file-circle-check ic"></i>
          <div><div class="nm" id="smiFileName"></div><div class="smi-note" id="smiFileSize"></div></div>
          <button type="button" class="btn btn-gray rm" onclick="smiClearFile()"><i class="fas fa-xmark"></i> Change file</button>
        </div>
        <input type="file" id="smiFile" accept=".csv,.xlsx" onchange="smiFileChosen()" style="display:none">

        <p class="smi-note" style="margin:10px 0 0">
          Not sure about the format? <a href="api/student_import.php?action=template"><i class="fas fa-download"></i> Download a blank template</a>.
          Template columns: <b>Student ID, Full Name, Year Level, Section, Course / Strand, Phone Number</b>. <b>Program</b> and <b>Course/Program</b> are also accepted program headers. FPST requires Year 1–2 and Section; TVL requires Grade 11–12 and Section. TESDA needs neither year nor section; an optional batch may use Section. ID/name-only lists still work with defaults. Format Student ID as <b>Text</b> to keep leading zeros. Save old .xls files as .xlsx or CSV first.
        </p>

        <details class="smi-more">
          <summary>Optional settings</summary>
          <div class="smi-opts" style="margin:10px 0 0">
            <fieldset>
              <legend>Academic year</legend>
              <input type="text" id="smiAcadYear" class="form-input-full" placeholder="e.g. 2026-2027" maxlength="20" style="margin:4px 0">
              <p class="smi-note">Just a label for the activity log.</p>
            </fieldset>
          </div>
        </details>

        <div class="smi-privacy smi-note">
          <i class="fas fa-shield-halved"></i> <b>Privacy:</b> only Student ID, name, year/section, course and phone are read — all other columns are ignored.
          The file is not kept on the server and nothing is ever deleted.
        </div>
      </div>

      <!-- Step 2: preview -->
      <div id="smiStep2" style="display:none">
        <div class="smi-summary" id="smiSummary"></div>
        <div class="smi-chips" id="smiChips"></div>

        <p class="smi-note" id="smiColsInfo"></p>
        <details class="smi-mapbox" id="smiMapBox">
          <summary>Wrong column picked? <span class="smi-note">Review how your columns were matched</span></summary>
          <div class="smi-map" id="smiMapGrid"></div>
          <p class="smi-maperr" id="smiMapWarn"></p>
        </details>

        <h4 style="margin:14px 0 8px;font-size:14px">What should happen to students who are already in the system?</h4>
        <div class="smi-presets">
          <label class="smi-preset"><input type="radio" name="smiPolicy" value="overwrite" checked onchange="smiNameFmtChanged()">
            <span><b>New school year</b><small>Update their year level, section and course to the class you selected.</small></span></label>
          <label class="smi-preset"><input type="radio" name="smiPolicy" value="fill_blank" onchange="smiNameFmtChanged()">
            <span><b>Fill in missing info only</b><small>Keep what they already have; only empty fields get filled.</small></span></label>
          <label class="smi-preset"><input type="radio" name="smiPolicy" value="skip" onchange="smiNameFmtChanged()">
            <span><b>Add new students only</b><small>Don't touch anyone who is already in the system.</small></span></label>
        </div>

        <details class="smi-more">
          <summary>More options</summary>
          <div class="smi-opts" style="margin:10px 0 0">
            <fieldset id="smiOwBox">
              <legend>Fields to update</legend>
              <label><input type="checkbox" class="smiOw" value="section" checked onchange="smiNameFmtChanged()"> Year level / section</label>
              <label><input type="checkbox" class="smiOw" value="course" checked onchange="smiNameFmtChanged()"> Course / program</label>
              <label><input type="checkbox" class="smiOw" value="name" onchange="smiNameFmtChanged()"> Full name <span class="smi-note">(usually leave off)</span></label>
            </fieldset>
            <fieldset>
              <legend>Other</legend>
              <label><input type="checkbox" id="smiReactivate" onchange="smiRender()"> Re-activate inactive students who appear in the list</label>
              <p class="smi-note" style="margin:8px 0 2px"><b>How are names written?</b></p>
              <label><input type="radio" name="smiNameFmt" value="as_is" checked onchange="smiNameFmtChanged()"> As they are</label>
              <label><input type="radio" name="smiNameFmt" value="last_first" onchange="smiNameFmtChanged()"> “Last, First” → “First Last”</label>
              <p class="smi-note" id="smiCommaHint"></p>
            </fieldset>
          </div>
        </details>

        <div class="filter-group" style="margin:12px 0 10px;gap:10px;flex-wrap:wrap;align-items:center">
          <input type="search" class="form-input-full smi-search" id="smiSearch" placeholder="Find a student by ID or name…" oninput="smiSearchChanged()" style="margin:0">
          <select class="filter-select" id="smiFilter" onchange="smiFilterChanged()" style="display:none">
            <option value="all">All rows</option><option value="new">New</option><option value="update">Update</option>
            <option value="nochange">No change</option><option value="problem">Problems</option><option value="warn">Warnings</option>
          </select>
          <button class="btn btn-gray" id="smiProblemCsvBtn" onclick="smiDownloadProblems()" style="display:none"><i class="fas fa-download"></i> Download problem rows</button>
        </div>
        <div class="table-container" style="max-height:42vh;overflow-y:auto">
          <table class="data-table">
            <thead><tr><th>Row</th><th>Student ID</th><th>Full Name</th><th>Phone</th><th>Program</th><th>Section</th><th>Year</th><th>What happens</th><th>Details</th></tr></thead>
            <tbody id="smiRows"></tbody>
          </table>
        </div>
        <div class="pagination">
          <div class="pagination-info"><span id="smiPageInfo"></span>
            <label class="smi-note" style="margin-left:12px">Rows per page
              <select class="filter-select" id="smiPerPage" onchange="smiPerPageChanged()" style="margin-left:4px">
                <option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option>
              </select></label>
          </div>
          <div class="pagination-btns" id="smiPageBtns"></div>
        </div>
      </div>

      <!-- Step 3: result -->
      <div id="smiStep3" style="display:none">
        <h4 style="margin:0 0 4px"><i class="fas fa-circle-check" style="color:#16a34a"></i> All done!</h4>
        <p class="smi-note" id="smiResultMsg" style="font-size:14px"></p>
        <div class="smi-result" id="smiResult"></div>
        <p class="smi-note">Tools, borrow/return history, incident records and missing-tool cases were not changed.</p>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" id="smiCancelBtn" onclick="closeStudentImportModal()">Cancel</button>
      <button class="btn btn-gray" id="smiBackBtn" onclick="smiBack()" style="display:none"><i class="fas fa-arrow-left"></i> Choose another file</button>
      <button class="btn btn-gray" id="smiAgainBtn" onclick="smiAgain()" style="display:none"><i class="fas fa-file-import"></i> Import another file</button>
      <button class="btn btn-blue" id="smiPreviewBtn" onclick="smiPreview()" disabled>Check file <i class="fas fa-arrow-right"></i></button>
      <button class="btn btn-blue" id="smiCommitBtn" onclick="smiCommit()" style="display:none" disabled>Import</button>
    </div>
  </div>
</div>


<?php if ($isAdmin): ?>
<!-- ═══════════════════════════════════════════════════════════
     MODAL: Update Sections (set each student's new section — e.g. 1-C → 2-B)
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="sectionsModal">
  <div class="modal" style="max-width:760px">
    <div class="modal-header">
      <h3 class="modal-title">Update Sections</h3>
      <button class="modal-close" onclick="closeModal('sectionsModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:var(--gray-500);margin-bottom:14px">Pick a student's new section only if it changed. Leave “no change” for everyone else — classmates can end up in different sections.</p>
      <div class="filter-group" style="flex-wrap:wrap;margin-bottom:14px;gap:12px">
        <span class="filter-label">Course:</span>
        <select class="filter-select" id="usCourse" onchange="usCourseChanged()"></select>
        <span class="filter-label">Current section:</span>
        <select class="filter-select" id="usSection" onchange="usRender()"></select>
      </div>
      <div style="position:relative;margin-bottom:14px">
        <input type="text" class="form-input-full" id="usSearch" style="margin-bottom:0"
               placeholder="Search student by name or ID number…" autocomplete="off"
               oninput="usRender()">
      </div>
      <p id="usResultInfo" style="font-size:12px;color:var(--gray-500);margin:-6px 0 10px;min-height:16px"></p>
      <div class="table-container" style="max-height:50vh;overflow-y:auto">
        <table class="data-table">
          <thead><tr><th>Student</th><th>Current</th><th>New section</th></tr></thead>
          <tbody id="usRows"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('sectionsModal')">Cancel</button>
      <button class="btn btn-blue" id="usSaveBtn" onclick="usSave()" disabled>Save changes</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- ═══════════════════════════════════════════════════════════
     MODAL: Resolve Damaged / Missing units (repaired, found again)
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="conditionModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <h3 class="modal-title">Resolve Damaged / Missing</h3>
      <button class="modal-close" onclick="closeModal('conditionModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="cond_toolId">
      <p id="cond_summary" style="font-size:13px;color:var(--gray-600);margin-bottom:14px;line-height:1.6"></p>
      <div class="form-group"><label class="field-label">What happened? *</label>
        <select id="cond_action" class="form-input-full" onchange="onConditionActionChange()"></select>
      </div>
      <div class="form-group"><label class="field-label">Quantity *</label><input type="number" id="cond_qty" class="form-input-full" min="1" value="1"></div>
      <div class="form-group"><label class="field-label">Result *</label>
        <select id="cond_to" class="form-input-full"></select>
      </div>
      <div class="form-group"><label class="field-label">Notes</label><input type="text" id="cond_notes" class="form-input-full" placeholder="e.g. handle replaced, found in storage room"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('conditionModal')">Cancel</button>
      <button class="btn btn-blue" id="saveConditionBtn" onclick="saveCondition()">Save</button>
    </div>
  </div>
</div>
<div class="modal-overlay" id="toolDisposalModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header"><h3 class="modal-title">Dispose Damaged Tools</h3><button class="modal-close" aria-label="Close disposal form" onclick="closeModal('toolDisposalModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
      <input type="hidden" id="disposeToolId">
      <p id="disposeToolSummary" class="rp-note"></p>
      <div class="form-group"><label class="field-label" for="disposeToolQty">Quantity to dispose *</label><input type="number" id="disposeToolQty" class="form-input-full" min="1" step="1" value="1" required></div>
      <div class="form-group"><label class="field-label" for="disposeToolReason">Disposal reason *</label><textarea id="disposeToolReason" class="form-input-full" maxlength="500" rows="3" required placeholder="Explain why these damaged tools must be disposed of"></textarea></div>
      <div class="form-group"><label class="field-label" for="disposeToolCase">Linked damage case (optional)</label><select id="disposeToolCase" class="form-input-full"><option value="">No linked damage case</option></select></div>
      <p id="disposeToolError" class="rp-warning" role="alert"></p>
      <p class="rp-note">Only damaged units are removed. The tool SKU and all borrower and incident history remain unchanged.</p>
    </div>
    <div class="modal-footer"><button class="btn btn-gray" onclick="closeModal('toolDisposalModal')">Cancel</button><button type="button" class="card-btn primary" onclick="reviewToolDisposal()">Review Disposal</button></div>
  </div>
</div>
<div class="modal-overlay" id="toolDisposalConfirmModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header"><h3 class="modal-title">Confirm Tool Disposal</h3><button class="modal-close" aria-label="Close disposal confirmation" onclick="closeModal('toolDisposalConfirmModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body"><p id="disposeConfirmText" class="rp-note"></p><p id="disposeConfirmError" class="rp-warning" role="alert"></p></div>
    <div class="modal-footer"><button class="btn btn-gray" onclick="closeModal('toolDisposalConfirmModal')">Cancel</button><button type="button" class="btn btn-danger" id="confirmToolDisposalBtn" onclick="confirmToolDisposal()">Dispose Tool</button></div>
  </div>
</div>
<div class="modal-overlay" id="toolDisposalHistoryModal">
  <div class="modal" style="max-width:1000px;width:94vw">
    <div class="modal-header"><h3 class="modal-title">Disposed Tools History</h3><button class="modal-close" aria-label="Close disposal history" onclick="closeModal('toolDisposalHistoryModal')"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
      <div class="charge-filters"><div><label for="disposeHistorySearch">Tool, SKU, reason, case or staff</label><input id="disposeHistorySearch" class="form-input-full" type="search" maxlength="150"></div><div><label for="disposeHistoryFrom">From</label><input id="disposeHistoryFrom" class="form-input-full" type="date"></div><div><label for="disposeHistoryTo">To</label><input id="disposeHistoryTo" class="form-input-full" type="date"></div><button type="button" class="card-btn primary" onclick="loadToolDisposalHistory(1)">Filter</button></div>
      <p id="disposeHistoryCount" class="rp-note"></p>
      <div class="table-container" style="max-height:55vh;overflow:auto"><table class="data-table"><thead><tr><th>Disposed At</th><th>Tool / SKU</th><th>Quantity</th><th>Reason</th><th>Damage Case</th><th>Borrower</th><th>Disposed By</th><th>Stock History</th></tr></thead><tbody id="disposeHistoryBody"></tbody></table></div>
      <div class="charge-pagination"><button type="button" id="disposeHistoryPrev" class="card-btn secondary" onclick="loadToolDisposalHistory(disposalHistoryPage-1)">Previous</button><span id="disposeHistoryPage"></span><button type="button" id="disposeHistoryNext" class="card-btn secondary" onclick="loadToolDisposalHistory(disposalHistoryPage+1)">Next</button></div>
    </div>
    <div class="modal-footer"><button class="btn btn-gray" onclick="closeModal('toolDisposalHistoryModal')">Close</button></div>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Report Detail (view, then optionally export)
═══════════════════════════════════════════════════════════ -->
<section class="report-workspace" id="reportModal" hidden aria-label="Report view">
  <div class="card">
    <div class="report-view-heading">
      <button class="btn btn-gray" onclick="returnToReports()">Back to Reports</button>
      <div><h2 id="reportModalTitle" tabindex="-1">Report</h2><p id="reportModalCount" role="status"></p></div>
      <button class="btn btn-blue" id="exportReportBtn" onclick="exportCurrentReport()"><i class="fas fa-download"></i> Export CSV</button>
    </div>
    <div class="report-toolbar-row">
    <div class="inventory-report-toolbar" id="inventoryReportToolbar" hidden>
      <label>Search this report by tool name or code<input type="search" id="inventoryReportSearch" class="form-input-full" placeholder="Search this report by tool name or code" oninput="inventoryReportFilterChanged()"></label>
      <label>Category<select id="inventoryReportCategory" class="filter-select" onchange="inventoryReportFilterChanged()"><option value="">All categories</option></select></label>
      <button type="button" class="card-btn secondary" onclick="clearInventoryReportFilters()">Clear filters</button>
    </div>
    <details class="report-columns tt-column-control" id="reportColumns"><summary class="tt-column-button">Columns</summary><div id="reportColumnOptions"></div></details>
    </div>
    <div id="inventoryReportPagination" class="inventory-report-pagination" hidden>
      <span id="inventoryReportRange" role="status"></span>
      <label>Rows per page <select id="inventoryReportPageSize" onchange="inventoryReportPageSizeChanged(this.value)"><option>25</option><option>50</option><option>100</option></select></label>
      <button type="button" class="card-btn secondary" id="inventoryReportPrevious" onclick="inventoryReportChangePage(-1)">Previous</button>
      <button type="button" class="card-btn secondary" id="inventoryReportNext" onclick="inventoryReportChangePage(1)">Next</button>
    </div>
    <div class="report-records" tabindex="0" aria-label="Report filters and records">
      <div id="stockHistoryControls" class="stock-history-controls" hidden>
       <div><label class="field-label" for="stockDateFrom">From</label><input type="date" id="stockDateFrom" class="form-input-full"></div>
       <div><label class="field-label" for="stockDateTo">To</label><input type="date" id="stockDateTo" class="form-input-full"></div>
       <button type="button" class="card-btn primary" onclick="refreshStockHistoryReport()">Apply dates</button>
       <button type="button" class="card-btn secondary" onclick="stockHistoryToday()">Today</button>
       <button type="button" class="card-btn secondary" onclick="stockHistoryAll()">All dates</button>
      </div>
      <div id="stockHistorySummary" hidden></div>
      <div class="table-container report-table-scroll" tabindex="0" aria-label="Report records">
        <table class="data-table">
          <thead id="reportTableHead"></thead>
          <tbody id="reportTableBody"><tr class="empty-row"><td><span class="spinner dark"></span> Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Tool QR Code
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="qrModal">
  <div class="modal" style="max-width:340px">
    <div class="modal-header">
      <h3 class="modal-title">Tool QR Code</h3>
      <button class="modal-close" onclick="closeModal('qrModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="text-align:center">
      <div id="qrCodeContainer" style="display:flex;justify-content:center;margin:8px 0 12px"></div>
      <p id="qrToolLabel" style="font-weight:600;font-size:15px;margin-bottom:2px"></p>
      <p id="qrToolCode" style="color:var(--gray-500);font-size:13px;font-family:monospace"></p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('qrModal')">Close</button>
      <button class="btn btn-blue" onclick="printQR()"><i class="fas fa-print"></i> Print Label</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Borrower History
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="borrowerHistoryModal">
  <div class="modal" style="max-width:820px;width:92vw">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" id="bhName">Borrower</h3>
        <p id="bhMeta" style="color:var(--gray-500);font-size:13px;margin-top:2px"></p>
      </div>
      <button class="modal-close" onclick="closeModal('borrowerHistoryModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="bhStats" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px"></div>
      <section class="rp-block" aria-label="Borrower incidents"><h4>Damage &amp; Loss Incidents</h4><div id="bhIncidents"></div></section>
      <div class="rp-actions"><input id="bhSearch" class="form-input-full" type="search" aria-label="Search borrower history" placeholder="Transaction ID, tool or SKU" oninput="clearTimeout(window.bhSearchTimer);window.bhSearchTimer=setTimeout(()=>loadBorrowerProfileHistory(1),250)"><select id="bhFilter" class="filter-select" aria-label="Borrower history filter" onchange="loadBorrowerProfileHistory(1)"><option value="all">All activity</option><option value="active">Currently borrowed</option><option value="overdue">Overdue now</option><option value="late">Returned late</option><option value="returned">Completed loans</option><option value="returns">Return events</option></select></div>      <div class="table-container" style="max-height:50vh;overflow:auto">
        <table class="data-table">
          <thead><tr><th>Transaction ID / Event</th><th>Tool</th><th>Qty</th><th>Borrowed Date</th><th>Due Date</th><th>Returned Date</th><th>Status</th></tr></thead>
          <tbody id="bhHistoryBody"><tr class="empty-row"><td colspan="6"><span class="spinner dark"></span> Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
    <div class="charge-pagination"><span id="bhPagerInfo"></span><div id="bhPagerBtns"></div></div><div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('borrowerHistoryModal')">Close</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Account Settings
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="settingsModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <h3 class="modal-title">Account Settings</h3>
      <button class="modal-close" onclick="closeModal('settingsModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:var(--gray-500);margin-bottom:16px">Change your password. This only affects your own account.</p>
      <div class="form-group"><label class="field-label">Current Password *</label><input type="password" id="s_current" class="form-input-full" autocomplete="current-password"></div>
      <div class="form-group"><label class="field-label">New Password *</label><input type="password" id="s_new" class="form-input-full" placeholder="At least 8 characters" autocomplete="new-password"></div>
      <div class="form-group"><label class="field-label">Confirm New Password *</label><input type="password" id="s_confirm" class="form-input-full" autocomplete="new-password"></div>
      <?php if($isAdmin): ?><details class="rp-block" ontoggle="if(this.open)loadCirculationPolicy()"><summary>Borrowing Policies</summary>
       <p class="rp-note">Blank limits preserve unlimited borrowing. Type-specific policies override the default. Inactive accounts and stock integrity cannot be overridden.</p>
       <label class="field-label" for="policyType">Borrower type</label><select id="policyType" class="form-input-full" onchange="loadCirculationPolicy()"><option value="*">Default / all other types</option><option>Student</option><option>Faculty</option><option>Staff</option><option>Guest</option></select>
       <label class="field-label" for="policyQuantity">Maximum active quantity (blank = unlimited)</label><input id="policyQuantity" type="number" min="1" step="1" class="form-input-full">
       <label class="field-label" for="policyDays">Maximum loan days (blank = unlimited)</label><input id="policyDays" type="number" min="1" step="1" class="form-input-full">
       <label class="mt-check"><input id="policyOverdue" type="checkbox"> Allow additional borrowing with overdue tools</label>
       <p id="policyMessage" class="rp-note" role="status"></p><button type="button" class="card-btn primary" onclick="saveCirculationPolicy()">Save Borrowing Policy</button>
      </details><?php endif; ?>      <?php if ($isAdmin): ?><div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--gray-200)"><button type="button" class="card-btn secondary" onclick="ttOpenBackups()"><i class="fas fa-database"></i> Database Backup &amp; Restore</button></div><?php endif; ?>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('settingsModal')">Cancel</button>
      <button class="btn btn-blue" id="saveSettingsBtn" onclick="saveSettings()">Update Password</button>
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

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Reset User Password (Admin resets someone else's)
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="resetPasswordModal">
  <div class="modal" style="max-width:380px">
    <div class="modal-header">
      <h3 class="modal-title">Reset Password</h3>
      <button class="modal-close" onclick="closeModal('resetPasswordModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:var(--gray-500);margin-bottom:16px">Setting a new password for <strong id="rp_name"></strong>. They'll need to use this new password next time they log in.</p>
      <div class="form-group"><label class="field-label">New Password *</label><input type="password" id="rp_new" class="form-input-full" placeholder="At least 8 characters" autocomplete="new-password"></div>
      <div class="form-group"><label class="field-label">Confirm New Password *</label><input type="password" id="rp_confirm" class="form-input-full" autocomplete="new-password"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('resetPasswordModal')">Cancel</button>
      <button class="btn btn-blue" id="saveResetPasswordBtn" onclick="saveResetPassword()">Reset Password</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Current user's role, for client-side UI gating (the API endpoints
     enforce the real access control — see api/config.php's requireRole).
     Client-side hiding here is only so Staff don't see buttons they'd
     just get a 403 from; it is NOT the security boundary. -->
<!-- ═══════════════════════════════════════════════════════════
     MODAL: Report Missing Tool (Staff or Admin) — stock does NOT change until an Admin confirms
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="missingReportModal">
  <div class="modal" style="max-width:520px;width:94vw">
    <div class="modal-header">
      <h3 class="modal-title">Report Missing Tool</h3>
      <button class="modal-close" onclick="closeModal('missingReportModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="mt-summary" id="mr_loanSummary"></div>
      <label class="field-label">Responsible Borrower</label>
      <input type="text" class="form-input-full" id="mr_borrower" readonly>
      <label class="field-label">Quantity Missing *</label>
      <input type="number" class="form-input-full" id="mr_qty" min="1" value="1" style="margin-bottom:4px">
      <p class="mt-note" id="mr_qtyHint" style="margin:0 0 14px"></p>
      <label class="field-label">Date Missing *</label>
      <input type="date" class="form-input-full" id="mr_date">
      <label class="field-label">Reason *</label>
      <input type="text" class="form-input-full" id="mr_reason" list="mr_reasonList" maxlength="255" placeholder="e.g. Borrower cannot locate it" autocomplete="off">
      <datalist id="mr_reasonList"><option value="Borrower cannot locate it"><option value="Lost during an activity"><option value="Not returned after follow-up"><option value="Suspected stolen"></datalist>
      <label class="field-label">Investigation Notes</label>
      <textarea class="form-input-full" id="mr_notes" rows="3" maxlength="2000" placeholder="Who was contacted, where it was searched, follow-up dates…"></textarea>
      <p class="mt-note"><i class="fas fa-circle-info"></i> This only files the report. An Admin must confirm it before the tool is counted as missing.</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('missingReportModal')">Cancel</button>
      <button class="btn btn-danger" id="mr_submitBtn" onclick="submitMissingReport()">Submit Report</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: Missing Tool Case (details, audit trail, next actions)
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="missingActionModal">
  <div class="modal" style="max-width:680px;width:94vw">
    <div class="modal-header">
      <h3 class="modal-title" id="mc_title">Missing Tool Case</h3>
      <button class="modal-close" onclick="closeModal('missingActionModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="mc_body"></div>
    <div class="modal-footer">
      <button class="btn btn-gray" onclick="closeModal('missingActionModal')">Close</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/backups/settings-panel.php'; ?>
<div class="modal-overlay" id="rpIncidentModal">
 <div class="modal rp-modal" role="dialog" aria-modal="true" aria-labelledby="rpIncidentTitle">
  <div class="modal-header"><h3 class="modal-title" id="rpIncidentTitle">Manual Incident</h3><button class="modal-close" aria-label="Close incident form" onclick="closeModal('rpIncidentModal')"><i class="fas fa-times"></i></button></div>
  <div class="modal-body">
   <p class="rp-note">Returns create incidents automatically. Use this form for damage or loss discovered in available stock outside a return. Borrowed units must use Return Tool.</p>
   <label class="field-label" for="rpManualTool">Tool SKU</label><input id="rpManualTool" class="form-input-full" maxlength="50" required>
   <label class="field-label" for="rpManualBorrower">Responsible borrower ID number</label><input id="rpManualBorrower" class="form-input-full" maxlength="80" required>
   <label class="field-label" for="rpIncidentReason">Incident type</label><select class="form-input-full" id="rpIncidentReason"><option value="damaged">Damaged</option><option value="lost">Missing</option></select>
   <label class="field-label" for="rpIncidentQty">Affected quantity</label><input type="number" class="form-input-full" id="rpIncidentQty" min="1" max="100000" step="1" value="1" required>
   <label class="field-label" for="rpIncidentNotes">Incident details / evidence reference</label><textarea class="form-input-full" id="rpIncidentNotes" rows="3" maxlength="500" required></textarea>
  </div>
  <div class="modal-footer"><button class="btn btn-gray" onclick="closeModal('rpIncidentModal')">Cancel</button><button class="btn btn-blue" id="rpIncidentSubmit" onclick="rpSubmitIncident()">Record Incident</button></div>
 </div>
</div>
<div class="modal-overlay" id="rpDetailsModal">
 <div class="modal rp-modal" role="dialog" aria-modal="true" aria-labelledby="rpDetailsTitle">
  <div class="modal-header"><h3 class="modal-title" id="rpDetailsTitle">Damage &amp; Loss — Case Details</h3><button class="modal-close" aria-label="Close case details" onclick="closeModal('rpDetailsModal')"><i class="fas fa-times"></i></button></div>
  <div class="modal-body" id="rpDetailsBody"></div>
  <div class="modal-footer"><button class="btn btn-gray" onclick="closeModal('rpDetailsModal')">Close</button></div>
 </div>
</div>
<script>window.CURRENT_ROLE = <?php echo json_encode($currentRole); ?>; window.CURRENT_USER_ID = <?php echo (int)$_SESSION['user_id']; ?>;</script>
<!-- App scripts: plain (non-module) files sharing one global scope so the inline onclick="" handlers keep working.
     ORDER MATTERS: app.js first (shared utilities + navigation), then the tab modules, then the shared
     scanner helpers before borrow.js / return.js which use them. -->
<script src="assets/js/app.js"></script>
<?php if ($isAdmin): ?><script src="assets/js/backups.js"></script><?php endif; ?>
<script src="assets/js/dashboard.js"></script>
<script src="assets/js/tools.js"></script>
<script src="assets/js/borrowers.js"></script>
<script src="assets/js/table-redesign.js"></script>
<script src="assets/js/student-import.js"></script>
<script src="assets/js/scanner.js"></script>
<script src="assets/js/borrow.js"></script>
<script src="assets/js/return.js"></script>
<script src="assets/js/charge-payments.js"></script>
<script src="assets/js/missing-cases.js"></script>
<script src="assets/js/reports.js"></script><script src="assets/js/charge-workflow.js"></script><script src="assets/js/writeoffs.js"></script><script src="assets/js/incident-ui.js"></script>
<script src="assets/js/circulation.js"></script><script src="assets/js/users.js"></script>
<script src="assets/js/live-sync.js"></script>
</body>
</html>
