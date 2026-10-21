<?php
require_once __DIR__ . '/../includes/admin_layout.php';

$me = (int) $_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token. Reload the page and try again.'); }
    $go = 'settings.php';

    if (isset($_POST['saveGrading'])) {
        $pass = $_POST['pass_mark'] ?? '';
        $bands = [];
        $ok = is_numeric($pass) && $pass >= 0 && $pass <= 100;
        foreach ((array) ($_POST['min'] ?? []) as $i => $min) {
            $grade = trim(strip_tags((string) ($_POST['grade'][$i] ?? '')));
            $remark = trim(strip_tags((string) ($_POST['remark'][$i] ?? '')));
            if ($min === '' && $grade === '') { continue; }
            if (!is_numeric($min) || $min < 0 || $min > 100 || $grade === '' || mb_strlen($grade) > 3 || mb_strlen($remark) > 14
                || strpbrk($grade . $remark, ':|') !== false) { $ok = false; break; }
            $bands[] = [(float) $min, $grade, $remark];
        }
        if ($ok && (count($bands) < 2 || count($bands) > 8)) { $ok = false; }
        if ($ok) {
            usort($bands, function ($a, $b) { return $b[0] <=> $a[0]; });
            if ($bands[count($bands) - 1][0] > 0) { $bands[] = [0, end($bands)[1], end($bands)[2]]; } // always cover 0%
            setting_set('pass_mark', (string) (float) $pass);
            setting_set('grade_scale', grade_scale_format($bands));
            flash_set('success', 'Pass mark and grading scale saved.');
        } else {
            flash_set('danger', 'Check the grading table: 2-8 rows, marks between 0 and 100, grade up to 3 characters, remark up to 14 characters (no : or |).');
        }
    } elseif (isset($_POST['saveRegistration'])) {
        $open = ($_POST['reg_open'] ?? '1') === '1' ? '1' : '0';
        $closes = trim((string) ($_POST['reg_closes'] ?? ''));
        if ($closes !== '' && strtotime($closes) === false) {
            flash_set('danger', 'That closing date is not valid.');
        } else {
            setting_set('registration_open', $open);
            setting_set('registration_closes', $closes !== '' ? date('Y-m-d H:i:s', strtotime($closes)) : '');
            flash_set('success', $open === '1' ? 'Student self-registration is OPEN.' : 'Student self-registration is now CLOSED.');
        }
    } elseif (isset($_POST['addInstruction'])) {
        $text = trim((string) ($_POST['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 2000) {
            flash_set('danger', 'An instruction must be between 1 and 2000 characters.');
        } else {
            db_exec("INSERT INTO instruction (text) VALUES (?)", 's', $text);
            flash_set('success', 'Instruction added. Students see it before choosing a subject.');
        }
        $go = 'settings.php#instructions';
    } elseif (isset($_POST['deleteInstruction'])) {
        db_exec("DELETE FROM instruction WHERE id = ?", 'i', (int) ($_POST['id'] ?? 0));
        flash_set('success', 'Instruction removed.');
        $go = 'settings.php#instructions';
    } elseif (isset($_POST['addAdmin'])) {
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $u)) {
            flash_set('danger', 'Username must be 3-50 characters (letters, numbers, . _ -).');
        } elseif (strlen($p) < 10) {
            flash_set('danger', 'Password must be at least 10 characters.');
        } elseif (db_one("SELECT id FROM admin WHERE Username = ?", 's', $u)) {
            flash_set('danger', 'That username already exists.');
        } else {
            db_exec("INSERT INTO admin (Username, Password) VALUES (?, ?)", 'ss', $u, password_hash($p, PASSWORD_DEFAULT));
            flash_set('success', "Administrator \"$u\" added.");
        }
        $go = 'settings.php#admins';
    } elseif (isset($_POST['resetPassword'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $p = (string) ($_POST['password'] ?? '');
        if (!db_one("SELECT id FROM admin WHERE id = ?", 'i', $id)) {
            flash_set('danger', 'That administrator no longer exists.');
        } elseif (strlen($p) < 10) {
            flash_set('danger', 'The new password must be at least 10 characters.');
        } else {
            db_exec("UPDATE admin SET Password = ? WHERE id = ?", 'si', password_hash($p, PASSWORD_DEFAULT), $id);
            flash_set('success', 'Password changed.');
        }
        $go = 'settings.php#admins';
    } elseif (isset($_POST['renameAdmin'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $u = trim((string) ($_POST['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $u)) {
            flash_set('danger', 'Username must be 3-50 characters (letters, numbers, . _ -).');
        } elseif (db_one("SELECT id FROM admin WHERE Username = ? AND id <> ?", 'si', $u, $id)) {
            flash_set('danger', 'That username is already taken.');
        } else {
            db_exec("UPDATE admin SET Username = ? WHERE id = ?", 'si', $u, $id);
            if ($id === $me) { $_SESSION['username'] = $u; }
            flash_set('success', 'Username changed.');
        }
        $go = 'settings.php#admins';
    } elseif (isset($_POST['deleteAdmin'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $count = (int) db_one("SELECT COUNT(*) AS c FROM admin")['c'];
        if ($id === $me) {
            flash_set('danger', 'You cannot delete the account you are logged in with.');
        } elseif ($count <= 1) {
            flash_set('danger', 'At least one administrator must remain.');
        } else {
            db_exec("DELETE FROM admin WHERE id = ?", 'i', $id);
            flash_set('success', 'Administrator removed.');
        }
        $go = 'settings.php#admins';
    }
    header('Location: ' . $go);
    exit;
}

$instructions = getAllInstruction();
$admins = db_all("SELECT id, Username, RegDate FROM admin ORDER BY id");
$scale = grade_scale();
while (count($scale) < 8) { $scale[] = ['', '', '']; }
$closes = (string) setting('registration_closes', '');

admin_head('Settings', 'settings');
flash_show();
?>
<div class="row">
    <div class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">Pass mark &amp; grading scale</div>
            <div class="panel-body">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <div class="form-inline" style="margin-bottom:12px;">
                        <label>Pass mark</label>
                        <input type="number" name="pass_mark" min="0" max="100" step="0.5" value="<?php echo e(pass_mark()); ?>" class="form-control" style="width:90px;"> %
                    </div>
                    <table class="table table-condensed table-tight">
                        <thead><tr><th>From (%)</th><th>Grade</th><th>Remark</th></tr></thead>
                        <tbody>
                        <?php foreach ($scale as $i => $b): ?>
                            <tr>
                                <td><input type="number" name="min[]" min="0" max="100" step="0.5" value="<?php echo e($b[0]); ?>" class="form-control input-sm"></td>
                                <td><input type="text" name="grade[]" maxlength="3" value="<?php echo e($b[1]); ?>" class="form-control input-sm" style="width:70px;"></td>
                                <td><input type="text" name="remark[]" maxlength="14" value="<?php echo e($b[2]); ?>" class="form-control input-sm"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="text-muted small">A score gets the first grade whose "From" it reaches. Leave unused rows empty. Used in Results, Report cards and on the student's result page.</p>
                    <button class="btn btn-success" name="saveGrading" value="1"><i class="fa fa-save"></i> Save grading</button>
                </form>
            </div>
        </div>

        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">Student self-registration</div>
            <div class="panel-body">
                <p>Status: <?php echo registration_open() ? '<strong class="text-success">OPEN</strong>' : '<strong class="text-danger">CLOSED</strong>'; ?>
                   <small class="text-muted">(link: <code>/onlineForm/</code>)</small></p>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <div class="form-group">
                        <label class="radio-inline"><input type="radio" name="reg_open" value="1"<?php echo setting('registration_open', '1') !== '0' ? ' checked' : ''; ?>> Open</label>
                        <label class="radio-inline"><input type="radio" name="reg_open" value="0"<?php echo setting('registration_open', '1') === '0' ? ' checked' : ''; ?>> Closed</label>
                    </div>
                    <div class="form-group">
                        <label>Close automatically on <small class="text-muted">(optional)</small></label>
                        <input type="datetime-local" name="reg_closes" class="form-control" value="<?php echo $closes !== '' ? e(date('Y-m-d\TH:i', strtotime($closes))) : ''; ?>">
                    </div>
                    <button class="btn btn-success" name="saveRegistration" value="1"><i class="fa fa-save"></i> Save</button>
                    <p class="text-muted small" style="margin-top:8px;">Closing only stops new self-registration. Students you register in bulk, and everyone already registered, are not affected.</p>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="panel panel-default" id="instructions">
            <div class="panel-heading panel-heading-brand">Exam instructions <small>(shown to students before they pick a subject)</small></div>
            <ul class="list-group">
                <?php if (!$instructions): ?><li class="list-group-item text-muted">No instructions yet.</li><?php endif; ?>
                <?php foreach ($instructions as $i): ?>
                    <li class="list-group-item clearfix">
                        <form method="post" class="pull-right" onsubmit="return confirm('Remove this instruction?');">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $i['id']; ?>">
                            <button class="btn btn-xs btn-danger" name="deleteInstruction" value="1"><i class="fa fa-trash"></i></button>
                        </form>
                        <div style="margin-right:40px;"><?php echo nl2br(e($i['text'])); ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="panel-body">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <div class="form-group"><textarea name="text" rows="3" class="form-control" maxlength="2000" placeholder="New instruction..." required></textarea></div>
                    <button class="btn btn-success" name="addInstruction" value="1"><i class="fa fa-plus"></i> Add instruction</button>
                </form>
            </div>
        </div>

        <div class="panel panel-default" id="admins">
            <div class="panel-heading panel-heading-brand">Administrators</div>
            <ul class="list-group">
                <?php foreach ($admins as $a): $aid = (int) $a['id']; ?>
                    <li class="list-group-item">
                        <div class="clearfix">
                            <strong><i class="fa fa-user"></i> <?php echo e($a['Username']); ?></strong>
                            <?php if ($aid === $me): ?><span class="label label-success">you</span><?php endif; ?>
                            <button type="button" class="btn btn-xs btn-default pull-right" data-toggle="collapse" data-target="#adm<?php echo $aid; ?>">Manage</button>
                        </div>
                        <div class="collapse" id="adm<?php echo $aid; ?>" style="margin-top:10px;">
                            <form method="post" class="form-inline" style="margin-bottom:6px;" autocomplete="off">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo $aid; ?>">
                                <input type="text" name="username" value="<?php echo e($a['Username']); ?>" class="form-control input-sm" required>
                                <button class="btn btn-sm btn-default" name="renameAdmin" value="1">Rename</button>
                            </form>
                            <form method="post" class="form-inline" style="margin-bottom:6px;" autocomplete="off">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo $aid; ?>">
                                <input type="password" name="password" minlength="10" placeholder="New password (10+ characters)" autocomplete="new-password" class="form-control input-sm" required>
                                <button class="btn btn-sm btn-primary" name="resetPassword" value="1">Change password</button>
                            </form>
                            <?php if ($aid !== $me): ?>
                            <form method="post" onsubmit="return confirm('Remove this administrator?');">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo $aid; ?>">
                                <button class="btn btn-sm btn-danger" name="deleteAdmin" value="1"><i class="fa fa-trash"></i> Remove</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="panel-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <div class="form-group"><label>New administrator</label><input type="text" name="username" class="form-control" placeholder="Username" required></div>
                    <div class="form-group"><input type="password" name="password" class="form-control" minlength="10" placeholder="Password (at least 10 characters)" autocomplete="new-password" required></div>
                    <button class="btn btn-success" name="addAdmin" value="1"><i class="fa fa-user-plus"></i> Add administrator</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php admin_foot();
