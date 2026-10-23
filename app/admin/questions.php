<?php
require_once __DIR__ . '/../includes/admin_layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token.'); }
    if (isset($_POST['delete'])) {
        $qd = db_one("SELECT subject_id FROM question WHERE id = ?", 'i', (int) ($_POST['id'] ?? 0));
        if ($qd && subject_allowed((int) $qd['subject_id'])) {
            db_exec("DELETE FROM question WHERE id = ?", 'i', (int) ($_POST['id'] ?? 0));
            db_exec("DELETE FROM exam_answer WHERE question_id = ?", 'i', (int) ($_POST['id'] ?? 0));
            db_exec("DELETE FROM exam_question WHERE question_id = ?", 'i', (int) ($_POST['id'] ?? 0));
            flash_set('success', 'Question deleted.');
        }
    } elseif (isset($_POST['clearAll'])) {
        // Delete every question in the chosen subject (and level), or the whole bank. A backup is taken first.
        $csub = (int) ($_POST['subject'] ?? 0);
        $clv = (string) ($_POST['level'] ?? '');
        if (strtoupper(trim((string) ($_POST['confirm'] ?? ''))) !== 'DELETE') {
            flash_set('danger', 'Nothing was deleted. Type DELETE in the box to confirm.');
        } elseif (!$csub && !is_admin_role()) {
            flash_set('danger', 'Choose a subject first.');
        } elseif ($csub && !subject_allowed($csub)) {
            flash_set('danger', 'You are not assigned to that subject.');
        } else {
            $w = ['(? = 0 OR q.subject_id = ?)', subject_scope_sql('q.subject_id')]; $types = 'ii'; $args = [$csub, $csub];
            if ($clv === 'all') { $w[] = "q.level = ''"; }
            elseif ($clv !== '') { $w[] = 'q.level = ?'; $types .= 's'; $args[] = $clv; }
            $where = implode(' AND ', $w);
            $n = (int) db_one("SELECT COUNT(*) AS c FROM question q WHERE $where", $types, ...$args)['c'];
            $busy = (int) db_one("SELECT COUNT(*) AS c FROM dosubject d WHERE d.istaken = 0 AND d.dosub_id IN (SELECT q.subject_id FROM question q WHERE $where)", $types, ...$args)['c'];
            if ($n === 0) {
                flash_set('warning', 'There were no questions to clear.');
            } elseif ($busy > 0) {
                flash_set('danger', "$busy student(s) are writing an exam in these subjects right now. Wait until they finish, then try again.");
            } else {
                $saved = '';
                try { require_once __DIR__ . '/../includes/backup.php'; $saved = backup_to_server(); } catch (Throwable $e) { $saved = ''; }
                db_exec("DELETE FROM exam_answer WHERE question_id IN (SELECT q.id FROM question q WHERE $where)", $types, ...$args);
                db_exec("DELETE FROM exam_question WHERE question_id IN (SELECT q.id FROM question q WHERE $where)", $types, ...$args);
                db_exec("DELETE FROM question WHERE id IN (SELECT id FROM (SELECT q.id FROM question q WHERE $where) t)", $types, ...$args);
                flash_set('success', "$n question(s) cleared." . ($saved !== '' ? " A backup was saved first ($saved) in case you need them back." : ' (A backup could not be saved first.)'));
            }
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$subjectId = (int) ($_GET['subject'] ?? 0);
$levelF = (string) ($_GET['level'] ?? '');   // '' = any, 'all' = only all-level questions, or a level label
$scope = subject_scope_sql('q.subject_id');
$levelWhere = $levelF === '' ? '1=1' : ($levelF === 'all' ? "q.level = ''" : 'q.level = ?');
$lvlParams = ($levelF !== '' && $levelF !== 'all') ? [$levelF] : [];
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;
$total = (int) db_one("SELECT COUNT(*) AS c FROM question q WHERE (? = 0 OR q.subject_id = ?) AND $levelWhere AND $scope", 'ii' . ($lvlParams ? 's' : ''), $subjectId, $subjectId, ...$lvlParams)['c'];
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$rows = db_all(
    "SELECT q.id, q.topic, q.level, q.question_desc, q.option1, q.option2, q.option3, q.option4, q.true_answer, s.sub_name, c.class
       FROM question q JOIN subject s ON s.sub_id = q.subject_id JOIN class c ON c.id = q.class_id
      WHERE (? = 0 OR q.subject_id = ?) AND $levelWhere AND $scope ORDER BY q.id DESC LIMIT ? OFFSET ?",
    'ii' . ($lvlParams ? 's' : '') . 'ii', $subjectId, $subjectId, ...array_merge($lvlParams, [$per, ($page - 1) * $per])
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
    <select name="level" class="form-control" onchange="this.form.submit()">
        <option value="">Any level</option>
        <option value="all"<?php echo $levelF === 'all' ? ' selected' : ''; ?>>All-level questions only</option>
        <?php foreach (array_merge(levels_for_section(true), levels_for_section(false)) as $lv): ?><option value="<?php echo e($lv); ?>"<?php echo $levelF === $lv ? ' selected' : ''; ?>><?php echo e($lv); ?></option><?php endforeach; ?>
    </select>
    <span class="text-muted"><?php echo $total; ?> question(s)</span>
    <a class="btn btn-success pull-right" href="question_portal.php<?php echo $subjectId ? '?class=' . (int) (db_one("SELECT class_id FROM subject WHERE sub_id = ?", 'i', $subjectId)['class_id'] ?? 0) . '&subject=' . $subjectId : ''; ?>"><i class="fa fa-plus"></i> Upload question</a>
    <a class="btn btn-default pull-right" href="question_import.php" style="margin-right:8px;"><i class="fa fa-upload"></i> Bulk upload (CSV)</a>
</form>

<div style="margin:-5px 0 15px;">
    <button type="button" class="btn btn-danger btn-sm" data-toggle="collapse" data-target="#clearBox"><i class="fa fa-trash"></i> Clear questions</button>
    <div class="collapse" id="clearBox">
        <div class="alert alert-danger" style="margin-top:10px;">
            <form method="post" class="form-inline">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="subject" value="<?php echo (int) $subjectId; ?>">
                <input type="hidden" name="level" value="<?php echo e($levelF); ?>">
                <strong>Delete <?php echo (int) $total; ?> question(s)</strong>
                <?php echo $subjectId ? 'in the subject shown' : (is_admin_role() ? '<strong>from EVERY subject</strong>' : ''); ?><?php echo $levelF === 'all' ? ' (all-level questions only)' : ($levelF !== '' ? ' for ' . e($levelF) : ''); ?>.
                This cannot be undone from the app (a backup is saved first). Results already recorded are kept.
                <?php if (!$subjectId && !is_admin_role()): ?><br>Choose a subject above first.<?php else: ?>
                <br>Type <strong>DELETE</strong> to confirm:
                <input type="text" name="confirm" class="form-control input-sm" autocomplete="off" style="width:110px;">
                <button class="btn btn-danger btn-sm" name="clearAll" value="1">Clear questions</button><?php endif; ?>
            </form>
        </div>
    </div>
</div>
<div class="panel panel-default"><div class="table-responsive">
<table class="table table-striped table-hover table-tight" style="margin:0;">
    <thead><tr><th>#</th><th>Class / Subject</th><th>Level</th><th>Question</th><th>Correct answer</th><th style="width:150px;"></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted">No questions found.</td></tr><?php endif; ?>
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
            <td><?php echo $r['level'] === '' ? '<span class="text-muted">All</span>' : '<span class="label label-info">' . e($r['level']) . '</span>'; ?></td>
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
    <?php $qs = ($subjectId ? '&subject=' . $subjectId : '') . ($levelF !== '' ? '&level=' . rawurlencode($levelF) : ''); ?>
    <li class="previous<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a href="?page=<?php echo max(1, $page - 1) . $qs; ?>">&larr; Newer</a></li>
    <li>Page <?php echo $page; ?> of <?php echo $pages; ?></li>
    <li class="next<?php echo $page >= $pages ? ' disabled' : ''; ?>"><a href="?page=<?php echo min($pages, $page + 1) . $qs; ?>">Older &rarr;</a></li>
</ul>
<?php endif; ?>
<?php admin_foot();
