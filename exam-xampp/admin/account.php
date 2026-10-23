<?php
// Every signed-in person (administrator or teacher) can change their own password here.
require_once __DIR__ . '/../includes/admin_layout.php';

$me = (int) $_SESSION['admin_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token. Reload the page and try again.'); }
    $cur = (string) ($_POST['current'] ?? '');
    $new = (string) ($_POST['password'] ?? '');
    $row = db_one("SELECT Password FROM admin WHERE id = ?", 'i', $me);
    if (!$row || !password_verify($cur, $row['Password'])) {
        flash_set('danger', 'Your current password is not correct.');
    } elseif (strlen($new) < 10) {
        flash_set('danger', 'The new password must be at least 10 characters.');
    } elseif ($new !== (string) ($_POST['confirm'] ?? '')) {
        flash_set('danger', 'The two new passwords do not match.');
    } else {
        db_exec("UPDATE admin SET Password = ? WHERE id = ?", 'si', password_hash($new, PASSWORD_DEFAULT), $me);
        flash_set('success', 'Password changed.');
    }
    header('Location: account.php');
    exit;
}

$subjects = teacher_subject_ids();
admin_head('My account', '');
flash_show();
?>
<div class="row"><div class="col-md-5">
    <div class="panel panel-default">
        <div class="panel-heading panel-heading-brand"><?php echo e($_SESSION['username']); ?> &mdash; <?php echo is_admin_role() ? 'administrator' : 'teacher'; ?></div>
        <div class="panel-body">
            <?php if (!is_admin_role()): ?>
                <p class="text-muted small">You can upload and edit questions, and view results.
                    <?php echo $subjects === null ? 'You can work on all subjects.' : 'Your subjects: ' . count($subjects) . '.'; ?></p>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <div class="form-group"><label>Current password</label><input type="password" name="current" class="form-control" autocomplete="current-password" required></div>
                <div class="form-group"><label>New password (10+ characters)</label><input type="password" name="password" minlength="10" class="form-control" autocomplete="new-password" required></div>
                <div class="form-group"><label>New password again</label><input type="password" name="confirm" minlength="10" class="form-control" autocomplete="new-password" required></div>
                <button class="btn btn-primary">Change password</button>
            </form>
        </div>
    </div>
</div></div>
<?php admin_foot();
