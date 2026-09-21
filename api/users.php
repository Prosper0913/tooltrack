<?php
// ================================================================
//  api/users.php  —  Admin-only user management (Admin/Staff accounts)
//
//  GET    → list users (id, name, username, role, created_at — never password)
//  POST   { name, username, password, role }
//  PATCH  { id, new_password }  → Admin resets ANOTHER user's password
//                                  (the "forgot password" recovery path)
//  DELETE ?id=N   (cannot delete yourself, cannot delete the last Admin)
// ================================================================
require_once __DIR__ . '/config.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

$me = requireRole(ROLE_ADMIN);

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $db->query('SELECT id, name, username, role, created_at FROM users ORDER BY name');
    ok($stmt->fetchAll());
}

// ── POST (Create) ──────────────────────────────────────────────
if ($method === 'POST') {
    $b = body();

    $name     = trim($b['name']     ?? '');
    $username = trim($b['username'] ?? '');
    $password = trim($b['password'] ?? '');
    $role     = trim($b['role']     ?? '');

    if (!$name || !$username || !$password || !$role) {
        fail('Name, username, password, and role are required.');
    }
    if (!in_array($role, [ROLE_ADMIN, ROLE_STAFF], true)) {
        fail('Role must be Admin or Staff.');
    }
    if (strlen($password) < 8) {
        fail('Password must be at least 8 characters.');
    }

    $check = $db->prepare('SELECT id FROM users WHERE username = ?');
    $check->execute([$username]);
    if ($check->fetch()) fail("Username '$username' is already taken.");

    $hash  = password_hash($password, PASSWORD_DEFAULT);
    $email = $username . '@school.edu'; // placeholder — users table requires a unique email but the app logs in by username

    $stmt = $db->prepare('INSERT INTO users (name, username, email, password, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$name, $username, $email, $hash, $role]);

    $id   = (int)$db->lastInsertId();
    $stmt = $db->prepare('SELECT id, name, username, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$id]);
    ok($stmt->fetch(), 201);
}

// ── PATCH (Admin resets another user's password) ────────────────
// This is the "someone forgot their password" recovery path for
// every account EXCEPT the one currently logged in — an Admin can
// reset a Staff account (or another Admin account) without knowing
// their old password. If the sole Admin is the one locked out, see
// reset_admin_password.php (CLI-only) instead.
if ($method === 'PATCH') {
    $b = body();
    $id  = (int)($b['id'] ?? 0);
    $new = trim($b['new_password'] ?? '');

    if (!$id || !$new) fail('id and new_password are required.');
    if (strlen($new) < 8) fail('New password must be at least 8 characters.');

    $stmt = $db->prepare('SELECT id, name FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) fail('User not found.', 404);

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $id]);

    ok(['message' => "Password reset for {$target['name']}."]);
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('Missing user id.');

    if ($id === $me['id']) {
        fail('You cannot delete your own account while logged in as it.');
    }

    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) fail('User not found.', 404);

    if ($target['role'] === ROLE_ADMIN) {
        $count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn();
        if ($count <= 1) fail('Cannot delete the last remaining Admin account.');
    }

    $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    ok(['deleted_id' => $id]);
}

fail('Method not allowed.', 405);
