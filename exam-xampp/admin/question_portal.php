<?php
require_once __DIR__ . '/../includes/add_question.php'; // also loads the admin layout + session guard
require_once __DIR__ . '/../includes/question_editor.php';

$classId   = (int) ($_GET['class'] ?? 0);
$subjectId = (int) ($_GET['subject'] ?? 0);
$levelSel = (string) ($_GET['level'] ?? '');
$levelsNow = $classId ? levels_for_class($classId) : [];
$subjectsForClass = $classId ? db_all("SELECT sub_id, sub_name FROM subject WHERE class_id = ? AND " . subject_scope_sql('sub_id') . " ORDER BY sub_name", 'i', $classId) : [];
$counts = [];
foreach (db_all("SELECT subject_id, COUNT(*) AS c FROM question GROUP BY subject_id") as $r) { $counts[(int) $r['subject_id']] = (int) $r['c']; }

admin_head('Upload a Question', 'portal', '<script src="../ckeditor/ckeditor.js" type="text/javascript"></script>');
flash_show();
?>
<div class="row">
    <div class="col-md-4">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">Quick guide</div>
            <div class="panel-body">
                <ol style="padding-left:18px;">
                    <li>Pick the <strong>class</strong> (Junior or Senior), the <strong>level</strong> (JSS 1/2/3 or SSS 1/2/3, or <em>All levels</em>) and the <strong>subject</strong>. Students only get the questions for their own level.</li>
                    <li>Type the question and the four options, then mark the correct one.</li>
                    <li>After saving, the class and subject stay selected so you can add the next question straight away.</li>
                    <li>Have many questions? Use <a href="question_import.php">Bulk Upload</a>. Need a picture (e.g. a maths diagram)? Click <strong>Add picture</strong> above the question box.</li>
                    <li>When you are ready, open the subject under <a href="exam_control.php">Subject Status</a> and set its time.</li>
                </ol>
                <p class="text-muted small" style="margin:0;">Students get the questions in a random order. You can also make each student
                   draw only some of the questions (e.g. 40 of 100) in Subject Status.</p>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">New question</div>
            <div class="panel-body">
                <form action="question_portal.php" method="post">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <div class="row">
                        <div class="col-sm-4 form-group">
                            <label for="classGroup">Class</label>
                            <select name="classGroup" id="classGroup" class="form-control" required>
                                <option value="">Select class</option>
                                <?php echo class_options_html($classId); ?>
                            </select>
                        </div>
                        <div class="col-sm-4 form-group">
                            <label for="level">Level</label>
                            <select name="level" id="level" class="form-control" required>
                                <option value="">Select level</option>
                                <?php if ($levelsNow): ?>
                                    <option value="all"<?php echo $levelSel === 'all' ? ' selected' : ''; ?>>All levels (<?php echo e($levelsNow[0] . ' - ' . $levelsNow[2]); ?>)</option>
                                    <?php foreach ($levelsNow as $lv): ?><option value="<?php echo e($lv); ?>"<?php echo $levelSel === $lv ? ' selected' : ''; ?>><?php echo e($lv); ?></option><?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-sm-4 form-group">
                            <label for="classSubject">Subject</label>
                            <select name="classSubject" id="classSubject" class="form-control" required>
                                <option value="">Select subject</option>
                                <?php foreach ($subjectsForClass as $s): ?>
                                    <option value="<?php echo (int) $s['sub_id']; ?>"<?php echo $subjectId === (int) $s['sub_id'] ? ' selected' : ''; ?>>
                                        <?php echo e(trim($s['sub_name'])); ?> (<?php echo $counts[(int) $s['sub_id']] ?? 0; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="questionDescription">Question</label>
                        <?php echo question_editor_tools(); ?>
                        <textarea name="questionDescription" id="questionDescription" rows="5" class="form-control"></textarea>
                    </div>
                    <?php foreach ([1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D'] as $n => $letter): ?>
                        <div class="form-group">
                            <div class="input-group">
                                <span class="input-group-addon"><?php echo $letter; ?></span>
                                <input type="text" name="option<?php echo $n; ?>" id="option<?php echo $n; ?>" class="form-control" placeholder="Option <?php echo $letter; ?>" required>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-group">
                        <label for="trueAnswer">Correct answer</label>
                        <select name="trueAnswer" id="trueAnswer" class="form-control" required>
                            <option value="">Which option is correct?</option>
                            <option value="1">A</option><option value="2">B</option><option value="3">C</option><option value="4">D</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="topic">Topic <small class="text-muted">(optional &mdash; for the topic mix)</small></label>
                        <input type="text" name="topic" id="topic" class="form-control" maxlength="100" list="topicList" placeholder="e.g. Algebra">
                        <datalist id="topicList"><?php foreach (db_all("SELECT DISTINCT topic FROM question WHERE topic IS NOT NULL AND topic <> '' ORDER BY topic LIMIT 200") as $t): ?><option value="<?php echo e($t['topic']); ?>"><?php endforeach; ?></datalist>
                    </div>
                    <button type="submit" class="btn btn-success btn-lg" name="btn-submit" value="1"><i class="fa fa-check"></i> Save question</button>
                    <a href="questions.php<?php echo $subjectId ? '?subject=' . $subjectId : ''; ?>" class="btn btn-default btn-lg">View questions</a>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var classSel = document.getElementById('classGroup'), subSel = document.getElementById('classSubject');
    var levelSel = document.getElementById('level');
    var LEVELS = {junior: <?php echo json_encode(levels_for_section(true)); ?>, senior: <?php echo json_encode(levels_for_section(false)); ?>};
    function fillLevels() {
        var opt = classSel.options[classSel.selectedIndex], sec = opt ? opt.getAttribute('data-section') : '', list = LEVELS[sec] || [];
        levelSel.innerHTML = '<option value="">Select level</option>' + (list.length ? '<option value="all">All levels (' + list[0] + ' - ' + list[2] + ')</option>' : '')
            + list.map(function (l) { return '<option value="' + l + '">' + l + '</option>'; }).join('');
    }
    classSel.addEventListener('change', function () {
        fillLevels();
        subSel.innerHTML = '<option value="">Loading...</option>';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '../includes/subjects.php');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () { subSel.innerHTML = xhr.responseText; };
        xhr.send('classSubject=' + encodeURIComponent(classSel.value));
    });
    // The question box is a rich-text editor; make sure its text is copied back before the form is sent.
    document.querySelector('form').addEventListener('submit', function (e) {
        for (var k in CKEDITOR.instances) { CKEDITOR.instances[k].updateElement(); }
        if (!CKEDITOR.instances.questionDescription.getData().replace(/<[^>]*>/g, '').trim()
            && CKEDITOR.instances.questionDescription.getData().indexOf('<img') === -1) {
            e.preventDefault();
            alert('Please type the question.');
        }
    });
})();
</script>
<?php echo question_editor_script('questionDescription'); admin_foot();
