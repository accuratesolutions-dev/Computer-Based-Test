<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin_role();

// ---------- actions (post-redirect-get) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token. Reload the page and try again.'); }
    $anchor = '';

    if (isset($_POST['save'])) {                       // one subject: status + time + questions per student
        $subId   = (int) ($_POST['subject_id'] ?? 0);
        $status  = (string) ($_POST['status'] ?? '');
        $minutes = (int) ($_POST['duration'] ?? -1);
        $limit   = (int) ($_POST['question_limit'] ?? -1);
        $openAt  = trim((string) ($_POST['open_at'] ?? ''));
        $closeAt = trim((string) ($_POST['close_at'] ?? ''));
        $shuffle = !empty($_POST['shuffle_options']) ? 1 : 0;
        $openTs  = $openAt !== '' ? strtotime($openAt) : null;
        $closeTs = $closeAt !== '' ? strtotime($closeAt) : null;
        $sub = db_one("SELECT sub_id, sub_name, class_id FROM subject WHERE sub_id = ?", 'i', $subId);
        $anchor = '#class' . (int) ($sub['class_id'] ?? 0);
        if (!$sub) {
            flash_set('danger', 'That subject no longer exists.');
        } elseif (!in_array($status, ['Enabled', 'Disabled'], true) || $minutes < 0 || $minutes > 600 || $limit < 0 || $limit > 5000) {
            flash_set('danger', 'Time must be 0-600 minutes and questions per student 0-5000.');
        } elseif (($openAt !== '' && $openTs === false) || ($closeAt !== '' && $closeTs === false)) {
            flash_set('danger', 'The opening or closing time is not a valid date.');
        } elseif ($openTs && $closeTs && $closeTs <= $openTs) {
            flash_set('danger', 'The closing time must be after the opening time.');
        } elseif ($status === 'Enabled' && $minutes < 1) {
            flash_set('danger', 'Enter the exam time in minutes before opening ' . trim($sub['sub_name']) . '.');
        } else {
            db_exec(
                "UPDATE subject SET status = ?, sub_duration = ?, question_limit = ?, open_at = ?, close_at = ?, shuffle_options = ? WHERE sub_id = ?",
                'siissii', $status, $minutes, $limit,
                $openTs ? date('Y-m-d H:i:s', $openTs) : null, $closeTs ? date('Y-m-d H:i:s', $closeTs) : null, $shuffle, $subId
            );
            $when = '';
            if ($status === 'Enabled' && $openTs && $openTs > time()) { $when = ', opens ' . date('D j M, g:i A', $openTs); }
            flash_set('success', trim($sub['sub_name']) . ': ' . ($status === 'Enabled' ? "open for $minutes minute(s)$when" : 'closed') . '. Saved.');
        }
    } elseif (isset($_POST['bulk'])) {                 // whole class: open all / close all
        $classId = (int) ($_POST['class_id'] ?? 0);
        $anchor = '#class' . $classId;
        $minutes = (int) ($_POST['duration'] ?? 0);
        if (!db_one("SELECT id FROM class WHERE id = ?", 'i', $classId)) {
            flash_set('danger', 'Unknown class.');
        } elseif ($_POST['bulk'] === 'open') {
            if ($minutes < 1 || $minutes > 600) {
                flash_set('danger', 'Enter the exam time (1-600 minutes) to open every subject in this class.');
            } else {
                // Only subjects that actually have questions are opened.
                $n = db_exec(
                    "UPDATE subject s SET s.status = 'Enabled', s.sub_duration = ?
                      WHERE s.class_id = ? AND EXISTS (SELECT 1 FROM question q WHERE q.subject_id = s.sub_id)",
                    'ii', $minutes, $classId
                );
                flash_set('success', "$n subject(s) with questions are now open for $minutes minute(s).");
            }
        } else {
            $n = db_exec("UPDATE subject SET status = 'Disabled' WHERE class_id = ?", 'i', $classId);
            flash_set('success', 'All subjects in this class are now closed.');
        }
    } elseif (isset($_POST['add_subject'])) {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $anchor = '#class' . $classId;
        $name = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($_POST['name'] ?? ''))));
        if (!db_one("SELECT id FROM class WHERE id = ?", 'i', $classId)) {
            flash_set('danger', 'Unknown class.');
        } elseif ($name === '' || mb_strlen($name) > 100) {
            flash_set('danger', 'Enter a subject name (up to 100 characters).');
        } elseif (db_one("SELECT sub_id FROM subject WHERE class_id = ? AND TRIM(sub_name) = ?", 'is', $classId, $name)) {
            flash_set('danger', "\"$name\" already exists in this class.");
        } else {
            db_exec("INSERT INTO subject (class_id, sub_name) VALUES (?, ?)", 'is', $classId, $name);
            flash_set('success', "Subject \"$name\" added. Upload its questions next.");
        }
    } elseif (isset($_POST['delete_subject'])) {
        $subId = (int) ($_POST['subject_id'] ?? 0);
        $sub = db_one("SELECT sub_id, sub_name, class_id FROM subject WHERE sub_id = ?", 'i', $subId);
        $anchor = '#class' . (int) ($sub['class_id'] ?? 0);
        if (!$sub) {
            flash_set('danger', 'That subject no longer exists.');
        } elseif ((int) db_one("SELECT COUNT(*) AS c FROM question WHERE subject_id = ?", 'i', $subId)['c'] > 0
               || (int) db_one("SELECT COUNT(*) AS c FROM exam_result WHERE subject_id = ?", 'i', $subId)['c'] > 0
               || (int) db_one("SELECT COUNT(*) AS c FROM dosubject WHERE dosub_id = ?", 'i', $subId)['c'] > 0) {
            flash_set('danger', trim($sub['sub_name']) . ' has questions or exam attempts, so it cannot be deleted. Close it instead.');
        } else {
            db_exec("DELETE FROM subject WHERE sub_id = ?", 'i', $subId);
            flash_set('success', 'Subject "' . trim($sub['sub_name']) . '" deleted.');
        }
    }
    header('Location: exam_control.php' . $anchor);
    exit;
}

