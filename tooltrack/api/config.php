<?php
// ================================================================
//  api/config.php  —  Database connection
//  Edit the 4 constants below to match your server.
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
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── CORS headers ─────────────────────────────────────────────
// NOTE: this app is currently served same-origin (index.php and
// api/*.php on the same host), so the browser doesn't apply CORS
// to these requests at all — sessions/cookies just work. If you
// ever split the frontend onto a different origin, '*' below is
// invalid together with credentials=true (browsers will reject
// it) — replace '*' with your exact frontend origin at that point.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');          // tighten in production
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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
            echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

// ── Helpers ────────────────────────────────────────────────────
function ok($data = null, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function fail(string $message, int $code = 400): void {
    http_response_code($code);
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
// Call requireLogin() at the top of any endpoint that needs a
// logged-in user (i.e. almost all of them). Call requireRole()
// instead when only specific role(s) may proceed.
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
