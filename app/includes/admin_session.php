<?php
// Guard for ADMIN pages. A student login can never satisfy this check.
require_once __DIR__ . '/functions.php';
start_secure_session();
ensure_migrated();
if (empty($_SESSION['admin_id'])) {
    redirect('admin/index.php');
}
require_once __DIR__ . '/roles.php';
// Re-read the account every request: a removed account is logged out and a role change applies at once.
$__acct = db_one("SELECT Username, role FROM admin WHERE id = ?", 'i', (int) $_SESSION['admin_id']);
if (!$__acct) {
    session_destroy();
    redirect('admin/index.php');
}
$_SESSION['role'] = $__acct['role'] === 'teacher' ? 'teacher' : 'admin';
$_SESSION['username'] = $__acct['Username'];
if (!is_admin_role() && !in_array(basename($_SERVER['SCRIPT_NAME']), TEACHER_PAGES, true)) {
    deny_page();
}
