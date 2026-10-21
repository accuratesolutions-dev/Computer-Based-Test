<?php
require_once __DIR__ . '/../../includes/functions.php';
start_secure_session();
if (empty($_SESSION['student_id'])) {
    http_response_code(403);
    echo "<script>alert('You cannot access this page!'); window.location='index.php';</script>";
    exit;
}
