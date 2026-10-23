<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin_role();
require_once __DIR__ . '/../includes/grading.php';

$classId = (int) ($_GET['class'] ?? 0);
$level = trim((string) ($_GET['level'] ?? ''));
$studentId = (int) ($_GET['student'] ?? 0);
$mode = $_GET['mode'] ?? 'list'; // list | cards | csv

$students = [];
$subjects = [];
$group = null;
if ($classId) {
    finalize_expired_attempts();
    $group = db_one("SELECT id, class FROM class WHERE id = ?", 'i', $classId);
}
if ($group) {
    $students = db_all(
        "SELECT id, examNumber, surname, otherNames, level, passport FROM register
          WHERE department = ? AND (? = '' OR level = ?) ORDER BY surname, otherNames",
        'sss', (string) $classId, $level, $level
    );
    $byId = [];
    foreach ($students as $i => $s) { $byId[(int) $s['id']] = $i; $students[$i]['scores'] = []; }
    foreach (db_all(
        "SELECT e.student_id, e.subject_id, e.correct, e.total, e.percent, s.sub_name
           FROM exam_result e JOIN subject s ON s.sub_id = e.subject_id
           JOIN register r ON r.id = e.student_id
          WHERE r.department = ? AND (? = '' OR r.level = ?) ORDER BY s.sub_name",
        'sss', (string) $classId, $level, $level
    ) as $r) {
        $i = $byId[(int) $r['student_id']] ?? null;
        if ($i === null) { continue; }
        $students[$i]['scores'][(int) $r['subject_id']] = (float) $r['percent'];
        $subjects[(int) $r['subject_id']] = trim($r['sub_name']);
    }
    asort($subjects);
    foreach ($students as $i => $s) {
        $n = count($s['scores']);
        $students[$i]['n'] = $n;
        $students[$i]['total'] = array_sum($s['scores']);
        $students[$i]['avg'] = $n ? $students[$i]['total'] / $n : null;
    }
    // Position: by average, ties share a position (1,2,2,4). Students with no results are unranked.
    $ranked = array_values(array_filter(array_keys($students), function ($i) use ($students) { return $students[$i]['avg'] !== null; }));
    usort($ranked, function ($a, $b) use ($students) { return $students[$b]['avg'] <=> $students[$a]['avg']; });
    $pos = 0; $prev = null;
    foreach ($ranked as $k => $i) {
        $avg = round($students[$i]['avg'], 2);
        if ($prev === null || $avg !== $prev) { $pos = $k + 1; $prev = $avg; }
        $students[$i]['pos'] = $pos;
    }
    $ranked_count = count($ranked);
    if ($mode === 'list' || $mode === 'csv') {
        usort($students, function ($a, $b) { return ($a['pos'] ?? 99999) <=> ($b['pos'] ?? 99999) ?: strcmp($a['surname'], $b['surname']); });
    }
}

