<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin_role();
require_once __DIR__ . '/../includes/grading.php';
require_once __DIR__ . '/../includes/settings.php';

/* ---------- report card details (school name, term, comments...) ---------- */
$RC_KEYS = ['rc_school', 'rc_address', 'rc_motto', 'rc_session', 'rc_term', 'rc_next_term', 'rc_teacher', 'rc_principal'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token. Reload the page and try again.'); }
    $back = 'admin/report_cards.php';
    if (isset($_POST['saveDetails'])) {
        foreach ($RC_KEYS as $k) {
            setting_set($k, substr(trim(strip_tags((string) ($_POST[$k] ?? ''))), 0, 150));
        }
        setting_set('rc_blank_comments', ($_POST['rc_blank_comments'] ?? '') === '1' ? '1' : '0');
        if (!empty($_POST['rc_remove_logo'])) {
            $old = (string) setting('rc_logo', '');
            if ($old !== '' && is_file(__DIR__ . '/../' . $old)) { @unlink(__DIR__ . '/../' . $old); }
            setting_set('rc_logo', '');
        }
        if (!empty($_FILES['rc_logo']['tmp_name']) && is_uploaded_file($_FILES['rc_logo']['tmp_name'])) {
            $info = @getimagesize($_FILES['rc_logo']['tmp_name']);
            $ext = $info ? ([IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'][$info[2]] ?? '') : '';
            if ($ext === '' || $_FILES['rc_logo']['size'] > 1048576) {
                flash_set('danger', 'The logo must be a PNG or JPG picture of 1 MB or less. Other details were saved.');
            } else {
                $dir = __DIR__ . '/../uploads';
                $old = (string) setting('rc_logo', '');
                if ($old !== '' && is_file(__DIR__ . '/../' . $old)) { @unlink(__DIR__ . '/../' . $old); }
                $name = 'school-logo-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
                if (move_uploaded_file($_FILES['rc_logo']['tmp_name'], $dir . '/' . $name)) {
                    setting_set('rc_logo', 'uploads/' . $name);
                    flash_set('success', 'Report card details and logo saved.');
                } else {
                    flash_set('danger', 'Could not save the logo (is the uploads folder writable?). Other details were saved.');
                }
            }
        } else {
            flash_set('success', 'Report card details saved.');
        }
    }
    redirect($back);
}

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
    // Per-subject class statistics (over the same class/level selection).
    $subjStats = [];
    foreach ($subjects as $sid => $_) {
        $vals = [];
        foreach ($students as $s) { if (isset($s['scores'][$sid])) { $vals[] = $s['scores'][$sid]; } }
        if ($vals) { $subjStats[$sid] = ['avg' => array_sum($vals) / count($vals), 'high' => max($vals), 'low' => min($vals), 'n' => count($vals), 'vals' => $vals]; }
    }
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

function rc_num($v, $d = 1)
{
    return rtrim(rtrim(number_format((float) $v, $d), '0'), '.');
}

// Automatic comments by average score band; [class teacher, principal].
function rc_comment($avg)
{
    if ($avg >= 70) { return ['An excellent performance. Keep up the outstanding work and keep setting the standard for others.', 'Excellent result. The school is proud of you. Keep it up.']; }
    if ($avg >= 60) { return ['A very good performance. A little more effort in the weaker subjects will lift you higher.', 'Very good result. Aim higher next time.']; }
    if ($avg >= 50) { return ['A good performance. You can do better with more practice and regular revision.', 'Good result. More effort will bring improvement.']; }
    if ($avg >= 40) { return ['A fair performance. Please put in more study time, especially in the weaker subjects.', 'Fair result. You are capable of much more; work harder.']; }
    return ['A weak performance. Please seek extra help, revise regularly and attend every lesson.', 'Below expectation. Serious improvement is needed next term.'];
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
        $school = trim((string) setting('rc_school', ''));
        if ($school === '') { $school = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Akyerit CBT'; }
        $address = (string) setting('rc_address', '');
        $motto = (string) setting('rc_motto', '');
        $session = (string) setting('rc_session', '');
        $term = (string) setting('rc_term', '');
        $nextTerm = (string) setting('rc_next_term', '');
        $teacherName = (string) setting('rc_teacher', '');
        $principalName = (string) setting('rc_principal', '');
        $blank = setting('rc_blank_comments', '0') === '1';
        $logo = (string) setting('rc_logo', '');
        $logoOk = $logo !== '' && is_file(__DIR__ . '/../' . $logo);
        $scale = grade_scale();
        $cohortAvg = $ranked_count ? array_sum(array_map(function ($i) use ($students) { return $students[$i]['avg']; }, $ranked)) / $ranked_count : 0;
                ?><!doctype html><html><head><meta charset="utf-8"><title>Report cards</title>
        <style>
        @page{size:A4 portrait;margin:0}
        *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:0;background:#d9dde3}
        .bar{position:sticky;top:0;z-index:5;background:#fff;padding:8px 14px;border-bottom:1px solid #bbb}
        .bar button{padding:6px 14px;font-size:14px}
        .page{width:210mm;height:297mm;margin:12px auto;background:#fff;padding:9mm 10mm;box-shadow:0 2px 10px rgba(0,0,0,.25);page-break-after:always;break-after:page;overflow:hidden}
        .page:last-child{page-break-after:auto;break-after:auto}
        .rc{height:100%;border:2.2px solid #16365c;padding:4mm 5mm;display:flex;flex-direction:column;font-size:11.5px;position:relative}
        .rc:before{content:"";position:absolute;inset:1.4mm;border:.7px solid #16365c;pointer-events:none}
        .hdr{display:flex;align-items:center;gap:12px;border-bottom:3px double #16365c;padding-bottom:6px;position:relative}
        .hdr .lg{width:20mm;height:20mm;display:flex;align-items:center;justify-content:center}
        .hdr .lg img{max-width:100%;max-height:100%}
        .hdr .t{flex:1;text-align:center}
        .hdr h1{margin:0;font-size:21px;letter-spacing:.6px;text-transform:uppercase;color:#16365c}
        .hdr .ad{font-size:10.5px;color:#444;margin-top:2px}.hdr .mo{font-size:10.5px;font-style:italic;color:#444}
        .title{margin:7px 0 6px;text-align:center;background:#16365c;color:#fff;padding:4px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase;font-size:12.5px}
        .bio{display:flex;gap:10px;margin-bottom:7px}
        .bio .ph{width:27mm;height:33mm;border:1px solid #666;display:flex;align-items:center;justify-content:center;color:#888;font-size:9px;text-align:center;overflow:hidden;flex:none}
        .bio .ph img{width:100%;height:100%;object-fit:cover}
        .bio table{flex:1;border-collapse:collapse}
        .bio td{padding:2.5px 6px;border:1px solid #9aa7b8;font-size:11.5px}
        .bio td.k{background:#eaf0f7;font-weight:bold;width:21%;color:#16365c}
        .bio td.nm{font-size:15px;font-weight:bold}
        table.sc{border-collapse:collapse;width:100%}
        table.sc th{background:#16365c;color:#fff;font-size:10px;padding:4px 4px;border:1px solid #16365c;text-align:center}
        table.sc th.l,table.sc td.l{text-align:left}
        table.sc td{border:1px solid #9aa7b8;padding:5px 5px;text-align:center;font-size:11.5px}
        table.sc.rm td{padding:9px 6px;font-size:12.5px}table.sc.md td{padding:7px 5px;font-size:12px}
        table.sc tbody tr:nth-child(even){background:#f4f7fb}
        table.sc td.sub{font-weight:bold;text-align:left}
        .gr{font-weight:bold;font-size:12.5px}.fail{color:#b00020}
        .bw{position:relative;height:9px;background:#e4e8ee;border-radius:2px;min-width:90px}
        .bw i{position:absolute;left:0;top:0;bottom:0;background:#2f6db5;border-radius:2px}
        .bw i.f{background:#c0392b}.bw b{position:absolute;top:-2px;bottom:-2px;width:2px;background:#111}
        table.sc.dn td{padding:3px 4px;font-size:10.5px}table.sc.xd td{padding:.5px 3px;font-size:9.5px}
        table.sc.dn .bw,table.sc.xd .bw{height:7px}
        .flex1{flex:1;min-height:2mm}
        .legend{font-size:9px;color:#555;margin:3px 0 0}.legend b.m{display:inline-block;width:2px;height:8px;background:#111;vertical-align:middle}
        .sum{display:grid;grid-template-columns:repeat(6,1fr);gap:6px;margin:8px 0}
        .sum div{border:1px solid #16365c;text-align:center;padding:4px 2px;background:#f4f7fb}
        .sum b{display:block;font-size:17px;color:#16365c;line-height:1.25}.sum span{font-size:9.5px;color:#444;text-transform:uppercase}
        .res{padding:4px 8px;font-weight:bold;text-align:center;letter-spacing:1px;margin-bottom:6px;border:1.5px solid}
        .res.p{color:#176b2c;border-color:#176b2c;background:#e9f6ec}.res.x{color:#b00020;border-color:#b00020;background:#fbebee}
        .ins{font-size:10.5px;margin-bottom:6px}.ins span{margin-right:14px}
        .cm{display:flex;gap:10px;margin-top:2px}.cm>div{flex:1;border:1px solid #9aa7b8;padding:4px 6px;min-height:21mm}
        .cm h5{margin:0 0 2px;font-size:10px;text-transform:uppercase;color:#16365c}.cm p{margin:0;font-size:10.8px;line-height:1.35}
        .cm .ln{border-bottom:1px dotted #555;height:14px}
        .sig{display:flex;justify-content:space-between;gap:20px;margin-top:9mm;font-size:10px}
        .sig span{flex:1;border-top:1px solid #333;padding-top:2px;text-align:center}
        .ft{display:flex;justify-content:space-between;gap:10px;align-items:flex-end;margin-top:7px;border-top:1px solid #9aa7b8;padding-top:4px;font-size:9px;color:#444}
        .ft .key b{color:#111}
        @media print{body{background:#fff}.bar{display:none}.page{margin:0;box-shadow:none}}
        </style></head><body>
        <div class="bar"><button onclick="window.print()">Print (<?php echo count($sel); ?> report card<?php echo count($sel) === 1 ? '' : 's'; ?> - one A4 page each)</button> <a href="report_cards.php?class=<?php echo $classId; ?>&amp;level=<?php echo e(rawurlencode($level)); ?>">Back</a>
            <span style="color:#666;font-size:12px;margin-left:10px;">Tip: in the print window choose paper A4, margins None, and turn on Background graphics.</span></div>
        <?php foreach ($sel as $s):
            $g = grade_for($s['avg']);
            $rows = [];
            foreach ($subjects as $sid => $name) {
                if (!isset($s['scores'][$sid])) { continue; }
                $sc = $s['scores'][$sid];
                $st = $subjStats[$sid];
                $sp = 1; foreach ($st['vals'] as $v) { if ($v > $sc) { $sp++; } }
                $rows[] = ['name' => $name, 'sc' => $sc, 'g' => grade_for($sc), 'st' => $st, 'pos' => $sp];
            }
            $nr = count($rows);
            $dense = $nr > 24 ? 'xd' : ($nr > 19 ? 'dn' : ($nr > 13 ? '' : ($nr > 8 ? 'md' : 'rm')));
            $passed = 0; foreach ($rows as $r) { if ($r['g']['pass']) { $passed++; } }
            $best = $worst = null;
            foreach ($rows as $r) {
                if ($best === null || $r['sc'] > $best['sc']) { $best = $r; }
                if ($worst === null || $r['sc'] < $worst['sc']) { $worst = $r; }
            }
            $cm = rc_comment($s['avg']);
        ?>
        <div class="page"><div class="rc">
            <div class="hdr">
                <div class="lg"><?php if ($logoOk): ?><img src="<?php echo e(url($logo)); ?>" alt=""><?php endif; ?></div>
                <div class="t"><h1><?php echo e($school); ?></h1>
                    <?php if ($address !== ''): ?><div class="ad"><?php echo e($address); ?></div><?php endif; ?>
                    <?php if ($motto !== ''): ?><div class="mo">"<?php echo e($motto); ?>"</div><?php endif; ?></div>
                <div class="lg"></div>
            </div>
            <div class="title">Student Report Card<?php echo $term !== '' ? ' &ndash; ' . e($term) : ''; ?><?php echo $session !== '' ? ' &ndash; ' . e($session) . ' Session' : ''; ?></div>
            <div class="bio">
                <div class="ph"><?php if ($s['passport'] !== ''): ?><img src="<?php echo e(passport_url($s['passport'])); ?>" alt=""><?php else: ?>Passport<br>photo<?php endif; ?></div>
                <table>
                    <tr><td class="k">Name</td><td class="nm" colspan="3"><?php echo e(strtoupper($s['surname']) . ' ' . $s['otherNames']); ?></td></tr>
                    <tr><td class="k">Exam number</td><td><?php echo e($s['examNumber']); ?></td><td class="k">Class</td><td><?php echo e(trim($group['class'] . ' ' . $s['level'])); ?></td></tr>
                    <tr><td class="k">Subjects taken</td><td><?php echo (int) $s['n']; ?></td><td class="k">Students in class</td><td><?php echo (int) $ranked_count; ?> with results</td></tr>
                    <tr><td class="k">Term</td><td><?php echo $term !== '' ? e($term) : '&nbsp;'; ?></td><td class="k">Session</td><td><?php echo $session !== '' ? e($session) : '&nbsp;'; ?></td></tr>
                </table>
            </div>
            <table class="sc <?php echo $dense; ?>">
                <thead><tr><th class="l" style="width:27%">Subject</th><th>Score %</th><th>Grade</th><th>Class avg</th><th>Highest</th><th>Lowest</th><th>Pos.</th><th style="width:21%">Performance</th><th class="l">Remark</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $ok = $r['g']['pass']; ?>
                    <tr>
                        <td class="sub"><?php echo e($r['name']); ?></td>
                        <td><strong class="<?php echo $ok ? '' : 'fail'; ?>"><?php echo e(rc_num($r['sc'], 1)); ?></strong></td>
                        <td class="gr <?php echo $ok ? '' : 'fail'; ?>"><?php echo e($r['g']['grade']); ?></td>
                        <td><?php echo e(rc_num($r['st']['avg'], 1)); ?></td>
                        <td><?php echo e(rc_num($r['st']['high'], 1)); ?></td>
                        <td><?php echo e(rc_num($r['st']['low'], 1)); ?></td>
                        <td><?php echo e(ordinal($r['pos'])); ?></td>
                        <td><div class="bw"><i class="<?php echo $ok ? '' : 'f'; ?>" style="width:<?php echo (float) max(0, min(100, $r['sc'])); ?>%"></i><b style="left:<?php echo (float) max(0, min(99, $r['st']['avg'])); ?>%"></b></div></td>
                        <td class="l"><?php echo e($r['g']['remark']); ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
            <div class="legend">Bar = student's score; <b class="m"></b> = class average for that subject.</div>
            <div class="flex1"></div>
            <div class="sum">
                <div><b><?php echo e(rc_num($s['total'], 1)); ?></b><span>Total score</span></div>
                <div><b><?php echo e(number_format($s['avg'], 1)); ?>%</b><span>Average</span></div>
                <div><b><?php echo e($g['grade']); ?></b><span><?php echo e($g['remark']); ?></span></div>
                <div><b><?php echo e(ordinal($s['pos'])); ?></b><span>Position of <?php echo (int) $ranked_count; ?></span></div>
                <div><b><?php echo e(number_format($cohortAvg, 1)); ?>%</b><span>Class average</span></div>
                <div><b><?php echo (int) $passed; ?>/<?php echo (int) $nr; ?></b><span>Subjects passed</span></div>
            </div>
            <div class="res <?php echo $g['pass'] ? 'p' : 'x'; ?>">OVERALL RESULT: <?php echo $g['pass'] ? 'PASS' : 'FAIL'; ?> <span style="font-weight:normal;letter-spacing:0;">(pass mark <?php echo e(rc_num(pass_mark(), 1)); ?>%)</span></div>
            <?php if ($nr >= 2): ?><div class="ins"><span><strong>Strongest subject:</strong> <?php echo e($best['name']); ?> (<?php echo e(rc_num($best['sc'], 1)); ?>%)</span><span><strong>Needs most attention:</strong> <?php echo e($worst['name']); ?> (<?php echo e(rc_num($worst['sc'], 1)); ?>%)</span></div><?php endif; ?>
            <div class="cm">
                <div><h5>Class teacher's comment</h5><?php if ($blank): ?><div class="ln"></div><div class="ln"></div><div class="ln"></div><?php else: ?><p><?php echo e($cm[0]); ?></p><?php endif; ?></div>
                <div><h5>Principal's comment</h5><?php if ($blank): ?><div class="ln"></div><div class="ln"></div><div class="ln"></div><?php else: ?><p><?php echo e($cm[1]); ?></p><?php endif; ?></div>
            </div>
            <div class="sig">
                <span>Class teacher<?php echo $teacherName !== '' ? ': ' . e($teacherName) : ''; ?></span>
                <span>Principal<?php echo $principalName !== '' ? ': ' . e($principalName) : ''; ?></span>
                <span>Parent / Guardian</span>
            </div>
            <div class="ft">
                <div class="key"><b>Grading:</b>
                    <?php $bands = []; foreach ($scale as $b) { $bands[] = e($b[1]) . ' ' . rc_num($b[0], 0) . '+ (' . e($b[2]) . ')'; } echo implode(' &middot; ', $bands); ?></div>
                <div style="text-align:right;white-space:nowrap;">Issued <?php echo date('j M Y'); ?><?php echo $nextTerm !== '' ? '<br><b>Next term begins:</b> ' . e($nextTerm) : ''; ?></div>
            </div>
        </div></div>
        <?php endforeach; ?></body></html><?php
        exit;
    }
}

admin_head('Report Cards', 'reports');
flash_show();
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

<div class="panel panel-default">
    <div class="panel-heading"><a data-toggle="collapse" href="#rcDetails"><i class="fa fa-cog"></i> Report card details (school name, term, session, logo, comments)</a></div>
    <div id="rcDetails" class="panel-collapse collapse<?php echo trim((string) setting('rc_school', '')) === '' ? ' in' : ''; ?>"><div class="panel-body">
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <div class="row">
            <div class="col-sm-6 form-group"><label>School name</label><input class="form-control" name="rc_school" maxlength="150" value="<?php echo e(setting('rc_school', '')); ?>" placeholder="e.g. Greenfield College"></div>
            <div class="col-sm-6 form-group"><label>Address / contact line</label><input class="form-control" name="rc_address" maxlength="150" value="<?php echo e(setting('rc_address', '')); ?>" placeholder="e.g. 12 School Road, Lagos | 080 0000 0000"></div>
            <div class="col-sm-4 form-group"><label>Motto (optional)</label><input class="form-control" name="rc_motto" maxlength="150" value="<?php echo e(setting('rc_motto', '')); ?>"></div>
            <div class="col-sm-2 form-group"><label>Session</label><input class="form-control" name="rc_session" maxlength="150" value="<?php echo e(setting('rc_session', '')); ?>" placeholder="2026/2027"></div>
            <div class="col-sm-3 form-group"><label>Term / exam name</label><input class="form-control" name="rc_term" maxlength="150" value="<?php echo e(setting('rc_term', '')); ?>" placeholder="First Term Examination"></div>
            <div class="col-sm-3 form-group"><label>Next term begins</label><input class="form-control" name="rc_next_term" maxlength="150" value="<?php echo e(setting('rc_next_term', '')); ?>" placeholder="12 January 2027"></div>
            <div class="col-sm-4 form-group"><label>Class teacher's name (optional)</label><input class="form-control" name="rc_teacher" maxlength="150" value="<?php echo e(setting('rc_teacher', '')); ?>"></div>
            <div class="col-sm-4 form-group"><label>Principal's name (optional)</label><input class="form-control" name="rc_principal" maxlength="150" value="<?php echo e(setting('rc_principal', '')); ?>"></div>
            <div class="col-sm-4 form-group"><label>School logo (PNG/JPG, max 1 MB)</label><input type="file" name="rc_logo" accept="image/png,image/jpeg">
                <?php $lg = (string) setting('rc_logo', ''); if ($lg !== '' && is_file(__DIR__ . '/../' . $lg)): ?><img src="<?php echo e('../' . $lg); ?>" alt="" style="height:34px;margin-top:4px;"> <label style="font-weight:normal;"><input type="checkbox" name="rc_remove_logo" value="1"> remove</label><?php endif; ?></div>
        </div>
        <div class="checkbox"><label><input type="checkbox" name="rc_blank_comments" value="1"<?php echo setting('rc_blank_comments', '0') === '1' ? ' checked' : ''; ?>> Leave the comment boxes blank (teachers write comments by hand). Otherwise a comment is written automatically from the student's average.</label></div>
        <button class="btn btn-primary" type="submit" name="saveDetails" value="1"><i class="fa fa-save"></i> Save details</button>
    </form></div></div>
</div>
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
