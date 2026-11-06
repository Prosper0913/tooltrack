<?php
// ================================================================
//  api/auth.php
//
//  GET    → returns logged-in user info for sidebar
//  POST   → login  { username, password }  → sets session, returns user
//  PUT    → change own password  { current_password, new_password }
//  DELETE → logout
// ================================================================
require_once __DIR__ . '/config.php';
// (config.php already calls session_start())

$method = $_SERVER['REQUEST_METHOD'];

function userPayload(array $user): array {
    $initials = implode('', array_map(
        fn($w) => strtoupper($w[0]),
        array_filter(explode(' ', $user['name']))
    ));
    return [
        'id'       => $user['id'],
        'name'     => $user['name'],
        'role'     => $user['role'],
        'initials' => substr($initials, 0, 2),
    ];
}

// Verifies $attempt against $stored. Handles the legacy-plaintext seed
// row transparently — if $stored isn't a real hash yet, a correct
// plaintext match silently upgrades it to bcrypt (same logic used to
// live only in the login flow; change-password needed it too, so it's
// shared here now).
function verifyAndMaybeUpgrade(PDO $db, int $userId, string $stored, string $attempt): bool {
    $isHashed = password_get_info($stored)['algo'] !== null;
    if ($isHashed) return password_verify($attempt, $stored);

    $ok = hash_equals($stored, $attempt);
    if ($ok) {
        $newHash = password_hash($attempt, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$newHash, $userId]);
    }
    return $ok;
}

// ── GET: return current session user ──────────────────────────
if ($method === 'GET') {
    $user = requireLogin();
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name, role FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if (!$row) fail('User not found', 404);

    ok(userPayload($row));
}

// ── POST: login ───────────────────────────────────────────────
if ($method === 'POST') {
    $b = body();
    $username = trim($b['username'] ?? '');
    $password = trim($b['password'] ?? '');

    if (!$username || !$password) fail('Username and password are required.');

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name, role, password FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !verifyAndMaybeUpgrade($db, (int)$user['id'], $user['password'], $password)) {
        // Logged with no user_id (failed logins are exactly the kind of
        // thing an audit trail exists to catch — e.g. repeated attempts
        // against the same username from an unfamiliar IP).
        logAudit('login_failed', "Failed login attempt for username '$username'", null, $username, null);
        fail('Invalid username or password.', 401);
    }

    // Regenerate the session id on login to prevent session fixation.
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    logAudit('login', "Logged in", (int)$user['id'], $user['name'], $user['role']);

    ok(userPayload($user));
}

// ── PUT: change own password (Settings panel) ───────────────────
if ($method === 'PUT') {
    $user = requireLogin();
    $b = body();
    $current = trim($b['current_password'] ?? '');
    $new     = trim($b['new_password']     ?? '');

    if (!$current || !$new) fail('Current and new password are required.');
    if (strlen($new) < 8) fail('New password must be at least 8 characters.');

    $db   = getDB();
    $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if (!$row) fail('User not found.', 404);

    if (!verifyAndMaybeUpgrade($db, $user['id'], $row['password'], $current)) {
        fail('Current password is incorrect.', 401);
    }

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$newHash, $user['id']]);
    logAudit('password_change', 'Changed own password');

    ok(['message' => 'Password updated.']);
}

// ── DELETE: logout ────────────────────────────────────────────
if ($method === 'DELETE') {
    logAudit('logout', 'Logged out');
    $_SESSION = [];
    session_destroy();
    ok(['message' => 'Logged out.']);
}

fail('Method not allowed.', 405);
