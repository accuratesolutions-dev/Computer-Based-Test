<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin_role();
require_once __DIR__ . '/../includes/student_import.php';

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student-import-template-' . ($_GET['template'] === 'junior' ? 'junior' : 'senior') . '.csv"');
    echo "\xEF\xBB\xBF" . student_template_csv($_GET['template'] === 'junior');
    exit;
}

// Download the exam numbers just created (they cannot be recovered from the student any other way).
if (isset($_GET['download'])) {
    $list = $_SESSION['import_result'] ?? [];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student-exam-numbers-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Exam Number', 'Surname', 'Other Names', 'Date of Birth (login password)', 'Class', 'Level']);
    foreach ($list as $r) { fputcsv($out, array_map('csv_cell', $r)); }
    exit;
}

$errors = [];
$created = [];
$skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token. Reload the page and try again.'); }

    $classId = (int) ($_POST['classGroup'] ?? 0);
    $class = db_one("SELECT id, class, sortname FROM class WHERE id = ?", 'i', $classId);
    $file = $_FILES['csv'] ?? null;

    if (!$class) {
        $errors[] = 'Please choose a class.';
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a CSV file to upload.';
    } elseif ($file['size'] > 2 * 1024 * 1024) {
        $errors[] = 'The file is larger than 2 MB.';
    } elseif (!preg_match('/\.(csv|txt)$/i', (string) $file['name'])) {
        $errors[] = 'Please upload a .csv file (in Excel: Save As, then "CSV UTF-8").';
    } else {
        $subjectMap = [];
        $byId = [];
        foreach (db_all("SELECT sub_id, sub_name FROM subject WHERE class_id = ?", 'i', $classId) as $s) {
            $name = trim($s['sub_name']);
            $subjectMap[strtolower($name)] = $name;
            $byId[(int) $s['sub_id']] = $name;
        }
        $defaults = [];
        foreach ((array) ($_POST['defaultSubjects'] ?? []) as $sid) {
            if (isset($byId[(int) $sid])) { $defaults[$byId[(int) $sid]] = $byId[(int) $sid]; }
        }
        $defaults = array_values($defaults);
        if (count($defaults) > 9) {
            $errors[] = 'Tick at most 9 default subjects.';
        } else {
            [$rows, $parseErrors] = parse_student_csv($file['tmp_name'], $subjectMap, $defaults, class_is_junior($class));
            if ($parseErrors) {
                $errors = array_slice($parseErrors, 0, 50);
                if (count($parseErrors) > 50) { $errors[] = '...and ' . (count($parseErrors) - 50) . ' more problems.'; }
                $errors[] = 'Nothing was imported. Fix the rows above and upload the file again.';
            } else {
                global $con;
                $sinceId = (int) db_one("SELECT COALESCE(MAX(id), 0) AS m FROM register")['m'];
                mysqli_begin_transaction($con);
                try {
                    foreach ($rows as $r) {
                        // Skip people already registered in this class (so re-uploading a file is harmless).
                        if (db_one("SELECT id FROM register WHERE department = ? AND surname = ? AND otherNames = ? AND DateOfBirth = ?",
                                   'isss', $classId, $r['surname'], $r['other'], $r['dob'])) {
                            $skipped++;
                            continue;
                        }
                        $subs = array_pad($r['subjects'], 9, '');
                        $examNumber = null;
                        for ($try = 0; $try < 8 && $examNumber === null; $try++) {
                            $candidate = random_int(10000000, 99999999) . 'TECH';
                            try {
                                db_exec(
                                    "INSERT INTO register (surname, otherNames, gender, phoneNumber, DateOfBirth, department, level,
                                        subject1, subject2, subject3, subject4, subject5, subject6, subject7, subject8, subject9, examNumber)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                    'sssssssssssssssss',
                                    $r['surname'], $r['other'], $r['gender'], $r['phone'], $r['dob'], (string) $classId, $r['level'],
                                    ...array_merge($subs, [$candidate])
                                );
                                $examNumber = $candidate;
                            } catch (mysqli_sql_exception $ex) {
                                if ((int) $ex->getCode() !== 1062) { throw $ex; }
                            }
                        }
                        if ($examNumber === null) { throw new RuntimeException('Could not generate a unique exam number.'); }
                        $created[] = [$examNumber, $r['surname'], $r['other'], $r['dob'], $class['class'], $r['level']];
                    }
                    mysqli_commit($con);
                    $_SESSION['import_result'] = $created;
                    $_SESSION['import_since'] = ['since' => $sinceId, 'class' => $classId];
                } catch (Throwable $t) {
                    mysqli_rollback($con);
                    throw $t;
                }
            }
        }
    }
}

