<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin_role();

$subId = (int) ($_GET['subject'] ?? $_POST['subject_id'] ?? 0);
$sub = db_one("SELECT s.*, c.class FROM subject s JOIN class c ON c.id = s.class_id WHERE s.sub_id = ?", 'i', $subId);
if (!$sub) {
    flash_set('danger', 'Subject not found.');
    header('Location: exam_control.php');
    exit;
}

// Topics in the bank and how many questions each has ('' = questions with no topic).
$topics = [];
foreach (db_all("SELECT topic, COUNT(*) AS c FROM question WHERE subject_id = ? GROUP BY topic", 'i', $subId) as $r) {
    $key = trim((string) $r['topic']);
    $topics[$key] = ($topics[$key] ?? 0) + (int) $r['c'];
}
ksort($topics);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token. Reload the page and try again.'); }
    if (isset($_POST['clear'])) {
        db_exec("UPDATE subject SET question_mix = NULL WHERE sub_id = ?", 'i', $subId);
        flash_set('success', 'Topic mix turned off. Students draw from all questions again.');
    } else {
        $mix = [];
        $errors = [];
        foreach ((array) ($_POST['count'] ?? []) as $enc => $n) {
            $topic = base64_decode((string) $enc, true);
            if ($topic === false || !array_key_exists($topic, $topics)) { continue; }
            $n = (int) $n;
            if ($n < 0) { $errors[] = 'Numbers cannot be negative.'; break; }
            if ($n > $topics[$topic]) { $n = $topics[$topic]; } // can never draw more than exist
            if ($n > 0) { $mix[$topic] = $n; }
        }
        if ($errors) {
            flash_set('danger', $errors[0]);
        } elseif (!$mix) {
            flash_set('danger', 'Enter a number for at least one topic, or use "Turn off".');
        } else {
            db_exec("UPDATE subject SET question_mix = ? WHERE sub_id = ?", 'si', json_encode($mix, JSON_UNESCAPED_UNICODE), $subId);
            flash_set('success', 'Topic mix saved: each student gets ' . array_sum($mix) . ' questions.');
        }
    }
    header('Location: topic_mix.php?subject=' . $subId);
    exit;
}

$mix = json_decode((string) $sub['question_mix'], true);
$mix = is_array($mix) ? $mix : [];

admin_head('Topic mix', 'subjects');
flash_show();
?>
<p><strong><?php echo e($sub['class']); ?> &mdash; <?php echo e(trim($sub['sub_name'])); ?></strong>
   <a href="exam_control.php" class="btn btn-default btn-xs" style="margin-left:10px;">&larr; Back to Subject status</a></p>
<p class="text-muted">Choose how many questions each student gets from every topic (e.g. 10 Algebra + 10 Geometry). The questions are picked at random
   and then mixed together. When a topic mix is on, it replaces the &ldquo;Questions per student&rdquo; number. Add topics when you upload questions (a <code>topic</code> column in the CSV).</p>
<?php if (!$topics): ?>
    <div class="alert alert-warning">This subject has no questions yet.</div>
<?php else: ?>
<form method="post" style="max-width:560px;">
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="subject_id" value="<?php echo $subId; ?>">
    <div class="panel panel-default">
        <table class="table table-condensed table-tight" style="margin:0;">
            <thead><tr><th>Topic</th><th>In bank</th><th style="width:160px;">Per student</th></tr></thead>
            <?php foreach ($topics as $name => $n): ?>
                <tr>
                    <td><?php echo $name === '' ? '<em class="text-muted">(no topic)</em>' : e($name); ?></td>
                    <td><?php echo $n; ?></td>
                    <td><input type="number" min="0" max="<?php echo $n; ?>" name="count[<?php echo e(base64_encode($name)); ?>]" value="<?php echo (int) ($mix[$name] ?? 0); ?>" class="form-control input-sm"></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <button class="btn btn-success" name="save" value="1"><i class="fa fa-save"></i> Save topic mix</button>
    <?php if ($mix): ?><button class="btn btn-default" name="clear" value="1">Turn off</button><?php endif; ?>
</form>
<?php endif; ?>
<?php admin_foot();
