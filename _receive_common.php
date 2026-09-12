<?php
// ============================================================
//  tooltrack/_receive_common.php
//  Shared bootstrap for tooltrack's receive-from-CMS endpoints.
//
//  - Loads DB credentials (same connection style as
//    sync_fpst_masterlist.php so there's only one place to edit
//    creds for the whole tooltrack project).
//  - Authenticates the inbound request against a shared secret
//    via hash_equals() (timing-safe) — the SAME key that
//    sync_fpst_masterlist.php already hardcodes for outbound
//    calls to CMS, so there's only one secret to rotate.
//  - Provides json_out() / json_fail() helpers that match the
//    JSON shape CMS's own API uses.
//
//  Every endpoint in tooltrack that accepts inbound pushes from
//  CMS should `require_once __DIR__ . '/_receive_common.php';`
//  as the first line — that gives them all the same auth + DB
//  + JSON conventions.
// ============================================================

// ── Config — must match tooltrack's existing connection ──────
// EDIT THESE if tooltrack's DB creds are different from the
// defaults in sync_fpst_masterlist.php.
$DB_HOST = 'localhost';
$DB_NAME = 'tooltrack_db';
$DB_USER = 'root';
$DB_PASS = '';

// Shared secret — keep this IN SYNC with the constant in
// classroomv2/includes/sync_to_tooltrack.php. The CMS sends it
// as the `X-Tooltrack-Key` header on every push; we compare
// timing-safely below. Reuse the existing CMS_API_KEY value
// (the one already hardcoded in sync_fpst_masterlist.php) so
// there's only one secret in the whole setup.
$TOOLTRACK_RECEIVE_KEY = 'a888034173f952595606d57bf49804937dda9faea3d60c89426a7d0c4a239eda';

// ── Bootstrap ────────────────────────────────────────────────
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$conn->set_charset('utf8mb4');

// CORS is not needed — CMS calls server-to-server, not from a
// browser. Keep this strict.

// ── Authenticate the inbound request ─────────────────────────
// Endpoints that handle a real webhook call authenticate_request();
// endpoints that just render a UI (none yet, but reserved) skip it.
function authenticate_request() {
    global $TOOLTRACK_RECEIVE_KEY;
    $sent = $_SERVER['HTTP_X_TOOLTRACK_KEY'] ?? '';
    if ($sent === '' || !hash_equals($TOOLTRACK_RECEIVE_KEY, $sent)) {
        json_fail(401, 'Invalid or missing X-Tooltrack-Key header.');
    }
}

// ── JSON helpers ─────────────────────────────────────────────
function json_out($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail($code, $message, $extra = []) {
    json_out(array_merge(['success' => false, 'error' => $message], $extra), $code);
}

// ── Read & decode JSON body ──────────────────────────────────
function read_json_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        json_fail(400, 'Empty request body.');
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        json_fail(400, 'Request body must be valid JSON.');
    }
    return $body;
}

// ── Reformat a name to match tooltrack's "First MI. Last" style
// (lifted from sync_fpst_masterlist.php so manual and pushed
// rows look identical in the borrowers list).
function reformat_name($first_name, $middle_initial, $last_name) {
    $mi = trim((string)$middle_initial);
    $parts = array_filter([
        trim((string)$first_name),
        $mi !== '' ? $mi . '.' : '',
        trim((string)$last_name),
    ]);
    return implode(' ', $parts);
}
