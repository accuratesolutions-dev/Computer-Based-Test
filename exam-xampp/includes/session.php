<?php
// Guard for STUDENT pages. Admins use admin_session.php (separate session key).
require_once __DIR__ . '/functions.php';
start_secure_session();
if (!defined('SKIP_MIGRATE')) { ensure_migrated(); }
if (empty($_SESSION['student_id'])) {
    redirect('index.php');
}
