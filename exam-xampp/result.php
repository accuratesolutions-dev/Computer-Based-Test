<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/grading.php';

$studentId = (int) $_SESSION['student_id'];
$student = db_one("SELECT * FROM register WHERE id = ?", 'i', $studentId);
if (!$student) {
    redirect('includes/logout.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The exam form was submitted (by the student or by the timer): grade it, then redirect so a refresh never re-posts.
    if (!csrf_check()) {
        redirect('subjects.php');
    }
    $subId = (int) ($_SESSION['exam_sub_id'] ?? 0);
    $subject = null;
    foreach (student_subjects($student) as $s) {
        if ((int) $s['sub_id'] === $subId) {
            $subject = $s;
        }
    }
    if (!$subject) {
        redirect('subjects.php');
    }
    // Enforce the server clock (60s grace for network delay / auto-submit).
    $attempt = db_one(
        "SELECT * FROM dosubject WHERE dostd_id = ? AND doclass_id = ? AND dosub_id = ?",
        'iii', $studentId, (int) $subject['class_id'], $subId
    );
    $withinTime = $attempt && attempt_remaining($attempt) >= -60;
    finalize_exam($studentId, $subject, $_POST, $withinTime);
    unset($_SESSION['exam_sub_id']);
    redirect('result.php?sID=' . $subId);
}

// GET: show the stored result of a subject this student has completed.
$subId = (int) ($_GET['sID'] ?? 0);
$subject = null;
foreach (student_subjects($student) as $s) {
    if ((int) $s['sub_id'] === $subId) {
        $subject = $s;
    }
}
$result = $subject ? db_one("SELECT * FROM exam_result WHERE student_id = ? AND subject_id = ?", 'ii', $studentId, $subId) : null;
if (!$result) {
    redirect('subjects.php');
}

// How many subjects are still to be written.
$done = [];
foreach (db_all("SELECT subject_id FROM exam_result WHERE student_id = ?", 'i', $studentId) as $r) { $done[(int) $r['subject_id']] = true; }
$remaining = 0;
foreach (student_subjects($student) as $s) { if (!isset($done[(int) $s['sub_id']])) { $remaining++; } }

include __DIR__ . '/includes/header.php';
?>
<body class="index_body">
<?php include __DIR__ . '/includes/student_bar.php'; ?>
<div class="container">
    <div class="custom mt-4 text-center">
        <h2><?php echo e(trim($subject['sub_name'])); ?> &mdash; Exam Submitted</h2>
        <h1 class="mt-4"><?php echo (int) $result['correct']; ?> / <?php echo (int) $result['total']; ?></h1>
        <?php $g = grade_for($result['percent']); ?>
        <p class="lead"><?php echo e($result['percent']); ?>% &mdash; Grade <strong><?php echo e($g['grade']); ?></strong>
            <?php if ($g['remark'] !== ''): ?>(<?php echo e($g['remark']); ?>)<?php endif; ?></p>
        <div class="mt-4">
            <?php if ($remaining > 0): ?>
                <a class="btn btn-success btn-lg" href="subjects.php">Continue to my subjects &rarr;</a>
            <?php else: ?>
                <a class="btn btn-info btn-lg mr-2" href="subjects.php">View my subjects</a>
                <button type="button" class="btn btn-danger btn-lg" onclick="quitExam();">Log out</button>
            <?php endif; ?>
        </div>
        <p class="text-muted mt-3 mb-0">
            <?php echo $remaining > 0 ? $remaining . ' subject(s) left to write.' : 'You have finished all your subjects. You may log out.'; ?>
        </p>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="includes/quitExam.js"></script>
