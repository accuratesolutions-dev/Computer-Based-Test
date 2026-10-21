<?php
require_once __DIR__ . '/../includes/admin_layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token.'); }
    if (isset($_POST['delete'])) {
        db_exec("DELETE FROM question WHERE id = ?", 'i', (int) ($_POST['id'] ?? 0));
        db_exec("DELETE FROM exam_answer WHERE question_id = ?", 'i', (int) ($_POST['id'] ?? 0));
        db_exec("DELETE FROM exam_question WHERE question_id = ?", 'i', (int) ($_POST['id'] ?? 0));
        flash_set('success', 'Question deleted.');
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$subjectId = (int) ($_GET['subject'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;
$total = (int) db_one("SELECT COUNT(*) AS c FROM question WHERE (? = 0 OR subject_id = ?)", 'ii', $subjectId, $subjectId)['c'];
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$rows = db_all(
    "SELECT q.id, q.topic, q.question_desc, q.option1, q.option2, q.option3, q.option4, q.true_answer, s.sub_name, c.class
       FROM question q JOIN subject s ON s.sub_id = q.subject_id JOIN class c ON c.id = q.class_id
      WHERE (? = 0 OR q.subject_id = ?) ORDER BY q.id DESC LIMIT ? OFFSET ?",
    'iiii', $subjectId, $subjectId, $per, ($page - 1) * $per
);

admin_head('Questions', 'questions');
flash_show();
?>
<form class="form-inline" method="get" style="margin-bottom:15px;">
    <select name="subject" class="form-control" onchange="this.form.submit()">
        <option value="0">All subjects</option>
        <?php foreach (admin_subject_options() as $s): ?>
            <option value="<?php echo (int) $s['sub_id']; ?>"<?php echo $subjectId === (int) $s['sub_id'] ? ' selected' : ''; ?>><?php echo e($s['class'] . ' — ' . trim($s['sub_name'])); ?></option>
        <?php endforeach; ?>
    </select>
    <span class="text-muted"><?php echo $total; ?> question(s)</span>
    <a class="btn btn-success pull-right" href="question_portal.php<?php echo $subjectId ? '?class=' . (int) (db_one("SELECT class_id FROM subject WHERE sub_id = ?", 'i', $subjectId)['class_id'] ?? 0) . '&subject=' . $subjectId : ''; ?>"><i class="fa fa-plus"></i> Upload question</a>
    <a class="btn btn-default pull-right" href="question_import.php" style="margin-right:8px;"><i class="fa fa-upload"></i> Bulk upload (CSV)</a>
</form>

<div class="panel panel-default"><div class="table-responsive">
<table class="table table-striped table-hover table-tight" style="margin:0;">
    <thead><tr><th>#</th><th>Class / Subject</th><th>Question</th><th>Correct answer</th><th style="width:150px;"></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted">No questions found.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r):
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($r['question_desc']), ENT_QUOTES, 'UTF-8')));
        $text = mb_strlen($text) > 110 ? mb_substr($text, 0, 110) . '…' : $text;
        $letter = '?';
        foreach ([1, 2, 3, 4] as $k) {
            if (strcasecmp(trim($r['option' . $k]), trim($r['true_answer'])) === 0) { $letter = chr(64 + $k); break; }
        } ?>
        <tr>
            <td><?php echo (int) $r['id']; ?></td>
            <td><?php echo e($r['class']); ?><br><small class="text-muted"><?php echo e(trim($r['sub_name'])); ?></small><?php if (trim((string) $r['topic']) !== ''): ?><br><span class="label label-default"><?php echo e($r['topic']); ?></span><?php endif; ?></td>
            <td class="q-snippet"><?php echo e($text !== '' ? $text : '(image / no text)'); ?></td>
            <td><strong><?php echo e($letter); ?></strong> <small class="text-muted"><?php echo e(mb_strimwidth($r['true_answer'], 0, 40, '…')); ?></small></td>
            <td>
                <a class="btn btn-xs btn-primary" href="question_edit.php?id=<?php echo (int) $r['id']; ?>"><i class="fa fa-pencil"></i> Edit</a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this question permanently?');">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                    <button class="btn btn-xs btn-danger" name="delete" value="1"><i class="fa fa-trash"></i></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>

<?php if ($pages > 1): ?>
<ul class="pager">
    <?php $qs = $subjectId ? '&subject=' . $subjectId : ''; ?>
    <li class="previous<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a href="?page=<?php echo max(1, $page - 1) . $qs; ?>">&larr; Newer</a></li>
    <li>Page <?php echo $page; ?> of <?php echo $pages; ?></li>
    <li class="next<?php echo $page >= $pages ? ' disabled' : ''; ?>"><a href="?page=<?php echo min($pages, $page + 1) . $qs; ?>">Older &rarr;</a></li>
</ul>
<?php endif; ?>
<?php admin_foot();
