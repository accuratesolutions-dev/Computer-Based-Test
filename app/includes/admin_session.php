<?php
// Guard for ADMIN pages. A student login can never satisfy this check.
require_once __DIR__ . '/functions.php';
start_secure_session();
ensure_migrated();
if (empty($_SESSION['admin_id'])) {
    redirect('admin/index.php');
}
