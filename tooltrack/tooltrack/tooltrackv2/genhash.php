<?php
// ================================================================
//  genhash.php — helper for creating new user accounts
//
//  There's currently no UI for adding users (Admin or Staff), so
//  until that's built, use this from the command line to generate
//  a ready-to-run INSERT statement with a properly bcrypt-hashed
//  password. Never insert a plaintext password directly.
//
//  Usage:
//    php genhash.php "Full Name" "username" "password" "Admin"
//    php genhash.php "Jane Cruz" "jcruz" "s0mePass!" "Staff"
//
//  Roles must be exactly 'Admin' or 'Staff' (see ROLE_ADMIN /
//  ROLE_STAFF in api/config.php).
// ================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script is for command-line use only (it would otherwise print password hashes to the web).');
}

[$_, $name, $username, $password, $role] = $argv + [null, null, null, null, null];

if (!$name || !$username || !$password || !$role) {
    fwrite(STDERR, "Usage: php genhash.php \"Full Name\" \"username\" \"password\" \"Admin|Staff\"\n");
    exit(1);
}
if (!in_array($role, ['Admin', 'Staff'], true)) {
    fwrite(STDERR, "Role must be exactly 'Admin' or 'Staff'.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "-- Run this in phpMyAdmin / mysql CLI against tooltrack_db:\n";
printf(
    "INSERT INTO users (name, username, email, password, role) VALUES (%s, %s, %s, %s, %s);\n",
    $pdo_quote = "'" . addslashes($name) . "'",
    "'" . addslashes($username) . "'",
    "'" . addslashes($username) . "@school.edu'",
    "'" . addslashes($hash) . "'",
    "'" . addslashes($role) . "'"
);
