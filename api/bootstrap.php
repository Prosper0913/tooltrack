<?php
// ================================================================
//  api/bootstrap.php  —  shared by EVERY PHP file (pages and API
//  endpoints alike). Sets up session, DB connection, roles, and
//  helper functions — but sends NO HTTP headers of its own, so it's
//  safe to include from an HTML page like index.php.
//
//  api/*.php endpoints should require api/config.php instead (which
//  includes this file, then layers the JSON/CORS headers on top).
//  index.php and any other HTML page should require this file
//  directly.
// ================================================================

// ── Error handling ──────────────────────────────────────────────
// display_errors OFF: a PHP warning/notice/fatal error must never be
// allowed to print HTML (e.g. "<br />\n<b>Warning</b>: ...") into the
// middle of what's supposed to be a pure JSON response — that's what
// breaks res.json() in app.js with "Unexpected token '<'". Instead,
// log_errors ON writes the same info to the PHP error log, where you
// can actually go read it without it corrupting the API response.
// Flip display_errors back on temporarily on your own dev machine if
// you want errors inline in the browser while debugging.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('DB_HOST', 'localhost');
define('DB_NAME', 'tooltrack_db');
define('DB_USER', 'root');        // your MySQL username
define('DB_PASS', '');            // your MySQL password

// ── Roles ────────────────────────────────────────────────────
// Standardized role strings used everywhere in the app.
// (Existing seed data used 'Department Admin' — the migration
//  SQL renames it to 'Admin' so this matches going forward.)
define('ROLE_ADMIN', 'Admin');
define('ROLE_STAFF',  'Staff');

// ── Session (must start before any output) ─────────────────────
// Scope the session cookie to this app's own path. Without this, PHP's
// default session.cookie_path is "/" — meaning if ToolTrack is deployed
// as a subfolder alongside another PHP app on the same domain (e.g.
// yourhost.com/tooltrack next to another app at yourhost.com/), both
// apps would share the exact same session cookie across the whole
// domain, and their $_SESSION data could collide or bleed together.
// Also uses cookie_httponly (JS can't read the session cookie) and
// samesite=Lax (a reasonable default CSRF mitigation) while we're at it.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'path'     => '/tooltrack/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── PDO connection (shared by every endpoint) ──────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

// ── JSON response helpers (used by API endpoints; harmless if an
//    HTML page never calls them) ────────────────────────────────
function ok($data = null, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function fail(string $message, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function generateTxnId(): string {
    return 'TXN-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
}

// ── Auth guards ──────────────────────────────────────────────
// Call requireLogin() at the top of any endpoint/page that needs a
// logged-in user. Call requireRole() when only specific role(s)
// may proceed. On an API endpoint these fail() with JSON + a status
// code; on an HTML page, check currentUser() yourself instead (see
// index.php) since redirecting is usually what you want there, not
// a JSON error.
function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id'   => (int)$_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? '',
        'role' => $_SESSION['user_role'] ?? '',
    ];
}

function requireLogin(): array {
    $user = currentUser();
    if (!$user) fail('Not authenticated. Please log in.', 401);
    return $user;
}

// $roles may be a single role string or an array of allowed roles.
function requireRole($roles): array {
    $user = requireLogin();
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($user['role'], $allowed, true)) {
        fail('You do not have permission to perform this action.', 403);
    }
    return $user;
}

// ── ID number validation/normalization ──────────────────────────
// Strips spaces and dashes before checking — so "2024-00123",
// "2024 00123", and "202400123" are all treated as the same ID. This
// matters because a real physical school ID usually has a dash on
// it, but the stored/validated format is digits-only; without this
// normalization, someone typing the ID exactly as printed on their
// card gets rejected even though it's "correct". Only Student is
// held to the 8-10 digit shape — Faculty/Staff use the school's HR
// numbering (unspecified here) and Guest isn't in that system at all.
// Returns the normalized value to store; fail()s (exits) on an
// invalid Student ID.
function validateIdNumber(string $type, string $idNumber): string {
    $clean = preg_replace('/[\s\-]/', '', $idNumber);
    if ($type === 'Student' && !preg_match('/^\d{8,10}$/', $clean)) {
        fail('Student ID must be 8 to 10 digits (numbers only).');
    }
    return $clean;
}
