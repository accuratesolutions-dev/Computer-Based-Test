<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/grading.php';

// POST: let a student retake a subject (deletes the result, attempt and saved answers).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token.'); }
    if (isset($_POST['reset'])) {
        $sid = (int) ($_POST['student_id'] ?? 0);
        $sub = (int) ($_POST['subject_id'] ?? 0);
        db_exec("DELETE FROM exam_result WHERE student_id = ? AND subject_id = ?", 'ii', $sid, $sub);
        db_exec("DELETE FROM dosubject WHERE dostd_id = ? AND dosub_id = ?", 'ii', $sid, $sub);
        db_exec("DELETE FROM exam_answer WHERE student_id = ? AND subject_id = ?", 'ii', $sid, $sub);
        db_exec("DELETE FROM exam_question WHERE student_id = ? AND subject_id = ?", 'ii', $sid, $sub); // a retake draws a fresh random set
        flash_set('success', 'Attempt reset. The student can take that subject again.');
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Grade anything that timed out without being submitted, so results are always complete.
$autoGraded = finalize_expired_attempts();

$subjectId = (int) ($_GET['subject'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$like = '%' . addcslashes($q, '%_\\') . '%';

$rows = db_all(
    "SELECT e.student_id, e.subject_id, e.correct, e.total, e.percent, e.submitted_at,
            r.examNumber, r.surname, r.otherNames, r.level, s.sub_name, c.class
       FROM exam_result e
       JOIN register r ON r.id = e.student_id
       JOIN subject s ON s.sub_id = e.subject_id
       JOIN class c ON c.id = e.class_id
      WHERE (? = 0 OR e.subject_id = ?)
        AND (r.examNumber LIKE ? OR r.surname LIKE ? OR r.otherNames LIKE ?)
      ORDER BY e.submitted_at DESC
      LIMIT 5000",
    'iisss', $subjectId, $subjectId, $like, $like, $like
);

if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="results-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
    fputcsv($out, ['Exam Number', 'Surname', 'Other Names', 'Class', 'Level', 'Subject', 'Correct', 'Total', 'Percent', 'Grade', 'Result', 'Submitted']);
    foreach ($rows as $r) {
        fputcsv($out, array_map('csv_cell', [$r['examNumber'], $r['surname'], $r['otherNames'], $r['class'], $r['level'],
            trim($r['sub_name']), $r['correct'], $r['total'], $r['percent'], grade_for($r['percent'])['grade'], grade_for($r['percent'])['pass'] ? 'Pass' : 'Fail', $r['submitted_at']]));
    }
    exit;
}

$count = count($rows);
$avg = $count ? array_sum(array_column($rows, 'percent')) / $count : 0;
$pass = 0;
foreach ($rows as $r) { if ((float) $r['percent'] >= pass_mark()) { $pass++; } }

$inProgress = [];
foreach (db_all("SELECT d.*, r.examNumber, r.surname, r.otherNames, s.sub_name FROM dosubject d
                   JOIN register r ON r.id = d.dostd_id JOIN subject s ON s.sub_id = d.dosub_id WHERE d.istaken = 0") as $a) {
    $left = strtotime($a['time']) + ((int) $a['duration'] * 60) - time();
    if ($left > 0) { $a['left'] = $left; $inProgress[] = $a; }
}

admin_head('Exam Results', 'results');
flash_show();
if ($autoGraded > 0) {
    echo '<div class="alert alert-info">' . (int) $autoGraded . ' unsubmitted attempt(s) had timed out and were graded from their auto-saved answers.</div>';
}
?>
<div class="row" style="margin-bottom:15px;">
    <div class="col-sm-4"><div class="panel panel-default"><div class="panel-body"><div class="stat"><?php echo $count; ?></div>results shown</div></div></div>
    <div class="col-sm-4"><div class="panel panel-default"><div class="panel-body"><div class="stat"><?php echo number_format($avg, 1); ?>%</div>average score</div></div></div>
    <div class="col-sm-4"><div class="panel panel-default"><div class="panel-body"><div class="stat"><?php echo $count ? number_format($pass * 100 / $count, 0) : 0; ?>%</div>passed (pass mark <?php echo e(pass_mark()); ?>%)</div></div></div>
</div>

<form class="form-inline" method="get" style="margin-bottom:15px;">
    <select name="subject" class="form-control">
        <option value="0">All subjects</option>
        <?php foreach (admin_subject_options() as $s): ?>
            <option value="<?php echo (int) $s['sub_id']; ?>"<?php echo $subjectId === (int) $s['sub_id'] ? ' selected' : ''; ?>><?php echo e($s['class'] . ' — ' . trim($s['sub_name'])); ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="q" value="<?php echo e($q); ?>" class="form-control" placeholder="Search name or exam number">
    <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> Filter</button>
    <a class="btn btn-default" href="results.php">Clear</a>
    <button class="btn btn-success pull-right" type="submit" name="export" value="1"><i class="fa fa-download"></i> Export CSV</button>
</form>

<?php if ($inProgress): ?>
<div class="panel panel-warning">
    <div class="panel-heading"><strong><?php echo count($inProgress); ?> exam(s) in progress</strong> (finish when their timer ends)</div>
    <table class="table table-condensed table-tight" style="margin:0;">
        <?php foreach ($inProgress as $a): ?>
            <tr><td><?php echo e($a['examNumber']); ?></td><td><?php echo e($a['surname'] . ' ' . $a['otherNames']); ?></td>
                <td><?php echo e(trim($a['sub_name'])); ?></td><td><?php echo floor($a['left'] / 60); ?> min left</td></tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<div class="panel panel-default">
    <div class="table-responsive">
    <table class="table table-striped table-hover table-tight" style="margin:0;">
        <thead><tr><th>Exam No.</th><th>Name</th><th>Class</th><th>Subject</th><th>Score</th><th>%</th><th>Grade</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted">No results yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo e($r['examNumber']); ?></td>
                <td><?php echo e($r['surname'] . ' ' . $r['otherNames']); ?></td>
                <td><?php echo e($r['class']); ?> <small class="text-muted"><?php echo e($r['level']); ?></small></td>
                <td><?php echo e(trim($r['sub_name'])); ?></td>
                <td><?php echo (int) $r['correct']; ?> / <?php echo (int) $r['total']; ?></td>
                <?php $g = grade_for($r['percent']); ?>
                <td><span class="label label-<?php echo $g['pass'] ? 'success' : 'danger'; ?>"><?php echo e($r['percent']); ?>%</span></td>
                <td><strong><?php echo e($g['grade']); ?></strong> <small class="text-muted"><?php echo e($g['remark']); ?></small></td>
                <td><?php echo e($r['submitted_at']); ?></td>
                <td>
                    <form method="post" onsubmit="return confirm('Delete this result so the student can retake the subject?');">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="student_id" value="<?php echo (int) $r['student_id']; ?>">
                        <input type="hidden" name="subject_id" value="<?php echo (int) $r['subject_id']; ?>">
                        <button class="btn btn-xs btn-default" name="reset" value="1" title="Allow retake"><i class="fa fa-undo"></i> Reset</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php admin_foot();
