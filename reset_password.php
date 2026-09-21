<?php
// ================================================================
//  reset_password.php — emergency password recovery (CLI-only)
//
//  Use this when NOBODY can log in to use the in-app "Admin resets
//  a user's password" feature (api/users.php PATCH) — e.g. the sole
//  Admin account itself is the one that's locked out.
//
//  This deliberately requires shell/SSH access to the server it's
//  running on (same trust level as phpMyAdmin access) rather than
//  being a web-reachable "forgot password" form — a web form that
//  can reset any account's password with no proof of identity would
//  itself be a serious security hole. Physical/SSH access to the
//  server is the actual "proof of identity" here.
//
//  Usage:
//    php reset_password.php <username> <new-password>
//    php reset_password.php admin S0meNewPassword!
// ================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script is for command-line use only.');
}

require_once __DIR__ . '/api/bootstrap.php';

[$_, $username, $newPassword] = $argv + [null, null, null];

if (!$username || !$newPassword) {
    fwrite(STDERR, "Usage: php reset_password.php <username> <new-password>\n");
    exit(1);
}
if (strlen($newPassword) < 8) {
    fwrite(STDERR, "New password must be at least 8 characters.\n");
    exit(1);
}

$db   = getDB();
$stmt = $db->prepare('SELECT id, name FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user) {
    fwrite(STDERR, "No user found with username '$username'.\n");
    exit(1);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $user['id']]);

echo "Password reset for {$user['name']} (username: $username). They can log in with the new password now.\n";
