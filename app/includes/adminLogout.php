<?php
require_once __DIR__ . '/functions.php';
start_secure_session();
unset($_SESSION['admin_id'], $_SESSION['username']);
session_regenerate_id(true);
redirect('admin/index.php');
