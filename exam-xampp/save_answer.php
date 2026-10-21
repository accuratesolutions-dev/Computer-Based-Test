<?php
// AJAX auto-save endpoint for the exam page. Returns JSON.
define('SKIP_MIGRATE', true); // hot path: the schema already exists once an exam has started
require_once __DIR__ . '/includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

function out($ok, $msg = '', $code = 200)
{
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'msg' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(false, 'Method not allowed', 405);
}
if (!csrf_check()) {
    out(false, 'Session expired. Please reload the page.', 403);
}

$studentId = (int) $_SESSION['student_id'];
$qid = (int) ($_POST['question_id'] ?? 0);
$choice = (int) ($_POST['choice'] ?? 0);
if ($choice < 1 || $choice > 4) {
    out(false, 'Invalid choice', 422);
}

$q = db_one("SELECT id, class_id, subject_id FROM question WHERE id = ?", 'i', $qid);
if (!$q) {
    out(false, 'Unknown question', 404);
}

// Must be an open attempt of this student for this question's subject, still inside the time limit.
$attempt = db_one(
    "SELECT * FROM dosubject WHERE dostd_id = ? AND doclass_id = ? AND dosub_id = ? AND istaken = 0",
    'iii', $studentId, (int) $q['class_id'], (int) $q['subject_id']
);
if (!$attempt) {
    out(false, 'No active exam for this question', 409);
}
if (time() > strtotime($attempt['time']) + ((int) $attempt['duration'] * 60) + 60) {
    out(false, 'Time is up', 409);
}

// Only questions in this student's own random set can be answered.
$setSize = db_one("SELECT COUNT(*) AS c FROM exam_question WHERE student_id = ? AND subject_id = ?", 'ii', $studentId, (int) $q['subject_id']);
if ((int) $setSize['c'] > 0
    && !db_one("SELECT id FROM exam_question WHERE student_id = ? AND question_id = ?", 'ii', $studentId, $qid)) {
    out(false, 'That question is not in your exam', 403);
}

db_exec(
    "INSERT INTO exam_answer (student_id, subject_id, question_id, choice) VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE choice = VALUES(choice)",
    'iiii', $studentId, (int) $q['subject_id'], $qid, $choice
);
out(true, 'saved');