// ---------- data ----------
$bank = [];
foreach (db_all("SELECT subject_id, COUNT(*) AS c FROM question GROUP BY subject_id") as $r) { $bank[(int) $r['subject_id']] = (int) $r['c']; }
$running = [];
foreach (db_all("SELECT dosub_id, COUNT(*) AS c FROM dosubject WHERE istaken = 0 GROUP BY dosub_id") as $r) { $running[(int) $r['dosub_id']] = (int) $r['c']; }
$subjectsByClass = [];
foreach (db_all("SELECT * FROM subject ORDER BY class_id, sub_name") as $s) { $subjectsByClass[(int) $s['class_id']][] = $s; }
$classes = db_all("SELECT id, class, sortname FROM class ORDER BY id");
usort($classes, function ($a, $b) { return (int) class_is_junior($b) <=> (int) class_is_junior($a) ?: (int) $a['id'] <=> (int) $b['id']; }); // Junior first

admin_head('Subject Status', 'subjects');
flash_show();
?>
<p class="text-muted">Open a subject so students can start it, set how long they get, and (optionally) how many questions each student draws at random from the question bank.
   <strong>0 = every question.</strong> Students see changes within a few seconds &mdash; no need for them to refresh.</p>

<?php foreach ($classes as $c):
    $cid = (int) $c['id'];
    $list = $subjectsByClass[$cid] ?? [];
    $junior = class_is_junior($c);
    $openCount = 0;
    foreach ($list as $s) { if ($s['status'] === 'Enabled') { $openCount++; } }
    ?>
