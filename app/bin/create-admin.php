<?php
// CLI: php bin/create-admin.php <username> <password>   (resets password if the user exists)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';

[$script, $user, $pass] = array_pad($argv, 3, null);
if (!$user || !$pass) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username> <password>\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $user) || strlen($pass) < 10) {
    fwrite(STDERR, "Username: 3-50 chars [A-Za-z0-9._-]. Password: at least 10 characters.\n");
    exit(1);
}
$hash = password_hash($pass, PASSWORD_DEFAULT);
if (db_one("SELECT id FROM admin WHERE Username = ?", 's', $user)) {
    db_exec("UPDATE admin SET Password = ? WHERE Username = ?", 'ss', $hash, $user);
    echo "Password reset for admin '$user'.\n";
} else {
    db_exec("INSERT INTO admin (Username, Password) VALUES (?, ?)", 'ss', $user, $hash);
    echo "Admin '$user' created.\n";
}
