<?php
require_once __DIR__ . '/functions.php';
start_secure_session();
unset($_SESSION['student_id']);
session_regenerate_id(true);
redirect('index.php');