function ordinal($n)
{
    $n = (int) $n;
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

if ($group && $mode === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="class-list-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['Position', 'Exam Number', 'Surname', 'Other Names', 'Level'], array_values($subjects), ['Subjects taken', 'Total', 'Average', 'Grade', 'Result']));
    foreach ($students as $s) {
        $g = $s['avg'] !== null ? grade_for($s['avg']) : null;
        $row = [$s['pos'] ?? '', $s['examNumber'], $s['surname'], $s['otherNames'], $s['level']];
        foreach ($subjects as $sid => $_) { $row[] = isset($s['scores'][$sid]) ? $s['scores'][$sid] : ''; }
        $row[] = $s['n']; $row[] = round($s['total'], 2); $row[] = $s['avg'] !== null ? round($s['avg'], 2) : '';
        $row[] = $g ? $g['grade'] : ''; $row[] = $g ? ($g['pass'] ? 'Pass' : 'Fail') : '';
        fputcsv($out, array_map('csv_cell', $row));
    }
    exit;
}

if ($group && $mode === 'cards') {
    $sel = array_values(array_filter($students, function ($s) use ($studentId) { return $s['n'] > 0 && (!$studentId || (int) $s['id'] === $studentId); }));
    if ($sel) {
        $school = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Akyerit CBT';
        ?><!doctype html><html><head><meta charset="utf-8"><title>Report cards</title>
        <style>
        body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:0;padding:10px}
        .bar{margin-bottom:10px}.bar button{padding:6px 14px;font-size:14px}
        .rc{border:2px solid #222;padding:18px 22px;margin:0 auto 16px;max-width:720px;page-break-after:always;break-after:page}
        .rc:last-child{page-break-after:auto}
        .rc h2{margin:0;text-align:center;font-size:20px}.rc h4{margin:2px 0 12px;text-align:center;font-weight:normal;color:#444}
        .who{display:flex;gap:14px;align-items:center;margin-bottom:12px}.who img{width:80px;height:95px;object-fit:cover;border:1px solid #888}
        table{border-collapse:collapse;width:100%;font-size:14px}th,td{border:1px solid #444;padding:5px 8px;text-align:left}th{background:#eee}
        .sum{margin-top:12px;display:flex;gap:10px}.sum div{flex:1;border:1px solid #444;padding:8px;text-align:center}.sum b{display:block;font-size:20px}
        .sig{margin-top:30px;display:flex;justify-content:space-between;font-size:12px}.sig span{border-top:1px solid #333;padding-top:3px;width:40%;text-align:center}
        @media print{.bar{display:none}body{padding:0}.rc{margin:0 auto;max-width:none}}
        </style></head><body>
        <div class="bar"><button onclick="window.print()">Print (<?php echo count($sel); ?> report card<?php echo count($sel) === 1 ? '' : 's'; ?>)</button> <a href="report_cards.php?class=<?php echo $classId; ?>&amp;level=<?php echo e(rawurlencode($level)); ?>">Back</a></div>
        <?php foreach ($sel as $s): $g = grade_for($s['avg']); ?>
        <div class="rc">
            <h2><?php echo e($school); ?></h2><h4>Computer-Based Test - Report Card</h4>
            <div class="who">
                <?php if ($s['passport'] !== ''): ?><img src="<?php echo e(passport_url($s['passport'])); ?>" alt=""><?php endif; ?>
                <div><strong style="font-size:17px;"><?php echo e($s['surname'] . ' ' . $s['otherNames']); ?></strong><br>
                    Exam No: <?php echo e($s['examNumber']); ?><br>Class: <?php echo e($group['class'] . ' ' . $s['level']); ?></div>
            </div>
            <table><thead><tr><th>Subject</th><th>Score (%)</th><th>Grade</th><th>Remark</th></tr></thead><tbody>
            <?php foreach ($subjects as $sid => $name): if (!isset($s['scores'][$sid])) { continue; } $sg = grade_for($s['scores'][$sid]); ?>
                <tr><td><?php echo e($name); ?></td><td><?php echo e(rtrim(rtrim(number_format($s['scores'][$sid], 2), '0'), '.')); ?></td><td><strong><?php echo e($sg['grade']); ?></strong></td><td><?php echo e($sg['remark']); ?></td></tr>
            <?php endforeach; ?></tbody></table>
            <div class="sum">
                <div><b><?php echo e(rtrim(rtrim(number_format($s['total'], 2), '0'), '.')); ?></b>Total (<?php echo (int) $s['n']; ?> subjects)</div>
                <div><b><?php echo e(number_format($s['avg'], 1)); ?>%</b>Average</div>
                <div><b><?php echo e($g['grade']); ?></b><?php echo e($g['remark']); ?></div>
                <div><b><?php echo ordinal($s['pos']); ?></b>Position (of <?php echo (int) $ranked_count; ?>)</div>
            </div>
            <p style="margin:10px 0 0;"><strong>Result:</strong> <?php echo $g['pass'] ? 'PASS' : 'FAIL'; ?> (pass mark <?php echo e(pass_mark()); ?>%) &nbsp; Printed <?php echo date('j M Y'); ?></p>
            <div class="sig"><span>Class teacher</span><span>Principal</span></div>
        </div>
        <?php endforeach; ?></body></html><?php
        exit;
    }
}

admin_head('Report Cards', 'reports');
if ($group && $mode === 'cards') { echo '<div class="alert alert-warning">No results to print for that selection.</div>'; }
?>
<form class="form-inline" method="get" style="margin-bottom:15px;">
    <select name="class" class="form-control" required><option value="">Choose a class</option><?php echo class_options_html($classId); ?></select>
    <input type="text" name="level" class="form-control" placeholder="Level (optional, e.g. S.S.S 1)" value="<?php echo e($level); ?>">
    <button class="btn btn-primary" type="submit"><i class="fa fa-list"></i> Show class list</button>
    <?php if ($group): ?>
        <a class="btn btn-default" target="_blank" href="?class=<?php echo $classId; ?>&amp;level=<?php echo e(rawurlencode($level)); ?>&amp;mode=cards"><i class="fa fa-print"></i> Print all report cards</a>
        <a class="btn btn-success" href="?class=<?php echo $classId; ?>&amp;level=<?php echo e(rawurlencode($level)); ?>&amp;mode=csv"><i class="fa fa-download"></i> Export (Excel/CSV)</a>
    <?php endif; ?>
</form>
<?php if (!$group): ?>
    <p class="text-muted">Pick a class (and optionally a level) to see every student's scores, total, average, position and grade. Position is by average score; students on the same average share a position. Grades and the pass mark come from <a href="settings.php">Settings</a>.</p>
<?php else: ?>
    <p><strong><?php echo e($group['class']); ?><?php echo $level !== '' ? ' ' . e($level) : ''; ?></strong>: <?php echo count($students); ?> student(s), <?php echo (int) $ranked_count; ?> with results.</p>
    <div class="panel panel-default"><div class="table-responsive">
    <table class="table table-striped table-tight" style="margin:0;">
        <thead><tr><th>Pos.</th><th>Exam No.</th><th>Name</th><?php foreach ($subjects as $name): ?><th><?php echo e($name); ?></th><?php endforeach; ?><th>Total</th><th>Avg %</th><th>Grade</th><th></th></tr></thead>
        <tbody>
        <?php if (!$students): ?><tr><td colspan="<?php echo 7 + count($subjects); ?>" class="text-center text-muted">No students in this class.</td></tr><?php endif; ?>
        <?php foreach ($students as $s): $g = $s['avg'] !== null ? grade_for($s['avg']) : null; ?>
            <tr>
                <td><?php echo isset($s['pos']) ? (int) $s['pos'] : '-'; ?></td>
                <td><?php echo e($s['examNumber']); ?></td>
                <td><?php echo e($s['surname'] . ' ' . $s['otherNames']); ?><?php echo $level === '' ? ' <small class="text-muted">' . e($s['level']) . '</small>' : ''; ?></td>
                <?php foreach ($subjects as $sid => $_): ?><td><?php echo isset($s['scores'][$sid]) ? e(rtrim(rtrim(number_format($s['scores'][$sid], 2), '0'), '.')) : '<span class="text-muted">-</span>'; ?></td><?php endforeach; ?>
                <td><?php echo $s['n'] ? e(rtrim(rtrim(number_format($s['total'], 2), '0'), '.')) : '-'; ?></td>
                <td><?php echo $g ? e(number_format($s['avg'], 1)) : '-'; ?></td>
                <td><?php echo $g ? '<span class="label label-' . ($g['pass'] ? 'success' : 'danger') . '">' . e($g['grade']) . '</span>' : '-'; ?></td>
                <td><?php if ($s['n']): ?><a class="btn btn-xs btn-default" target="_blank" href="?class=<?php echo $classId; ?>&amp;level=<?php echo e(rawurlencode($level)); ?>&amp;mode=cards&amp;student=<?php echo (int) $s['id']; ?>"><i class="fa fa-print"></i></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
<?php endif; ?>
<?php admin_foot();
