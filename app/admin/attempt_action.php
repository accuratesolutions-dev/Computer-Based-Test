<?php
// AJAX: invigilator controls for exams in progress (pause, resume, reset the clock, add minutes). Administrators only.
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin_role();
header('Content-Type: application/json');
header('Cache-Control: no-store');

function out($ok, $msg, $code = 200) { http_response_code($code); echo json_encode(['ok' => $ok, 'msg' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { out(false, 'POST only', 405); }
if (!csrf_check()) { out(false, 'Session expired - reload the page.', 403); }

$action = (string) ($_POST['action'] ?? '');
$studentId = (int) ($_POST['student'] ?? 0);
$subId = (int) ($_POST['subject'] ?? 0);   // 0 with pauseAll/resumeAll = every subject
$now = date('Y-m-d H:i:s');
$ts = time();

// Resume = the paused stretch is given back, so the student loses no time.
function resume_attempt(array $a, $ts, $now)
{
    if (!attempt_is_paused($a)) { return false; }
    $gap = max(0, $ts - strtotime($a['paused_at']));
    db_exec("UPDATE dosubject SET extra_seconds = extra_seconds + ?, paused_at = NULL WHERE id = ? AND istaken = 0", 'ii', $gap, (int) $a['id']);
    return true;
}

if ($action === 'pauseAll' || $action === 'resumeAll') {
    $rows = db_all("SELECT * FROM dosubject WHERE istaken = 0 AND (? = 0 OR dosub_id = ?)", 'ii', $subId, $subId);
    $n = 0;
    foreach ($rows as $a) {
        if ($action === 'pauseAll' && !attempt_is_paused($a) && attempt_remaining($a, $ts) > 0) {
            db_exec("UPDATE dosubject SET paused_at = ? WHERE id = ? AND istaken = 0 AND paused_at IS NULL", 'si', $now, (int) $a['id']); $n++;
        } elseif ($action === 'resumeAll' && resume_attempt($a, $ts, $now)) { $n++; }
    }
    out(true, $n . ' student(s) ' . ($action === 'pauseAll' ? 'paused' : 'resumed') . '.');
}

$a = db_one("SELECT * FROM dosubject WHERE dostd_id = ? AND dosub_id = ? AND istaken = 0", 'ii', $studentId, $subId);
if (!$a) { out(false, 'That exam is no longer in progress.', 404); }

if ($action === 'pause') {
    if (!attempt_is_paused($a)) { db_exec("UPDATE dosubject SET paused_at = ? WHERE id = ? AND paused_at IS NULL", 'si', $now, (int) $a['id']); }
    out(true, 'Paused.');
} elseif ($action === 'resume') {
    resume_attempt($a, $ts, $now);
    out(true, 'Resumed.');
} elseif ($action === 'reset') {
    // Start the clock again with the full time; answers already saved are kept.
    db_exec("UPDATE dosubject SET time = ?, extra_seconds = 0, paused_at = NULL WHERE id = ? AND istaken = 0", 'si', $now, (int) $a['id']);
    out(true, 'Time reset to the full ' . (int) $a['duration'] . ' minutes.');
} elseif ($action === 'add') {
    $min = (int) ($_POST['minutes'] ?? 0);
    if ($min < 1 || $min > 120) { out(false, 'Enter 1 to 120 minutes.', 422); }
    db_exec("UPDATE dosubject SET extra_seconds = extra_seconds + ? WHERE id = ? AND istaken = 0", 'ii', $min * 60, (int) $a['id']);
    out(true, $min . ' minute(s) added.');
}
out(false, 'Unknown action', 400);
