<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/csv_import.php';

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="question-import-template.csv"');
    echo "\xEF\xBB\xBF" . import_template_csv();
    exit;
}

$errors = [];
$imported = 0;
$skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token. Reload the page and try again.'); }

    $classId = (int) ($_POST['classGroup'] ?? 0);
    $subjectId = (int) ($_POST['classSubject'] ?? 0);
    $file = $_FILES['csv'] ?? null;

    if (!db_one("SELECT sub_id FROM subject WHERE sub_id = ? AND class_id = ?", 'ii', $subjectId, $classId)) {
        $errors[] = 'Please choose a class and a subject.';
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a CSV file to upload.';
    } elseif ($file['size'] > 2 * 1024 * 1024) {
        $errors[] = 'The file is larger than 2 MB.';
    } elseif (!preg_match('/\.(csv|txt)$/i', (string) $file['name'])) {
        $errors[] = 'Please upload a .csv file (in Excel: Save As, then "CSV UTF-8").';
    } else {
        [$rows, $parseErrors] = parse_question_csv($file['tmp_name']);
        if ($parseErrors) {
            $errors = array_slice($parseErrors, 0, 50);
            if (count($parseErrors) > 50) { $errors[] = '...and ' . (count($parseErrors) - 50) . ' more problems.'; }
            $errors[] = 'Nothing was imported. Fix the rows above and upload the file again.';
        } else {
            // Skip questions already present for this subject (so re-uploading a file is harmless).
            $existing = [];
            foreach (db_all("SELECT question_desc FROM question WHERE class_id = ? AND subject_id = ?", 'ii', $classId, $subjectId) as $r) {
                $existing[md5(mb_strtolower(trim(strip_tags($r['question_desc']))))] = true;
            }
            global $con;
            mysqli_begin_transaction($con);
            try {
                foreach ($rows as $r) {
                    $key = md5(mb_strtolower(trim($r['question'])));
                    if (isset($existing[$key])) { $skipped++; continue; }
                    $existing[$key] = true;
                    $html = question_text_html($r['question']);
                    db_exec(
                        "INSERT INTO question (class_id, subject_id, question_desc, option1, option2, option3, option4, true_answer, topic)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        'iisssssss', $classId, $subjectId, $html,
                        $r['options'][1], $r['options'][2], $r['options'][3], $r['options'][4], $r['options'][$r['correct']],
                        $r['topic'] !== '' ? $r['topic'] : null
                    );
                    $imported++;
                }
                mysqli_commit($con);
            } catch (Throwable $t) {
                mysqli_rollback($con);
                throw $t;
            }
        }
    }
}

admin_head('Bulk Upload Questions', 'import');
if ($imported > 0 || ($skipped > 0 && !$errors)) {
    echo '<div class="alert alert-success"><strong>' . (int) $imported . ' question(s) imported.</strong>'
       . ($skipped ? ' ' . (int) $skipped . ' duplicate(s) skipped.' : '')
       . ' <a href="questions.php?subject=' . (int) ($_POST['classSubject'] ?? 0) . '">View these questions</a></div>';
}
if ($errors) {
    echo '<div class="alert alert-danger"><ul style="margin:0;padding-left:18px;">';
    foreach ($errors as $m) { echo '<li>' . e($m) . '</li>'; }
    echo '</ul></div>';
}
?>
<div class="row">
    <div class="col-md-5">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">How it works</div>
            <div class="panel-body">
                <ol>
                    <li><a href="?template=1"><i class="fa fa-download"></i> Download the template</a> and open it in Excel.</li>
                    <li>Put one question per row. Columns: <code>question</code>, <code>option_a</code>, <code>option_b</code>, <code>option_c</code>, <code>option_d</code>, <code>correct</code>, and optionally <code>topic</code> (used for the <a href="exam_control.php">topic mix</a>).</li>
                    <li><code>correct</code> is the letter <strong>A, B, C or D</strong>.</li>
                    <li>Save as <strong>CSV UTF-8</strong> (File &rarr; Save As).</li>
                    <li>Choose the class and subject on the right and upload. All questions in one file go to that one subject.</li>
                </ol>
                <p class="text-muted small">Up to <?php echo IMPORT_MAX_ROWS; ?> questions per file, 2 MB max. If any row has a problem,
                   nothing is imported and you are told which rows to fix. Questions already in the subject are skipped, so uploading the
                   same file twice is safe. The question text may contain <code>&lt;sup&gt;</code> and <code>&lt;sub&gt;</code> (x&lt;sup&gt;2&lt;/sup&gt;), <code>&lt;b&gt;</code>, <code>&lt;i&gt;</code>, and pictures from the <a href="images.php">Image library</a> (paste the tag it gives you). Options are plain text.</p>
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
                            <?php echo class_options_html((int) ($_POST['classGroup'] ?? 0)); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <select name="classSubject" id="classSubject" class="form-control" required>
                            <option value="">Select class first</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>CSV file</label>
                        <input type="file" name="csv" accept=".csv,.txt,text/csv" class="form-control" required>
                    </div>
                    <button class="btn btn-success" type="submit"><i class="fa fa-upload"></i> Import questions</button>
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
<script src="<?php echo e(url('assets/js/jquery-3.2.1.min.js')); ?>"></script>
<script>
var subjects = <?php echo json_encode($subjectMap, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
function loadSubjects(selected) {
    var sel = document.getElementById('classSubject');
    sel.innerHTML = '<option value="">Select subject</option>';
    (subjects[document.getElementById('classGroup').value] || []).forEach(function (s) {
        var o = document.createElement('option'); o.value = s.id; o.textContent = s.name;
        if (selected && String(s.id) === String(selected)) { o.selected = true; }
        sel.appendChild(o);
    });
}
document.getElementById('classGroup').addEventListener('change', function () { loadSubjects(); });
// Keep the class and subject selected after an upload, ready for the next file.
loadSubjects(<?php echo (int) ($_POST['classSubject'] ?? 0); ?>);
</script>
<?php admin_foot();
