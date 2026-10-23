<?php
// AJAX: the exam page asks every few seconds whether the invigilator paused it or changed the time. Returns JSON.
define('SKIP_MIGRATE', true);
require_once __DIR__ . '/includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$studentId = (int) $_SESSION['student_id'];
$subId = (int) ($_SESSION['exam_sub_id'] ?? 0);
$a = $subId ? db_one("SELECT * FROM dosubject WHERE dostd_id = ? AND dosub_id = ?", 'ii', $studentId, $subId) : null;
if (!$a || (int) $a['istaken'] === 1) {
    echo json_encode(['ok' => true, 'ended' => true, 'paused' => false, 'left' => 0]);
    exit;
}
$left = attempt_remaining($a);
echo json_encode(['ok' => true, 'ended' => false, 'paused' => attempt_is_paused($a), 'left' => max(0, $left)]);
