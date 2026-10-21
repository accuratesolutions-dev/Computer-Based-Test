<?php
// Student login endpoint (AJAX). Responses: "1" ok, "2" wrong details, "empty", or a message.
// A student must supply BOTH the exam number and the date of birth given at registration.
require_once __DIR__ . '/functions.php';
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$regNumber = trim((string) ($_POST['regNumber'] ?? ''));
$dob       = trim((string) ($_POST['dob'] ?? ''));
if ($regNumber === '' || $dob === '') {
    echo 'empty';
    exit;
}

// Two throttles: per client IP (spraying many numbers) and per exam number (guessing one student's DOB).
$numKey = 'n:' . substr(hash('sha256', strtolower($regNumber)), 0, 16);
$numBlocked = (int) db_one(
    "SELECT COUNT(*) AS c FROM login_attempts WHERE ip = 'exam-number' AND bucket = ? AND attempted_at > (NOW() - INTERVAL 10 MINUTE)",
    's', $numKey
)['c'] >= 5;

if (login_blocked('student') || $numBlocked) {
    http_response_code(429);
    echo 'Too many failed attempts. Please wait 10 minutes and try again.';
    exit;
}

$d = DateTime::createFromFormat('Y-m-d', $dob);
$validDob = $d && $d->format('Y-m-d') === $dob;

$student = $validDob
    ? db_one("SELECT id, DateOfBirth FROM register WHERE examNumber = ? LIMIT 1", 's', $regNumber)
    : null;

// Same response whether the number or the date was wrong, so neither can be probed separately.
if ($student && $student['DateOfBirth'] !== '' && hash_equals((string) $student['DateOfBirth'], $dob)) {
    session_regenerate_id(true);
    $_SESSION['student_id'] = (int) $student['id'];
    login_cleared('student');
    db_exec("DELETE FROM login_attempts WHERE ip = 'exam-number' AND bucket = ?", 's', $numKey);
    echo '1';
} else {
    login_failed('student');
    db_exec("INSERT INTO login_attempts (ip, bucket) VALUES ('exam-number', ?)", 's', $numKey);
    echo '2';
}
