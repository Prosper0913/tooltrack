<?php
// ================================================================
//  api/auth.php
//
//  GET  → returns logged-in user info for sidebar
//  POST → login  { username, password }  → sets session, returns user
//  DELETE → logout
// ================================================================
session_start();
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: return current session user ──────────────────────────
if ($method === 'GET') {
    if (empty($_SESSION['user_id'])) {
        fail('Not authenticated', 401);
    }
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name, role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) fail('User not found', 404);

    $initials = implode('', array_map(
        fn($w) => strtoupper($w[0]),
        array_filter(explode(' ', $user['name']))
    ));

    ok([
        'id'       => $user['id'],
        'name'     => $user['name'],
        'role'     => $user['role'],
        'initials' => substr($initials, 0, 2),
    ]);
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

if (!$user || $password !== $user['password']) {
    fail('Invalid username or password.', 401);
}

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    $initials = implode('', array_map(
        fn($w) => strtoupper($w[0]),
        array_filter(explode(' ', $user['name']))
    ));

    ok([
        'id'       => $user['id'],
        'name'     => $user['name'],
        'role'     => $user['role'],
        'initials' => substr($initials, 0, 2),
    ]);
}

// ── DELETE: logout ────────────────────────────────────────────
if ($method === 'DELETE') {
    session_destroy();
    ok(['message' => 'Logged out.']);
}

fail('Method not allowed.', 405);
