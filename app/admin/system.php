<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/backup.php';

// Download a saved backup (name is validated; nothing else in the folder is reachable).
if (isset($_GET['file'])) {
    $name = basename((string) $_GET['file']);
    $path = backup_dir() . '/' . $name;
    if (!preg_match('/^backup-\d{8}-\d{6}\.sql$/', $name) || !is_file($path)) { http_response_code(404); exit('Not found'); }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token. Reload the page and try again.'); }
    if (isset($_POST['download'])) {            // stream a fresh backup straight to the browser
        @set_time_limit(0);
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="exam-backup-' . date('Ymd-His') . '.sql"');
        $out = fopen('php://output', 'wb');
        backup_write($out);
        exit;
    }
    if (isset($_POST['saveServer'])) {
        try {
            $n = backup_to_server();
            flash_set('success', "Backup saved on the server: $n");
        } catch (Throwable $t) {
            flash_set('danger', 'Could not save the backup: ' . $t->getMessage());
        }
    }
    header('Location: system.php');
    exit;
}

/* ---------- system check ---------- */
$checks = [];
function chk(&$list, $label, $ok, $detail = '', $warnOnly = false)
{
    $list[] = [$label, $ok ? 'ok' : ($warnOnly ? 'warn' : 'bad'), $detail];
}
chk($checks, 'PHP version', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION);
foreach (['mysqli' => true, 'mbstring' => true, 'gd' => false, 'zip' => false, 'fileinfo' => false] as $ext => $required) {
    $has = extension_loaded($ext);
    chk($checks, "PHP extension: $ext", $has, $has ? 'loaded' : ($required ? 'MISSING - the app needs this' : 'not loaded' . ($ext === 'zip' ? ' (photo ZIP upload will be unavailable)' : '')), !$required);
}
try {
    $ver = db_one("SELECT VERSION() AS v");
    chk($checks, 'Database connection', true, 'connected, server ' . ($ver['v'] ?? ''));
} catch (Throwable $t) {
    chk($checks, 'Database connection', false, $t->getMessage());
}
$must = ['admin','class','subject','instruction','question','register','dosubject','exam_result','login_attempts','exam_answer','exam_question','app_meta'];
$have = array_map(function ($r) { return strtolower((string) array_values($r)[0]); }, db_all("SHOW TABLES"));
$missing = array_values(array_diff($must, $have));
chk($checks, 'Database tables', !$missing, $missing ? 'missing: ' . implode(', ', $missing) : 'all ' . count($must) . ' present');
foreach ([
    'uploads (question images)' => dirname(__DIR__) . '/uploads',
    'Student photos' => dirname(__DIR__) . '/onlineForm/images/Student_Images',
    'backups' => backup_dir(),
] as $label => $dir) {
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    chk($checks, "Folder writable: $label", is_dir($dir) && is_writable($dir), is_writable($dir) ? 'yes' : 'NO - uploads/backups will fail');
}
chk($checks, 'Session storage writable', is_writable(session_save_path() ?: sys_get_temp_dir()), session_save_path() ?: sys_get_temp_dir());
$up = ini_get('upload_max_filesize'); $post = ini_get('post_max_size'); $mf = (int) ini_get('max_file_uploads');
chk($checks, 'Upload limits', true, "upload_max_filesize $up, post_max_size $post, max_file_uploads $mf (photos: a ZIP avoids the $mf-file limit)");
chk($checks, 'Server time', true, date('Y-m-d H:i:s T') . '  (all exam timers use this clock)');
$score = [
    'Registered students' => (int) db_one("SELECT COUNT(*) AS c FROM register")['c'],
    'Questions in the bank' => (int) db_one("SELECT COUNT(*) AS c FROM question")['c'],
    'Subjects open now' => (int) db_one("SELECT COUNT(*) AS c FROM subject WHERE status = 'Enabled'")['c'],
    'Exams in progress' => (int) db_one("SELECT COUNT(*) AS c FROM dosubject WHERE istaken = 0")['c'],
    'Results recorded' => (int) db_one("SELECT COUNT(*) AS c FROM exam_result")['c'],
];
$host = $_SERVER['HTTP_HOST'] ?? 'SERVER-IP';
$examUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $host . url('');
$backups = backup_files();
$last = (int) setting('last_backup', '0');

