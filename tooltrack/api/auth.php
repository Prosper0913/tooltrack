<?php
// ================================================================
//  api/auth.php
//
//  GET  → returns logged-in user info for sidebar
//  POST → login  { username, password }  → sets session, returns user
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

    if (!$user) {
        fail('Invalid username or password.', 401);
    }

    $stored     = $user['password'];
    $isHashed   = password_get_info($stored)['algo'] !== null; // bcrypt/argon hashes are self-describing
    $passwordOk = false;

    if ($isHashed) {
        $passwordOk = password_verify($password, $stored);
    } else {
        // Legacy plaintext row (pre-migration seed data). Accept once,
        // then transparently upgrade it to a real hash so this branch
        // is never hit again for this account.
        $passwordOk = hash_equals($stored, $password);
        if ($passwordOk) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
            $upd->execute([$newHash, $user['id']]);
        }
    }

    if (!$passwordOk) {
        fail('Invalid username or password.', 401);
    }

    // Regenerate the session id on login to prevent session fixation.
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    ok(userPayload($user));
}

// ── DELETE: logout ────────────────────────────────────────────
if ($method === 'DELETE') {
    $_SESSION = [];
    session_destroy();
    ok(['message' => 'Logged out.']);
}

fail('Method not allowed.', 405);