<div class="panel panel-default" id="class<?php echo $cid; ?>">
    <div class="panel-heading panel-heading-brand clearfix">
        <strong><?php echo e($c['class']); ?></strong>
        <span class="label label-<?php echo $junior ? 'warning' : 'info'; ?>" style="margin-left:6px;"><?php echo $junior ? 'JSS' : 'SSS'; ?></span>
        <small style="margin-left:8px;"><?php echo count($list); ?> subject(s), <?php echo $openCount; ?> open</small>
        <form method="post" class="form-inline pull-right" style="margin:-3px 0;">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="class_id" value="<?php echo $cid; ?>">
            <input type="number" name="duration" min="1" max="600" class="form-control input-sm" style="width:90px;" placeholder="minutes">
            <button class="btn btn-success btn-sm" name="bulk" value="open" title="Open every subject that has questions"><i class="fa fa-unlock"></i> Open all</button>
            <button class="btn btn-default btn-sm" name="bulk" value="close" onclick="return confirm('Close every subject in this class?');"><i class="fa fa-lock"></i> Close all</button>
        </form>
    </div>
    <div class="table-responsive">
    <table class="table table-striped table-tight" style="margin:0;">
        <thead><tr><th>Subject</th><th>Questions in bank</th><th style="width:150px;">Questions per student</th><th style="width:130px;">Time (minutes)</th><th style="width:130px;">Status</th><th style="width:170px;"></th></tr></thead>
        <tbody>
        <?php if (!$list): ?><tr><td colspan="6" class="text-center text-muted">No subjects yet. Add one below.</td></tr><?php endif; ?>
        <?php foreach ($list as $s):
            $sid = (int) $s['sub_id'];
            $n = $bank[$sid] ?? 0;
            $form = 'f' . $sid; ?>
            <tr>
                <td>
                    <?php echo e(trim($s['sub_name'])); ?>
                    <?php if (!empty($s['open_at']) || !empty($s['close_at'])): ?><i class="fa fa-clock-o text-info" title="Scheduled"></i><?php endif; ?>
                    <?php if (!empty($s['question_mix'])): ?><i class="fa fa-pie-chart text-info" title="Topic mix"></i><?php endif; ?>
                    <?php if (!empty($s['shuffle_options'])): ?><i class="fa fa-random text-info" title="Options shuffled"></i><?php endif; ?>
                    <?php if (!empty($running[$sid])): ?><span class="label label-info" title="Students currently writing this subject"><?php echo (int) $running[$sid]; ?> in progress</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($n > 0): ?><a href="questions.php?subject=<?php echo $sid; ?>"><?php echo $n; ?></a>
                    <?php else: ?><a href="question_portal.php?class=<?php echo $cid; ?>&amp;subject=<?php echo $sid; ?>" class="text-danger">0 &ndash; add</a><?php endif; ?>
                </td>
                <td>
                    <form id="<?php echo $form; ?>" method="post">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="subject_id" value="<?php echo $sid; ?>">
                    </form>
                    <input form="<?php echo $form; ?>" type="number" name="question_limit" min="0" max="5000" value="<?php echo (int) $s['question_limit']; ?>" class="form-control input-sm">
                </td>
                <td><input form="<?php echo $form; ?>" type="number" name="duration" min="0" max="600" value="<?php echo (int) $s['sub_duration']; ?>" class="form-control input-sm"></td>
                <td>
                    <select form="<?php echo $form; ?>" name="status" class="form-control input-sm <?php echo $s['status'] === 'Enabled' ? 'status-open' : 'status-closed'; ?>">
                        <option value="Disabled"<?php echo $s['status'] !== 'Enabled' ? ' selected' : ''; ?>>Closed</option>
                        <option value="Enabled"<?php echo $s['status'] === 'Enabled' ? ' selected' : ''; ?>>Open</option>
                    </select>
                </td>
                <td>
                    <button form="<?php echo $form; ?>" class="btn btn-primary btn-sm" name="save" value="1"><i class="fa fa-save"></i> Save</button>
                    <button type="button" class="btn btn-default btn-sm" data-toggle="collapse" data-target="#more<?php echo $sid; ?>" title="Schedule, topic mix, option shuffle"><i class="fa fa-cog"></i></button>
                    <?php if ($n === 0): ?>
                        <button form="<?php echo $form; ?>" class="btn btn-default btn-sm" name="delete_subject" value="1" title="Delete this subject" onclick="return confirm('Delete this subject?');"><i class="fa fa-trash"></i></button>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="collapse" id="more<?php echo $sid; ?>">
                <td colspan="6" style="background:#f9fafb;">
                    <div class="form-inline">
                        <label>Opens at</label>
                        <input form="<?php echo $form; ?>" type="datetime-local" name="open_at" class="form-control input-sm" value="<?php echo !empty($s['open_at']) ? e(date('Y-m-d\TH:i', strtotime($s['open_at']))) : ''; ?>">
                        <label style="margin-left:12px;">Closes at</label>
                        <input form="<?php echo $form; ?>" type="datetime-local" name="close_at" class="form-control input-sm" value="<?php echo !empty($s['close_at']) ? e(date('Y-m-d\TH:i', strtotime($s['close_at']))) : ''; ?>">
                        <label style="margin-left:12px;font-weight:normal;"><input form="<?php echo $form; ?>" type="checkbox" name="shuffle_options" value="1"<?php echo !empty($s['shuffle_options']) ? ' checked' : ''; ?>> Shuffle answer options</label>
                        <a class="btn btn-default btn-sm" style="margin-left:12px;" href="topic_mix.php?subject=<?php echo $sid; ?>"><i class="fa fa-pie-chart"></i> Topic mix<?php echo !empty($s['question_mix']) ? ' (on)' : ''; ?></a>
                    </div>
                    <p class="text-muted small" style="margin:6px 0 0;">Set the status to <strong>Open</strong>, add the times, and Save: students then see &ldquo;Opens 9:00 AM&rdquo; and the START button appears by itself at that time.
                       After the closing time nobody can <em>start</em> it, but students already writing may finish. Shuffling skips questions like &ldquo;All of the above&rdquo;. Save with the blue button.</p>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="panel-footer">
        <form method="post" class="form-inline">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="class_id" value="<?php echo $cid; ?>">
            <input type="text" name="name" maxlength="100" class="form-control input-sm" placeholder="New subject name" style="width:240px;" required>
            <button class="btn btn-default btn-sm" name="add_subject" value="1"><i class="fa fa-plus"></i> Add subject to <?php echo e($c['class']); ?></button>
        </form>
    </div>
</div>
<?php endforeach; ?>
<?php admin_foot();
