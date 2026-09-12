<?php
// ================================================================
//  api/config.php  —  Database connection
//  Edit the 4 constants below to match your server.
// ================================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'tooltrack_db');
define('DB_USER', 'root');        // your MySQL username
define('DB_PASS', '');            // your MySQL password

// ── CORS headers (allow your frontend origin) ──────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');          // tighten in production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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