admin_head('Bulk Register Students', 'register');
if ($created || ($skipped && !$errors)) {
    echo '<div class="alert alert-success"><strong>' . count($created) . ' student(s) registered.</strong>'
       . ($skipped ? ' ' . (int) $skipped . ' already registered (skipped).' : '') . '</div>';
}
if ($errors) {
    echo '<div class="alert alert-danger"><ul style="margin:0;padding-left:18px;">';
    foreach ($errors as $m) { echo '<li>' . e($m) . '</li>'; }
    echo '</ul></div>';
}
if ($created): ?>
<div class="panel panel-success">
    <div class="panel-heading"><strong>Exam numbers</strong> &mdash; give each student their exam number. They log in with it plus their date of birth.
        <a class="btn btn-success btn-xs pull-right" href="?download=1"><i class="fa fa-download"></i> Download CSV</a>
        <?php if (!empty($_SESSION['import_since'])): ?><a class="btn btn-default btn-xs pull-right" style="margin-right:6px;" target="_blank" href="exam_cards.php?show=1&amp;since=<?php echo (int) $_SESSION['import_since']['since']; ?>&amp;class=<?php echo (int) $_SESSION['import_since']['class']; ?>"><i class="fa fa-print"></i> Print exam cards</a><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-condensed table-tight" style="margin:0;">
        <thead><tr><th>Exam No.</th><th>Name</th><th>Date of birth</th><th>Class</th></tr></thead>
        <tbody>
        <?php foreach ($created as $c): ?>
            <tr><td><strong><?php echo e($c[0]); ?></strong></td><td><?php echo e($c[1] . ' ' . $c[2]); ?></td><td><?php echo e($c[3]); ?></td><td><?php echo e($c[4] . ' ' . $c[5]); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
<div class="row">
    <div class="col-md-5">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">How it works</div>
            <div class="panel-body">
                <ol>
                    <li>Download the template for the class you are registering &mdash;
                        <a href="?template=junior"><i class="fa fa-download"></i> Junior (JSS)</a> or
                        <a href="?template=senior"><i class="fa fa-download"></i> Senior (SSS)</a> &mdash; and open it in Excel.</li>
                    <li>One student per row. Columns: <code>surname</code>, <code>other_names</code>, <code>gender</code>, <code>phone</code>, <code>date_of_birth</code>, <code>level</code>, <code>subjects</code>.</li>
                    <li><code>date_of_birth</code>: <code>2008-03-14</code> or <code>14/03/2008</code> (day first).</li>
                    <li><code>level</code>: Junior class &rarr; J.S.S 1, 2 or 3 (also accepts JSS1). Senior classes &rarr; S.S.S 1, 2 or 3.</li>
                    <li><code>subjects</code>: names separated by <code>|</code>, up to 9, exactly as shown on the registration form
                        (e.g. <code>Biology|Chemistry|Physics</code>). Leave blank to use the <em>default subjects</em> you tick on this page.</li>
                    <li>Save as <strong>CSV UTF-8</strong>, choose the class, upload. One file per class.</li>
                </ol>
                <p class="text-muted small">Up to <?php echo STUDENT_IMPORT_MAX_ROWS; ?> students per file. If any row has a problem nothing is imported and
                   you are told which rows to fix. A student already registered (same name and date of birth in the class) is skipped. Exam numbers are
                   generated for you and shown here once; they are always visible later under <a href="students.php">Students</a>.
                   Photos are not part of the upload; those students show a placeholder picture.</p>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">Upload a CSV file</div>
            <div class="panel-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <div class="form-group">
                        <label>Class</label>
                        <select name="classGroup" id="classGroup" class="form-control" required>
                            <option value="">Select class</option>
                            <?php echo class_options_html(); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Default subjects <small class="text-muted">(used for rows with a blank subjects cell; max 9)</small></label>
                        <div id="defaultSubjects" class="well well-sm" style="max-height:170px;overflow:auto;margin:0;">
                            <span class="text-muted">Select a class first</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>CSV file</label>
                        <input type="file" name="csv" accept=".csv,.txt,text/csv" class="form-control" required>
                    </div>
                    <button class="btn btn-success" type="submit"><i class="fa fa-upload"></i> Register students</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
$subjectMap = [];
foreach (admin_subject_options() as $s) {
    $subjectMap[$s['class_id']][] = ['id' => (int) $s['sub_id'], 'name' => trim($s['sub_name'])];
}
?>
<script>
var subjects = <?php echo json_encode($subjectMap, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
document.getElementById('classGroup').addEventListener('change', function () {
    var box = document.getElementById('defaultSubjects');
    box.innerHTML = '';
    var list = subjects[this.value] || [];
    if (!list.length) { box.innerHTML = '<span class="text-muted">Select a class first</span>'; return; }
    list.forEach(function (s) {
        var l = document.createElement('label');
        l.style.cssText = 'display:block;font-weight:normal;margin:2px 0;';
        var c = document.createElement('input'); c.type = 'checkbox'; c.name = 'defaultSubjects[]'; c.value = s.id;
        l.appendChild(c); l.appendChild(document.createTextNode(' ' + s.name)); box.appendChild(l);
    });
});
</script>
<?php admin_foot();
