<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/question_editor.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$qrow = db_one("SELECT * FROM question WHERE id = ?", 'i', $id);
if (!$qrow || !subject_allowed((int) $qrow['subject_id'])) {
    flash_set('danger', 'Question not found.');
    header('Location: questions.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token.'); }
    $desc = safe_html($_POST['questionDescription'] ?? '');
    $opts = [];
    for ($i = 1; $i <= 4; $i++) { $opts[$i] = trim(strip_tags((string) ($_POST['option' . $i] ?? ''))); }
    $correct = (int) ($_POST['trueAnswer'] ?? 0);
    $topic = trim(strip_tags((string) ($_POST['topic'] ?? '')));
    $levelIn = (string) ($_POST['level'] ?? '');
    $validLevels = levels_for_class((int) $qrow['class_id']);
    $level = $levelIn === 'all' ? '' : (in_array($levelIn, $validLevels, true) ? $levelIn : null);
    if ($level === null) {
        $error = 'Please choose the level or "All levels".';
    } elseif (trim(strip_tags($desc, '<img>')) === '') {
        $error = 'The question cannot be empty.';
    } elseif (in_array('', $opts, true)) {
        $error = 'All four options are required.';
    } elseif ($correct < 1 || $correct > 4) {
        $error = 'Please select which option is correct.';
    } else {
        db_exec(
            "UPDATE question SET question_desc = ?, option1 = ?, option2 = ?, option3 = ?, option4 = ?, true_answer = ?, topic = ?, level = ? WHERE id = ?",
            'ssssssssi', $desc, $opts[1], $opts[2], $opts[3], $opts[4], $opts[$correct], $topic !== '' ? mb_substr($topic, 0, 100) : null, $level, $id
        );
        flash_set('success', 'Question #' . $id . ' updated.');
        header('Location: questions.php?subject=' . (int) $qrow['subject_id']);
        exit;
    }
    // re-show what the admin typed
    $qrow['question_desc'] = $desc;
    foreach ([1, 2, 3, 4] as $i) { $qrow['option' . $i] = $opts[$i]; }
    $qrow['true_answer'] = $correct >= 1 && $correct <= 4 ? $opts[$correct] : '';
    $qrow['level'] = $level ?? '';
}
$levelsNow = levels_for_class((int) $qrow['class_id']);

$sel = 0;
foreach ([1, 2, 3, 4] as $k) {
    if (strcasecmp(trim($qrow['option' . $k]), trim($qrow['true_answer'])) === 0) { $sel = $k; break; }
}
$meta = db_one("SELECT s.sub_name, c.class FROM subject s JOIN class c ON c.id = s.class_id WHERE s.sub_id = ?", 'i', (int) $qrow['subject_id']);

admin_head('Edit Question #' . $id, 'questions');
if ($error) { echo '<div class="alert alert-danger">' . e($error) . '</div>'; }
?>
<p class="text-muted"><?php echo e(($meta['class'] ?? '') . ' — ' . trim($meta['sub_name'] ?? '')); ?>.
   Changing a question does not alter results already recorded.</p>
<form method="post" style="max-width:820px;">
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <div class="form-group">
        <label>Question</label>
        <?php echo question_editor_tools(); ?>
        <textarea name="questionDescription" id="questionDescription" rows="5" class="form-control"><?php echo e(preg_replace('~(src\s*=\s*["\'])(uploads/questions/)~i', '$1../$2', (string) $qrow['question_desc'])); ?></textarea>
    </div>
    <?php foreach (['A', 'B', 'C', 'D'] as $i => $letter): $k = $i + 1; ?>
        <div class="form-group">
            <label>Option <?php echo $letter; ?></label>
            <input type="text" name="option<?php echo $k; ?>" class="form-control" value="<?php echo e($qrow['option' . $k]); ?>" required>
        </div>
    <?php endforeach; ?>
    <div class="form-group">
        <label>Correct option</label>
        <select name="trueAnswer" class="form-control" required>
            <option value="">Which option is correct?</option>
            <?php foreach (['A', 'B', 'C', 'D'] as $i => $letter): ?>
                <option value="<?php echo $i + 1; ?>"<?php echo $sel === $i + 1 ? ' selected' : ''; ?>><?php echo $letter; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Level</label>
        <select name="level" class="form-control" required>
            <option value="all"<?php echo ($qrow['level'] ?? '') === '' ? ' selected' : ''; ?>>All levels (<?php echo e($levelsNow[0] . ' - ' . $levelsNow[2]); ?>)</option>
            <?php foreach ($levelsNow as $lv): ?><option value="<?php echo e($lv); ?>"<?php echo ($qrow['level'] ?? '') === $lv ? ' selected' : ''; ?>><?php echo e($lv); ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Topic <small class="text-muted">(optional)</small></label>
        <input type="text" name="topic" class="form-control" maxlength="100" value="<?php echo e($qrow['topic'] ?? ''); ?>">
    </div>
    <button class="btn btn-success" type="submit"><i class="fa fa-save"></i> Save changes</button>
    <a class="btn btn-default" href="questions.php?subject=<?php echo (int) $qrow['subject_id']; ?>">Cancel</a>
</form>
<script src="<?php echo e(url('ckeditor/ckeditor.js')); ?>"></script>
<?php echo question_editor_script('questionDescription'); ?>
<?php admin_foot();