admin_head('System check & backup', 'system');
flash_show();
?>
<div class="row">
    <div class="col-md-7">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">System check</div>
            <table class="table table-condensed table-tight" style="margin:0;">
                <?php foreach ($checks as $c): ?>
                    <tr>
                        <td style="width:30px;"><?php echo $c[1] === 'ok' ? '<i class="fa fa-check-circle text-success"></i>' : ($c[1] === 'warn' ? '<i class="fa fa-exclamation-triangle text-warning"></i>' : '<i class="fa fa-times-circle text-danger"></i>'); ?></td>
                        <td><?php echo e($c[0]); ?></td>
                        <td class="text-muted small"><?php echo e($c[2]); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">Right now</div>
            <table class="table table-condensed" style="margin:0;">
                <?php foreach ($score as $label => $n): ?><tr><td><?php echo e($label); ?></td><td><strong><?php echo $n; ?></strong></td></tr><?php endforeach; ?>
            </table>
        </div>
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">Exam-day rehearsal checklist</div>
            <div class="panel-body">
                <p class="text-muted small">Do this a day or two before the real exam, with a few helpers acting as students.</p>
                <ol style="padding-left:18px;">
                    <li>Every check above is green. Take a backup (right) and keep a copy on a flash drive.</li>
                    <li>On the server computer run <code>ipconfig</code>; the exam address for students is <code><?php echo e($examUrl); ?></code> (use the server's IPv4 address instead of <em>localhost</em>). Allow Apache through the Windows Firewall.</li>
                    <li>From <strong>another computer</strong> on the lab network open that address. If it does not load, fix this first.</li>
                    <li>Register one student per class (Junior and each Senior class) and print their <a href="exam_cards.php">exam cards</a>.</li>
                    <li>Make sure every subject has questions (<a href="exam_control.php">Subject status</a> shows 0 in red when empty).</li>
                    <li>Open one subject for 5 minutes. Have all the helpers log in at the <em>same time</em> and start it. Check the <a href="monitor.php">Live monitor</a>.</li>
                    <li>Answer a few questions, refresh the browser, and check the answers come back. Unplug a network cable for 30 seconds and plug it back in: answers must still be saved.</li>
                    <li>Submit one exam and let one timer run out; check both show in <a href="results.php">Results</a>.</li>
                    <li>Use <strong>Reset</strong> in Results to remove the rehearsal results, then delete the rehearsal students.</li>
                    <li>Class sizes over ~100 students on one old computer: stagger start times by class, and close other programs on the server.</li>
                </ol>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">Database backup</div>
            <div class="panel-body">
                <p>Last automatic backup: <strong><?php echo $last ? e(date('D j M Y, H:i', $last)) : 'none yet'; ?></strong><br>
                   <small class="text-muted">One is saved automatically (and the newest 14 kept) the first time an administrator logs in each day.</small></p>
                <form method="post" style="margin-bottom:8px;">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <button class="btn btn-primary" name="download" value="1"><i class="fa fa-download"></i> Download a backup now</button>
                    <button class="btn btn-default" name="saveServer" value="1"><i class="fa fa-save"></i> Save one on the server</button>
                </form>
                <p class="text-muted small">Keep a copy on a flash drive or another computer &mdash; a backup on the same computer does not protect against that computer failing.
                   To restore: phpMyAdmin &rarr; select the exam database &rarr; <em>Import</em> &rarr; choose the .sql file.</p>
            </div>
            <?php if ($backups): ?>
            <table class="table table-condensed table-tight" style="margin:0;">
                <thead><tr><th>Saved on the server</th><th>Size</th><th></th></tr></thead>
                <?php foreach ($backups as $b): ?>
                    <tr><td><?php echo e(date('j M Y H:i', $b['time'])); ?></td><td><?php echo e(number_format($b['size'] / 1024, 0)); ?> KB</td>
                        <td><a class="btn btn-xs btn-default" href="system.php?file=<?php echo e(rawurlencode($b['name'])); ?>"><i class="fa fa-download"></i></a></td></tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php admin_foot();
