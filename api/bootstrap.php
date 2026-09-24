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

// ── Audit trail ──────────────────────────────────────────────
// Call this right after any action worth being able to answer "who
// did this, from where, and when" about later. Never throws — a
// logging failure should never break the actual action it's
// recording, so any DB error here is swallowed.
//
// $userId/$username/$role default to whoever's logged in (the usual
// case). Pass them explicitly only for the one case where there
// isn't a session yet: a failed login attempt.
function logAudit(string $action, string $description, ?int $userId = null, ?string $username = null, ?string $role = null): void {
    try {
        $db = getDB();
        if ($userId === null) {
            $u        = currentUser();
            $userId   = $u['id']   ?? null;
            $username = $u['name'] ?? null;
            $role     = $u['role'] ?? null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        $db->prepare('
            INSERT INTO audit_log (user_id, username_snapshot, role_snapshot, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([$userId, $username, $role, $action, $description, $ip, $ua]);
    } catch (\Throwable $e) {
        // Deliberately silent — see doc comment above.
    }
}

// Turns a raw User-Agent string into something a human can scan at a
// glance ("Chrome on Windows") instead of the full raw string. This
// is a lightweight heuristic, not a proper UA-parsing library — good
// enough for "what kind of device was this," not for anything that
// needs to be exact.
function friendlyDevice(?string $ua): string {
    if (!$ua) return 'Unknown device';
    $os =
        (stripos($ua, 'Windows') !== false)               ? 'Windows' :
        (stripos($ua, 'Android') !== false)                ? 'Android' :
        (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) ? 'iOS' :
        (stripos($ua, 'Mac OS') !== false)                 ? 'macOS' :
        (stripos($ua, 'Linux') !== false)                  ? 'Linux' : 'Unknown OS';
    $browser =
        (stripos($ua, 'Edg/') !== false)     ? 'Edge' :
        (stripos($ua, 'Chrome/') !== false)  ? 'Chrome' :
        (stripos($ua, 'Firefox/') !== false) ? 'Firefox' :
        (stripos($ua, 'Safari/') !== false)  ? 'Safari' : 'Unknown browser';
    return "$browser on $os";
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
