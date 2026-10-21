<?php
require_once __DIR__ . '/../includes/admin_layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token.'); }
    if (isset($_POST['delete'])) {
        $sid = (int) ($_POST['id'] ?? 0);
        $st = db_one("SELECT passport FROM register WHERE id = ?", 'i', $sid);
        if ($st) {
            foreach (['exam_result' => 'student_id', 'dosubject' => 'dostd_id', 'exam_answer' => 'student_id', 'exam_question' => 'student_id', 'register' => 'id'] as $table => $col) {
                db_exec("DELETE FROM `$table` WHERE `$col` = ?", 'i', $sid);
            }
            $dir = realpath(__DIR__ . '/../onlineForm/images/Student_Images');
            $file = $dir ? realpath($dir . '/' . basename((string) $st['passport'])) : false;
            if ($file && is_file($file) && strpos($file, $dir) === 0) { @unlink($file); }
            flash_set('success', 'Student and all their results were deleted.');
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$like = '%' . addcslashes($q, '%_\\') . '%';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;
$total = (int) db_one("SELECT COUNT(*) AS c FROM register WHERE examNumber LIKE ? OR surname LIKE ? OR otherNames LIKE ? OR phoneNumber LIKE ?", 'ssss', $like, $like, $like, $like)['c'];
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$rows = db_all(
    "SELECT r.id, r.examNumber, r.surname, r.otherNames, r.phoneNumber, r.DateOfBirth, r.level, r.passport, r.regDate, c.class,
            (SELECT COUNT(*) FROM exam_result e WHERE e.student_id = r.id) AS done
       FROM register r LEFT JOIN class c ON c.id = r.department
      WHERE r.examNumber LIKE ? OR r.surname LIKE ? OR r.otherNames LIKE ? OR r.phoneNumber LIKE ?
      ORDER BY r.id DESC LIMIT ? OFFSET ?",
    'ssssii', $like, $like, $like, $like, $per, ($page - 1) * $per
);

if (isset($_GET['export'])) {
    $all = db_all(
        "SELECT r.examNumber, r.surname, r.otherNames, r.gender, r.phoneNumber, r.DateOfBirth, c.class, r.level,
                r.subject1, r.subject2, r.subject3, r.subject4, r.subject5, r.subject6, r.subject7, r.subject8, r.subject9
           FROM register r LEFT JOIN class c ON c.id = r.department
          WHERE r.examNumber LIKE ? OR r.surname LIKE ? OR r.otherNames LIKE ? OR r.phoneNumber LIKE ? ORDER BY r.id",
        'ssss', $like, $like, $like, $like
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Exam Number', 'Surname', 'Other Names', 'Gender', 'Phone', 'Date of Birth', 'Class', 'Level', 'Subject 1', 'Subject 2', 'Subject 3', 'Subject 4', 'Subject 5', 'Subject 6', 'Subject 7', 'Subject 8', 'Subject 9']);
    foreach ($all as $r) { fputcsv($out, array_map('csv_cell', array_values($r))); }
    exit;
}

admin_head('Registered Students', 'students');
flash_show();
?>
<form class="form-inline" method="get" style="margin-bottom:15px;">
    <input type="text" name="q" value="<?php echo e($q); ?>" class="form-control" placeholder="Search name, exam number or phone">
    <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> Search</button>
    <a class="btn btn-default" href="students.php">Clear</a>
    <span class="text-muted"><?php echo $total; ?> student(s)</span>
    <a class="btn btn-default pull-right" style="margin-left:8px;" href="exam_cards.php"><i class="fa fa-print"></i> Exam cards</a>
    <a class="btn btn-success pull-right" href="student_import.php"><i class="fa fa-upload"></i> Bulk register (CSV)</a>
    <button class="btn btn-default pull-right" type="submit" name="export" value="1" style="margin-right:8px;"><i class="fa fa-download"></i> Export CSV</button>
</form>
<div class="panel panel-default"><div class="table-responsive">
<table class="table table-striped table-hover table-tight" style="margin:0;">
    <thead><tr><th>Photo</th><th>Exam No.</th><th>Name</th><th>Class</th><th>Phone</th><th>Date of birth</th><th>Exams done</th><th>Registered</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted">No students found.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?php if ($r['passport'] !== ''): ?><img src="<?php echo e(passport_url($r['passport'])); ?>" alt="" style="height:40px;width:40px;object-fit:cover;border-radius:4px;"><?php endif; ?></td>
            <td><?php echo e($r['examNumber']); ?></td>
            <td><?php echo e($r['surname'] . ' ' . $r['otherNames']); ?></td>
            <td><?php echo e($r['class']); ?> <small class="text-muted"><?php echo e($r['level']); ?></small></td>
            <td><?php echo e($r['phoneNumber']); ?></td>
            <td><?php echo e($r['DateOfBirth']); ?></td>
            <td><?php echo (int) $r['done']; ?></td>
            <td><?php echo e($r['regDate']); ?></td>
            <td>
                <form method="post" onsubmit="return confirm('Delete this student and ALL their results? This cannot be undone.');">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                    <button class="btn btn-xs btn-danger" name="delete" value="1"><i class="fa fa-trash"></i></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?php if ($pages > 1): $qs = $q !== '' ? '&q=' . urlencode($q) : ''; ?>
<ul class="pager">
    <li class="previous<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a href="?page=<?php echo max(1, $page - 1) . $qs; ?>">&larr; Newer</a></li>
    <li>Page <?php echo $page; ?> of <?php echo $pages; ?></li>
    <li class="next<?php echo $page >= $pages ? ' disabled' : ''; ?>"><a href="?page=<?php echo min($pages, $page + 1) . $qs; ?>">Older &rarr;</a></li>
</ul>
<?php endif; ?>
<?php admin_foot();
