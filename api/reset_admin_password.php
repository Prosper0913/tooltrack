<?php
// ================================================================
//  api/reset_admin_password.php  —  CLI-ONLY emergency password reset
//
//  Use this ONLY when the sole Admin account is locked out and
//  there's no other Admin who can reset it from the User Accounts
//  screen. Run it from the command line (not the browser) — it
//  will refuse to run over HTTP.
//
//  Usage (from a terminal, in this folder):
//    php reset_admin_password.php
//
//  It will:
//    1. List existing Admin accounts
//    2. Ask which username to reset
//    3. Ask for (and confirm) a new password
//    4. Hash it and update the row
//    5. Delete itself, so it can't be run again / left lying around
// ================================================================

// Refuse to run as a web request — CLI only.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

function prompt(string $label): string {
    echo $label;
    return trim(fgets(STDIN));
}

// Reads a line without echoing it to the terminal, where supported.
function promptSecret(string $label): string {
    echo $label;
    if (stripos(PHP_OS, 'WIN') === 0) {
        // Windows terminals generally can't suppress echo without extra
        // extensions, so fall back to a visible prompt with a warning.
        echo "(input will be visible)\n";
        return trim(fgets(STDIN));
    }
    system('stty -echo');
    $value = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";
    return $value;
}

echo "=== ToolTrack: Emergency Admin Password Reset ===\n\n";

try {
    $db = getDB();
} catch (Throwable $e) {
    echo "Could not connect to the database. Is MySQL running in XAMPP?\n";
    exit(1);
}

$admins = $db->query("SELECT id, name, username FROM users WHERE role = 'Admin' ORDER BY name")->fetchAll();

if (!$admins) {
    echo "No Admin accounts found in the database.\n";
    exit(1);
}

echo "Admin accounts:\n";
foreach ($admins as $a) {
    echo "  - {$a['username']}  ({$a['name']})\n";
}
echo "\n";

$username = prompt("Username to reset: ");

$stmt = $db->prepare('SELECT id, name FROM users WHERE username = ? AND role = ?');
$stmt->execute([$username, ROLE_ADMIN]);
$user = $stmt->fetch();

if (!$user) {
    echo "No Admin account found with username '$username'.\n";
    exit(1);
}

$new1 = promptSecret("New password (min 8 chars): ");
if (strlen($new1) < 8) {
    echo "Password must be at least 8 characters. Aborting.\n";
    exit(1);
}
$new2 = promptSecret("Confirm new password: ");

if ($new1 !== $new2) {
    echo "Passwords do not match. Aborting.\n";
    exit(1);
}

$hash = password_hash($new1, PASSWORD_DEFAULT);
$db->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
   ->execute([$hash, $user['id']]);

echo "\nPassword reset for '{$username}' ({$user['name']}).\n";

// Self-delete so this can't be re-run or left sitting on the server.
$self = __FILE__;
if (@unlink($self)) {
    echo "This script has deleted itself.\n";
} else {
    echo "NOTE: couldn't delete this script automatically — please delete\n";
    echo "reset_admin_password.php from the api/ folder manually.\n";
}
