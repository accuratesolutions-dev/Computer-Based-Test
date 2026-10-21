<?php
require_once __DIR__ . '/../../includes/functions.php';
start_secure_session();

function fail($msg, $code = 422)
{
    http_response_code($code);
    echo $msg;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Invalid request.', 405);
}
if (!registration_open()) {
    fail('Registration is closed.', 403);
}
if (login_blocked('register')) {
    fail('Too many registrations from this device. Please try again later.', 429);
}

$surname    = trim((string) ($_POST['surname'] ?? ''));
$otherNames = trim((string) ($_POST['otherNames'] ?? ''));
$gender     = (string) ($_POST['gender'] ?? '');
$phone      = preg_replace('/\s+/', '', (string) ($_POST['phoneNumber'] ?? ''));
$dob        = (string) ($_POST['dateOfBirth'] ?? '');
$classId    = (int) ($_POST['department'] ?? 0);
$level      = (string) ($_POST['level'] ?? '');

if ($surname === '' || $otherNames === '' || mb_strlen($surname) > 100 || mb_strlen($otherNames) > 150) {
    fail('Please enter a valid surname and other names.');
}
if (!in_array($gender, ['Male', 'Female'], true)) {
    fail('Please select your gender.');
}
if (!preg_match('/^\+?\d{7,15}$/', $phone)) {
    fail('Please enter a valid phone number.');
}
$d = DateTime::createFromFormat('Y-m-d', $dob);
if (!$d || $d->format('Y-m-d') !== $dob) {
    fail('Please enter a valid date of birth.');
}
$class = db_one("SELECT id, class, sortname FROM class WHERE id = ?", 'i', $classId);
if (!$class) {
    fail('Please select a valid class.');
}
$level = (string) canonical_level($level, class_is_junior($class));
if ($level === '') {
    fail('Please select a level that belongs to your class.');
}

$subjects = [];
for ($i = 1; $i <= 9; $i++) {
    $name = trim((string) ($_POST['subject' . $i] ?? ''));
    $row = $name === '' ? null : findSubject($classId, $name);
    if (!$row) {
        fail('Please select a valid subject for Subject ' . $i . '.');
    }
    if (in_array($row['sub_name'], $subjects, true)) {
        fail('"' . trim($row['sub_name']) . '" was selected twice. Choose a different subject.');
    }
    $subjects[] = $row['sub_name'];
}

$examNumber = null;
for ($attempt = 0; $attempt < 5; $attempt++) {
    $candidate = random_int(10000000, 99999999) . 'TECH';
    try {
        db_exec(
            "INSERT INTO register (surname, otherNames, gender, phoneNumber, DateOfBirth, department, level,
                                   subject1, subject2, subject3, subject4, subject5, subject6, subject7, subject8, subject9, examNumber)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'sssssssssssssssss',
            $surname, $otherNames, $gender, $phone, $dob, (string) $classId, $level,
            ...array_merge($subjects, [$candidate])
        );
        $examNumber = $candidate;
        break;
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() !== 1062) {
            throw $ex;
        }
    }
}
if ($examNumber === null) {
    fail('Could not generate an exam number. Please try again.', 500);
}

session_regenerate_id(true);
$_SESSION['student_id'] = db_insert_id();
login_failed('register'); // counts toward the registration rate limit

echo 'Form Submitted Successfully, Please proceed to complete the registration!';
